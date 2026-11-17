<?php

namespace App\Console\Commands;

use App\Modules\Workforce\Referral\ReferralService;
use Illuminate\Console\Command;

/** ADR-819 — retention of Workforce Parrainage : anonymise closed / expired referrals past their purge date. */
class PurgeReferralsCommand extends Command
{
    protected $signature = 'referrals:purge';

    protected $description = 'Anonymise referrals past their retention period (identity removed, history kept).';

    public function handle(): int
    {
        $this->info(ReferralService::purgeDue().' referral(s) anonymised.');

        return self::SUCCESS;
    }
}
