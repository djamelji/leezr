<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\Shift;

class CancelShiftUseCase
{
    public function execute(Shift $shift, ?string $reason = null): Shift
    {
        // I7: Only draft or published can be cancelled
        $shift->transitionTo(Shift::STATUS_CANCELLED);
        $shift->cancelled_at = now();
        $shift->cancelled_reason = $reason;
        $shift->save();

        app(AuditLogger::class)->logCompany(
            companyId: $shift->company_id,
            action: 'shift.cancelled',
            targetType: 'shift',
            targetId: (string) $shift->id,
            options: [
                'metadata' => [
                    'category' => 'workforce.planning',
                    'employee_id' => $shift->employee_id,
                    'date' => $shift->date->toDateString(),
                    'reason' => $reason,
                ],
            ],
        );

        return $shift;
    }
}
