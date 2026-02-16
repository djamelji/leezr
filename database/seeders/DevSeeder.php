<?php

namespace Database\Seeders;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Dev seeder — demo data for local development only.
 * 100% idempotent via updateOrCreate with unique keys.
 * NEVER executed in production (gated by DatabaseSeeder).
 */
class DevSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ─── Platform scope — demo platform admin ────────────────
        $platformUser = PlatformUser::updateOrCreate(
            ['email' => 'platform@leezr.test'],
            [
                'first_name' => 'Platform',
                'last_name' => 'Admin',
                'password' => 'password',
            ],
        );

        $adminRole = PlatformRole::where('key', 'admin')->first();
        if ($adminRole && !$platformUser->hasRole('admin')) {
            $platformUser->roles()->attach($adminRole->id);
        }

        // ─── Company scope — demo company + users ────────────────
        $owner = User::updateOrCreate(
            ['email' => 'owner@leezr.test'],
            [
                'first_name' => 'Djamel',
                'last_name' => '',
                'password' => 'password',
                'password_set_at' => now(),
            ],
        );

        $company = Company::updateOrCreate(
            ['slug' => 'leezr-logistics'],
            ['name' => 'Leezr Logistics'],
        );

        $company->memberships()->updateOrCreate(
            ['user_id' => $owner->id],
            ['role' => 'owner'],
        );

        $admin = User::updateOrCreate(
            ['email' => 'admin@leezr.test'],
            [
                'first_name' => 'Alice',
                'last_name' => 'Martin',
                'password' => 'password',
                'password_set_at' => now(),
            ],
        );

        $company->memberships()->updateOrCreate(
            ['user_id' => $admin->id],
            ['role' => 'admin'],
        );

        $user = User::updateOrCreate(
            ['email' => 'bob@leezr.test'],
            [
                'first_name' => 'Bob',
                'last_name' => 'Dupont',
                'password' => 'password',
                'password_set_at' => now(),
            ],
        );

        $company->memberships()->updateOrCreate(
            ['user_id' => $user->id],
            ['role' => 'user'],
        );

        // ─── Module activation for demo company ──────────────────
        foreach (ModuleRegistry::definitions() as $key => $definition) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->id, 'module_key' => $key],
                ['is_enabled_for_company' => true],
            );
        }

        // ─── Jobdomain assignment (seeds default roles + permissions) ────
        // Note: CompanyPermissionCatalog::sync() already ran in SystemSeeder
        JobdomainGate::assignToCompany($company, 'logistique');

        // ─── Assign RBAC roles to demo members ──────────────────
        $adminRole = CompanyRole::where('company_id', $company->id)
            ->where('key', 'admin')->first();

        if ($adminRole) {
            $company->memberships()
                ->where('user_id', $admin->id)
                ->update(['company_role_id' => $adminRole->id]);
        }

        $viewerRole = CompanyRole::where('company_id', $company->id)
            ->where('key', 'viewer')->first();

        if ($viewerRole) {
            $company->memberships()
                ->where('user_id', $user->id)
                ->update(['company_role_id' => $viewerRole->id]);
        }

        // ─── Field activations for demo company ──────────────────
        $companyFields = FieldDefinition::whereNull('company_id')
            ->where('scope', FieldDefinition::SCOPE_COMPANY)->get();
        foreach ($companyFields as $index => $field) {
            FieldActivation::updateOrCreate(
                ['company_id' => $company->id, 'field_definition_id' => $field->id],
                ['enabled' => true, 'required_override' => $field->code === 'siret', 'order' => $index * 10],
            );
        }

        $companyUserFields = FieldDefinition::whereNull('company_id')
            ->where('scope', FieldDefinition::SCOPE_COMPANY_USER)->get();
        foreach ($companyUserFields as $index => $field) {
            FieldActivation::updateOrCreate(
                ['company_id' => $company->id, 'field_definition_id' => $field->id],
                ['enabled' => true, 'required_override' => false, 'order' => $index * 10],
            );
        }

        $platformUserFields = FieldDefinition::whereNull('company_id')
            ->where('scope', FieldDefinition::SCOPE_PLATFORM_USER)->get();
        foreach ($platformUserFields as $index => $field) {
            FieldActivation::updateOrCreate(
                ['company_id' => null, 'field_definition_id' => $field->id],
                ['enabled' => true, 'required_override' => false, 'order' => $index * 10],
            );
        }

        // ─── Sample field values ───────────────────────────────
        $siret = FieldDefinition::where('code', 'siret')->first();
        if ($siret) {
            FieldValue::updateOrCreate(
                ['field_definition_id' => $siret->id, 'model_type' => 'company', 'model_id' => $company->id],
                ['value' => '12345678901234'],
            );
        }

        $phone = FieldDefinition::where('code', 'phone')->first();
        if ($phone) {
            FieldValue::updateOrCreate(
                ['field_definition_id' => $phone->id, 'model_type' => 'user', 'model_id' => $owner->id],
                ['value' => '+33 6 12 34 56 78'],
            );
        }

        // ─── Sample shipments (stable references for idempotency) ─
        Shipment::updateOrCreate(
            ['company_id' => $company->id, 'reference' => 'SHP-DEMO-0001'],
            [
                'created_by_user_id' => $owner->id,
                'status' => Shipment::STATUS_DRAFT,
                'origin_address' => '12 Rue de Paris, 75001 Paris',
                'destination_address' => '45 Avenue des Champs, 69001 Lyon',
                'scheduled_at' => now()->addDays(2),
                'notes' => 'Fragile goods — handle with care',
            ],
        );

        Shipment::updateOrCreate(
            ['company_id' => $company->id, 'reference' => 'SHP-DEMO-0002'],
            [
                'created_by_user_id' => $owner->id,
                'status' => Shipment::STATUS_PLANNED,
                'origin_address' => '8 Boulevard Haussmann, 75009 Paris',
                'destination_address' => '22 Rue de la Gare, 33000 Bordeaux',
                'scheduled_at' => now()->addDays(5),
            ],
        );

        Shipment::updateOrCreate(
            ['company_id' => $company->id, 'reference' => 'SHP-DEMO-0003'],
            [
                'created_by_user_id' => $admin->id,
                'status' => Shipment::STATUS_IN_TRANSIT,
                'origin_address' => '3 Place Bellecour, 69002 Lyon',
                'destination_address' => '15 Quai des Belges, 13001 Marseille',
                'scheduled_at' => now()->subDay(),
            ],
        );

        Shipment::updateOrCreate(
            ['company_id' => $company->id, 'reference' => 'SHP-DEMO-0004'],
            [
                'created_by_user_id' => $owner->id,
                'status' => Shipment::STATUS_DELIVERED,
                'origin_address' => '1 Rue du Port, 44000 Nantes',
                'destination_address' => '10 Allée du Commerce, 31000 Toulouse',
                'scheduled_at' => now()->subDays(3),
            ],
        );
    }
}
