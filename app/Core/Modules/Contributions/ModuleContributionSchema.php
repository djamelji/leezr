<?php

namespace App\Core\Modules\Contributions;

use App\Core\Documents\DocumentConfidentiality;
use App\Core\Fields\FieldDefinition;
use App\Core\Requirements\RequirementLevel;

/**
 * ADR-794 (L4) — format of the `contributions` declared by a ModuleManifest :
 *
 *   ['version' => 2,                                   // integer, bumped whenever an item changes
 *    'fields' => [
 *      ['ref' => 'hire_date'],                        // existing canonical definition (stable code)
 *      ['ref' => 'badge_number', 'definition' => [    // NEW definition, created once in the catalogue
 *          'label' => 'Badge number', 'translations' => ['fr' => 'N° de badge', 'en' => 'Badge number'],
 *          'type' => 'string', 'category' => 'hr', 'sensitive' => false,
 *          'validation' => ['max' => 30], 'options' => null, 'applicable_markets' => ['FR']]],
 *    ],
 *    'documents' => [
 *      ['ref' => 'rib'],
 *      ['ref' => 'safety_training', 'definition' => [
 *          'label' => 'Safety training', 'translations' => [...], 'category' => 'training',
 *          'requires_expiration' => true, 'default_validity_months' => 24, 'renewable' => true,
 *          'confidentiality' => 'confidential', 'accepted_types' => ['pdf'], 'max_file_size_mb' => 10]],
 *    ],
 *    'requirements' => [                              // PROPOSALS only — never an obligation by itself
 *      ['target' => 'document:rib', 'level' => 'configurable_required', 'job_role_keys' => [],
 *       'contract_types' => [], 'stage' => 'hire', 'reason' => ['fr' => '…', 'en' => '…']],
 *    ]]
 *
 * Rules : identity = the stable code (never the label) ; a proposal targets an item declared by the SAME
 * module ; level ∈ recommended | default | configurable_required — `imperative` is refused (a module is
 * not a legal authority, L1 invariant) ; historical operational keys (`required_by_modules`,
 * `required_by_jobdomains`) are forbidden in a definition.
 */
final class ModuleContributionSchema
{
    public const LEVELS = [RequirementLevel::RECOMMENDED, RequirementLevel::DEFAULT, RequirementLevel::CONFIGURABLE_REQUIRED];
    public const STAGES = ['hire', 'onboarding', 'ongoing'];
    private const CODE = '/^[a-z][a-z0-9_]{1,60}$/';
    private const FORBIDDEN_RULES = ['required_by_modules', 'required_by_jobdomains', 'required'];

    /**
     * @return array{version: int, items: list<array{kind: string, ref: string, payload: array}>, errors: list<string>}
     */
    public static function normalize(array $c): array
    {
        $errors = [];
        $items = [];
        $version = $c['version'] ?? null;
        $hasItems = ! empty($c['fields']) || ! empty($c['documents']) || ! empty($c['requirements']);
        if ($hasItems && (! is_int($version) || $version < 1)) {
            $errors[] = 'version: a positive integer is required';
        }
        $declared = [];

        foreach (['fields' => 'field', 'documents' => 'document'] as $list => $kind) {
            foreach ($c[$list] ?? [] as $i => $item) {
                $ref = $item['ref'] ?? null;
                if (! is_string($ref) || ! preg_match(self::CODE, $ref)) {
                    $errors[] = "{$list}.{$i}.ref: invalid stable reference";

                    continue;
                }
                $def = $item['definition'] ?? null;
                if ($def !== null && ($e = $kind === 'field' ? self::fieldErrors($def) : self::documentErrors($def))) {
                    array_push($errors, ...array_map(fn ($m) => "{$list}.{$i}.definition: {$m}", $e));

                    continue;
                }
                if (isset($declared["{$kind}:{$ref}"])) {
                    $errors[] = "{$list}.{$i}.ref: declared twice";

                    continue;
                }
                $declared["{$kind}:{$ref}"] = true;
                $items[] = ['kind' => $kind, 'ref' => $ref, 'payload' => self::sorted(['definition' => $def])];
            }
        }

        foreach ($c['requirements'] ?? [] as $i => $p) {
            [$kind, $code] = array_pad(explode(':', (string) ($p['target'] ?? ''), 2), 2, '');
            $e = [];
            if (! isset($declared["{$kind}:{$code}"])) {
                $e[] = 'target must be a field or document declared by the same module';
            }
            if (! in_array($p['level'] ?? null, self::LEVELS, true)) {
                $e[] = 'level must be recommended, default or configurable_required (never imperative)';
            }
            foreach (['job_role_keys', 'contract_types'] as $dim) {
                foreach ($p[$dim] ?? [] as $v) {
                    if (! is_string($v) || ! preg_match('/^[A-Za-z0-9_\-]{1,60}$/', $v)) {
                        $e[] = "invalid {$dim}";
                    }
                }
            }
            if (isset($p['stage']) && ! in_array($p['stage'], self::STAGES, true)) {
                $e[] = 'invalid stage';
            }
            if (! is_array($p['reason'] ?? null) || ! is_string($p['reason']['fr'] ?? null) || ! is_string($p['reason']['en'] ?? null)) {
                $e[] = 'a functional justification (reason.fr, reason.en) is required';
            }
            if ($e) {
                array_push($errors, ...array_map(fn ($m) => "requirements.{$i}: {$m}", $e));

                continue;
            }
            $applicability = array_filter([
                'job_role_keys' => self::sortedList($p['job_role_keys'] ?? []),
                'contract_types' => self::sortedList($p['contract_types'] ?? []),
            ]);
            $ref = "{$kind}:{$code}@".($applicability ? md5(json_encode($applicability)) : 'all');
            if (isset($declared['requirement:'.$ref])) {
                $errors[] = "requirements.{$i}: the same proposal (target and population) is declared twice";

                continue;
            }
            $declared['requirement:'.$ref] = true;
            $items[] = ['kind' => 'requirement', 'ref' => $ref, 'payload' => self::sorted([
                'target_kind' => $kind, 'code' => $code, 'level' => $p['level'], 'applicability' => $applicability ?: null,
                'stage' => $p['stage'] ?? null, 'reason' => ['fr' => $p['reason']['fr'], 'en' => $p['reason']['en']],
            ])];
        }

        return ['version' => is_int($version) ? $version : 0, 'items' => $items, 'errors' => $errors];
    }

