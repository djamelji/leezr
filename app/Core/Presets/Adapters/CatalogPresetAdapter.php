<?php

namespace App\Core\Presets\Adapters;

use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * ADR-820 — « Bibliothèque de départ » : the fields and document types a JobDomain makes AVAILABLE to a new
 * company. A library item is never a requirement : apply() only creates activations with
 * `required_override = false` ; an obligation only comes from an explicitly configured process (company or
 * position requirements). `included` = active in the company catalogue from day one ; otherwise the item is
 * only SUGGESTED (shown as « proposé » in the company catalogue, activated by the company when it wants).
 *
 * Content : { fields: [{code, subject: company|person, included, order?}], documents: [same] }.
 */
final class CatalogPresetAdapter implements PresetFamilyAdapter
{
    public const SUBJECTS = ['company', 'person'];

    public function kind(): string
    {
        return 'catalog';
    }

    public function validate(array $content, ?string $marketKey = null): void
    {
        $errors = [];
        $seen = [];
        foreach (['fields' => 'field', 'documents' => 'document'] as $list => $kind) {
            foreach ($content[$list] ?? [] as $i => $item) {
                $subject = $item['subject'] ?? 'person';
                if (! in_array($subject, self::SUBJECTS, true)) {
                    $errors["{$list}.{$i}.subject"] = 'Invalid subject.';

                    continue;
                }
                if (! self::targetId($kind, (string) ($item['code'] ?? ''), $subject)) {
                    $errors["{$list}.{$i}.code"] = 'Unknown '.$kind.' for this subject.';
                }
                if (isset($item['order']) && (! is_int($item['order']) || $item['order'] < 0)) {
                    $errors["{$list}.{$i}.order"] = 'Invalid order.';
                }
                foreach (['required', 'level', 'required_override'] as $forbidden) {
                    if (array_key_exists($forbidden, $item)) {
                        $errors["{$list}.{$i}.{$forbidden}"] = 'A library item never carries an obligation.';
                    }
                }
                $key = self::key($kind, $item);
                if (isset($seen[$key])) {
                    $errors["{$list}.{$i}.code"] = 'Duplicate item.';
                }
                $seen[$key] = true;
            }
        }
        if ($seen === []) {
            $errors['fields'] = 'A library needs at least one field or document.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function items(array $content): array
    {
        $out = [];
        foreach (['fields' => 'field', 'documents' => 'document'] as $list => $kind) {
            foreach ($content[$list] ?? [] as $item) {
                $out[self::key($kind, $item)] = $item + ['kind' => $kind, 'subject' => $item['subject'] ?? 'person', 'included' => (bool) ($item['included'] ?? true)];
            }
        }

        return $out;
    }

    public static function key(string $kind, array $item): string
    {
        return ($item['subject'] ?? 'person').'>'.$kind.':'.($item['code'] ?? '?');
    }

    public function onPublish(object $preset): void {}

    public function preview(Company $company, object $preset): array
    {
        $out = [];
        foreach ($this->items((array) $preset->content) as $key => $item) {
            $activation = $this->activation($company, $item);
            $status = match (true) {
                ! $item['included'] => 'not_applicable', // suggestion : jamais activée par le preset
                $activation === null => 'new',
                (bool) $activation->enabled => 'identical',
                default => 'customized', // désactivé par la société : on ne le réactive pas
            };
            $out[] = ['key' => $key, 'label' => $item['kind'].':'.$item['code'], 'status' => $status, 'detail' => $item['included'] ? null : 'suggested'];
        }

        return $out;
    }

    public function apply(Company $company, object $preset, ?array $selected, ?int $actorId): array
    {
        $done = ['created' => [], 'updated' => [], 'skipped' => []];
        $items = $this->items((array) $preset->content);
        foreach ($this->preview($company, $preset) as $p) {
            $wanted = $p['status'] === 'new' && ($selected === null || in_array($p['key'], $selected, true));
            if (! $wanted) {
                $done['skipped'][] = $p['key'];

                continue;
            }
            $item = $items[$p['key']];
            $id = self::targetId($item['kind'], $item['code'], $item['subject']);
            if ($item['kind'] === 'field') {
                $order = $item['order'] ?? (int) FieldDefinition::whereKey($id)->value('default_order');
                FieldActivation::create(['company_id' => $company->id, 'field_definition_id' => $id, 'enabled' => true, 'required_override' => false, 'order' => $order]);
            } else {
                $order = $item['order'] ?? (int) DocumentType::whereKey($id)->value('default_order');
                DocumentTypeActivation::create(['company_id' => $company->id, 'document_type_id' => $id, 'enabled' => true, 'required_override' => false, 'order' => $order]);
            }
            $done['created'][] = $p['key'];
        }

        return $done;
    }

    /** Codes SUGGESTED (not included) by a library content, per kind — read by the company catalogue. */
    public static function suggestedCodes(array $content): array
    {
        $out = ['field' => [], 'document' => []];
        foreach ((new self())->items($content) as $item) {
            if (! $item['included']) {
                $out[$item['kind']][] = $item['code'];
            }
        }

        return $out;
    }

    /** The published library effective for a domain / market (null = none : historical defaults apply). */
    public static function effectiveContent(string $jobdomainKey, ?string $marketKey): ?array
    {
        $rec = collect(\App\Core\Presets\PresetService::recommendationsFor($jobdomainKey, $marketKey))
            ->where('kind', 'catalog')->sortByDesc(fn ($r) => $r['market_key'] !== null)->first(fn ($r) => $r['usable']);

        return $rec ? \App\Core\Presets\PresetService::content(\App\Core\Presets\PresetService::find('catalog', $rec['effective_id'])) : null;
    }

    private function activation(Company $company, array $item): ?object
    {
        $id = self::targetId($item['kind'], $item['code'], $item['subject']);

        return $item['kind'] === 'field'
            ? FieldActivation::where('company_id', $company->id)->where('field_definition_id', $id)->first()
            : DocumentTypeActivation::where('company_id', $company->id)->where('document_type_id', $id)->first();
    }

    public static function targetId(string $kind, string $code, string $subject): ?int
    {
        $id = $kind === 'field'
            ? FieldDefinition::whereNull('company_id')->where('code', $code)->where('scope', $subject === 'company' ? FieldDefinition::SCOPE_COMPANY : FieldDefinition::SCOPE_COMPANY_USER)->value('id')
            : DocumentType::where('is_system', true)->where('code', $code)->where('scope', $subject === 'company' ? DocumentType::SCOPE_COMPANY : DocumentType::SCOPE_COMPANY_USER)->value('id');

        return $id ? (int) $id : null;
    }
}
