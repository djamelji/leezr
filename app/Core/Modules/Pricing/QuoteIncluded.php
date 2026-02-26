<?php

namespace App\Core\Modules\Pricing;

/**
 * A non-billed module included in a quote via transitive requires.
 */
final class QuoteIncluded
{
    public function __construct(
        public readonly string $key,
        public readonly string $title,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
        ];
    }
}
