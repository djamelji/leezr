<?php

namespace App\Core\Documents\Search;

use Illuminate\Support\Carbon;

/** ADR-778 (UX3) — criteria of a transversal document search. All optional ; all applied SERVER-side. */
final class DocumentSearchQuery
{
    public const STATUSES = ['valid', 'expiring', 'expired', 'signed', 'pending_signature'];

    public function __construct(
        public readonly ?string $term = null,      // name / type / person
        public readonly ?string $source = null,    // provenance : company | person | generated
        public readonly ?string $typeCode = null,
        public readonly ?string $personRef = null, // employee-{id} | user-{id}
        public readonly ?string $status = null,
        public readonly ?Carbon $from = null,      // period (document date)
        public readonly ?Carbon $to = null,
        public readonly ?Carbon $expiresBefore = null,
    ) {}

    public static function fromArray(array $in): self
    {
        $date = fn (?string $v) => $v ? Carbon::parse($v) : null;

        return new self(
            term: isset($in['q']) && trim((string) $in['q']) !== '' ? trim((string) $in['q']) : null,
            source: $in['source'] ?? null,
            typeCode: $in['type'] ?? null,
            personRef: $in['person'] ?? null,
            status: $in['status'] ?? null,
            from: $date($in['from'] ?? null),
            to: $date($in['to'] ?? null)?->endOfDay(),
            expiresBefore: $date($in['expires_before'] ?? null)?->endOfDay(),
        );
    }

    public function like(): ?string
    {
        return $this->term === null ? null : '%'.str_replace(['%', '_'], ['\%', '\_'], $this->term).'%';
    }
}
