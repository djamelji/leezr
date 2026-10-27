<?php

namespace App\Core\Billing;

use RuntimeException;

/** ADR-632 (M1) — erreurs de domaine du moteur d'événements facturables. */
class BillableEventException extends RuntimeException
{
    public static function conflict(string $msg): self
    {
        return new self('[billable_event:conflict] '.$msg);
    }

    public static function illegalTransition(string $from, string $to): self
    {
        return new self("[billable_event:illegal_transition] {$from} → {$to}");
    }

    public static function invalid(string $msg): self
    {
        return new self('[billable_event:invalid] '.$msg);
    }
}
