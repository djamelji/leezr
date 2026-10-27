<?php

namespace Tests\Feature;

use App\Core\Billing\BillableEvent;
use App\Core\Billing\BillableEventException;
use App\Core\Billing\BillableEventService;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-632 (M1) — moteur d'événements facturables : idempotence, concurrence, machine d'état,
 * isolation multi-tenant, reprise par lease. Composant de domaine, non branché au moteur.
 */
class BillableEventServiceTest extends TestCase
{
    use RefreshDatabase;

    private BillableEventService $svc;
    private Company $a;
    private Company $b;

    protected function setUp(): void
    {
        parent::setUp();
        MarketRegistry::sync();
        $this->svc = new BillableEventService;
        $this->a = $this->company('A');
        $this->b = $this->company('B');
    }

    private function company(string $n): Company
    {
        return Company::create(['name' => "Co {$n}", 'slug' => strtolower($n).'-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    private function payload(Company $c, string $key, array $over = []): array
    {
        return array_merge([
            'company_id' => $c->id, 'currency' => 'EUR', 'type' => 'module_activation', 'module_key' => 'workforce_planning',
            'service_period_start' => '2026-10-16 00:00:00', 'service_period_end' => '2026-11-01 00:00:00',
            'price_snapshot_cents' => 2900, 'proration_num' => 16, 'proration_den' => 31, 'amount_ht_cents' => 1496,
            'idempotency_key' => $key,
        ], $over);
    }

    public function test_record_creates_eligible_with_snapshots(): void
    {
        $e = $this->svc->record($this->payload($this->a, 'k1'));
        $this->assertSame('eligible', $e->status);
        $this->assertSame(1496, $e->amount_ht_cents);
        $this->assertSame(16, $e->proration_num);
        $this->assertSame(31, $e->proration_den);
        $this->assertSame('floor', $e->rounding);
    }

    public function test_double_record_same_key_is_idempotent(): void
    {
        $a = $this->svc->record($this->payload($this->a, 'dup'));
        $b = $this->svc->record($this->payload($this->a, 'dup'));
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, BillableEvent::where('idempotency_key', 'dup')->count());
    }

    public function test_same_key_divergent_payload_conflicts(): void
    {
        $this->svc->record($this->payload($this->a, 'div'));
        $this->expectException(BillableEventException::class);
        $this->svc->record($this->payload($this->a, 'div', ['amount_ht_cents' => 9999]));
    }

    public function test_reserve_is_atomic_and_scoped(): void
    {
        $e = $this->svc->record($this->payload($this->a, 'r1'));
        $reserved = $this->svc->reserve('run-1', $this->a->id, 'EUR', [$e->id]);
        $this->assertCount(1, $reserved);
        $this->assertSame('reserved', $e->fresh()->status);
        $this->assertSame('run-1', $e->fresh()->reserved_by_run_id);
        $this->assertNotNull($e->fresh()->reserved_at);
    }

    public function test_two_concurrent_runs_only_one_reserves(): void
    {
        $e = $this->svc->record($this->payload($this->a, 'r2'));
        $first = $this->svc->reserve('run-A', $this->a->id, 'EUR', [$e->id]);
        $second = $this->svc->reserve('run-B', $this->a->id, 'EUR', [$e->id]); // déjà réservé
        $this->assertCount(1, $first);
        $this->assertCount(0, $second, 'un event déjà réservé ne peut être re-réservé par un autre run');
        $this->assertSame('run-A', $e->fresh()->reserved_by_run_id);
    }

    public function test_reserve_wrong_company_or_currency_reserves_nothing(): void
    {
        $e = $this->svc->record($this->payload($this->a, 'r3'));
        $this->assertCount(0, $this->svc->reserve('run', $this->b->id, 'EUR', [$e->id]), 'isolation tenant');
        $this->assertCount(0, $this->svc->reserve('run', $this->a->id, 'USD', [$e->id]), 'devise différente');
        $this->assertSame('eligible', $e->fresh()->status);
    }

    public function test_mark_invoiced_from_reserved(): void
    {
        $e = $this->svc->record($this->payload($this->a, 'mi'));
        $this->svc->reserve('run-1', $this->a->id, 'EUR', [$e->id]);
        $done = $this->svc->markInvoiced($e->id, 500, 'run-1');
        $this->assertSame('invoiced', $done->status);
        $this->assertSame(500, $done->invoice_line_id);
    }

    public function test_double_mark_invoiced_same_line_idempotent_diff_line_conflict(): void
    {
        $e = $this->svc->record($this->payload($this->a, 'mi2'));
        $this->svc->reserve('run-1', $this->a->id, 'EUR', [$e->id]);
        $this->svc->markInvoiced($e->id, 600, 'run-1');
        $this->assertSame(600, $this->svc->markInvoiced($e->id, 600, 'run-1')->invoice_line_id); // idempotent
        $this->expectException(BillableEventException::class);
        $this->svc->markInvoiced($e->id, 601, 'run-1'); // autre ligne → conflit
    }

    public function test_mark_invoiced_requires_reserved_state(): void
    {
        $e = $this->svc->record($this->payload($this->a, 'mi3'));
        $this->expectException(BillableEventException::class); // eligible → invoiced interdit
        $this->svc->markInvoiced($e->id, 700, 'run-1');
    }

    public function test_mark_invoiced_wrong_run_conflicts(): void
    {
        $e = $this->svc->record($this->payload($this->a, 'mi4'));
        $this->svc->reserve('run-A', $this->a->id, 'EUR', [$e->id]);
        $this->expectException(BillableEventException::class);
        $this->svc->markInvoiced($e->id, 800, 'run-B');
    }

    public function test_invoice_line_id_unique_across_events(): void
    {
        $e1 = $this->svc->record($this->payload($this->a, 'u1'));
        $e2 = $this->svc->record($this->payload($this->a, 'u2', ['module_key' => 'workforce_documents']));
        $this->svc->reserve('run-1', $this->a->id, 'EUR', [$e1->id, $e2->id]);
        $this->svc->markInvoiced($e1->id, 900, 'run-1');
        $this->expectException(BillableEventException::class); // même invoice_line_id → UNIQUE DB
        $this->svc->markInvoiced($e2->id, 900, 'run-1');
    }

    public function test_cancel_only_from_eligible(): void
    {
        $e = $this->svc->record($this->payload($this->a, 'c1'));
        $this->assertSame('cancelled', $this->svc->cancel($e->id)->status);

        $e2 = $this->svc->record($this->payload($this->a, 'c2'));
        $this->svc->reserve('run-1', $this->a->id, 'EUR', [$e2->id]);
        $this->expectException(BillableEventException::class); // reserved → cancel interdit
        $this->svc->cancel($e2->id);
    }

    public function test_invoiced_never_cancelled_or_released(): void
    {
        $e = $this->svc->record($this->payload($this->a, 'inv'));
        $this->svc->reserve('run-1', $this->a->id, 'EUR', [$e->id]);
        $this->svc->markInvoiced($e->id, 1000, 'run-1');
        $this->assertSame(0, $this->svc->releaseStale(0), 'releaseStale ne touche jamais un invoiced');
        $this->assertSame('invoiced', $e->fresh()->status);
    }

    public function test_compensate_is_traceable_and_leaves_original_intact(): void
    {
        $orig = $this->svc->record($this->payload($this->a, 'comp'));
        $comp = $this->svc->compensate($orig->id);
        $this->assertSame($orig->id, $comp->compensation_of_event_id);
        $this->assertSame(-1496, $comp->amount_ht_cents, 'montant inversé');
        $this->assertSame('eligible', $comp->status);
        $this->assertSame('eligible', $orig->fresh()->status, 'original inchangé');
        $this->assertNotSame($orig->id, $comp->id);
    }

    public function test_release_stale_respects_lease(): void
    {
        $active = $this->svc->record($this->payload($this->a, 'lease-active'));
        $stale = $this->svc->record($this->payload($this->a, 'lease-stale'));
        $this->svc->reserve('run-live', $this->a->id, 'EUR', [$active->id]);
        $this->svc->reserve('run-dead', $this->a->id, 'EUR', [$stale->id]);
        // Simule un lease expiré pour l'un, récent pour l'autre.
        $stale->update(['reserved_at' => now()->subMinutes(30)]);

        $released = $this->svc->releaseStale(600); // TTL 10 min
        $this->assertSame(1, $released);
        $this->assertSame('eligible', $stale->fresh()->status, 'lease expiré → re-libéré');
        $this->assertSame('reserved', $active->fresh()->status, 'lease actif → NON libéré');
    }

    public function test_invalid_period_and_type_rejected(): void
    {
        $this->expectException(BillableEventException::class);
        $this->svc->record($this->payload($this->a, 'bad', ['service_period_end' => '2026-10-16 00:00:00'])); // end <= start
    }
}