    public static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(self::sorted($payload)));
    }

    private static function fieldErrors(array $d): array
    {
        $e = [];
        if (! is_string($d['label'] ?? null) || ! is_string($d['translations']['fr'] ?? null) || ! is_string($d['translations']['en'] ?? null)) {
            $e[] = 'label and translations (fr, en) are required';
        }
        $types = [FieldDefinition::TYPE_STRING, FieldDefinition::TYPE_NUMBER, FieldDefinition::TYPE_DATE, FieldDefinition::TYPE_BOOLEAN, FieldDefinition::TYPE_SELECT];
        if (! in_array($d['type'] ?? null, $types, true)) {
            $e[] = 'unsupported type';
        }
        if (($d['type'] ?? null) === FieldDefinition::TYPE_SELECT && (empty($d['options']) || ! is_array($d['options']))) {
            $e[] = 'a select needs options';
        }
        if (! in_array($d['category'] ?? null, ['base', 'hr', 'domain'], true)) {
            $e[] = 'category must be base, hr or domain';
        }
        if (isset($d['sensitive']) && ! is_bool($d['sensitive'])) {
            $e[] = 'sensitive must be a boolean';
        }
        foreach (array_keys($d['validation'] ?? []) as $k) {
            if (! in_array($k, ['max', 'min'], true) || in_array($k, self::FORBIDDEN_RULES, true)) {
                $e[] = "validation rule « {$k} » is not allowed";
            }
        }
        foreach (self::FORBIDDEN_RULES as $k) {
            if (array_key_exists($k, $d)) {
                $e[] = "« {$k} » is an operational rule, not a definition";
            }
        }

        return $e;
    }

    private static function documentErrors(array $d): array
    {
        $e = [];
        if (! is_string($d['label'] ?? null) || ! is_string($d['translations']['fr'] ?? null) || ! is_string($d['translations']['en'] ?? null)) {
            $e[] = 'label and translations (fr, en) are required';
        }
        if (! is_string($d['category'] ?? null) || ! preg_match('/^[a-z_]{2,40}$/', $d['category'])) {
            $e[] = 'a category is required';
        }
        $levels = [DocumentConfidentiality::INTERNAL, DocumentConfidentiality::CONFIDENTIAL, DocumentConfidentiality::HIGHLY_CONFIDENTIAL];
        if (isset($d['confidentiality']) && ! in_array($d['confidentiality'], $levels, true)) {
            $e[] = 'confidentiality must be internal, confidential or highly_confidential';
        }
        if (isset($d['default_validity_months']) && (! is_int($d['default_validity_months']) || $d['default_validity_months'] < 1 || $d['default_validity_months'] > 240)) {
            $e[] = 'default_validity_months must be between 1 and 240';
        }
        foreach (['requires_expiration', 'renewable'] as $b) {
            if (isset($d[$b]) && ! is_bool($d[$b])) {
                $e[] = "{$b} must be a boolean";
            }
        }
        foreach (self::FORBIDDEN_RULES as $k) {
            if (array_key_exists($k, $d)) {
                $e[] = "« {$k} » is an operational rule, not a definition";
            }
        }

        return $e;
    }

    private static function sortedList(array $v): array
    {
        $v = array_values(array_unique($v));
        sort($v);

        return $v;
    }

    private static function sorted(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map(fn ($x) => self::sorted($x), $v);
    }
}
