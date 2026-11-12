<?php

namespace App\Core\Documents\Payslip;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\Services\DocumentIntegrityService;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;
use App\Core\Workforce\Employee;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * ADR-762 (DMS M5) — import, reconnaissance, rapprochement et publication SÛRE des bulletins externes.
 *
 * Exigence absolue : un bulletin n'est JAMAIS publié dans le dossier d'un mauvais salarié. Garanties :
 *  - rapprochement déterministe d'abord ; nom/IA ⇒ revue, jamais publication directe ;
 *  - publication réservée aux items « matched » (certain OU affecté manuellement) ;
 *  - re-contrôle du sujet au moment de la publication ;
 *  - transactionnelle, idempotente (même sha/période ⇒ pas de doublon), rectificatif = nouvelle
 *    version (ancienne conservée), échec partiel isolé (jamais de publication silencieusement
 *    incomplète) ; opérations journalisées.
 */
class PayslipImportService
{
    public function __construct(
        private readonly PayslipBatchValidator $validator,
        private readonly PayslipMatcher $matcher,
        private readonly DocumentIntegrityService $integrity,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param  list<\Illuminate\Http\UploadedFile>  $files */
    public function createBatch(Company $company, User $actor, array $files): PayslipImportBatch
    {
        $this->validator->validate($files); // SÉCURITÉ avant tout traitement

        $batch = PayslipImportBatch::create([
            'company_id' => $company->id,
            'created_by' => $actor->id,
            'status' => PayslipImportBatch::STATUS_UPLOADED,
            'source_files' => array_map(fn ($f) => ['name' => $f->getClientOriginalName(), 'size' => $f->getSize()], $files),
        ]);

        foreach ($files as $i => $file) {
            $file->storeAs("payslip-imports/{$company->id}/{$batch->id}", 'source-'.$i.'.bin', 'local');
        }

        return $batch;
    }

    /**
     * Crée un segment (bulletin) + rapprochement. Utilisé par l'ingestion réelle (texte OCR) ET les
     * tests (texte synthétique). Le rapprochement n'est jamais fondé sur un nom seul pour publier.
     */
    public function addSegment(PayslipImportBatch $batch, string $filePath, ?string $pageRange, string $text): PayslipImportItem
    {
        $ids = $this->extractIdentifiers($text);
        $match = $this->matcher->match($batch->company_id, $ids['matricule'], $ids['name']);

        $state = $match['confidence'] === PayslipImportItem::C_CERTAIN
            ? PayslipImportItem::S_MATCHED        // certain → prêt (publication après validation du lot)
            : PayslipImportItem::S_REVIEW;        // probable/ambiguous/none → revue humaine

        return PayslipImportItem::create([
            'company_id' => $batch->company_id,
            'payslip_import_batch_id' => $batch->id,
            'file_path' => $filePath,
            'file_sha256' => $this->integrity->hashOf($filePath),
            'page_range' => $pageRange,
            'extracted_matricule' => $ids['matricule'],
            'extracted_period' => $ids['period'],
            'extracted_name' => $ids['name'],
            'matched_employee_id' => $match['employee_id'],
            'match_confidence' => $match['confidence'],
            'match_candidates' => $match['candidates'],
            'state' => $state,
        ]);
    }

    /** Extraction minimale des identifiants (matricule/période/nom) depuis le texte OCR/texte. */
    public function extractIdentifiers(string $text): array
    {
        $matricule = null;
        if (preg_match('/matricule\s*[:#]?\s*([A-Za-z0-9\-]+)/iu', $text, $m)) {
            $matricule = $m[1];
        }
        $period = null;
        if (preg_match('/p[ée]riode\s*[:#]?\s*(\d{4}-\d{2})/iu', $text, $m)) {
            $period = $m[1];
        }
        $name = null;
        if (preg_match('/(?:salari[ée]|nom)\s*[:#]?\s*([A-Za-zÀ-ÿ\'\- ]{3,60})/iu', $text, $m)) {
            $name = trim($m[1]);
        }

        return ['matricule' => $matricule, 'period' => $period, 'name' => $name];
    }

    /** Affectation manuelle (revue) : re-valide l'appartenance tenant → item publiable. */
    public function assign(PayslipImportItem $item, int $employeeId, User $actor): PayslipImportItem
    {
        $employee = Employee::withoutGlobalScopes()->where('company_id', $item->company_id)->find($employeeId);
        if (! $employee) {
            throw ValidationException::withMessages(['employee' => ['Employee not in this company.']]);
        }

        $item->update([
            'matched_employee_id' => $employee->id,
            'match_confidence' => PayslipImportItem::C_PROBABLE,
            'state' => PayslipImportItem::S_MATCHED, // décision humaine → publiable
        ]);

        return $item;
    }

    public function exclude(PayslipImportItem $item): void
    {
        $item->update(['state' => PayslipImportItem::S_EXCLUDED, 'matched_employee_id' => null]);
    }

    /**
     * Publie les items publiables d'un lot. VERROUILLÉ, transactionnel par item, idempotent, à échec
     * partiel isolé. Le lot n'est « published » que si TOUS les items publiables sont publiés.
     */
    public function publishBatch(PayslipImportBatch $batch, User $actor): array
    {
        $lock = Cache::lock('payslip:publish:'.$batch->id, 120);
        if (! $lock->get()) {
            return ['published' => 0, 'failed' => 0, 'locked_out' => true];
        }

        try {
            $batch->update(['status' => PayslipImportBatch::STATUS_PUBLISHING]);
            $published = 0;
            $failed = 0;

            PayslipImportItem::where('payslip_import_batch_id', $batch->id)
                ->where('state', PayslipImportItem::S_MATCHED)
                ->chunkById(100, function ($items) use (&$published, &$failed, $actor) {
                    foreach ($items as $item) {
                        try {
                            $this->publishItem($item, $actor);
                            $published++;
                        } catch (\Throwable $e) {
                            $item->update(['state' => PayslipImportItem::S_FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)]);
                            $failed++;
                        }
                    }
                });

            // « Published » seulement si TOUS les items sont résolus (publiés ou exclus) — un item
            // en revue/attente/échec empêche de déclarer le lot intégralement publié.
            $unresolved = PayslipImportItem::where('payslip_import_batch_id', $batch->id)
                ->whereIn('state', [PayslipImportItem::S_PENDING, PayslipImportItem::S_MATCHED, PayslipImportItem::S_REVIEW, PayslipImportItem::S_FAILED])
                ->count();
            $batch->update([
                'items_published' => PayslipImportItem::where('payslip_import_batch_id', $batch->id)->where('state', PayslipImportItem::S_PUBLISHED)->count(),
                'status' => $unresolved === 0 ? PayslipImportBatch::STATUS_PUBLISHED : PayslipImportBatch::STATUS_REVIEW,
                'processed_at' => now(),
            ]);

            return ['published' => $published, 'failed' => $failed];
        } finally {
            optional($lock)->release();
        }
    }

