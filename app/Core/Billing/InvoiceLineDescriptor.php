<?php

namespace App\Core\Billing;

use Carbon\CarbonInterface;

class InvoiceLineDescriptor
{
    private bool $isFr;
    private string $locale;

    private function __construct(string $locale)
    {
        $this->locale = $locale;
        $this->isFr = str_starts_with($locale, 'fr');
    }

    public static function resolve(string $locale): self
    {
        return new self($locale);
    }

    public function plan(string $planName): string
    {
        return $this->isFr
            ? "Abonnement {$planName}"
            : "{$planName} plan";
    }

    public function planRenewal(string $planName): string
    {
        return $this->isFr
            ? "Renouvellement {$planName}"
            : "{$planName} plan renewal";
    }

    public function addon(string $moduleName): string
    {
        return $this->isFr
            ? "Addon {$moduleName}"
            : "{$moduleName} addon";
    }

    public function coupon(string $code): string
    {
        return $this->isFr
            ? "Coupon : {$code}"
            : "Coupon: {$code}";
    }

    public function prorationCredit(string $planName, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $daysRemaining = null): string
    {
        $base = $this->isFr
            ? "Crédit {$planName} non utilisé"
            : "Credit for unused {$planName} plan";

        return $base . $this->dateContext($from, $to, $daysRemaining);
    }

    /**
     * ADR-335: Rich description for wallet proration credit (downgrade).
     */
    public function walletProrationCredit(string $fromPlan, string $toPlan, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $daysRemaining = null): string
    {
        $base = $this->isFr
            ? "Crédit prorata {$fromPlan} → {$toPlan}"
            : "Proration credit: {$fromPlan} → {$toPlan}";

        return $base . $this->dateContext($from, $to, $daysRemaining);
    }

    public function prorationCharge(string $planName, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $daysRemaining = null): string
    {
        $base = $this->isFr
            ? "Prorata {$planName}"
            : "{$planName} plan charge";

        return $base . $this->dateContext($from, $to, $daysRemaining);
    }

    private function dateContext(?CarbonInterface $from, ?CarbonInterface $to, ?int $days): string
    {
        if (!$from || !$to || $days === null) {
            return '';
        }

        $fromStr = $from->locale($this->locale)->isoFormat('D MMM');
        $toStr = $to->locale($this->locale)->isoFormat('D MMM');
        $dayLabel = $this->isFr ? 'j.' : 'days';

        return " ({$fromStr} → {$toStr} — {$days} {$dayLabel})";
    }
}
