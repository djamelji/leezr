<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\Payment;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\NotificationTopicRegistry;
use App\Notifications\Billing\PaymentReceived;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ADR-781 (UX5) — who is told what when a subscription payment succeeds.
 *  - Customer company owner : « payment made / invoice paid » — never « payment received ».
 *  - Platform (seller) users with billing visibility : « payment collected ».
 *  - Nobody in another company is told anything.
 */
class Ux5PaymentNotificationAudienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        if (! \App\Core\Markets\Market::where('key', 'FR')->exists()) {
            \App\Core\Markets\Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        }
        NotificationTopicRegistry::boot();
        NotificationTopicRegistry::sync();
        \App\Company\RBAC\CompanyPermissionCatalog::sync();
        Notification::fake();
    }

    private function companyWithOwner(string $name): array
    {
        $c = Company::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(), 'market_key' => 'FR', 'address_country_code' => 'FR']);
        $owner = User::factory()->create();
        Membership::create(['user_id' => $owner->id, 'company_id' => $c->id, 'role' => 'owner']);

        return [$c, $owner];
    }

    private function platformUser(array $perms): PlatformUser
    {
        $role = PlatformRole::create(['key' => 'r_'.uniqid(), 'name' => 'R']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $perms)->pluck('id')->all());
        $u = PlatformUser::create(['first_name' => 'P', 'last_name' => 'U', 'email' => uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $u->roles()->attach($role->id);

        return $u;
    }

    private function invoice(Company $c): Invoice
    {
        return Invoice::create([
            'company_id' => $c->id, 'number' => 'F-'.uniqid(), 'subtotal' => 2500, 'tax_amount' => 500, 'tax_rate_bps' => 2000,
            'amount' => 3000, 'amount_due' => 0, 'wallet_credit_applied' => 0, 'currency' => 'EUR', 'status' => 'paid',
            'issued_at' => now(), 'finalized_at' => now(), 'paid_at' => now(),
        ]);
    }

    public function test_customer_owner_is_told_the_payment_was_made_never_received(): void
    {
        [$c, $owner] = $this->companyWithOwner('Client Co');
        $invoice = $this->invoice($c);

        // Same call as InvoiceIssuer / InvoiceAutoChargeService (unchanged billing flows).
        NotificationDispatcher::send(
            topicKey: 'billing.payment_received',
            recipients: [$owner],
            payload: ['invoice_id' => $invoice->id, 'amount' => '30,00 €'],
            company: $c,
            mailNotification: new PaymentReceived($invoice),
        );

        $event = NotificationEvent::where('recipient_id', $owner->id)->where('topic_key', 'billing.payment_received')->firstOrFail();
        $this->assertSame('Paiement effectué', $event->title);
        $this->assertStringContainsString('acquittée', $event->body);
        $this->assertStringNotContainsStringIgnoringCase('reçu', $event->title.' '.$event->body);
        $this->assertSame($c->id, $event->company_id);
    }

    public function test_payment_email_is_translated_and_worded_for_the_customer(): void
    {
        [$c, $owner] = $this->companyWithOwner('Mail Co');
        app()->setLocale('fr');
        $mail = (new PaymentReceived($this->invoice($c)))->toMail($owner);

        $this->assertStringStartsWith('Paiement confirmé', $mail->subject);
        $this->assertStringNotContainsString('email.billing', $mail->subject);
        $html = view($mail->view, $mail->viewData)->render();
        $this->assertStringNotContainsString('email.billing', $html, 'no raw translation key in the body');
        $this->assertStringContainsString('acquittée', $html);
    }

    public function test_platform_billing_users_are_told_the_payment_was_collected(): void
    {
        [$c, $owner] = $this->companyWithOwner('Payer Co');
        $billing = $this->platformUser(['view_dashboard', 'view_billing']);
        $support = $this->platformUser(['view_dashboard', 'manage_support']);
        $invoice = $this->invoice($c);

        Payment::create(['invoice_id' => $invoice->id, 'company_id' => $c->id, 'amount' => 3000, 'currency' => 'EUR', 'status' => 'succeeded', 'provider' => 'internal', 'provider_payment_id' => 'pay_'.uniqid()]);

        $collected = NotificationEvent::where('topic_key', 'platform.payment_collected')->get();
        $this->assertContains($billing->id, $collected->pluck('recipient_id')->all(), 'billing visibility receives it');
        $this->assertNotContains($support->id, $collected->pluck('recipient_id')->all(), 'no billing permission : nothing');
        $mine = $collected->firstWhere('recipient_id', $billing->id);
        $this->assertTrue($collected->every(fn ($e) => $e->recipient_type === (new PlatformUser)->getMorphClass()), 'platform users only');
        $this->assertSame('Paiement encaissé', $mine->title);
        $this->assertStringContainsString('Payer Co', $mine->body);
        $this->assertStringContainsString($invoice->number, $mine->body);
        $this->assertNull($mine->company_id, 'platform notice, not a company one');

        // The customer never receives the seller's notice.
        $this->assertSame(0, NotificationEvent::where('recipient_type', $owner->getMorphClass())->where('topic_key', 'platform.payment_collected')->count());
        $this->assertNotContains($support->id, $collected->pluck('recipient_id')->all());
    }

    public function test_collected_notice_fires_once_and_only_on_success(): void
    {
        [$c] = $this->companyWithOwner('Once Co');
        $billing = $this->platformUser(['view_dashboard', 'view_billing']);
        $mine = fn () => NotificationEvent::where('topic_key', 'platform.payment_collected')->where('recipient_id', $billing->id)->where('recipient_type', $billing->getMorphClass())->count();
        $invoice = $this->invoice($c);

        $p = Payment::create(['invoice_id' => $invoice->id, 'company_id' => $c->id, 'amount' => 3000, 'currency' => 'EUR', 'status' => 'pending', 'provider' => 'internal', 'provider_payment_id' => 'pay_'.uniqid()]);
        $this->assertSame(0, $mine());

        $p->update(['status' => 'succeeded']);
        $p->update(['provider_payment_id' => 'pay_changed']); // unrelated later save
        $this->assertSame(1, $mine());

        Payment::create(['invoice_id' => $invoice->id, 'company_id' => $c->id, 'amount' => 3000, 'currency' => 'EUR', 'status' => 'failed', 'provider' => 'internal', 'provider_payment_id' => 'pay_'.uniqid()]);
        $this->assertSame(1, $mine());
    }

    public function test_another_company_owner_is_never_notified(): void
    {
        [$a, $ownerA] = $this->companyWithOwner('Alpha Pay');
        [, $ownerB] = $this->companyWithOwner('Beta Pay');
        $invoice = $this->invoice($a);
        Payment::create(['invoice_id' => $invoice->id, 'company_id' => $a->id, 'amount' => 3000, 'currency' => 'EUR', 'status' => 'succeeded', 'provider' => 'internal', 'provider_payment_id' => 'pay_'.uniqid()]);
        NotificationDispatcher::send('billing.payment_received', [$ownerA], ['amount' => '30,00 €'], $a);

        $this->assertSame(0, NotificationEvent::where('recipient_type', $ownerB->getMorphClass())->where('recipient_id', $ownerB->id)->count());
    }
}
