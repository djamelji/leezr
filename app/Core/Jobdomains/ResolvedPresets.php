<?php

namespace App\Core\Jobdomains;

/**
 * Immutable DTO: resolved presets for a (jobdomain, market) pair.
 *
 * Result of merging global defaults + market overlay.
 */
readonly class ResolvedPresets
{
    public function __construct(
        public string $jobdomainKey,
        public ?string $marketKey,
        public array $modules,
        public array $fields,
        public array $documents,
        public array $roles,
    ) {}
}
