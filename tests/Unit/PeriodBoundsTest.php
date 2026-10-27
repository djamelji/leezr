<?php

namespace Tests\Unit;

use App\Core\Billing\PeriodBounds;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * ADR-632 (M0) — PeriodBounds interprète les bornes historiques SANS jamais inventer une charge.
 * Pur, sans DB. Couvre les 4 classifications + bornes calendaires (février, bissextile, annuel) + le
 * cas 16/31 (activation mi-mois) + le respect des anniversaires non-minuit (décisions #2/#4).
 */
class PeriodBoundsTest extends TestCase
{
    private function b(string $start, string $end): PeriodBounds
    {
        return PeriodBounds::fromLegacy(CarbonImmutable::parse($start), CarbonImmutable::parse($end));
    }

    public function test_inclusive_end_of_day_becomes_exclusive_next_midnight(): void
    {
        $r = $this->b('2026-05-01 00:00:00', '2026-05-31 23:59:59');
        $this->assertSame('inclusive_eod', $r->classification);
        $this->assertTrue($r->resolved);
        $this->assertSame('2026-06-01 00:00:00', $r->endExclusive->format('Y-m-d H:i:s'));
        $this->assertSame(31, $r->calendarDays());
    }

    public function test_midnight_end_is_already_exclusive_and_unchanged(): void
    {
        $r = $this->b('2026-10-21 00:00:00', '2026-11-21 00:00:00');
        $this->assertSame('midnight_exclusive', $r->classification);
        $this->assertSame('2026-11-21 00:00:00', $r->endExclusive->format('Y-m-d H:i:s'));
        $this->assertSame(31, $r->calendarDays());
    }

    public function test_anniversary_precise_instant_is_preserved(): void
    {
        // sub#3-like : fin à la même heure que le début → instant contractuel, inchangé (décision #2).
        $r = $this->b('2026-10-21 12:14:35', '2026-11-21 12:14:35');
        $this->assertSame('anniversary', $r->classification);
        $this->assertTrue($r->resolved);
        $this->assertSame('2026-11-21 12:14:35', $r->endExclusive->format('Y-m-d H:i:s'));
    }

    public function test_ambiguous_period_is_not_resolved_and_yields_no_bound(): void
    {
        // Heure de fin arbitraire ≠ 00:00, ≠ 23:59:59, ≠ heure de début → NON résolu, revue manuelle.
        $r = $this->b('2026-10-01 00:00:00', '2026-10-31 08:00:00');
        $this->assertSame('ambiguous', $r->classification);
        $this->assertFalse($r->resolved);
        $this->assertNull($r->endExclusive);
        $this->assertNull($r->calendarDays());
    }

    public function test_mid_month_activation_16_of_31(): void
    {
        // Activation 16/10 dans [10-16, 11-01) → 16 jours facturables (semi-ouvert).
        $r = $this->b('2026-10-16 00:00:00', '2026-11-01 00:00:00');
        $this->assertSame(16, $r->calendarDays());
    }

    public function test_february_non_leap_and_leap(): void
    {
        $this->assertSame(28, $this->b('2026-02-01 00:00:00', '2026-03-01 00:00:00')->calendarDays());
        $this->assertSame(29, $this->b('2028-02-01 00:00:00', '2028-03-01 00:00:00')->calendarDays());
    }

    public function test_annual_cycle(): void
    {
        $this->assertSame(365, $this->b('2026-01-01 00:00:00', '2027-01-01 00:00:00')->calendarDays());
        $this->assertSame(366, $this->b('2028-01-01 00:00:00', '2029-01-01 00:00:00')->calendarDays());
    }

    public function test_utc_normalization(): void
    {
        // Entrée avec offset → ramenée en UTC avant classification.
        $r = PeriodBounds::fromLegacy(
            CarbonImmutable::parse('2026-05-01T02:00:00+02:00'), // = 00:00 UTC
            CarbonImmutable::parse('2026-06-01T02:00:00+02:00'),
        );
        $this->assertSame('midnight_exclusive', $r->classification);
        $this->assertSame('2026-06-01 00:00:00', $r->endExclusive->format('Y-m-d H:i:s'));
    }
}
