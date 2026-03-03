<?php

namespace App\Modules\Platform\Jobdomains\UseCases;

use App\Core\Jobdomains\Jobdomain;
use Illuminate\Validation\ValidationException;

class DeleteJobdomainUseCase
{
    public function execute(int $id): void
    {
        $jobdomain = Jobdomain::withCount('companies')->findOrFail($id);

        if ($jobdomain->companies_count > 0) {
            throw ValidationException::withMessages([
                'jobdomain' => "Cannot delete: this job domain is assigned to {$jobdomain->companies_count} company(ies).",
            ]);
        }

        $jobdomain->delete();
    }
}
