<?php

namespace App\Console\Commands;

use App\Core\Audit\AuditLogger;
use App\Modules\Workforce\Onboarding\Models\OnboardingInvitation as Inv;
use App\Modules\Workforce\Onboarding\Models\OnboardingValue;
use Illuminate\Console\Command;

/**
 * ADR-796 (L6) — retention of onboarding files. Expiry first (no deletion). Then, once a CLOSED file
 * (validated, expired, revoked) is older than its own retention period, its staged candidate values and
 * sessions are purged (validated values already live in the canonical store). DMS documents are never
 * deleted here (their own retention applies). Dry-run unless --apply ; every purge is audited.
 */
class OnboardingRetentionCommand extends Command
{
    protected $signature = 'onboarding:retention {--apply : actually purge}';

    protected $description = 'Expire onboarding links and purge staged candidate data after the configured retention';

    public function handle(): int
    {
        $expired = Inv::whereIn('status', Inv::CANDIDATE_WRITABLE)->where('expires_at', '<', now())->get();
        $due = Inv::whereIn('status', Inv::FINAL)->whereNull('purged_at')->get()
            ->filter(fn ($i) => ($i->validated_at ?? $i->revoked_at ?? $i->expires_at ?? $i->updated_at)->copy()->addDays($i->retention_days)->isPast());
        $this->line("To expire: {$expired->count()} · staged data to purge: {$due->count()}");
        if (! $this->option('apply')) {
            $this->line('Dry run — nothing changed. Add --apply.');

            return self::SUCCESS;
        }
        foreach ($expired as $i) {
            $i->forceFill(['status' => Inv::EXPIRED, 'token_hash' => null])->save();
            $i->sessions()->delete();
        }
        foreach ($due as $i) {
            $n = OnboardingValue::where('onboarding_invitation_id', $i->id)->delete();
            $i->sessions()->delete();
            $i->forceFill(['purged_at' => now(), 'code_hash' => null, 'token_hash' => null])->save();
            app(AuditLogger::class)->logCompany($i->company_id, 'onboarding.purged', 'onboarding_invitation', (string) $i->id, ['actorType' => 'system', 'metadata' => ['values_purged' => $n]]);
        }

        return self::SUCCESS;
    }
}