    /** Publie UN bulletin vers le bon salarié (re-contrôle du sujet, versioning, idempotence). */
    public function publishItem(PayslipImportItem $item, User $actor): PublishedPayslip
    {
        if (! $item->isPublishable()) {
            throw ValidationException::withMessages(['item' => ['Item is not publishable (requires a confirmed match).']]);
        }

        // Re-contrôle du sujet AU MOMENT de la publication (sécurité).
        $employee = Employee::withoutGlobalScopes()->where('company_id', $item->company_id)->find($item->matched_employee_id);
        if (! $employee) {
            throw ValidationException::withMessages(['employee' => ['Matched employee no longer exists in this company.']]);
        }
        $subject = DocumentSubject::employee((int) $employee->id);
        $period = $item->extracted_period ?: 'unknown';

        return DB::transaction(function () use ($item, $subject, $period, $actor, $employee) {
            // Idempotence : même contenu déjà publié pour (sujet, période) → ne pas dupliquer.
            $same = PublishedPayslip::where('company_id', $item->company_id)
                ->where('document_subject_type', $subject->type)->where('document_subject_id', $subject->id)
                ->where('period', $period)->where('file_sha256', $item->file_sha256)->current()->first();
            if ($same) {
                $item->update(['state' => PayslipImportItem::S_PUBLISHED, 'published_member_document_id' => $same->id]);

                return $same;
            }

            // Rectificatif : un bulletin courant existe pour (sujet, période) avec un contenu différent
            // → il est CONSERVÉ (superseded), une nouvelle version est publiée.
            $version = 1;
            $current = PublishedPayslip::where('company_id', $item->company_id)
                ->where('document_subject_type', $subject->type)->where('document_subject_id', $subject->id)
                ->where('period', $period)->current()->orderByDesc('version_no')->first();
            if ($current) {
                $current->update(['superseded_at' => now()]);
                $version = $current->version_no + 1;
            }

            $pub = PublishedPayslip::create([
                'company_id' => $item->company_id,
                'document_subject_type' => $subject->type,
                'document_subject_id' => $subject->id,
                'period' => $period,
                'file_path' => $item->file_path,
                'file_name' => 'bulletin-'.$period.'.pdf',
                'file_sha256' => $item->file_sha256,
                'source' => PublishedPayslip::SOURCE_EXTERNAL,
                'source_item_id' => $item->id,
                'version_no' => $version,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);

            $item->update(['state' => PayslipImportItem::S_PUBLISHED, 'published_member_document_id' => $pub->id]);

            // Journalisation sensible + notification autorisée (si compte lié).
            $this->audit->logCompany($item->company_id, 'payslip.published', 'published_payslip', (string) $pub->id, [
                'severity' => 'notice',
                'metadata' => ['employee_id' => $employee->id, 'period' => $period, 'batch_item' => $item->id],
            ]);
            if ($employee->user_id) {
                $recipient = User::find($employee->user_id);
                if ($recipient) {
                    NotificationDispatcher::send(
                        topicKey: 'documents.request_new',
                        recipients: [$recipient],
                        payload: ['document_type' => 'Bulletin de paie', 'period' => $period, 'link' => '/account-settings/documents'],
                        company: Company::find($item->company_id),
                        entityKey: "payslip:{$subject}:{$period}",
                    );
                }
            }

            return $pub;
        });
    }
}
