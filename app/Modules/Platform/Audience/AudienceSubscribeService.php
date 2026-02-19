<?php

namespace App\Modules\Platform\Audience;

use Illuminate\Support\Facades\DB;

final class AudienceSubscribeService
{
    /**
     * Subscribe an email to a mailing list.
     *
     * @return array{status: string, needs_confirmation: bool, token?: string}
     */
    public static function handle(string $listSlug, string $email, ?string $ip = null, ?string $userAgent = null): array
    {
        $list = MailingList::where('slug', $listSlug)->where('is_enabled', true)->first();

        if (! $list) {
            abort(404, 'Mailing list not found.');
        }

        return DB::transaction(function () use ($list, $email, $ip, $userAgent) {
            $subscriber = Subscriber::firstOrCreate(
                ['email' => $email],
                [
                    'status' => 'pending',
                    'metadata' => [
                        'ip' => $ip,
                        'user_agent' => $userAgent,
                        'subscribed_at' => now()->toIso8601String(),
                    ],
                ],
            );

            $subscription = MailingListSubscription::firstOrCreate(
                ['list_id' => $list->id, 'subscriber_id' => $subscriber->id],
                ['status' => 'pending'],
            );

            if ($subscription->status === 'confirmed') {
                return ['status' => 'already_subscribed', 'needs_confirmation' => false];
            }

            if ($list->double_opt_in) {
                $rawToken = bin2hex(random_bytes(32));

                AudienceToken::create([
                    'subscriber_id' => $subscriber->id,
                    'list_id' => $list->id,
                    'type' => 'confirm',
                    'token_hash' => hash('sha256', $rawToken),
                    'expires_at' => now()->addHours(48),
                ]);

                return ['status' => 'pending', 'needs_confirmation' => true, 'token' => $rawToken];
            }

            // Single opt-in — confirm immediately
            $subscription->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            $subscriber->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            return ['status' => 'confirmed', 'needs_confirmation' => false];
        });
    }
}
