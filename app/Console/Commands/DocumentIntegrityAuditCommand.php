<?php

namespace App\Console\Commands;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\GeneratedDocument;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Payslip\PublishedPayslip;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-771 — READ-ONLY integrity audit of every documentary reference to a physical file.
 *
 * Why : two historical behaviours (fixed in ADR-770) may have damaged existing data — a real PDF
 * replacement overwrote the previous file (deterministic name), and account-less employees of a
 * company shared one storage path. This command DETECTS the traces, it repairs nothing.
 *
 * Guarantees : SELECT statements and file reads only — no insert/update/delete, no file written,
 * moved, restored or removed (the optional `--output` report is the only thing ever written, at the
 * path the operator gives). Works company by company, in chunks, hashing files as streams.
 *
 * Classes (a finding is never both) :
 *   corruption_demontree : a recorded fingerprint contradicts the bytes actually stored
 *   suspect              : consistent with a past overwrite / shared path, not provable from here
 *   manquant             : a reference whose file does not exist
 *   incoherence_tenant   : a file stored under another company's folder
 *   orphelin             : (with --orphans) a file under the company folders that nothing references
 *
 * Exit code : 2 when at least one proven corruption is found, 0 otherwise.
 */
class DocumentIntegrityAuditCommand extends Command
{
    protected $signature = 'documents:integrity-audit
        {--company= : Limit the audit to one company id}
        {--format=table : table | json}
        {--output= : Write the JSON report to this path}
        {--limit=200 : Maximum findings listed per class (counters stay exact)}
        {--orphans : Also list unreferenced files under the company document folders}';

    protected $description = 'Read-only integrity audit of stored documents (detects, never modifies)';

    public const CORRUPT = 'corruption_demontree';
    public const SUSPECT = 'suspect';
    public const MISSING = 'manquant';
    public const TENANT = 'incoherence_tenant';
    public const ORPHAN = 'orphelin';

    /** @var array<string, array{0:string,1:?string,2:?string}> sha / size / exists cache per "disk|path" */
    private array $files = [];
    private array $counts = [];
    private array $findings = [];
    private array $seen = [];
    private int $limit = 200;

