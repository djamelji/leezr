<?php

namespace App\Modules\Platform\Fields\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Fields\FieldDefinition;
use Illuminate\Auth\Access\AuthorizationException;

class DeletePlatformFieldUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(int $id): void
    {
        $definition = FieldDefinition::whereNull('company_id')->findOrFail($id);

        if ($definition->is_system) {
            throw new AuthorizationException('Cannot delete a system field.');
        }

        $this->audit->logPlatform(
            AuditAction::FIELD_DELETED, 'field_definition', (string) $definition->id,
            ['diffBefore' => $definition->only('code', 'scope', 'label', 'type')],
        );

        $definition->delete();
    }
}
