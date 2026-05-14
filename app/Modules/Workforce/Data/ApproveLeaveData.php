<?php

namespace App\Modules\Workforce\Data;

class ApproveLeaveData
{
    public function __construct(
        public readonly int $leaveRequestId,
        public readonly int $reviewerId,
        public readonly ?string $reviewNote = null,
    ) {}
}
