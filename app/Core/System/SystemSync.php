<?php

namespace App\Core\System;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use JsonSerializable;
use Traversable;

/**
 * ADR-832 — synchronisation of the system catalogs owned (in part) by the code.
 *
 * Each catalog record is reconciled field by field according to WHO OWNS the field:
 *
 *  - code         (A) technical definition held by the code : always written (changes are reported) ;
 *  - administered (B) value Platform may change : written at creation ; afterwards it follows a code evolution
 *                     ONLY while it still equals the code value last applied (three-way rule, baseline in
 *                     `system_sync_baselines`). A value changed since in Platform — or whose provenance is
 *                     unknown on a historical install — is PRESERVED and reported as a conflict ;
 *  - createOnly       runtime/admin state with no code evolution (activation, availability) : creation only ;
 *  - structural       written at creation ; a divergence is reported, never applied (needs a controlled migration).
 *
 * A conflict is resolved only on purpose : `php artisan system:sync --adopt=<subject>:<key>:<field>` applies the
 * code value (the decision is logged), or the value is left as administered. Company data is never reconciled.
 */
final class SystemSync
{
    private const TABLE = 'system_sync_baselines';

    /** @var list<array<string, mixed>> */
    private static array $report = [];

    /** @var list<string> */
    private static array $adopt = [];

    /** @var array<string, array<string, string>> subject => ["key\0field" => hash] */
    private static array $baselines = [];

    private static ?bool $ledger = null;

    private static bool $active = false;

    public static function begin(array $adopt = []): void
    {
        self::$report = [];
        self::$adopt = array_values(array_filter(array_map('trim', $adopt)));
        self::$baselines = [];
        self::$ledger = null;
        self::$active = true;
    }

    public static function active(): bool
    {
        return self::$active;
    }

    public static function end(): void
    {
        self::$active = false;
    }

    /** @return list<array<string, mixed>> */
    public static function report(): array
    {
        return self::$report;
    }

    /** @return list<array<string, mixed>> */
    public static function conflicts(): array
    {
        return array_values(array_filter(self::$report, fn ($e) => str_contains($e['type'], 'conflict')));
    }

