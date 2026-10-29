<?php

namespace App\Modules\Workforce\Contract\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentTemplate;
use App\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * W-CONTRACT-1 (ADR-647) — gestion des MODÈLES de contrat (réutilise DocumentTemplate subject_type
 * 'contract'). Les attributs contractuels (type, market, jobdomain, statut de validation juridique,
 * état, défaut) vivent dans `metadata` — AUCUNE modification du schéma Core partagé.
 *
 * Invariants : les modèles Platform restent inchangés quand une Company personnalise (travail sur
 * COPIE) ; un modèle PUBLIÉ est IMMUABLE (toute modification = nouvelle version) ; les documents déjà
 * générés ne sont jamais altérés. Les contraintes juridiques impératives sont portées par le Market
 * (champ `legal_status`), non effacées par une personnalisation Company. Jamais de `FR`/`CDI` en dur.
 */
class ContractTemplateService
{
    public const SUBJECT = 'contract';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    /** État contractuel stocké dans metadata, avec valeurs par défaut sûres. */
    public static function attrs(DocumentTemplate $t): array
    {
        $m = $t->metadata ?? [];

        return [
            'contract_type' => $m['contract_type'] ?? null,
            'market_key' => $m['market_key'] ?? null,
            'jobdomain_key' => $m['jobdomain_key'] ?? null,
            'legal_status' => $m['legal_status'] ?? 'example_unvalidated',
            'status' => $m['status'] ?? ($t->is_active ? self::STATUS_PUBLISHED : self::STATUS_DRAFT),
            'is_default' => (bool) ($m['is_default'] ?? false),
        ];
    }

    /**
     * Modèles de contrat DISPONIBLES pour la Company : catalogue Platform applicable (market/jobdomain
     * compatibles) + modèles propres de la Company. Les incompatibles ne sont jamais proposés.
     *
     * @param array<string,mixed> $filters contract_type
     */
    public static function available(Company $company, array $filters = []): array
    {
        $templates = DocumentTemplate::query()
            ->where('subject_type', self::SUBJECT)
            ->where(function ($q) use ($company) {
                $q->whereNull('company_id')->orWhere('company_id', $company->id);
            })
            ->orderBy('name')->get();

        $market = $company->market_key;
        $jobdomain = $company->jobdomain_key;

        return $templates->filter(function (DocumentTemplate $t) use ($company, $market, $jobdomain, $filters) {
            $a = self::attrs($t);
            // Company : tous ses modèles (y compris brouillons, pour l'édition).
            if ($t->company_id === $company->id) {
                return empty($filters['contract_type']) || $a['contract_type'] === $filters['contract_type'];
            }
            // Platform : uniquement applicables au Market (ou générique) + jobdomain (ou générique) + publiés.
            if ($a['market_key'] !== null && $a['market_key'] !== $market) {
                return false;
            }
            if ($a['jobdomain_key'] !== null && $a['jobdomain_key'] !== $jobdomain) {
                return false;
            }
            if (! $t->is_active) {
                return false;
            }

            return empty($filters['contract_type']) || $a['contract_type'] === $filters['contract_type'];
        })->map(fn (DocumentTemplate $t) => self::present($t))->values()->all();
    }

