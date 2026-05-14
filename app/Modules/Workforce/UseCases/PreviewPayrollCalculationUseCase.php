<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Workforce\PayrollCalculationEngine;
use App\Core\Workforce\PayrollLine;

class PreviewPayrollCalculationUseCase
{
    public function __construct(
        private PayrollCalculationEngine $engine
    ) {}

    /**
     * Preview calculation for a PayrollLine.
     * ZERO DB writes, ZERO audit trail.
     */
    public function execute(PayrollLine $line): array
    {
        return $this->engine->preview($line);
    }
}