    /** @return array<string, int> */
    public static function summary(): array
    {
        $out = [];
        foreach (self::$report as $e) {
            $out[$e['type']] = ($out[$e['type']] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function reconcile(
        string $subject,
        string $class,
        array $match,
        array $code = [],
        array $administered = [],
        array $createOnly = [],
        array $structural = [],
    ): Model {
        $key = self::keyOf($match);
        /** @var Model|null $row */
        $probe = (new $class)->forceFill($match); // lookup in the STORED representation (casts : dates, booleans)
        $lookup = [];
        foreach (array_keys($match) as $f) {
            $lookup[$f] = $probe->getAttributes()[$f] ?? null;
        }
        $row = $class::query()->where($lookup)->first();

        if (! $row) {
            $row = new $class;
            $row->forceFill($match + $code + $administered + $createOnly + $structural)->save();
            foreach (array_keys($administered) as $field) {
                self::remember($subject, $key, $field, self::canonOf($row, $field));
            }
            self::note('created', $subject, $key);

            return $row;
        }

        $codeChanged = [];
        foreach ($code as $field => $value) {
            if (self::canonOf($row, $field) !== self::canonValue($class, $field, $value)) {
                $row->setAttribute($field, $value);
                $codeChanged[] = $field;
            }
        }
        if ($codeChanged !== []) {
            self::note('code_updated', $subject, $key, ['fields' => $codeChanged]);
        }

        foreach ($administered as $field => $value) {
            $current = self::canonOf($row, $field);
            $wanted = self::canonValue($class, $field, $value);
            $baseline = self::baseline($subject, $key, $field);

            if ($current === $wanted) {
                if ($baseline !== self::hash($wanted)) {
                    self::remember($subject, $key, $field, $wanted);
                }

                continue;
            }
            if (self::adopted($subject, $key, $field)) {
                $row->setAttribute($field, $value);
                self::remember($subject, $key, $field, $wanted);
                self::note('adopted', $subject, $key, ['field' => $field, 'database' => self::decode($current), 'code' => self::decode($wanted)]);
                Log::notice('system_sync.adopted', ['subject' => $subject, 'key' => $key, 'field' => $field]);

                continue;
            }
            if ($baseline !== null && $baseline === self::hash($current)) {
                $row->setAttribute($field, $value);
                self::remember($subject, $key, $field, $wanted);
                self::note('evolved', $subject, $key, ['field' => $field, 'database' => self::decode($current), 'code' => self::decode($wanted)]);

                continue;
            }
            self::note('conflict', $subject, $key, [
                'field' => $field,
                'database' => self::decode($current),
                'code' => self::decode($wanted),
                'baseline' => $baseline === null ? 'unknown' : 'administered',
                'adopt' => "{$subject}:{$key}:{$field}",
            ]);
        }

        foreach ($structural as $field => $value) {
            $current = self::canonOf($row, $field);
            $wanted = self::canonValue($class, $field, $value);
            if ($current !== $wanted) {
                self::note('structural_conflict', $subject, $key, ['field' => $field, 'database' => self::decode($current), 'code' => self::decode($wanted)]);
            }
        }

        if ($row->isDirty()) {
            $row->save();
        }

        return $row;
    }

    /**
     * Set-like relations (permissions of a role, languages of a market) : a catalog member is OFFERED once.
     * Members created by this run, or all of them when the owner was just created, are attached ; a member
     * removed in Platform after being offered stays removed ; a missing member of unknown provenance (historical
     * install, no baseline) is reported as a conflict, attached only on `--adopt`.
     *
     * @param  list<string>  $catalog  members the code declares
     * @param  list<string>  $current  members attached today
     * @param  list<string>  $fresh  members created during this run
     * @return list<string> members to attach
     */
    public static function offerMembers(string $subject, string $key, array $catalog, array $current, array $fresh = [], bool $ownerCreated = false): array
    {
        $attach = [];
        foreach (array_values(array_unique($catalog)) as $member) {
            $field = 'member:'.$member;
            $offered = self::baseline($subject, $key, $field) !== null;
            if (in_array($member, $current, true)) {
                if (! $offered) {
                    self::remember($subject, $key, $field, '"offered"');
                }

                continue;
            }
            if ($offered) {
                self::note('preserved_removal', $subject, $key, ['member' => $member]);

                continue;
            }
            $type = ($ownerCreated || in_array($member, $fresh, true)) ? 'granted' : (self::adopted($subject, $key, $field) ? 'adopted' : null);
            if ($type === null) {
                self::note('conflict', $subject, $key, ['field' => $field, 'database' => 'absent', 'code' => 'declared', 'baseline' => 'unknown', 'adopt' => "{$subject}:{$key}:{$field}"]);

                continue;
            }
            $attach[] = $member;
            self::remember($subject, $key, $field, '"offered"');
            self::note($type, $subject, $key, ['member' => $member]);
        }

        return $attach;
    }

    // ─── Baselines (also used for set-like relations, e.g. the permissions offered to a role) ───────────

    public static function baseline(string $subject, string $key, string $field): ?string
    {
        if (! self::ledger()) {
            return null;
        }
        if (! self::$active) { // outside a sync run (a registry called alone) : no cache, read the row
            return DB::table(self::TABLE)->where(['subject' => $subject, 'subject_key' => $key, 'field' => $field])->value('value_hash');
        }
        if (! isset(self::$baselines[$subject])) {
            self::$baselines[$subject] = [];
            foreach (DB::table(self::TABLE)->where('subject', $subject)->get(['subject_key', 'field', 'value_hash']) as $r) {
                self::$baselines[$subject][$r->subject_key."\0".$r->field] = $r->value_hash;
            }
        }

        return self::$baselines[$subject][$key."\0".$field] ?? null;
    }

    public static function remember(string $subject, string $key, string $field, string $canonical): void
    {
        if (! self::ledger()) {
            return;
        }
        self::baseline($subject, $key, $field); // loads the subject (sync run)
        $hash = self::hash($canonical);
        DB::table(self::TABLE)->updateOrInsert(
            ['subject' => $subject, 'subject_key' => $key, 'field' => $field],
            ['value_hash' => $hash, 'value' => $canonical, 'recorded_at' => now()],
        );
        if (self::$active) {
            self::$baselines[$subject][$key."\0".$field] = $hash;
        }
    }

    public static function adopted(string $subject, string $key, string $field): bool
    {
        foreach (["{$subject}:{$key}:{$field}", "{$subject}:{$key}:*", "{$subject}:*:{$field}", "{$subject}:*:*"] as $pattern) {
            if (in_array($pattern, self::$adopt, true)) {
                return true;
            }
        }

        return false;
    }

    public static function note(string $type, string $subject, string $key, array $extra = []): void
    {
        if (! self::$active) {
            return; // conflicts are still logged ; the report exists only for a sync run
        }
        $entry = ['type' => $type, 'subject' => $subject, 'key' => $key] + $extra;
        if (in_array($entry, self::$report, true)) {
            return; // a registry reconciled twice in one run (PlatformSeeder + SystemSeeder) reports once
        }
        self::$report[] = $entry;
        if (str_contains($type, 'conflict')) {
            Log::warning('system_sync.'.$type, ['subject' => $subject, 'key' => $key, 'field' => $extra['field'] ?? null, 'baseline' => $extra['baseline'] ?? null]);
        }
    }

    // ─── Canonical values ─────────────────────────────────────────────────────────────────────────────────

    public static function keyOf(array $match): string
    {
        return implode('|', array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d') : (string) ($v ?? ''), array_values($match)));
    }

    public static function canon(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof BackedEnum) {
            return self::canon($value->value);
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && ! preg_match('/^0\d|^\+/', $value))) {
            $n = $value + 0;

            return (is_float($n) && floor($n) == $n && abs($n) < PHP_INT_MAX) ? (int) $n : $n;
        }
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof JsonSerializable) {
            return self::canon($value->jsonSerialize());
        }
        if ($value instanceof Traversable) {
            $value = iterator_to_array($value);
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map([self::class, 'canon'], $value);
        }

        return $value;
    }

    public static function encode(mixed $value): string
    {
        return json_encode(self::canon($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function canonOf(Model $row, string $field): string
    {
        return self::encode($row->getAttribute($field));
    }

    private static function canonValue(string $class, string $field, mixed $value): string
    {
        $probe = new $class;
        $probe->forceFill([$field => $value]);

        return self::encode($probe->getAttribute($field));
    }

    private static function decode(string $canonical): mixed
    {
        return json_decode($canonical, true);
    }

    private static function hash(string $canonical): string
    {
        return hash('sha256', $canonical);
    }

    private static function ledger(): bool
    {
        if (self::$ledger) {
            return true;
        }

        return self::$ledger = Schema::hasTable(self::TABLE); // only a positive answer is kept
    }
}
