<?php

namespace App\Modules\Logistics\Shipments\ReadModels;

use App\Core\Models\Company;
use App\Core\Models\Shipment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ShipmentReadModel
{
    /**
     * Paginated list of shipments for a company with optional filters.
     */
    public static function list(
        Company $company,
        ?string $status = null,
        ?string $search = null,
        ?int $assignedToUserId = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = Shipment::where('company_id', $company->id)
            ->with('createdBy:id,first_name,last_name', 'assignedTo:id,first_name,last_name')
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where('reference', 'like', "%{$search}%");
        }

        if ($assignedToUserId !== null) {
            $query->where('assigned_to_user_id', $assignedToUserId);
        }

        $page = $query->paginate($perPage);
        self::withDriverIdentifier($company, $page->getCollection());

        return $page;
    }

    /**
     * ADR-820 — the driver identifier is READ from the company's own field bound to the module need
     * (never copied, absent while the company has not bound it).
     */
    public static function withDriverIdentifier(Company $company, iterable $shipments): void
    {
        $needs = new \App\Core\Modules\Data\ModuleDataNeeds($company);
        if (! $needs->mapping('logistics_shipments', 'driver_identifier')) {
            return;
        }
        $cache = [];
        foreach ($shipments as $s) {
            $uid = $s->assigned_to_user_id;
            $s->setAttribute('driver_identifier', $uid ? ($cache[$uid] ??= $needs->valueFor('logistics_shipments', 'driver_identifier', (int) $uid)) : null);
        }
    }

    /**
     * ADR-822 — people who can receive a shipment, with the driver identifier the company bound to the module
     * need (read, never copied). `identifier_required` is true only once the company bound the need : a missing
     * value then blocks ONLY the assignment of that person (ModuleDataNeeds::assertAvailable), nothing else.
     *
     * @return array{identifier_required: bool, identifier_label: ?string, people: list<array{user_id: int, name: string, role: ?string, driver_identifier: ?string}>}
     */
    public static function assignees(Company $company): array
    {
        $needs = new \App\Core\Modules\Data\ModuleDataNeeds($company);
        $mapping = $needs->mapping('logistics_shipments', 'driver_identifier');
        $memberships = \App\Core\Models\Membership::where('company_id', $company->id)
            ->with(['user:id,first_name,last_name,email', 'companyRole:id,name,is_administrative'])->get()
            ->filter(fn ($m) => $m->user && $m->role !== 'owner');
        $values = [];
        if ($mapping) {
            $field = \App\Core\Fields\FieldDefinition::find($mapping->field_definition_id);
            $employees = \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $company->id)
                ->whereIn('user_id', $memberships->pluck('user_id'))->get();
            $raw = $field && $employees->isNotEmpty() ? (new \App\Core\Fields\EmployeeFieldStore($company))->rawValues($employees, [$field->code]) : [];
            foreach ($employees as $e) {
                $v = $raw[$e->id][$field->code] ?? null;
                $values[$e->user_id] = $v === null || $v === '' ? null : (string) $v;
            }
        }

        return [
            'identifier_required' => (bool) $mapping,
            'identifier_label' => $mapping ? \App\Core\Fields\FieldDefinition::find($mapping->field_definition_id)?->resolvedLabel(app()->getLocale()) : null,
            'people' => $memberships->map(fn ($m) => [
                'user_id' => $m->user_id, 'name' => $m->user->display_name, 'role' => $m->companyRole?->name,
                'driver_identifier' => $values[$m->user_id] ?? null,
            ])->sortBy('name')->values()->all(),
        ];
    }

    /**
     * Single shipment by ID, scoped to company.
     */
    public static function find(Company $company, int $id): Shipment
    {
        $shipment = Shipment::where('company_id', $company->id)
            ->with('createdBy:id,first_name,last_name', 'assignedTo:id,first_name,last_name')
            ->findOrFail($id);
        self::withDriverIdentifier($company, [$shipment]);

        return $shipment;
    }
}
