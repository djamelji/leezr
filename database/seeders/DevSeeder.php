<?php

namespace Database\Seeders;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\MemberDocument;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\EntitlementResolver;
use App\Core\Modules\ModuleRegistry;
use App\Core\Ai\PlatformAiModule;
use App\Core\Modules\PlatformModule;
use App\Platform\Models\PlatformFontFamily;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformSetting;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

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
        // ─── Notification topics (system-level registry sync) ────
        $this->call(NotificationTopicSeeder::class);

        // ─── Platform scope — demo platform admin ────────────────
        $platformUser = PlatformUser::updateOrCreate(
            ['email' => 'admin@leezr.com'],
            [
                'first_name' => 'Djamel',
                'last_name' => 'Ji',
                'password' => 'password',
            ],
        );

        $adminRole = PlatformRole::where('key', 'admin')->first();
        if ($adminRole && !$platformUser->hasRole('admin')) {
            $platformUser->roles()->attach($adminRole->id);
        }

        // ─── Typography — ensure Poppins Google Font (ADR-073) ─────
        PlatformSetting::instance()->update([
            'typography' => [
                'active_source' => 'google',
                'active_family_id' => null,
                'google_fonts_enabled' => true,
                'google_active_family' => 'Poppins',
                'google_weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900],
                'headings_family_id' => null,
                'body_family_id' => null,
            ],
        ]);

        PlatformFontFamily::firstOrCreate(
            ['slug' => 'poppins'],
            ['name' => 'Poppins', 'source' => 'google', 'is_enabled' => true],
        );

        // ─── AI Providers (ADR-413) ──────────────────────────────
        // Anthropic Claude — requires API credits
        PlatformAiModule::updateOrCreate(
            ['provider_key' => 'anthropic'],
            [
                'name' => 'Anthropic',
                'description' => 'Anthropic API (Claude). Requires API key.',
                'is_installed' => true,
                'is_active' => false,
                'credentials' => [
                    'api_key' => env('ANTHROPIC_API_KEY', ''),
                    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
                    'timeout' => 60,
                ],
                'config' => [],
                'health_status' => 'unknown',
                'sort_order' => 10,
            ],
        );

        // Ollama — self-hosted (primary for VPS / Linux)
        PlatformAiModule::updateOrCreate(
            ['provider_key' => 'ollama'],
            [
                'name' => 'Ollama',
                'description' => 'Self-hosted AI inference via Ollama',
                'is_installed' => true,
                'is_active' => true,
                'credentials' => [
                    'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
                    'model' => env('OLLAMA_MODEL', 'moondream'),
                    'vision_model' => env('OLLAMA_VISION_MODEL', 'moondream'),
                    'timeout' => 120,
                ],
                'config' => [
                    'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
                ],
                'health_status' => 'unknown',
                'sort_order' => 1,
            ],
        );

        // ─── AI Capability Routing — assign Ollama to all capabilities ──
        $settings = PlatformSetting::instance();
        $ai = $settings->ai ?? [];
        $ai['routing'] = [
            'vision' => 'ollama',
            'completion' => 'ollama',
            'text_extraction' => 'ollama',
        ];
        $settings->update(['ai' => $ai]);

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
            ['name' => 'Leezr Logistics', 'plan_key' => 'pro', 'market_key' => 'FR', 'jobdomain_key' => 'logistique', 'legal_status_key' => 'sas'],
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

        $user = User::updateOrCreate(
            ['email' => 'bob@leezr.test'],
            [
                'first_name' => 'Bob',
                'last_name' => 'Dupont',
                'password' => 'password',
                'password_set_at' => now(),
            ],
        );

        // ─── Subscription for demo company ────────────────────────
        Subscription::updateOrCreate(
            ['company_id' => $company->id, 'plan_key' => 'pro'],
            [
                'interval' => 'monthly',
                'status' => 'active',
                'provider' => 'internal',
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->endOfMonth(),
            ],
        );

        // ─── Trial company — ADR-287 ────────────────────────────
        $trialOwner = User::updateOrCreate(
            ['email' => 'trial@leezr.test'],
            [
                'first_name' => 'Claire',
                'last_name' => 'Essai',
                'password' => 'password',
                'password_set_at' => now(),
            ],
        );

        $trialCompany = Company::updateOrCreate(
            ['slug' => 'trial-logistics'],
            ['name' => 'Trial Logistics', 'plan_key' => 'pro', 'market_key' => 'FR', 'jobdomain_key' => 'logistique', 'legal_status_key' => 'sas'],
        );

        $trialCompany->memberships()->updateOrCreate(
            ['user_id' => $trialOwner->id],
            ['role' => 'owner'],
        );

        Subscription::updateOrCreate(
            ['company_id' => $trialCompany->id, 'plan_key' => 'pro'],
            [
                'interval' => 'monthly',
                'status' => 'trialing',
                'provider' => 'internal',
                'is_current' => 1,
                'current_period_start' => now(),
                'current_period_end' => now()->addDays(7),
                'trial_ends_at' => now()->addDays(7),
            ],
        );

        JobdomainGate::assignToCompany($trialCompany, 'logistique');

        // Activate modules for trial company
        foreach (array_keys(ModuleRegistry::forScope('company')) as $key) {
            $entitlement = EntitlementResolver::check($trialCompany, $key);
            CompanyModule::updateOrCreate(
                ['company_id' => $trialCompany->id, 'module_key' => $key],
                ['is_enabled_for_company' => $entitlement['entitled']],
            );
        }

        // ─── Module activation for demo company ──────────────────
        // Only activate entitled modules (respects jobdomain + plan gates)
        $companyModuleKeys = array_keys(ModuleRegistry::forScope('company'));

        foreach ($companyModuleKeys as $key) {
            $entitlement = EntitlementResolver::check($company, $key);

            CompanyModule::updateOrCreate(
                ['company_id' => $company->id, 'module_key' => $key],
                ['is_enabled_for_company' => $entitlement['entitled']],
            );
        }

        // Cleanup: remove any non-company-scope modules that were
        // incorrectly activated in previous runs
        CompanyModule::where('company_id', $company->id)
            ->whereNotIn('module_key', $companyModuleKeys)
            ->delete();

        // ─── Module commercial config (productization seeds) ────────────
        $this->seedModuleConfigs();

        // ─── Jobdomain assignment (seeds default roles + permissions) ────
        // Note: CompanyPermissionCatalog::sync() already ran in SystemSeeder
        // Must run BEFORE creating non-owner memberships (creates company roles)
        JobdomainGate::assignToCompany($company, 'logistique');

        // ─── Create non-owner memberships WITH company_role_id ──────────
        // DB trigger enforces: non-owner membership must have company_role_id
        $dispatcherRole = CompanyRole::where('company_id', $company->id)
            ->where('key', 'dispatcher')->first();

        $driverRole = CompanyRole::where('company_id', $company->id)
            ->where('key', 'driver')->first();

        if ($dispatcherRole) {
            $company->memberships()->updateOrCreate(
                ['user_id' => $admin->id],
                ['role' => 'user', 'company_role_id' => $dispatcherRole->id],
            );
        }

        if ($driverRole) {
            $company->memberships()->updateOrCreate(
                ['user_id' => $user->id],
                ['role' => 'user', 'company_role_id' => $driverRole->id],
            );
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
        $companyFieldValues = [
            'siret' => '84726451300012',
            'vat_number' => 'FR32847264513',
            'legal_name' => 'Leezr Logistics SAS',
            'legal_form' => 'SAS',
            'company_address' => '15 Rue de la Logistique',
            'company_complement' => 'Bâtiment B, 2e étage',
            'company_city' => 'Lyon',
            'company_postal_code' => '69003',
            'company_region' => 'Auvergne-Rhône-Alpes',
            'company_phone' => '+33 4 72 00 12 34',
            'billing_address' => '15 Rue de la Logistique',
            'billing_complement' => 'Bâtiment B, 2e étage',
            'billing_city' => 'Lyon',
            'billing_postal_code' => '69003',
            'billing_region' => 'Auvergne-Rhône-Alpes',
            'billing_email' => 'facturation@leezr-logistics.fr',
        ];

        foreach ($companyFieldValues as $code => $value) {
            $def = FieldDefinition::where('code', $code)->whereNull('company_id')->first();
            if ($def) {
                FieldValue::updateOrCreate(
                    ['field_definition_id' => $def->id, 'model_type' => 'company', 'model_id' => $company->id],
                    ['value' => $value],
                );
            }
        }

        $phone = FieldDefinition::where('code', 'phone')->first();
        if ($phone) {
            FieldValue::updateOrCreate(
                ['field_definition_id' => $phone->id, 'model_type' => 'user', 'model_id' => $owner->id],
                ['value' => '+33 6 12 34 56 78'],
            );
        }

        // ─── Document type activations for demo company (ADR-419) ──
        $this->seedDocumentData($company, $owner, $admin, $user);

        // ─── Pending approval company — ADR-289 ──────────────────
        // ADR-301: admin_approval is for upgrades only, not registration
        // The pending company's subscription is seeded directly with status='pending'
        PlatformBillingPolicy::instance()->update(['admin_approval_required' => false]);

        $pendingOwner = User::updateOrCreate(
            ['email' => 'pending@leezr.test'],
            [
                'first_name' => 'Marie',
                'last_name' => 'Duval',
                'password' => 'password',
                'password_set_at' => now(),
            ],
        );

        $pendingCompany = Company::updateOrCreate(
            ['slug' => 'pending-approval-co'],
            ['name' => 'Pending Approval Co', 'plan_key' => 'starter', 'market_key' => 'FR', 'jobdomain_key' => 'logistique', 'legal_status_key' => 'sarl'],
        );

        $pendingCompany->memberships()->updateOrCreate(
            ['user_id' => $pendingOwner->id],
            ['role' => 'owner'],
        );

        Subscription::updateOrCreate(
            ['company_id' => $pendingCompany->id, 'plan_key' => 'business'],
            [
                'interval' => 'monthly',
                'status' => 'pending',
                'provider' => 'internal',
                'current_period_start' => null,
                'current_period_end' => null,
            ],
        );

        JobdomainGate::assignToCompany($pendingCompany, 'logistique');

        foreach (array_keys(ModuleRegistry::forScope('company')) as $key) {
            $entitlement = EntitlementResolver::check($pendingCompany, $key);
            CompanyModule::updateOrCreate(
                ['company_id' => $pendingCompany->id, 'module_key' => $key],
                ['is_enabled_for_company' => $entitlement['entitled']],
            );
        }

        // Billing demo data is handled by FinanceDemoSeeder (called from DatabaseSeeder)

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
                'assigned_to_user_id' => $user->id,
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
                'assigned_to_user_id' => $user->id,
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

    private function seedModuleConfigs(): void
    {
        PlatformModule::where('key', 'logistics_tracking')->update([
            'display_name_override' => 'Real-Time Tracking',
            'description_override' => 'Track shipments in real time with live geolocation updates.',
            'icon_type' => 'tabler',
            'icon_name' => 'tabler-map-pin',
            'is_listed' => true,
            'is_sellable' => true,
            'addon_pricing' => [

                'pricing_model' => 'flat',
                'pricing_metric' => 'none',
                'pricing_params' => [
                    'price_monthly' => 29,
                ],
            ],
            'notes' => 'Flat monthly add-on fee for real-time shipment tracking.',
        ]);

        PlatformModule::where('key', 'logistics_fleet')->update([
            'display_name_override' => 'Fleet Management',
            'description_override' => 'Manage vehicles, drivers and maintenance schedules.',
            'icon_type' => 'tabler',
            'icon_name' => 'tabler-truck',
            'is_listed' => true,
            'is_sellable' => true,
            'addon_pricing' => [

                'pricing_model' => 'per_seat',
                'pricing_metric' => 'users',
                'pricing_params' => [
                    'included' => ['starter' => 5, 'pro' => 10, 'business' => 25],
                    'overage_unit_price' => ['starter' => 1, 'pro' => 0.8, 'business' => 0.6],
                ],
            ],
            'notes' => 'Per-seat pricing with plan-based included seats.',
        ]);

        PlatformModule::where('key', 'logistics_analytics')->update([
            'display_name_override' => 'Advanced Analytics',
            'description_override' => 'Operational insights and performance dashboards.',
            'icon_type' => 'tabler',
            'icon_name' => 'tabler-chart-bar',
            'is_listed' => true,
            'is_sellable' => true,
            'addon_pricing' => [

                'pricing_model' => 'plan_flat',
                'pricing_metric' => 'none',
                'pricing_params' => [
                    'starter' => 49,
                    'pro' => 29,
                    'business' => 19,
                ],
            ],
            'notes' => 'Additional monthly price varies by plan tier.',
        ]);

        // Future example: image-based icon (e.g., Stripe module)
        // PlatformModule::where('key', 'payments_stripe')->update([
        //     'icon_type' => 'image',
        //     'icon_name' => '/images/modules/stripe.svg',
        // ]);
    }

    /**
     * ADR-419: Seed document type activations, document requests, and uploaded
     * documents so the AI pipeline has material to process after migrate:fresh.
     */
    private function seedDocumentData(Company $company, User $owner, User $admin, User $user): void
    {
        // ─── 1. Activate document types for the company ─────────────
        $memberDocCodes = ['id_card', 'driving_license', 'medical_certificate', 'rib', 'work_contract'];
        $companyDocCodes = ['kbis', 'insurance_certificate', 'transport_license'];

        foreach (array_merge($memberDocCodes, $companyDocCodes) as $index => $code) {
            $docType = DocumentType::where('code', $code)->first();
            if ($docType) {
                DocumentTypeActivation::updateOrCreate(
                    ['company_id' => $company->id, 'document_type_id' => $docType->id],
                    ['enabled' => true, 'required_override' => in_array($code, ['id_card', 'driving_license', 'kbis']), 'order' => $index * 10],
                );
            }
        }

        // ─── 2. Copy fixture files to storage ───────────────────────
        $fixturesPath = database_path('seeders/fixtures');

        $storedFiles = [];
        $fixtures = [
            'dummy_driving_license.png' => 'documents/seed/dummy_driving_license.png',
            'dummy_id_card.png' => 'documents/seed/dummy_id_card.png',
            'dummy_kbis.png' => 'documents/seed/dummy_kbis.png',
        ];

        foreach ($fixtures as $source => $dest) {
            $sourcePath = $fixturesPath.'/'.$source;
            if (file_exists($sourcePath)) {
                Storage::put($dest, file_get_contents($sourcePath));
                $storedFiles[$source] = $dest;
            }
        }

        // ─── 3. Member document requests for Bob (driver) ───────────
        $drivingLicense = DocumentType::where('code', 'driving_license')->first();
        $idCard = DocumentType::where('code', 'id_card')->first();
        $medicalCert = DocumentType::where('code', 'medical_certificate')->first();

        if (! $drivingLicense || ! $idCard || ! $medicalCert) {
            return; // DocumentTypeCatalog hasn't been synced yet
        }

        // Request 1: driving_license — SUBMITTED (Bob uploaded a file)
        $dlRequest = DocumentRequest::updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id, 'document_type_id' => $drivingLicense->id],
            [
                'status' => DocumentRequest::STATUS_SUBMITTED,
                'requested_at' => now()->subDays(5),
                'submitted_at' => now()->subDays(2),
            ],
        );

        // The actual uploaded document for the submitted request
        if (isset($storedFiles['dummy_driving_license.png'])) {
            MemberDocument::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $user->id, 'document_type_id' => $drivingLicense->id],
                [
                    'file_path' => $storedFiles['dummy_driving_license.png'],
                    'file_name' => 'permis_bob_dupont.png',
                    'file_size_bytes' => Storage::size($storedFiles['dummy_driving_license.png']),
                    'mime_type' => 'image/png',
                    'uploaded_by' => $user->id,
                    'expires_at' => now()->addYears(2),
                    'ai_status' => 'completed',
                ],
            );
        }

        // Request 2: id_card — REQUESTED (pending upload from Bob)
        DocumentRequest::updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id, 'document_type_id' => $idCard->id],
            [
                'status' => DocumentRequest::STATUS_REQUESTED,
                'requested_at' => now()->subDays(3),
            ],
        );

        // Request 3: medical_certificate — REQUESTED (pending from Bob)
        DocumentRequest::updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id, 'document_type_id' => $medicalCert->id],
            [
                'status' => DocumentRequest::STATUS_REQUESTED,
                'requested_at' => now()->subDay(),
            ],
        );

        // ─── 4. Member document requests for Alice (dispatcher) ─────
        // Alice: id_card SUBMITTED (with file)
        DocumentRequest::updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $admin->id, 'document_type_id' => $idCard->id],
            [
                'status' => DocumentRequest::STATUS_SUBMITTED,
                'requested_at' => now()->subDays(7),
                'submitted_at' => now()->subDays(4),
            ],
        );

        if (isset($storedFiles['dummy_id_card.png'])) {
            MemberDocument::updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $admin->id, 'document_type_id' => $idCard->id],
                [
                    'file_path' => $storedFiles['dummy_id_card.png'],
                    'file_name' => 'ci_alice_martin.png',
                    'file_size_bytes' => Storage::size($storedFiles['dummy_id_card.png']),
                    'mime_type' => 'image/png',
                    'uploaded_by' => $admin->id,
                    'expires_at' => now()->addYears(5),
                    'ai_status' => 'completed',
                ],
            );
        }

        // Alice: driving_license REQUESTED
        DocumentRequest::updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $admin->id, 'document_type_id' => $drivingLicense->id],
            [
                'status' => DocumentRequest::STATUS_REQUESTED,
                'requested_at' => now()->subDays(2),
            ],
        );

        // ─── 5. Company documents (company-scope) ───────────────────
        $kbis = DocumentType::where('code', 'kbis')->first();
        if ($kbis && isset($storedFiles['dummy_kbis.png'])) {
            CompanyDocument::updateOrCreate(
                ['company_id' => $company->id, 'document_type_id' => $kbis->id],
                [
                    'file_path' => $storedFiles['dummy_kbis.png'],
                    'file_name' => 'kbis_leezr_logistics.png',
                    'file_size_bytes' => Storage::size($storedFiles['dummy_kbis.png']),
                    'mime_type' => 'image/png',
                    'uploaded_by' => $owner->id,
                    'expires_at' => now()->addMonths(3),
                    'ai_status' => 'completed',
                ],
            );
        }
    }
}
