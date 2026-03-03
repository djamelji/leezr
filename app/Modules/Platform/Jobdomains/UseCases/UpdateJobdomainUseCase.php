<?php

namespace App\Modules\Platform\Jobdomains\UseCases;

use App\Core\Jobdomains\Jobdomain;

class UpdateJobdomainUseCase
{
    public function execute(UpdateJobdomainData $data): Jobdomain
    {
        $jobdomain = Jobdomain::findOrFail($data->id);

        if (isset($data->attributes['default_fields'])) {
            JobdomainPresetValidator::validateDefaultFields($data->attributes['default_fields']);
        }

        if (isset($data->attributes['default_roles'])) {
            JobdomainPresetValidator::validateDefaultRoles($data->attributes['default_roles']);
        }

        if (isset($data->attributes['default_documents'])) {
            JobdomainPresetValidator::validateDefaultDocuments($data->attributes['default_documents']);
        }

        $jobdomain->update($data->attributes);
        $jobdomain->loadCount('companies');

        return $jobdomain;
    }
}
