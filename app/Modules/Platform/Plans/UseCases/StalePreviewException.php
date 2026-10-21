<?php

namespace App\Modules\Platform\Plans\UseCases;

/**
 * ADR-590 — raised when a tariff migration is confirmed against a STALE preview: one or more selected
 * subscriptions had their contracted price changed between preview and apply. The operation is
 * refused (never applied silently); the operator must re-preview.
 */
class StalePreviewException extends \DomainException
{
    /** @param array<int> $subscriptionIds */
    public function __construct(public array $subscriptionIds)
    {
        parent::__construct('Tariff migration preview is stale: contracted price changed for '.count($subscriptionIds).' subscription(s). Please re-preview.');
    }
}
