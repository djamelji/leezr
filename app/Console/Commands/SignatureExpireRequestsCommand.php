<?php

namespace App\Console\Commands;

use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\UseCases\ExpireSignatureRequestsUseCase;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/**
 * ADR-767 — scheduled entry point of `ExpireSignatureRequestsUseCase` (it existed since ADR-651 but had
 * no command, no schedule and no caller: overdue signature requests stayed active forever).
 *
 * Cross-tenant by design (system job, no tenant bound): the use case reads `withoutGlobalScopes()` and
 * writes every event/audit with the request's OWN `company_id`. Idempotent: a second run finds nothing.
 */
class SignatureExpireRequestsCommand extends Command implements Isolatable
{
    protected $signature = 'workforce:signature-expire {--dry-run : Count the overdue requests without expiring them}';

    protected $description = 'Expire active signature requests whose deadline has passed (revokes pending signing links).';

    public function handle(ExpireSignatureRequestsUseCase $useCase): int
    {
        if ($this->option('dry-run')) {
            $due = SignatureRequest::withoutGlobalScopes()
                ->whereNotIn('status', SignatureRequest::TERMINAL_STATUSES)
                ->whereNotNull('expires_at')->where('expires_at', '<', now())->count();
            $this->info('[signature.expire] '.json_encode(['dry_run' => true, 'due' => $due]));

            return self::SUCCESS;
        }

        $this->info('[signature.expire] '.json_encode(['expired' => $useCase->execute()]));

        return self::SUCCESS;
    }
}