    public static function present(DocumentTemplate $t): array
    {
        $a = self::attrs($t);

        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'version' => $t->version,
            'source' => $t->company_id ? 'company' : 'platform',
            'subject_type' => $t->subject_type,
            'contract_type' => $a['contract_type'],
            'market_key' => $a['market_key'],
            'jobdomain_key' => $a['jobdomain_key'],
            'legal_status' => $a['legal_status'],
            'status' => $a['status'],
            'is_default' => $a['is_default'],
            'is_active' => (bool) $t->is_active,
            'platform_template_id' => $t->platform_template_id,
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    /** Copie un modèle Platform vers la Company (brouillon, version 1). Le Platform reste intact. */
    public static function duplicateFromPlatform(Company $company, int $platformTemplateId, int $actorId): DocumentTemplate
    {
        $platform = DocumentTemplate::whereNull('company_id')->where('subject_type', self::SUBJECT)->findOrFail($platformTemplateId);
        $attrs = self::attrs($platform);

        $copy = DocumentTemplate::create([
            'company_id' => $company->id,
            'platform_template_id' => $platform->id,
            'code' => $platform->code,
            'name' => $platform->name.' (copie)',
            'description' => $platform->description,
            'subject_type' => self::SUBJECT,
            'body_html' => $platform->body_html,
            'header_html' => $platform->header_html,
            'footer_html' => $platform->footer_html,
            'variables_schema' => $platform->variables_schema ?? [],
            'version' => self::nextVersion($company->id, $platform->code),
            'is_system' => false,
            'is_active' => false, // brouillon → non résolvable tant que non publié
            'metadata' => array_merge($attrs, ['status' => self::STATUS_DRAFT, 'is_default' => false, 'origin_platform_id' => $platform->id]),
        ]);

        self::audit($company->id, $actorId, 'contract_template.duplicated', $copy->id, "Modèle « {$platform->name} » dupliqué");

        return $copy;
    }

    /** Nouvelle version éditable (brouillon) d'un modèle Company publié — l'original reste immuable. */
    public static function createVersion(Company $company, int $templateId, int $actorId): DocumentTemplate
    {
        $src = self::ownTemplate($company, $templateId);
        $attrs = self::attrs($src);

        $copy = DocumentTemplate::create([
            'company_id' => $company->id,
            'platform_template_id' => $src->platform_template_id,
            'code' => $src->code,
            'name' => $src->name,
            'description' => $src->description,
            'subject_type' => self::SUBJECT,
            'body_html' => $src->body_html,
            'header_html' => $src->header_html,
            'footer_html' => $src->footer_html,
            'variables_schema' => $src->variables_schema ?? [],
            'version' => self::nextVersion($company->id, $src->code),
            'is_system' => false,
            'is_active' => false,
            'metadata' => array_merge($attrs, ['status' => self::STATUS_DRAFT, 'is_default' => false]),
        ]);

        self::audit($company->id, $actorId, 'contract_template.versioned', $copy->id, "Nouvelle version du modèle « {$src->name} »");

        return $copy;
    }

    /** Édite un BROUILLON Company (nom, contenu, type). Un modèle publié est immuable → 422. */
    public static function update(Company $company, int $templateId, array $data, int $actorId): DocumentTemplate
    {
        $t = self::ownTemplate($company, $templateId);
        if (self::attrs($t)['status'] === self::STATUS_PUBLISHED) {
            throw new \DomainException('Un modèle publié est immuable. Créez une nouvelle version pour le modifier.');
        }

        $clean = collect($data)->only(['name', 'description', 'body_html', 'header_html', 'footer_html'])->toArray();
        // W-CONTRACT-2 : sanitation SERVEUR du contenu WYSIWYG (défense en profondeur).
        foreach (['body_html', 'header_html', 'footer_html'] as $f) {
            if (isset($clean[$f])) {
                $clean[$f] = \App\Core\Documents\Services\HtmlSanitizer::sanitize($clean[$f]);
            }
        }
        $t->fill($clean);
        if (isset($data['contract_type']) || isset($data['legal_status'])) {
            $t->metadata = array_merge($t->metadata ?? [], array_filter([
                'contract_type' => $data['contract_type'] ?? null,
                'legal_status' => $data['legal_status'] ?? null,
            ], fn ($v) => $v !== null));
        }
        $t->save();

        self::audit($company->id, $actorId, 'contract_template.updated', $t->id, "Modèle « {$t->name} » modifié (brouillon)");

        return $t;
    }

    /** Publie un brouillon (immuable ensuite) + archive les versions publiées antérieures du même code. */
    public static function publish(Company $company, int $templateId, int $actorId): DocumentTemplate
    {
        $t = self::ownTemplate($company, $templateId);

        return DB::transaction(function () use ($company, $t, $actorId) {
            DocumentTemplate::where('company_id', $company->id)->where('subject_type', self::SUBJECT)
                ->where('code', $t->code)->where('id', '!=', $t->id)->where('is_active', true)
                ->get()->each(function ($old) {
                    $old->metadata = array_merge($old->metadata ?? [], ['status' => self::STATUS_ARCHIVED]);
                    $old->is_active = false;
                    $old->save();
                });

            $t->is_active = true;
            $t->metadata = array_merge($t->metadata ?? [], ['status' => self::STATUS_PUBLISHED]);
            $t->save();

            self::audit($company->id, $actorId, 'contract_template.published', $t->id, "Modèle « {$t->name} » v{$t->version} publié");

            return $t;
        });
    }

    public static function archive(Company $company, int $templateId, int $actorId): DocumentTemplate
    {
        $t = self::ownTemplate($company, $templateId);
        $t->is_active = false;
        $t->metadata = array_merge($t->metadata ?? [], ['status' => self::STATUS_ARCHIVED]);
        $t->save();
        self::audit($company->id, $actorId, 'contract_template.archived', $t->id, "Modèle « {$t->name} » archivé");

        return $t;
    }

    private static function ownTemplate(Company $company, int $templateId): DocumentTemplate
    {
        return DocumentTemplate::where('company_id', $company->id)->where('subject_type', self::SUBJECT)->findOrFail($templateId);
    }

    private static function nextVersion(int $companyId, string $code): int
    {
        return (int) DocumentTemplate::where('company_id', $companyId)->where('code', $code)->max('version') + 1;
    }

    private static function audit(int $companyId, int $actorId, string $action, int $id, string $desc): void
    {
        app(AuditLogger::class)->logCompany(
            companyId: $companyId, action: $action, targetType: 'contract_template', targetId: (string) $id,
            options: ['metadata' => ['category' => 'workforce.contract', 'actor_id' => $actorId, 'description' => $desc]],
        );
    }
}
