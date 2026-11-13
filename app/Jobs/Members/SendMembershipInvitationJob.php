<?php

namespace App\Jobs\Members;

use App\Company\Security\MembershipInvitation;
use App\Core\Models\Membership;
use App\Core\Tenancy\InteractsWithTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * M7 LOT 3 — sends the invitation e-mail of a membership and RECORDS the outcome on it
 * (`sent` / `failed` + error). A mail transport failure never bubbles up to the HTTP request that
 * created the invitation: it is persisted, logged, audited, retried by the queue (3 attempts with
 * backoff) and can be re-sent manually. Tenant job: runs under the membership's company.
 */
class SendMembershipInvitationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, InteractsWithTenant, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(int $companyId, public int $membershipId, public ?int $actorId = null)
    {
        $this->onTenant($companyId);
    }

    public function handle(): void
    {
        $this->runInTenant(function () {
            $membership = Membership::with('user', 'company')->whereKey($this->membershipId)->get()->first();
            if (! $membership) {
                return; // invitation cancelled / declined meanwhile
            }

            $error = MembershipInvitation::sendNow($membership, $this->actorId);

            // Asynchronous worker : let the queue retry a failed transport (the state stays `failed`
            // until an attempt succeeds). On the `sync` connection there is nothing to release to.
            if ($error !== null && $this->job && $this->job->getConnectionName() !== 'sync') {
                if ($this->attempts() < $this->tries) {
                    $this->release($this->backoff[$this->attempts() - 1] ?? 300);
                } else {
                    // M7 LOT 4 : last attempt — the job is recorded in `failed_jobs` (visible to operations,
                    // replayable with `queue:retry`) ; the membership already says `failed`.
                    $this->fail(new \RuntimeException('Invitation e-mail not delivered after '.$this->tries.' attempts: '.$error));
                }
            }
        });
    }
}
