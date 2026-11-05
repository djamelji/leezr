<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Notifications\NotificationEvent;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PlanningNotificationOutbox;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\Services\PlanningOutbox;
use App\Modules\Workforce\UseCases\PublishShiftsUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ADR-717 §3 — outbox durable des notifications de planning.
 * Vérifie : intention enregistrée DANS la transaction de publication ; flush → événement in-app ;
 * survie au crash post-commit (flush différé livre quand même) ; idempotence (flush rejoué = 1 event) ;
 * statuts pending → sent.
 */
class WorkforcePlanningOutboxLot3Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 08:00:00');
        Model::unguard();
        \App\Core\Jobdomains\Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_planning']]);
        \App\Core\Markets\Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            \App\Core\Modules\PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();
        $this->seed(\Database\Seeders\NotificationTopicSeeder::class);
        $this->company = Company::create(['name' => 'OB', 'slug' => 'ob-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employeeWithUser(): Employee
    {
        $e = Employee::create(['company_id' => $this->company->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        EmploymentContract::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);
        $e->update(['user_id' => User::factory()->create()->id]);

        return $e->fresh();
    }

    private function draft(Employee $e): Shift
    {
        return Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => '2026-11-03',
            'start_at' => '2026-11-03 09:00:00', 'end_at' => '2026-11-03 17:00:00', 'timezone' => 'Europe/Paris',
            'status' => Shift::STATUS_DRAFT,
        ]);
    }

    public function test_publish_enqueues_intent_in_transaction(): void
    {
        Notification::fake();
        $e = $this->employeeWithUser();
        app(PublishShiftsUseCase::class)->execute($this->company->id, [$this->draft($e)->id]);

        // L'intention existe (écrite dans la transaction de publication), encore pending car pas de flush.
        $this->assertSame(1, PlanningNotificationOutbox::where('status', 'pending')->count());
        $this->assertSame(0, NotificationEvent::where('topic_key', 'workforce.planning_published')->count());
    }

    public function test_flush_delivers_and_marks_sent(): void
    {
        Notification::fake();
        $e = $this->employeeWithUser();
        app(PublishShiftsUseCase::class)->execute($this->company->id, [$this->draft($e)->id]);

        $sent = app(PlanningOutbox::class)->flush();

        $this->assertSame(1, $sent);
        $this->assertSame(1, NotificationEvent::where('topic_key', 'workforce.planning_published')->where('recipient_id', $e->user_id)->count());
        $this->assertSame('sent', PlanningNotificationOutbox::first()->status);
        $this->assertNotNull(PlanningNotificationOutbox::first()->processed_at);
    }

    public function test_survives_crash_after_commit_deferred_flush_still_delivers(): void
    {
        Notification::fake();
        $e = $this->employeeWithUser();
        // Simule : publication commit, PUIS crash avant le flush (aucun flush immédiat).
        app(PublishShiftsUseCase::class)->execute($this->company->id, [$this->draft($e)->id]);
        $this->assertSame(0, NotificationEvent::count(), 'Crash avant flush → pas encore d\'événement.');

        // Le filet planifié (commande) rattrape.
        $this->artisan('workforce:flush-planning-notifications')->assertSuccessful();

        $this->assertSame(1, NotificationEvent::where('topic_key', 'workforce.planning_published')->count(), 'L\'intention durable a survécu et a été livrée.');
    }

    public function test_flush_is_idempotent_no_duplicate(): void
    {
        Notification::fake();
        $e = $this->employeeWithUser();
        app(PublishShiftsUseCase::class)->execute($this->company->id, [$this->draft($e)->id]);

        app(PlanningOutbox::class)->flush();
        app(PlanningOutbox::class)->flush(); // rejeu
        $this->artisan('workforce:flush-planning-notifications'); // + filet planifié

        $this->assertSame(1, NotificationEvent::where('topic_key', 'workforce.planning_published')->where('recipient_id', $e->user_id)->count(), 'Aucun doublon malgré plusieurs flush.');
    }
}
