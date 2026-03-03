<?php

namespace App\Modules\Platform\Jobdomains\UseCases;

use App\Core\Jobdomains\Jobdomain;

class CreateJobdomainUseCase
{
    public function execute(CreateJobdomainData $data): Jobdomain
    {
        if (!empty($data->defaultFields)) {
            JobdomainPresetValidator::validateDefaultFields($data->defaultFields);
        }

        $jobdomain = Jobdomain::create([
            'key' => $data->key,
            'label' => $data->label,
            'description' => $data->description,
            'is_active' => true,
            'default_modules' => $data->defaultModules,
            'default_fields' => $data->defaultFields,
        ]);

        $jobdomain->loadCount('companies');

        return $jobdomain;
    }
}
