<?php

namespace App\Core\Home;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleGate;
use Illuminate\Http\Request;

/**
 * ADR-776 (Company Experience 2.0 / UX1) — what a home contribution may know about the viewer :
 * the company of the request, the person, and their REAL capabilities (same rule as the
 * navigation : owner = every permission, everyone else = the permissions of their company role).
 */
final class HomeContext
{
    /** @param  array<string>|null  $permissions  null = owner (bypass) */
    public function __construct(
        public readonly Request $request,
        public readonly Company $company,
        public readonly User $user,
        private readonly ?array $permissions,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $company = $request->attributes->get('company');
        $user = $request->user();

        if ($user->isOwnerOf($company)) {
            return new self($request, $company, $user, null);
        }

        $membership = $user->membershipFor($company);
        $membership?->loadMissing('companyRole.permissions');

        return new self($request, $company, $user, $membership?->companyRole
            ? $membership->companyRole->effectivePermissionKeys() /* ADR-808 */
            : []);
    }

    public function can(string $permission): bool
    {
        return $this->permissions === null || in_array($permission, $this->permissions, true);
    }

    public function moduleActive(string $moduleKey): bool
    {
        return ModuleGate::isActive($this->company, $moduleKey);
    }
}
