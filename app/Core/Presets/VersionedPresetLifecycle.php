<?php

namespace App\Core\Presets;

/**
 * ADR-793 (L3) — ONE versioning lifecycle for every preset family (extracted from the organisation
 * presets, ADR-636/638) : draft → published → archived ; a published or archived version is IMMUTABLE
 * (only its status may move published ⇄ archived) ; a change = a new version of the same family ;
 * one published version per family. Enforced on save (model level), not only in a controller.
 *
 * Using models expose : family_key, version, status, published_at, and contentAttributes().
 */
trait VersionedPresetLifecycle
{
    protected static function bootVersionedPresetLifecycle(): void
    {
        static::saving(function ($preset) {
            $was = $preset->getOriginal('status');
            if (! $preset->exists || ! in_array($was, ['published', 'archived'], true)) {
                return;
            }
            $frozen = array_intersect(array_keys($preset->getDirty()), array_merge($preset->contentAttributes(), ['family_key', 'version', 'key']));
            if ($frozen !== []) {
                throw new \DomainException('A published preset version is immutable : create a new version ('.implode(', ', $frozen).').');
            }
        });
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /** Publishes this version ; the previously published version of the family is archived (kept). */
    public function publishVersion(?int $by = null): void
    {
        if ($this->status === 'published') {
            return;
        }
        static::query()->where('family_key', $this->family_key)->where('id', '!=', $this->id)->where('status', 'published')
            ->get()->each(fn ($p) => $p->forceFill(['status' => 'archived', 'updated_by' => $by])->save());
        $this->forceFill(['status' => 'published', 'published_at' => now(), 'updated_by' => $by])->save();
    }

    public function archiveVersion(?int $by = null): void
    {
        $this->forceFill(['status' => 'archived', 'updated_by' => $by])->save();
    }

    /** New DRAFT version of the same family, copied from this one (numbered after the highest version). */
    public function nextVersion(?int $by = null): static
    {
        // Créations simultanées : l'unicité (famille, version) arbitre ; on retente avec le numéro suivant.
        for ($attempt = 1; ; $attempt++) {
            $next = (int) static::query()->where('family_key', $this->family_key)->max('version') + 1;
            $copy = $this->replicate(['published_at']);
            $copy->forceFill(['version' => $next, 'status' => 'draft', 'key' => $this->family_key.'_v'.$next, 'created_by' => $by, 'updated_by' => $by]);
            try {
                $copy->save();

                return $copy;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }
}
