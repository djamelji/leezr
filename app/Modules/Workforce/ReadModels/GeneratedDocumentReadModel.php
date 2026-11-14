<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Documents\GeneratedDocument;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\PayrollLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class GeneratedDocumentReadModel
{
    /**
     * Paginated list of generated documents for a company.
     *
     * PRODUCT-UX-2 (LOT A2) : filtre collaborateur TRANSVERSAL (résout document→employé quel que soit
     * le type de sujet : employee / contract / leave_request / payroll_line), recherche texte, période.
     * Chaque ligne est enrichie du collaborateur résolu (lecture seule, additive — aucun changement
     * du modèle documentaire). Le filtrage reste borné à la company (tenant) ; l'accès fin par
     * document reste contrôlé au téléchargement (WorkforceAccess).
     */
    public static function forCompany(int $companyId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['template:id,code,name,subject_type', 'generatedByUser:id,first_name,last_name']);

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', $filters['subject_type']);
        }

        if (! empty($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }

        if (! empty($filters['template_code'])) {
            $query->whereHas('template', fn ($q) => $q->where('code', $filters['template_code']));
        }

        if (! empty($filters['signature_status'])) {
            $query->where('signature_status', $filters['signature_status']);
        }

        // Filtre COLLABORATEUR transversal (tous les types de sujet rattachés à l'employé).
        if (! empty($filters['employee_id'])) {
            $empId = (int) $filters['employee_id'];
            $contractIds = EmploymentContract::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_id', $empId)->pluck('id')->all();
            $leaveIds = LeaveRequest::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_id', $empId)->pluck('id')->all();
            $lineIds = PayrollLine::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_id', $empId)->pluck('id')->all();
            $query->where(function ($q) use ($empId, $contractIds, $leaveIds, $lineIds) {
                $q->where(fn ($w) => $w->where('subject_type', 'employee')->where('subject_id', $empId))
                    ->orWhere(fn ($w) => $w->where('subject_type', 'contract')->whereIn('subject_id', $contractIds ?: [0]))
                    ->orWhere(fn ($w) => $w->where('subject_type', 'leave_request')->whereIn('subject_id', $leaveIds ?: [0]))
                    ->orWhere(fn ($w) => $w->where('subject_type', 'payroll_line')->whereIn('subject_id', $lineIds ?: [0]));
            });
        }

        // Recherche texte : nom/code du modèle ou chemin de fichier (colonnes réelles uniquement).
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('file_path', 'like', $term)
                    ->orWhereHas('template', fn ($t) => $t->where('name', 'like', $term)->orWhere('code', 'like', $term));
            });
        }

        // Période (sur la date de génération).
        if (! empty($filters['date_from'])) {
            $query->whereDate('generated_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('generated_at', '<=', $filters['date_to']);
        }

        $paginator = $query->orderByDesc('generated_at')->paginate($perPage);

        // Enrichissement collaborateur par PAGE (batch, pas de N+1).
        self::attachEmployees($paginator->getCollection(), $companyId);
        self::applyRealSignatureState($paginator->getCollection(), $companyId);

        return $paginator;
    }

    /**
     * Résout et attache le collaborateur (id + nom) à chaque document de la page, tous types de
     * sujet confondus. Lecture seule, en lot.
     */
    private static function attachEmployees(Collection $docs, int $companyId): void
    {
        if ($docs->isEmpty()) {
            return;
        }
        $byType = $docs->groupBy('subject_type');
        $docToEmp = []; // document_id => employee_id

        // employee : subject_id EST l'employee_id
        foreach ($byType->get('employee', collect()) as $d) {
            $docToEmp[$d->id] = (int) $d->subject_id;
        }
        // contract / leave_request / payroll_line : résolution subject_id -> employee_id
        $resolvers = [
            'contract' => EmploymentContract::class,
            'leave_request' => LeaveRequest::class,
            'payroll_line' => PayrollLine::class,
        ];
        foreach ($resolvers as $type => $model) {
            $ids = $byType->get($type, collect())->pluck('subject_id')->unique()->filter()->all();
            if (! $ids) {
                continue;
            }
            $map = $model::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $ids)->pluck('employee_id', 'id');
            foreach ($byType->get($type, collect()) as $d) {
                if (isset($map[$d->subject_id])) {
                    $docToEmp[$d->id] = (int) $map[$d->subject_id];
                }
            }
        }

        $empIds = array_values(array_unique(array_filter($docToEmp)));
        $names = $empIds
            ? Employee::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $empIds)
                ->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn ($e) => [$e->id => trim($e->first_name.' '.$e->last_name)])
            : collect();

        foreach ($docs as $d) {
            $empId = $docToEmp[$d->id] ?? null;
            $d->setAttribute('employee_id', $empId);
            $d->setAttribute('employee_name', $empId ? ($names[$empId] ?? null) : null);
        }
    }

    /**
     * Get all generated documents for a specific subject.
     */
    public static function forSubject(string $subjectType, int $subjectId, int $companyId): Collection
    {
        return GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with(['template:id,code,name', 'generatedByUser:id,first_name,last_name'])
            ->orderByDesc('generated_at')
            ->get();
    }

    /**
     * Get all generated documents for an employee (across all subject types).
     */
    public static function forEmployee(int $employeeId, int $companyId): Collection
    {
        // Direct employee docs
        $employeeDocs = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subject_type', 'employee')
            ->where('subject_id', $employeeId)
            ->with(['template:id,code,name'])
            ->get();

        // Contract docs
        $contractIds = \App\Core\Workforce\EmploymentContract::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('company_id', $companyId)
            ->pluck('id');

        $contractDocs = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subject_type', 'contract')
            ->whereIn('subject_id', $contractIds)
            ->with(['template:id,code,name'])
            ->get();

        // Leave request docs
        $leaveIds = \App\Core\Workforce\LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('company_id', $companyId)
            ->pluck('id');

        $leaveDocs = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subject_type', 'leave_request')
            ->whereIn('subject_id', $leaveIds)
            ->with(['template:id,code,name'])
            ->get();

        return self::applyRealSignatureState($employeeDocs->merge($contractDocs)->merge($leaveDocs), $companyId)
            ->sortByDesc('generated_at')
            ->values();
    }

    /**
     * ADR-772 — the signature state SHOWN for a document is the real state of the signature engine,
     * not the `signature_status` column (a projection that is only written on send and on completion,
     * so a declined, expired or cancelled request stayed « pending » in the registry forever).
     *
     *   signed    : a request is COMPLETED and sealed ;
     *   pending   : the latest request is in progress ;
     *   declined / expired / cancelled / failed : state of the latest request ;
     *   otherwise : the stored value (documents signed outside the engine keep their manual status).
     *
     * Read-side only : nothing is saved, and the HR contract is never touched.
     */
    public static function realSignatureState(GeneratedDocument $doc, ?\App\Modules\Workforce\Signature\Models\SignatureRequest $latest, bool $hasSealedSignature): string
    {
        if ($hasSealedSignature) {
            return GeneratedDocument::SIGNATURE_SIGNED;
        }

        return match ($latest?->status) {
            'pending', 'partially_signed' => GeneratedDocument::SIGNATURE_PENDING,
            'declined', 'expired', 'cancelled', 'failed' => $latest->status,
            default => (string) $doc->signature_status,
        };
    }

    /** Sets the real signature state on a page of documents (one query, in memory, never saved). */
    public static function applyRealSignatureState(Collection $docs, int $companyId): Collection
    {
        if ($docs->isEmpty()) {
            return $docs;
        }
        $requests = \App\Modules\Workforce\Signature\Models\SignatureRequest::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereIn('generated_document_id', $docs->pluck('id'))->where('status', '!=', 'draft')->orderBy('id')
            ->get(['id', 'generated_document_id', 'status', 'signed_document_path'])->groupBy('generated_document_id');

        foreach ($docs as $doc) {
            $ofDoc = $requests->get($doc->id, collect());
            $sealed = $ofDoc->contains(fn ($r) => $r->status === 'completed' && $r->signed_document_path !== null);
            $doc->setAttribute('signature_status', self::realSignatureState($doc, $ofDoc->last(), $sealed));
        }

        return $docs;
    }

    /**
     * ADR-771 — the generated documents of a person as shown in her FILE : one row per document
     * (most recent first), its signature state and, once a signature is really finalised, the
     * signed version at the same place. Metadata only — never the generation snapshot.
     *
     * @param  callable(GeneratedDocument): bool  $canOpen
     */
    public static function fileOf(int $companyId, int $employeeId, callable $canOpen): array
    {
        $docs = self::forEmployee($employeeId, $companyId);
        $requests = \App\Modules\Workforce\Signature\Models\SignatureRequest::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereIn('generated_document_id', $docs->pluck('id'))->orderBy('id')->get()->groupBy('generated_document_id');

        return $docs->sortByDesc(fn ($d) => [$d->generated_at?->getTimestamp(), $d->id])->values()->map(function (GeneratedDocument $d) use ($requests, $canOpen) {
            $last = $requests->get($d->id)?->last();
            $signed = \App\Modules\Workforce\Documents\Security\GeneratedDocumentAccess::signedRequest($d);

            return [
                'id' => $d->id,
                'label' => $d->template?->name ?? 'Document',
                'template_code' => $d->template?->code,
                'subject_type' => $d->subject_type,
                'contract_id' => $d->subject_type === 'contract' ? (int) $d->subject_id : null,
                'generated_at' => $d->generated_at?->toIso8601String(),
                // État de signature VRAI, lu sur les demandes : « signé » seulement si une demande est
                // complétée et scellée ; un refus, une expiration ou une annulation ne restent pas « en
                // attente » (la colonne du document n'est qu'une projection, non mise à jour dans ces cas).
                'signature_status' => self::realSignatureState($d, $last, $signed !== null),
                'signature_request' => $last ? ['id' => $last->id, 'status' => $last->status] : null,
                'signed' => ['available' => $signed !== null, 'signature_request_id' => $signed?->id, 'completed_at' => $signed?->completed_at?->toIso8601String()],
                'can_download' => (bool) $canOpen($d),
            ];
        })->all();
    }

    /**
     * Statistics for a company's generated documents.
     */
    public static function statistics(int $companyId): array
    {
        $docs = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->selectRaw('subject_type, signature_status, COUNT(*) as count')
            ->groupBy('subject_type', 'signature_status')
            ->get();

        $bySubject = [];
        $bySignature = [];
        $total = 0;

        foreach ($docs as $row) {
            $bySubject[$row->subject_type] = ($bySubject[$row->subject_type] ?? 0) + $row->count;
            $bySignature[$row->signature_status] = ($bySignature[$row->signature_status] ?? 0) + $row->count;
            $total += $row->count;
        }

        return [
            'total' => $total,
            'by_subject_type' => $bySubject,
            'by_signature_status' => $bySignature,
        ];
    }

    /**
     * Get all payslip drafts for a PayrollRun (via its lines).
     */
    public static function forPayrollRun(int $payrollRunId, int $companyId): Collection
    {
        $lineIds = \App\Core\Workforce\PayrollLine::withoutGlobalScopes()
            ->where('payroll_run_id', $payrollRunId)
            ->where('company_id', $companyId)
            ->pluck('id');

        return GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subject_type', 'payroll_line')
            ->whereIn('subject_id', $lineIds)
            ->with(['template:id,code,name', 'generatedByUser:id,first_name,last_name'])
            ->orderByDesc('generated_at')
            ->get();
    }
}
