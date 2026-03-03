<?php

namespace App\Core\Fields;

/**
 * ADR-170: Semantic tags for field/document mandatory resolution.
 *
 * Tags describe universal activities or competencies (not roles or jobdomains).
 * A tag like 'driving' means "vehicle operation" regardless of industry.
 *
 * Tags are global, not namespaced by jobdomain.
 * This class is append-only — tag meanings never change.
 */
final class TagDictionary
{
    // Logistics domain
    const DRIVING = 'driving';
    const DISPATCHING = 'dispatching';
    const MANAGEMENT = 'management';
}
