<?php

namespace App\Modules\Platform\Audience;

use Illuminate\Support\Facades\DB;

final class AudienceConfirmService
{
    /**
     * Confirm a subscription via token.
     *
     * @return array{status: string, email?: string, list_name?: string}
     */
    public static function handle(string $rawToken): array
    {
        $tokenHash = hash('sha256', $rawToken);

        $token = AudienceToken::valid()
            ->where('token_hash', $tokenHash)
            ->where('type', 'confirm')
            ->first();

        if (! $token) {
            return ['status' => 'invalid'];
        }

        return DB::transaction(function () use ($token) {
            $token->update(['used_at' => now()]);

            $subscription = MailingListSubscription::where('list_id', $token->list_id)
                ->where('subscriber_id', $token->subscriber_id)
                ->first();

            if ($subscription) {
                $subscription->update([
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                ]);
            }

            $subscriber = $token->subscriber;
            $subscriber->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            return [
                'status' => 'confirmed',
                'email' => $subscriber->email,
                'list_name' => $token->mailingList->name,
            ];
        });
    }
}
