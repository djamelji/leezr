<?php

namespace Tests\Feature;

use App\Core\Billing\ReadModels\PlatformCommercialSeriesService;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ADR-747 — séries « Pilotage commercial » : MRR niveau, périodes, comparatif N-1.
 */
class PlatformCommercialSeriesTest extends TestCase
{
    use RefreshDatabase;

    private PlatformCommercialSeriesService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(PlatformCommercialSeriesService::class);
    }

    private function sub(int $cents, Carbon $createdAt): Subscription
    {
        $slug = 'c'.uniqid();
        $company = Company::create(['name' => 'C'.uniqid(), 'slug' => $slug, 'plan_key' => 'pro', 'status' => 'active']);
        $s = Subscription::create([
            'company_id' => $company->id, 'plan_key' => 'pro', 'interval' => 'monthly',
            'status' => 'active', 'provider' => 'internal', 'is_current' => 1, 'plan_price_cents' => $cents,
        ]);
        $s->forceFill(['created_at' => $createdAt])->save();

        return $s;
    }

    public function test_week_has_seven_iso_buckets(): void
    {
        $r = $this->svc->series('week', null, false);
        $this->assertSame('week', $r['period']);
        $this->assertCount(7, $r['labels']);
        $this->assertCount(7, $r['current']);
    }

    public function test_mrr_is_a_level_not_a_daily_sum(): void
    {
        // Un abonnement à 100 € créé il y a 40 jours → MRR niveau = 10000 chaque jour (pas cumulé).
        $this->sub(10000, Carbon::now()->subDays(40));
        $r = $this->svc->series('month', null, false);
        $real = array_values(array_filter($r['current'], fn ($v) => $v !== null));
        $this->assertNotEmpty($real);
        foreach ($real as $v) {
            $this->assertSame(10000, $v); // niveau constant, jamais 10000*jour
        }
    }

    public function test_current_period_is_partial_future_buckets_null(): void
    {
        $this->sub(5000, Carbon::now()->subMonths(2));
        $r = $this->svc->series('month', null, false);
        $this->assertTrue($r['partial']);
        $this->assertNull($r['current'][count($r['current']) - 1]); // dernier jour du mois = futur
    }

    public function test_n1_comparison_populated_when_history_exists(): void
    {
        $this->sub(20000, Carbon::now()->subMonths(14)); // existe depuis > 1 an
        $r = $this->svc->series('year', null, true);
        $this->assertTrue($r['has_history']);
        $this->assertNotNull($r['previous']);
        $this->assertSame(20000, $r['summary']['mrr_now']);
        $this->assertNotNull($r['summary']['mrr_prev_equiv']);
    }

    public function test_n1_absent_when_no_history_no_division_by_zero(): void
    {
        $this->sub(7000, Carbon::now()->subDays(5)); // créé récemment → aucun historique N-1
        $r = $this->svc->series('year', null, true);
        $this->assertFalse($r['has_history']);
        $this->assertNull($r['previous']);
        $this->assertNull($r['summary']['delta_pct']); // pas de division par zéro
    }

    public function test_year_has_twelve_monthly_buckets(): void
    {
        $r = $this->svc->series('year', null, false);
        $this->assertSame('month', $r['granularity']);
        $this->assertCount(12, $r['labels']);
    }
}
