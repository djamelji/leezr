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
        // ADR-796.1 : l'expiration est constatée par OnboardingLifecycleService (onboarding:expire) ; ici,
        // seulement la purge des données en attente des dossiers CLOS, après leur propre délai.
        $expired = app(\App\Modules\Workforce\Onboarding\OnboardingLifecycleService::class)->due();
        $due = Inv::whereIn('status', Inv::FINAL)->whereNull('purged_at')->get()
            ->filter(fn ($i) => ($i->validated_at ?? $i->revoked_at ?? $i->expires_at ?? $i->expired_at ?? $i->updated_at)->copy()->addDays($i->retention_days)->isPast());
        $this->line("To expire: {$expired->count()} · staged data to purge: {$due->count()}");
        if (! $this->option('apply')) {
            $this->line('Dry run — nothing changed. Add --apply.');

            return self::SUCCESS;
        }
        app(\App\Modules\Workforce\Onboarding\OnboardingLifecycleService::class)->expireDue();
        foreach ($due as $i) {
            $n = OnboardingValue::where('onboarding_invitation_id', $i->id)->delete();
            // Dépôts jamais entrés au DMS (quarantaine, refusés, indéterminés) : fichiers supprimés, trace conservée.
            $files = 0;
            foreach (\App\Modules\Workforce\Onboarding\Models\OnboardingUpload::where('onboarding_invitation_id', $i->id)->whereNull('files_deleted_at')->get() as $up) {
                \App\Modules\Workforce\Onboarding\OnboardingUploadScanService::deleteFiles($up);
                $files++;
            }
            $i->sessions()->delete();
            $i->forceFill(['purged_at' => now(), 'code_hash' => null, 'token_hash' => null])->save();
            app(AuditLogger::class)->logCompany($i->company_id, 'onboarding.purged', 'onboarding_invitation', (string) $i->id, ['actorType' => 'system', 'metadata' => ['values_purged' => $n, 'quarantine_deposits_purged' => $files]]);
        }

        return self::SUCCESS;
    }
}
