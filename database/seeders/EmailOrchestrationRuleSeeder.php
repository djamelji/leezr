<?php

namespace Database\Seeders;

use App\Core\Email\EmailOrchestrationRule;
use Illuminate\Database\Seeder;

class EmailOrchestrationRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'trigger_event' => 'trial.started',
                'template_key' => 'billing.trial_started',
                'timing' => 'immediate',
                'delay_value' => 0,
                'delay_unit' => 'days',
                'conditions' => null,
                'max_sends' => 1,
            ],
            [
                'trigger_event' => 'trial.expiring',
                'template_key' => 'billing.trial_expiring',
                'timing' => 'immediate',
                'delay_value' => 0,
                'delay_unit' => 'days',
                'conditions' => null,
                'max_sends' => 1,
            ],
            [
                'trigger_event' => 'trial.converted',
                'template_key' => 'billing.trial_converted',
                'timing' => 'immediate',
                'delay_value' => 0,
                'delay_unit' => 'days',
                'conditions' => null,
                'max_sends' => 1,
            ],
            [
                'trigger_event' => 'invoice.created',
                'template_key' => 'billing.invoice_created',
                'timing' => 'immediate',
                'delay_value' => 0,
                'delay_unit' => 'days',
                'conditions' => null,
                'max_sends' => 1,
            ],
            [
                'trigger_event' => 'payment.received',
                'template_key' => 'billing.payment_received',
                'timing' => 'immediate',
                'delay_value' => 0,
                'delay_unit' => 'days',
                'conditions' => null,
                'max_sends' => 1,
            ],
            [
                'trigger_event' => 'payment.failed',
                'template_key' => 'billing.payment_failed',
                'timing' => 'immediate',
                'delay_value' => 0,
                'delay_unit' => 'days',
                'conditions' => ['retry_count' => 0],
                'max_sends' => 1,
            ],
            [
                'trigger_event' => 'card.expiring',
                'template_key' => 'billing.payment_method_expiring',
                'timing' => 'immediate',
                'delay_value' => 0,
                'delay_unit' => 'days',
                'conditions' => null,
                'max_sends' => 1,
            ],
            [
                'trigger_event' => 'plan.changed',
                'template_key' => 'billing.plan_changed',
                'timing' => 'immediate',
                'delay_value' => 0,
                'delay_unit' => 'days',
                'conditions' => null,
                'max_sends' => 1,
            ],
            [
                'trigger_event' => 'addon.activated',
                'template_key' => 'billing.addon_activated',
                'timing' => 'immediate',
                'delay_value' => 0,
                'delay_unit' => 'days',
                'conditions' => null,
                'max_sends' => 1,
            ],
            [
                'trigger_event' => 'account.suspended',
                'template_key' => 'billing.account_suspended',
                'timing' => 'immediate',
                'delay_value' => 0,
                'delay_unit' => 'days',
                'conditions' => null,
                'max_sends' => 1,
            ],
            [
                'trigger_event' => 'billing.critical',
                'template_key' => 'billing.critical_alert',
                'timing' => 'immediate',
                'delay_value' => 0,
                'delay_unit' => 'days',
                'conditions' => null,
                'max_sends' => 0,
            ],
        ];

        foreach ($rules as $index => $rule) {
            EmailOrchestrationRule::updateOrCreate(
                [
                    'template_key' => $rule['template_key'],
                    'trigger_event' => $rule['trigger_event'],
                ],
                array_merge($rule, [
                    'is_active' => true,
                    'sort_order' => $index,
                ]),
            );
        }
    }
}
