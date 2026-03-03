<?php

namespace App\Modules\Core\Members\UseCases;

/**
 * ADR-176: Result of member document review.
 */
final class ReviewMemberDocumentResult
{
    public function __construct(
        public readonly string $code,
        public readonly string $status,
        public readonly ?string $reviewNote,
        public readonly string $reviewedAt,
    ) {}
}
