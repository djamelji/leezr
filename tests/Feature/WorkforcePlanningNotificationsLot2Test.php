<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Notifications\NotificationEvent;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\Services\PlanningNotifier;
use App\Modules\Workforce\UseCases\CreateScheduledActivityUseCase;
use App\Modules\Workforce\UseCases\PublishShiftsUseCase;
use App\Notifications\Workforce\PlanningPublishedNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Smart Planning Lot 2 (ADR-715) — notifications de publication / modification.
 * Vérifie : in-app (NotificationEvent) pour les salariés avec compte ; aucun pour ceux sans compte ;
 * idempotence (pas de doublon) ; aucune notif sur changement de BROUILLON ; notif ciblée sur révision
 * d'un planning publié ; email via le mailable (transport de test) ; aucun TimeEntry touché.
 */
class WorkforcePlanningNotificationsLot2Test extends TestCase
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
        // Les topics de notification doivent être seedés (sinon fallback email-only, pas d'in-app).
        $this->seed(\Database\Seeders\NotificationTopicSeeder::class);
        $this->company = Company::create(['name' => 'N2', 'slug' => 'n2-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
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

    private function employee(bool $withUser): Employee
    {
        $e = Employee::create(['company_id' => $this->company->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        EmploymentContract::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);
        if ($withUser) {
            $u = User::factory()->create();
            $e->update(['user_id' => $u->id]);
        }

        return $e->fresh();
    }

    private function draftShift(Employee $e, string $date = '2026-11-03', string $in = '09:00', string $out = '17:00'): Shift
    {
        return Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => $date,
            'start_at' => "{$date} {$in}:00", 'end_at' => "{$date} {$out}:00", 'timezone' => 'Europe/Paris', 'status' => Shift::STATUS_DRAFT,
        ]);
    }

    private function published(string $topic, int $userId): int
    {
        return NotificationEvent::where('topic_key', $topic)->where('recipient_id', $userId)->count();
    }

    public function test_publish_notifies_employees_with_user_inapp_and_email(): void
    {
        Notification::fake();
        $empU = $this->employee(true);
        $empNoUser = $this->employee(false);
        $s1 = $this->draftShift($empU);
        $s2 = $this->draftShift($empNoUser);

        $published = app(PublishShiftsUseCase::class)->execute($this->company->id, [$s1->id, $s2->id]);
        app(PlanningNotifier::class)->notifyPublished($this->company->id, $published);

        // In-app pour le salarié avec compte, aucun pour celui sans compte.
        $this->assertSame(1, $this->published('workforce.planning_published', $empU->user_id));
        $this->assertSame(0, NotificationEvent::where('topic_key', 'workforce.planning_published')->whereNull('recipient_id')->count());
        // Email (canal mail) envoyé au user via le mailable.
        Notification::assertSentTo($empU->user, PlanningPublishedNotification::class);
    }

    public function test_idempotent_no_duplicate_on_second_dispatch(): void
    {
        Notification::fake();
        $emp = $this->employee(true);
        $published = app(PublishShiftsUseCase::class)->execute($this->company->id, [$this->draftShift($emp)->id]);

        app(PlanningNotifier::class)->notifyPublished($this->company->id, $published);
        app(PlanningNotifier::class)->notifyPublished($this->company->id, $published); // retry

        $this->assertSame(1, $this->published('workforce.planning_published', $emp->user_id), 'Pas de doublon (entity_key non lu).');
    }

    public function test_no_notification_on_draft_shift_activity(): void
    {
        $emp = $this->employee(true);
        $draft = $this->draftShift($emp); // reste brouillon
        $result = app(CreateScheduledActivityUseCase::class)->execute($this->company->id, $draft->id, [
            'start_at' => '2026-11-03 09:00:00', 'end_at' => '2026-11-03 10:00:00', 'label' => 'X',
        ]);

        $this->assertSame([], $result['notify_employee_ids'], 'Un changement de brouillon ne notifie pas.');
        $this->assertSame(0, NotificationEvent::where('topic_key', 'workforce.planning_changed')->count());
    }

    public function test_revision_on_published_shift_notifies_only_affected(): void
    {
        Notification::fake();
        $empA = $this->employee(true);
        $empB = $this->employee(true);
        $pubA = app(PublishShiftsUseCase::class)->execute($this->company->id, [$this->draftShift($empA)->id])->first();
        app(PublishShiftsUseCase::class)->execute($this->company->id, [$this->draftShift($empB)->id]);

        // Ajout d'une activité sur le shift PUBLIÉ de A → notif « changed » pour A uniquement.
        $result = app(CreateScheduledActivityUseCase::class)->execute($this->company->id, $pubA->id, [
            'start_at' => '2026-11-03 10:00:00', 'end_at' => '2026-11-03 11:00:00', 'label' => 'Prestation',
        ]);
        $this->assertSame([$empA->id], $result['notify_employee_ids']);
        app(PlanningNotifier::class)->notifyChanged($this->company->id, $result['notify_employee_ids'], Carbon::parse('2026-11-03'));

        $this->assertSame(1, $this->published('workforce.planning_changed', $empA->user_id));
        $this->assertSame(0, $this->published('workforce.planning_changed', $empB->user_id));
    }

    public function test_publish_does_not_touch_timeentry(): void
    {
        $emp = $this->employee(true);
        $before = \App\Core\Workforce\TimeEntry::withoutGlobalScopes()->count();
        $published = app(PublishShiftsUseCase::class)->execute($this->company->id, [$this->draftShift($emp)->id]);
        app(PlanningNotifier::class)->notifyPublished($this->company->id, $published);
        $this->assertSame($before, \App\Core\Workforce\TimeEntry::withoutGlobalScopes()->count());
    }
}
