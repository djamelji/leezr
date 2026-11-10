<?php

namespace App\Console\Commands;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentComplianceStateService;
use App\Core\Documents\Services\DocumentIntegrityService;
use Illuminate\Console\Command;

/**
 * ADR-756 (DMS M0) — backfill NON destructif et idempotent des documents existants :
 *  - calcule le SHA-256 des fichiers accessibles (intégrité) ;
 *  - renseigne le sujet documentaire (member_documents → user:{id}) sur la colonne polymorphe
 *    morte, SANS rapprochement par nom/email et SANS modifier un rattachement existant ;
 *  - renseigne published_state par défaut.
 *
 * Ne crée AUCUNE version pour les fichiers historiques (les versions déjà écrasées sont perdues —
 * on ne les invente pas). Les fichiers inaccessibles sont signalés, jamais supprimés.
 */
class DocumentsM0BackfillCommand extends Command
{
    protected $signature = 'documents:m0-backfill {--chunk=200} {--dry-run}';

    protected $description = 'DMS M0: backfill hashes/subject/published_state for existing documents (non-destructive, idempotent)';

    public function handle(DocumentIntegrityService $integrity): int
    {
        $dry = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');

        $stats = ['member' => $this->seed(), 'company' => $this->seed()];

        // member_documents : hash + sujet user + publication
        MemberDocument::withoutGlobalScopes()
            ->where(function ($q) {
                $q->whereNull('file_sha256')
                    ->orWhereNull('document_subject_type')
                    ->orWhereNull('published_state');
            })
            ->chunkById($chunk, function ($docs) use ($integrity, $dry, &$stats) {
                foreach ($docs as $doc) {
                    $this->apply($doc, $integrity, $dry, $stats['member'], DocumentSubject::TYPE_USER, $doc->user_id);
                }
            });

        // company_documents : hash + publication (sujet = company)
        CompanyDocument::withoutGlobalScopes()
            ->where(function ($q) {
                $q->whereNull('file_sha256')->orWhereNull('published_state');
            })
            ->chunkById($chunk, function ($docs) use ($integrity, $dry, &$stats) {
                foreach ($docs as $doc) {
                    $this->apply($doc, $integrity, $dry, $stats['company'], null, null);
                }
            });

        $this->table(
            ['Scope', 'Scanned', 'Hashed', 'Subject set', 'Published set', 'Inaccessible'],
            [
                ['member_documents', $stats['member']['scanned'], $stats['member']['hashed'], $stats['member']['subject'], $stats['member']['published'], $stats['member']['inaccessible']],
                ['company_documents', $stats['company']['scanned'], $stats['company']['hashed'], '-', $stats['company']['published'], $stats['company']['inaccessible']],
            ],
        );

        if ($dry) {
            $this->warn('DRY-RUN : aucune écriture effectuée.');
        }

        return self::SUCCESS;
    }

    private function seed(): array
    {
        return ['scanned' => 0, 'hashed' => 0, 'subject' => 0, 'published' => 0, 'inaccessible' => 0];
    }

    private function apply($doc, DocumentIntegrityService $integrity, bool $dry, array &$stat, ?string $subjectType, ?int $subjectId): void
    {
        $stat['scanned']++;
        $dirty = false;

        if (empty($doc->file_sha256) && ! empty($doc->file_path)) {
            if ($integrity->accessible($doc->file_path)) {
                $doc->file_sha256 = $integrity->hashOf($doc->file_path);
                $stat['hashed']++;
                $dirty = true;
            } else {
                $stat['inaccessible']++;
                $this->warn("Fichier inaccessible (non modifié) : #{$doc->id} {$doc->file_path}");
            }
        }

        if ($subjectType !== null && empty($doc->document_subject_type)) {
            $doc->document_subject_type = $subjectType;
            $doc->document_subject_id = $subjectId;
            $stat['subject']++;
            $dirty = true;
        }

        if (empty($doc->published_state)) {
            $doc->published_state = DocumentComplianceStateService::P_PUBLISHED;
            $stat['published']++;
            $dirty = true;
        }

        if ($dirty && ! $dry) {
            $doc->saveQuietly();
        }
    }
}
