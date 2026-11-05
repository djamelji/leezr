<?php

namespace Tests\Unit;

use App\Core\Workforce\EmploymentContract;
use App\Modules\Workforce\Services\LeaveDeductionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * ADR-717 — matrice de décompte des absences. Fonction pure : aucune DB, aucun solde touché.
 * Vérifie que 4 h NE deviennent PAS 0,5 jour arbitrairement (décompte proportionnel non arrondi).
 */
class LeaveDeductionCalculatorTest extends TestCase
{
    private function contract(float $weekly): EmploymentContract
    {
        $c = new EmploymentContract;
        $c->weekly_hours = $weekly;

        return $c;
    }

    public function test_full_day_is_one_day(): void
    {
        $r = (new LeaveDeductionCalculator)->suggest($this->contract(35), '2026-11-02', '2026-11-02');
        $this->assertSame(100, $r['days_count_hundredths']);
        $this->assertSame(7.0, $r['daily_hours']); // 35/5
    }

    public function test_three_full_days(): void
    {
        $r = (new LeaveDeductionCalculator)->suggest($this->contract(35), '2026-11-02', '2026-11-04');
        $this->assertSame(300, $r['days_count_hundredths']);
    }

    public function test_four_hours_is_proportional_not_half_day(): void
    {
        // daily = 7h. 4h → 4/7 jour = 0,571… → 57 centièmes. Surtout PAS 50 (demi-journée).
        $r = (new LeaveDeductionCalculator)->suggest($this->contract(35), '2026-11-02', '2026-11-02', '09:00', '13:00');
        $this->assertSame(400, $r['hours_count_hundredths']);
        $this->assertSame(57, $r['days_count_hundredths']);
        $this->assertNotSame(50, $r['days_count_hundredths'], '4 h ne doivent PAS être arrondies à 0,5 jour.');
    }

    public function test_half_day_matches_half_daily_hours(): void
    {
        // daily = 7h → demi-journée = 3,5h → 0,50 jour.
        $r = (new LeaveDeductionCalculator)->suggest($this->contract(35), '2026-11-02', '2026-11-02', '09:00', '12:30');
        $this->assertSame(350, $r['hours_count_hundredths']);
        $this->assertSame(50, $r['days_count_hundredths']);
    }
}
