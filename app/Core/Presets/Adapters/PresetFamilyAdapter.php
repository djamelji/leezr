<?php

namespace App\Core\Presets\Adapters;

use App\Core\Models\Company;

/**
 * ADR-793 (L3) — one adapter per preset family : its OWN validation, its items (stable keys, for the
 * version diff), a READ-ONLY per-item preview against a company, and a selective, idempotent apply that
 * goes through the family's existing engine. Statuses : new · identical · customized · conflict ·
 * not_applicable · requires_confirmation.
 */
interface PresetFamilyAdapter
{
    public function kind(): string;

    /** @throws \Illuminate\Validation\ValidationException */
    public function validate(array $content, ?string $marketKey = null): void;

    /** @return array<string, array> items keyed by a stable key */
    public function items(array $content): array;

    /** @return list<array{key: string, label: string, status: string, detail: ?string}> — no write */
    public function preview(Company $company, object $preset): array;

    /** Applies the selected items (null = every item that needs no confirmation) ; idempotent. */
    public function apply(Company $company, object $preset, ?array $selected, ?int $actorId): array;

    /** Called when a version is published (e.g. materialise templates) ; no company is touched. */
    public function onPublish(object $preset): void;
}
