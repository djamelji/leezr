<?php

namespace App\Modules\Logistics\Shipments\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-818 (chantier H) — PLANNING of a shipment after its creation : date, addresses, notes. Only while it is
 * not on the road (draft / planned) ; the status and the assignment keep their own paths (ADR-807). Audited.
 */
final class RescheduleShipment
{
    public const EDITABLE = [Shipment::STATUS_DRAFT, Shipment::STATUS_PLANNED];

    public static function handle(Company $company, User $user, int $id, array $data): Shipment
    {
        return DB::transaction(function () use ($company, $user, $id, $data) {
            $shipment = Shipment::withoutGlobalScopes()->where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            if (! in_array($shipment->status, self::EDITABLE, true)) {
                throw ValidationException::withMessages(['status' => __('shipments.not_editable')]);
            }
            $before = $shipment->only(array_keys($data));
            $shipment->fill($data)->save();
            app(AuditLogger::class)->logCompany($company->id, 'shipment.rescheduled', 'shipment', (string) $shipment->id, [
                'actorId' => $user->id, 'actorType' => 'user', 'metadata' => ['before' => $before, 'after' => $shipment->only(array_keys($data))],
            ]);

            return $shipment;
        });
    }
}