    public function handle(): int
    {
        // A command instance may be reused in one process : every run starts from a clean state.
        [$this->files, $this->counts, $this->findings, $this->seen] = [[], [], [], []];
        $this->limit = max(0, (int) $this->option('limit'));
        $only = $this->option('company') !== null ? (int) $this->option('company') : null;
        $companyIds = $only !== null ? [$only] : $this->companyIds();

        foreach ($companyIds as $companyId) {
            $this->auditCompany((int) $companyId);
            $this->files = []; // bounded memory : one company at a time
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'scope' => $only !== null ? ['company_id' => $only] : ['companies' => count($companyIds)],
            'read_only' => true,
            'totals' => $this->totals(),
            'by_company' => $this->counts,
            'findings' => $this->findings,
            'truncated' => collect($this->totals())->contains(fn ($n) => $n > $this->limit),
        ];

        if ($this->option('output')) {
            file_put_contents((string) $this->option('output'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        if ($this->option('format') === 'json') {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['class', 'count'], collect($report['totals'])->map(fn ($n, $c) => [$c, $n])->values()->all());
            foreach (array_slice($this->findings, 0, 50) as $f) {
                $this->line(sprintf('  [%s] %s#%s company=%s %s — %s', $f['class'], $f['table'], $f['id'] ?? '-', $f['company_id'], $f['path'], $f['reason']));
            }
        }

        return ($report['totals'][self::CORRUPT] ?? 0) > 0 ? 2 : self::SUCCESS;
    }

    /** Every table that points to a stored file : [table, path column, sha column, size column, disk, subject columns]. */
    private function sources(): array
    {
        $generated = config('documents.generated_disk', 'local');

        return [
            [(new MemberDocument)->getTable(), 'file_path', 'file_sha256', 'file_size_bytes', 'local', ['document_subject_type', 'document_subject_id', 'user_id', 'document_type_id']],
            [(new DocumentVersion)->getTable(), 'file_path', 'file_sha256', 'file_size_bytes', 'local', ['subject_type', 'subject_id', 'documentable_type', 'documentable_id']],
            [(new CompanyDocument)->getTable(), 'file_path', 'file_sha256', 'file_size_bytes', 'local', ['document_type_id']],
            [(new PublishedPayslip)->getTable(), 'file_path', 'file_sha256', 'file_size_bytes', 'local', ['document_subject_type', 'document_subject_id', 'period']],
            [(new GeneratedDocument)->getTable(), 'file_path', null, 'file_size_bytes', $generated, ['subject_type', 'subject_id']],
            [(new SignatureRequest)->getTable(), 'signed_document_path', 'signed_document_sha256', null, $generated, ['generated_document_id']],
        ];
    }

    private function companyIds(): array
    {
        $ids = [];
        foreach ($this->sources() as [$table]) {
            $ids = array_merge($ids, DB::table($table)->distinct()->pluck('company_id')->all());
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        sort($ids);

        return $ids;
    }

    private function auditCompany(int $companyId): void
    {
        $byPath = []; // "disk|path" => list of references

        foreach ($this->sources() as [$table, $pathCol, $shaCol, $sizeCol, $disk, $subjectCols]) {
            $columns = array_values(array_filter(array_merge(['id', $pathCol, $shaCol, $sizeCol], $subjectCols)));
            DB::table($table)->where('company_id', $companyId)->whereNotNull($pathCol)->where($pathCol, '!=', '')
                ->select($columns)->orderBy('id')->chunkById(500, function ($rows) use (&$byPath, $table, $pathCol, $shaCol, $sizeCol, $disk, $subjectCols, $companyId) {
                    foreach ($rows as $row) {
                        $subject = [];
                        foreach ($subjectCols as $c) {
                            $subject[$c] = $row->{$c};
                        }
                        $ref = ['table' => $table, 'id' => $row->id, 'company_id' => $companyId, 'path' => $row->{$pathCol},
                            'sha' => $shaCol ? $row->{$shaCol} : null, 'has_sha_column' => $shaCol !== null,
                            'size' => $sizeCol ? $row->{$sizeCol} : null, 'subject' => $subject, 'disk' => $disk];
                        $byPath[$disk.'|'.$ref['path']][] = $ref;
                        $this->auditReference($ref);
                    }
                });
        }

        foreach ($byPath as $refs) {
            $this->auditSharedPath($refs);
        }

        if ($this->option('orphans')) {
            $this->auditOrphans($companyId, $byPath);
        }
    }

    private function auditReference(array $ref): void
    {
        [$actualSha, $actualSize, $exists] = $this->inspect($ref['disk'], $ref['path']);

        if (! $exists) {
            $this->add(self::MISSING, $ref, 'fichier absent du disque');

            return;
        }
        if ($ref['sha'] !== null && $ref['sha'] !== '' && ! hash_equals((string) $ref['sha'], (string) $actualSha)) {
            $this->add(self::CORRUPT, $ref, "l'empreinte enregistrée ne correspond pas au fichier présent");
        } elseif ($ref['has_sha_column'] && ($ref['sha'] === null || $ref['sha'] === '')) {
            $this->add(self::SUSPECT, $ref, 'empreinte absente : intégrité non vérifiable');
        } elseif ($ref['size'] !== null && (int) $ref['size'] !== (int) $actualSize) {
            $this->add(self::SUSPECT, $ref, 'taille enregistrée différente de la taille réelle');
        }

        // Stored under another company's folder.
        if (preg_match('#^(?:documents|payslips)/(?:generated/|signed/)?(\d+)/#', $ref['path'], $m) && (int) $m[1] !== (int) $ref['company_id']) {
            $this->add(self::TENANT, $ref, "rangé sous le dossier de l'entreprise {$m[1]}");
        }

        // Deterministic names produced by the historical (destructive) code paths.
        if (in_array($ref['table'], [(new MemberDocument)->getTable(), (new DocumentVersion)->getTable()], true)
            && preg_match('#^documents/\d+/(?:subject|members/\d+)/[a-z0-9_\-]+\.pdf$#i', $ref['path'])) {
            $this->add(self::SUSPECT, $ref, 'chemin au format déterministe ancien (remplacement possiblement destructif)');
        }
    }

    /** Several references to one physical file. */
    private function auditSharedPath(array $refs): void
    {
        if (count($refs) < 2) {
            return;
        }
        $generatedTable = (new GeneratedDocument)->getTable();
        $memberTable = (new MemberDocument)->getTable();
        $signatureTable = (new SignatureRequest)->getTable();

        // Expected links, by design : the typeless vault row of a generated document, and several
        // signature requests of one document, point to the SAME file on purpose.
        $meaningful = array_values(array_filter($refs, fn ($r) => ! ($r['table'] === $memberTable && ($r['subject']['document_type_id'] ?? null) === null)));
        if (count($meaningful) < 2) {
            return;
        }
        $tables = array_unique(array_column($meaningful, 'table'));
        if ($tables === [$signatureTable] && count(array_unique(array_map(fn ($r) => $r['subject']['generated_document_id'], $meaningful))) === 1) {
            return;
        }
        if (count($tables) === 1 && $tables[0] === $generatedTable) {
            return;
        }

        $shas = array_values(array_unique(array_filter(array_column($meaningful, 'sha'))));
        foreach ($meaningful as $ref) {
            if (count($shas) > 1) {
                // One file cannot carry two different fingerprints : at least one version was overwritten.
                $this->add(self::CORRUPT, $ref, 'plusieurs versions déclarées différentes pointent sur le même fichier');
            } else {
                $this->add(self::SUSPECT, $ref, 'chemin physique partagé par '.count($meaningful).' références');
            }
        }
    }

    private function auditOrphans(int $companyId, array $byPath): void
    {
        $known = [];
        foreach (array_keys($byPath) as $key) {
            $known[substr($key, strpos($key, '|') + 1)] = true;
        }
        foreach (["documents/{$companyId}", "payslips/{$companyId}"] as $dir) {
            foreach (Storage::disk('local')->allFiles($dir) as $path) {
                if (! isset($known[$path]) && ! $this->referencedAnywhere($path)) {
                    $this->add(self::ORPHAN, ['table' => '-', 'id' => null, 'company_id' => $companyId, 'path' => $path, 'subject' => []], 'fichier sans aucune référence');
                }
            }
        }
    }

    /** A file referenced by ANOTHER company is a tenant incoherence (reported there), not an orphan. */
    private function referencedAnywhere(string $path): bool
    {
        foreach ($this->sources() as [$table, $pathCol]) {
            if (DB::table($table)->where($pathCol, $path)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0:?string,1:?int,2:bool} sha256, size, exists — computed once per file, as a stream. */
    private function inspect(string $disk, string $path): array
    {
        $key = $disk.'|'.$path;
        if (isset($this->files[$key])) {
            return $this->files[$key];
        }
        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            return $this->files[$key] = [null, null, false];
        }
        $stream = $storage->readStream($path);
        $ctx = hash_init('sha256');
        hash_update_stream($ctx, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $this->files[$key] = [hash_final($ctx), (int) $storage->size($path), true];
    }

    private function add(string $class, array $ref, string $reason): void
    {
        // One reference is reported once per class (several reasons may apply to it).
        $key = $class.'|'.$ref['table'].'|'.($ref['id'] ?? $ref['path']);
        if (isset($this->seen[$key])) {
            if (is_int($this->seen[$key]) && ! str_contains($this->findings[$this->seen[$key]]['reason'], $reason)) {
                $this->findings[$this->seen[$key]]['reason'] .= ' ; '.$reason;
            }

            return;
        }
        $this->seen[$key] = true;

        // A proven corruption is never ALSO listed as a mere suspicion.
        if ($class === self::SUSPECT && isset($this->seen[self::CORRUPT.'|'.$ref['table'].'|'.($ref['id'] ?? $ref['path'])])) {
            return;
        }

        $company = (string) $ref['company_id'];
        $this->counts[$company][$class] = ($this->counts[$company][$class] ?? 0) + 1;
        $listed = count(array_filter($this->findings, fn ($f) => $f['class'] === $class));
        if ($listed < $this->limit) {
            // Reference only — never the content of a document.
            $this->findings[] = ['class' => $class, 'reason' => $reason, 'table' => $ref['table'], 'id' => $ref['id'],
                'company_id' => (int) $ref['company_id'], 'subject' => $ref['subject'], 'path' => $ref['path']];
            $this->seen[$key] = count($this->findings) - 1; // index, to merge later reasons
        }
    }

    private function totals(): array
    {
        $totals = [self::CORRUPT => 0, self::SUSPECT => 0, self::MISSING => 0, self::TENANT => 0, self::ORPHAN => 0];
        foreach ($this->counts as $byClass) {
            foreach ($byClass as $class => $n) {
                $totals[$class] += $n;
            }
        }

        return $totals;
    }
}
