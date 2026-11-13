<?php

namespace App\Console\Commands;

use App\Company\Security\MembershipInvitation;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/**
 * M7 LOT 3 — traces the expiry of pending invitations in the audit log.
 *
 * Expiry itself is a pure function of `invitation_expires_at` (an expired invitation is refused
 * the very second it expires, with or without this task) ; this command only writes the audit
 * event once per invitation. Cross-tenant system task : every event is written with the
 * membership's OWN `company_id`. Idempotent : a second run finds nothing. Nothing is deleted.
 */
class MembersExpireInvitationsCommand extends Command implements Isolatable
{
    protected $signature = 'members:invitations-expire';

    protected $description = 'Trace (audit) the pending company invitations whose validity has passed.';

    public function handle(): int
    {
        $this->info('[members.invitations-expire] '.json_encode(['expired' => MembershipInvitation::traceExpired()]));

        return self::SUCCESS;
    }
}
