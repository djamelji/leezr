<?php

namespace App\Modules\Workforce\Employees\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Department;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use App\Modules\Workforce\Data\CreateEmployeeData;
use App\Modules\Workforce\UseCases\CreateEmployeeUseCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * W-G2 (ADR-642) — import guidé de NOUVEAUX collaborateurs depuis un CSV.
 *
 * Doctrine :
 *  - dry-run (`preview`) STRICTEMENT sans effet de bord ;
 *  - identité jamais déduite du seul nom : clé métier = `employee_number` (unique par Company) ;
 *  - import de nouveaux collaborateurs uniquement — jamais d'écrasement d'un dossier existant ;
 *  - ne crée QUE l'Employee : aucun compte, invitation, contrat, rémunération, congé ni accès ;
 *  - `apply` transactionnel + idempotent (ré-import d'un même fichier ne recrée rien) + audité.
 */
class EmployeeImportService
{
    public const MAX_ROWS = 1000;
    public const MAX_BYTES = 2_000_000; // 2 Mo

    /** Champs cibles autorisés (issus du modèle Employee). `department`/`job_role`/`manager` = résolus par libellé/clé. */
    public const FIELDS = ['first_name', 'last_name', 'employee_number', 'email', 'phone', 'hire_date', 'status', 'department', 'job_role', 'manager'];
    public const REQUIRED = ['first_name', 'last_name', 'hire_date'];

    /** Parse un contenu CSV en lignes associatives. Détecte le délimiteur et retire le BOM UTF-8. */
    public function parse(string $content): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new \DomainException("Fichier trop volumineux (max ".(self::MAX_BYTES / 1_000_000)." Mo).");
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // BOM
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        if (count($lines) < 2) {
            throw new \DomainException('Fichier vide ou sans ligne de données.');
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $headers = array_map(fn ($h) => trim($h), str_getcsv($lines[0], $delimiter));

        $rows = [];
        for ($i = 1; $i < count($lines); $i++) {
            if (trim($lines[$i]) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new \DomainException('Trop de lignes (max '.self::MAX_ROWS.').');
            }
            $cells = str_getcsv($lines[$i], $delimiter);
            $row = [];
            foreach ($headers as $idx => $h) {
                $row[$h] = isset($cells[$idx]) ? trim($cells[$idx]) : '';
            }
            $rows[] = ['_line' => $i + 1, 'data' => $row];
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function detectDelimiter(string $headerLine): string
    {
        $candidates = [',' => substr_count($headerLine, ','), ';' => substr_count($headerLine, ';'), "\t" => substr_count($headerLine, "\t")];
        arsort($candidates);

        return array_key_first($candidates) ?: ',';
    }

    /**
     * Valide chaque ligne (dry-run SANS écriture) et renvoie un rapport détaillé.
     *
     * @param  array<string,string>  $mapping  targetField => nom de colonne CSV
     */
    public function preview(int $companyId, array $parsedRows, array $mapping): array
    {
        $departments = Department::withoutGlobalScopes()->where('company_id', $companyId)->get()
            ->keyBy(fn ($d) => mb_strtolower(trim($d->name)));
        $jobRoles = JobRole::withoutGlobalScopes()->where('company_id', $companyId)->get()
            ->keyBy(fn ($r) => mb_strtolower(trim($r->title)));
        $existingNumbers = Employee::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereNotNull('employee_number')->pluck('employee_number')
            ->map(fn ($n) => mb_strtolower(trim($n)))->flip();

        $managers = Employee::withoutGlobalScopes()->where('company_id', $companyId)->get();
        $managerByNumber = $managers->whereNotNull('employee_number')
            ->keyBy(fn ($e) => mb_strtolower(trim($e->employee_number)));
        $managerByName = $managers->groupBy(fn ($e) => mb_strtolower(trim($e->first_name.' '.$e->last_name)));

        $seenNumbers = [];
        $report = [];
        $counts = ['total' => 0, 'create' => 0, 'skip_duplicate' => 0, 'conflict' => 0, 'invalid' => 0];

        foreach ($parsedRows as $parsed) {
            $counts['total']++;
            $raw = $parsed['data'];
            $get = fn (string $field) => isset($mapping[$field], $raw[$mapping[$field]]) ? trim((string) $raw[$mapping[$field]]) : '';

            $errors = [];
            $warnings = [];

            $first = $get('first_name');
            $last = $get('last_name');
            $number = $get('employee_number');
            $email = $get('email');
            $phone = $get('phone');
            $hireRaw = $get('hire_date');
            $statusRaw = $get('status');
            $deptName = $get('department');
            $roleName = $get('job_role');
            $managerRaw = $get('manager');

            foreach (self::REQUIRED as $req) {
                if ($get($req) === '') {
                    $errors[] = "Champ requis manquant : {$req}";
                }
            }

            $hireDate = $hireRaw !== '' ? $this->parseDate($hireRaw) : null;
            if ($hireRaw !== '' && $hireDate === null) {
                $errors[] = "Date d'embauche invalide : « {$hireRaw} »";
            }

            $status = Employee::STATUS_ACTIVE;
            if ($statusRaw !== '') {
                $s = mb_strtolower($statusRaw);
                if (! in_array($s, Employee::STATUSES, true)) {
                    $errors[] = "Statut inconnu : « {$statusRaw} »";
                } else {
                    $status = $s;
                }
            }

            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Email invalide : « {$email} »";
            }

            $departmentId = null;
            if ($deptName !== '') {
                $dept = $departments->get(mb_strtolower($deptName));
                if (! $dept) {
                    $errors[] = "Département introuvable : « {$deptName} »";
                } else {
                    $departmentId = $dept->id;
                }
            }

            $jobRoleId = null;
            if ($roleName !== '') {
                $role = $jobRoles->get(mb_strtolower($roleName));
                if (! $role) {
                    $errors[] = "Poste introuvable : « {$roleName} »";
                } else {
                    $jobRoleId = $role->id;
                }
            }

            $managerId = null;
            $isConflict = false;
            if ($managerRaw !== '') {
                $byNum = $managerByNumber->get(mb_strtolower($managerRaw));
                if ($byNum) {
                    $managerId = $byNum->id;
                } else {
                    $matches = $managerByName->get(mb_strtolower($managerRaw));
                    if (! $matches || $matches->isEmpty()) {
                        $errors[] = "Responsable introuvable : « {$managerRaw} »";
                    } elseif ($matches->count() > 1) {
                        $isConflict = true;
                        $warnings[] = "Responsable ambigu (« {$managerRaw} » correspond à plusieurs collaborateurs) — préciser la référence.";
                    } else {
                        $managerId = $matches->first()->id;
                    }
                }
            }

            // Doublon par clé métier (employee_number). Jamais par nom seul.
            // W-G2 réserve : SANS référence, l'identité n'est pas stable → on BLOQUE la ligne en conflit
            // (jamais de création silencieuse re-créable au second import). L'opérateur doit fournir une
            // référence pour garantir l'idempotence.
            $action = 'create';
            if ($number !== '') {
                $key = mb_strtolower($number);
                if ($existingNumbers->has($key)) {
                    $action = 'skip_duplicate';
                    $warnings[] = "Collaborateur déjà existant (référence « {$number} ») — ignoré.";
                } elseif (isset($seenNumbers[$key])) {
                    $action = 'conflict';
                    $warnings[] = "Référence « {$number} » en double dans le fichier (ligne ".$seenNumbers[$key].").";
                    $isConflict = true;
                } else {
                    $seenNumbers[$key] = $parsed['_line'];
                }
            } else {
                $isConflict = true;
                $errors[] = "Référence collaborateur (employee_number) requise : sans clé stable, l'import ne peut garantir l'absence de doublon.";
            }

            if (! empty($errors)) {
                $action = 'invalid';
            } elseif ($isConflict && $action === 'create') {
                $action = 'conflict';
            }

            $counts[$action]++;

            $report[] = [
                'line' => $parsed['_line'],
                'action' => $action,
                'errors' => $errors,
                'warnings' => $warnings,
                'data' => [
                    'first_name' => $first,
                    'last_name' => $last,
                    'employee_number' => $number ?: null,
                    'email' => $email ?: null,
                    'phone' => $phone ?: null,
                    'hire_date' => $hireDate?->toDateString(),
                    'status' => $status,
                    'department_id' => $departmentId,
                    'department' => $deptName ?: null,
                    'job_role_id' => $jobRoleId,
                    'job_role' => $roleName ?: null,
                    'manager_id' => $managerId,
                    'manager' => $managerRaw ?: null,
                ],
            ];
        }

        return ['counts' => $counts, 'rows' => $report];
    }

    /**
     * Applique l'import : crée UNIQUEMENT les lignes `action === 'create'` (transaction, idempotent,
     * audité). Les lignes invalides/doublons/conflits sont ignorées et reportées.
     */
    public function apply(int $companyId, array $parsedRows, array $mapping, int $actorId): array
    {
        $preview = $this->preview($companyId, $parsedRows, $mapping);

        $result = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'rows' => []];

        DB::transaction(function () use ($preview, $companyId, $actorId, &$result) {
            foreach ($preview['rows'] as $row) {
                if ($row['action'] !== 'create') {
                    $result['skipped']++;
                    $result['rows'][] = ['line' => $row['line'], 'status' => $row['action'], 'errors' => $row['errors']];

                    continue;
                }

                try {
                    $d = $row['data'];
                    $employee = app(CreateEmployeeUseCase::class)->execute(new CreateEmployeeData(
                        companyId: $companyId,
                        firstName: $d['first_name'],
                        lastName: $d['last_name'],
                        hireDate: $d['hire_date'],
                        employeeNumber: $d['employee_number'],
                        email: $d['email'],
                        phone: $d['phone'],
                        status: $d['status'],
                        departmentId: $d['department_id'],
                        jobRoleId: $d['job_role_id'],
                        managerId: $d['manager_id'],
                    ));
                    $result['created']++;
                    $result['rows'][] = ['line' => $row['line'], 'status' => 'created', 'employee_id' => $employee->id];
                } catch (\Throwable $e) {
                    // Collision de clé unique (ré-soumission concurrente) ou autre → ligne en échec, pas d'arrêt global.
                    $result['failed']++;
                    $result['rows'][] = ['line' => $row['line'], 'status' => 'failed', 'errors' => [$e->getMessage()]];
                }
            }
        });

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'employee.imported',
            targetType: 'employee',
            targetId: 'batch',
            options: ['metadata' => [
                'category' => 'workforce.employee',
                'description' => "Import collaborateurs : {$result['created']} créés, {$result['skipped']} ignorés, {$result['failed']} échecs",
            ]],
        );

        return $result;
    }

    private function parseDate(string $value): ?Carbon
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'Y/m/d'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $value);
                if ($d !== false && $d->format($fmt) === $value) {
                    return $d->startOfDay();
                }
            } catch (\Throwable) {
                // essai suivant
            }
        }

        return null;
    }
}
