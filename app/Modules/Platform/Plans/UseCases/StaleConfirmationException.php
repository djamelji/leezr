<?php

namespace App\Modules\Platform\Plans\UseCases;

/**
 * ADR-594 — raised when a tariff confirmation is submitted against a subscription that changed since
 * the review dialog was opened (concurrency). The confirmation is refused (→ HTTP 409).
 */
class StaleConfirmationException extends \DomainException
{
    public function __construct(public int $subscriptionId)
    {
        parent::__construct('The subscription changed since the review opened. Please reload before confirming.');
    }
}
