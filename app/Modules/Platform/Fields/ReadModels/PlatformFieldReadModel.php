<?php

namespace App\Modules\Platform\Fields\ReadModels;

use App\Core\Fields\FieldDefinition;

class PlatformFieldReadModel
{
    public static function catalog(?string $scope = null): array
    {
        $query = FieldDefinition::whereNull('company_id')
            ->orderBy('scope')
            ->orderBy('default_order');

        if ($scope) {
            $query->where('scope', $scope);
        }

        return $query->get()->toArray();
    }
}
