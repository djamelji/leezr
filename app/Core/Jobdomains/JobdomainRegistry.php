<?php

namespace App\Core\Jobdomains;

use App\Core\Fields\TagDictionary;

/**
 * Declarative registry of all jobdomain profiles.
 * Single source of truth for what a jobdomain provides.
 * The DB table (jobdomains) stores metadata + presets; this class seeds them.
 */
class JobdomainRegistry
{
    /**
     * Core billing fields — injected into EVERY jobdomain automatically.
     * Billing is a platform-level concern, not jobdomain-specific.
     */
    public const CORE_BILLING_FIELDS = [
        ['code' => 'siret', 'order' => 0],
        ['code' => 'vat_number', 'order' => 1],
        ['code' => 'legal_name', 'order' => 3],
        ['code' => 'billing_address', 'order' => 4],
        ['code' => 'billing_complement', 'order' => 5],
        ['code' => 'billing_city', 'order' => 6],
        ['code' => 'billing_postal_code', 'order' => 7],
        ['code' => 'billing_region', 'order' => 8],
        ['code' => 'billing_email', 'order' => 9],
    ];

    /**
     * All jobdomain definitions (hardcoded, not in DB).
     * CORE_BILLING_FIELDS are auto-merged into every jobdomain's default_fields.
     */
    public static function definitions(): array
    {
        return [
            'logistique' => [
                'label' => 'Logistique',
                'description' => 'Transport, fleet management, dispatch',
                'landing_route' => '/',
                'nav_profile' => 'logistique',
                'default_modules' => ['core.theme', 'core.members', 'core.settings', 'core.documents', 'logistics_shipments', 'core.documentation'],
                // ADR-169: default_fields only control activation + order.
                // Mandatory is handled exclusively by FieldDefinitionCatalog required_by_*.
                'default_fields' => [
                    // Company scope — address
                    ['code' => 'company_address', 'order' => 100],
                    ['code' => 'company_complement', 'order' => 101],
                    ['code' => 'company_city', 'order' => 102],
                    ['code' => 'company_postal_code', 'order' => 103],
                    ['code' => 'company_region', 'order' => 104],
                    // Company scope — contact
                    ['code' => 'company_phone', 'order' => 110],
                    // Company User — identity
                    ['code' => 'job_title', 'order' => 10],
                    ['code' => 'birth_date', 'order' => 20],
                    ['code' => 'nationality', 'order' => 30],
                    // Company User — contact
                    ['code' => 'phone', 'order' => 40],
                    ['code' => 'address', 'order' => 50],
                    ['code' => 'emergency_contact_name', 'order' => 60],
                    ['code' => 'emergency_contact_phone', 'order' => 70],
                    // Company User — hr
                    ['code' => 'hire_date', 'order' => 80],
                    ['code' => 'contract_type', 'order' => 90],
                    ['code' => 'employee_status', 'order' => 100],
                    ['code' => 'social_security_number', 'order' => 110],
                    ['code' => 'iban', 'order' => 120],
                    // Company User — driving
                    ['code' => 'license_number', 'order' => 200],
                    ['code' => 'license_category', 'order' => 210],
                    ['code' => 'license_expiry', 'order' => 220],
                    ['code' => 'adr_certified', 'order' => 230],
                    ['code' => 'vehicle_type', 'order' => 240],
                    // Company User — dispatch
                    ['code' => 'geographic_zone', 'order' => 300],
                    ['code' => 'work_schedule', 'order' => 310],
                    ['code' => 'work_mode', 'order' => 320],
                ],
                // ADR-389: default document types activated on assignment
                // required: true → sets required_override at assignment time
                'default_documents' => [
                    ['code' => 'id_card', 'order' => 0, 'required' => true],
                    ['code' => 'driving_license', 'order' => 10, 'required' => false],
                    ['code' => 'medical_certificate', 'order' => 20, 'required' => false],
                    ['code' => 'kbis', 'order' => 30, 'required' => true],
                    ['code' => 'insurance_certificate', 'order' => 40, 'required' => true],
                ],
                // ADR-170 + ADR-357: Semantic archetypes for tag-based mandatory + workspace resolution
                'archetypes' => [
                    'field_worker' => ['label' => 'Conducteur / Chauffeur', 'default_tags' => [TagDictionary::DRIVING]],
                    'operations_center' => ['label' => 'Exploitant / Dispatcher', 'default_tags' => [TagDictionary::DISPATCHING]],
                    'management' => ['label' => 'Manager / Direction', 'default_tags' => [TagDictionary::MANAGEMENT]],
                ],
                'default_roles' => [
                    'owner' => [
                        'name' => 'Propriétaire',
                        'archetype' => 'management',
                        'is_administrative' => true,
                        'dashboard_widgets' => ['compliance.rate', 'compliance.pending', 'compliance.roles', 'shipments.today', 'shipments.in_transit', 'shipments.late', 'drivers.active'],
                        'bundles' => [
                            'theme.full',
                            'members.team_access', 'members.team_management', 'members.sensitive_data',
                            'settings.company_info', 'settings.company_management',
                            'documents.access', 'documents.management',
                            'roles.governance',
                            'jobdomain.info', 'jobdomain.management',
                            'audit.viewer',
                            'modules.management',
                            'documentation.access',
                            'billing.management',
                            'support.access',
                            'shipments.operations', 'shipments.administration',
                        ],
                        'fields' => [
                            // identity
                            ['code' => 'job_title', 'required' => true, 'visible' => true, 'order' => 0, 'group' => 'identity', 'scope' => 'company_user'],
                            ['code' => 'birth_date', 'visible' => true, 'order' => 1, 'group' => 'identity', 'scope' => 'company_user'],
                            ['code' => 'nationality', 'visible' => true, 'order' => 2, 'group' => 'identity', 'scope' => 'company_user'],
                            // contact
                            ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 10, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'address', 'visible' => true, 'order' => 11, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'emergency_contact_name', 'visible' => true, 'order' => 12, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'emergency_contact_phone', 'visible' => true, 'order' => 13, 'group' => 'contact', 'scope' => 'company_user'],
                            // hr
                            ['code' => 'hire_date', 'visible' => true, 'order' => 20, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'contract_type', 'visible' => true, 'order' => 21, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'employee_status', 'visible' => true, 'order' => 22, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'social_security_number', 'visible' => true, 'order' => 23, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'iban', 'visible' => true, 'order' => 24, 'group' => 'hr', 'scope' => 'company_user'],
                            // driving — hidden for owner
                            ['code' => 'license_number', 'visible' => false, 'order' => 30, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'license_category', 'visible' => false, 'order' => 31, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'license_expiry', 'visible' => false, 'order' => 32, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'adr_certified', 'visible' => false, 'order' => 33, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'vehicle_type', 'visible' => false, 'order' => 34, 'group' => 'driving', 'scope' => 'company_user'],
                            // dispatch — hidden for owner
                            ['code' => 'geographic_zone', 'visible' => false, 'order' => 40, 'group' => 'dispatch', 'scope' => 'company_user'],
                            ['code' => 'work_schedule', 'visible' => false, 'order' => 41, 'group' => 'dispatch', 'scope' => 'company_user'],
                            ['code' => 'work_mode', 'visible' => false, 'order' => 42, 'group' => 'dispatch', 'scope' => 'company_user'],
                        ],
                        'doc_config' => [
                            ['code' => 'id_card', 'visible' => true, 'order' => 0],
                            ['code' => 'driving_license', 'visible' => false, 'order' => 10],
                            ['code' => 'medical_certificate', 'visible' => false, 'order' => 20],
                        ],
                    ],
                    'manager' => [
                        'name' => 'Manager',
                        'archetype' => 'management',
                        'is_administrative' => true,
                        'dashboard_widgets' => ['compliance.rate', 'compliance.pending', 'compliance.roles', 'shipments.today', 'shipments.in_transit', 'shipments.late', 'drivers.active'],
                        'bundles' => [
                            'theme.full',
                            'members.team_access', 'members.team_management', 'members.sensitive_data',
                            'settings.company_info', 'settings.company_management',
                            'documents.access', 'documents.management',
                            'roles.governance',
                            'jobdomain.info', 'jobdomain.management',
                            'shipments.operations', 'shipments.administration',
                            'billing.management',
                            'support.access',
                        ],
                        'fields' => [
                            // identity
                            ['code' => 'job_title', 'required' => true, 'visible' => true, 'order' => 0, 'group' => 'identity', 'scope' => 'company_user'],
                            ['code' => 'birth_date', 'visible' => true, 'order' => 1, 'group' => 'identity', 'scope' => 'company_user'],
                            ['code' => 'nationality', 'visible' => true, 'order' => 2, 'group' => 'identity', 'scope' => 'company_user'],
                            // contact
                            ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 10, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'address', 'visible' => true, 'order' => 11, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'emergency_contact_name', 'required' => true, 'visible' => true, 'order' => 12, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'emergency_contact_phone', 'required' => true, 'visible' => true, 'order' => 13, 'group' => 'contact', 'scope' => 'company_user'],
                            // hr
                            ['code' => 'hire_date', 'visible' => true, 'order' => 20, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'contract_type', 'required' => true, 'visible' => true, 'order' => 21, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'employee_status', 'visible' => true, 'order' => 22, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'social_security_number', 'required' => true, 'visible' => true, 'order' => 23, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'iban', 'required' => true, 'visible' => true, 'order' => 24, 'group' => 'hr', 'scope' => 'company_user'],
                            // driving — hidden for manager
                            ['code' => 'license_number', 'visible' => false, 'order' => 30, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'license_category', 'visible' => false, 'order' => 31, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'license_expiry', 'visible' => false, 'order' => 32, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'adr_certified', 'visible' => false, 'order' => 33, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'vehicle_type', 'visible' => false, 'order' => 34, 'group' => 'driving', 'scope' => 'company_user'],
                            // dispatch — hidden for manager
                            ['code' => 'geographic_zone', 'visible' => false, 'order' => 40, 'group' => 'dispatch', 'scope' => 'company_user'],
                            ['code' => 'work_schedule', 'visible' => false, 'order' => 41, 'group' => 'dispatch', 'scope' => 'company_user'],
                            ['code' => 'work_mode', 'visible' => false, 'order' => 42, 'group' => 'dispatch', 'scope' => 'company_user'],
                        ],
                        'doc_config' => [
                            ['code' => 'id_card', 'visible' => true, 'order' => 0],
                            ['code' => 'driving_license', 'visible' => false, 'order' => 10],
                            ['code' => 'medical_certificate', 'visible' => false, 'order' => 20],
                        ],
                    ],
                    'dispatcher' => [
                        'name' => 'Dispatcher',
                        'archetype' => 'operations_center',
                        'is_administrative' => true, // ADR-373: surface access for structure pages (billing still blocked by use-permission)
                        'dashboard_widgets' => ['compliance.rate', 'compliance.pending', 'shipments.today', 'shipments.in_transit', 'shipments.late', 'shipments.unassigned', 'drivers.active'],
                        'bundles' => [
                            'theme.full',
                            'members.team_access', 'members.team_management',
                            'settings.company_info',
                            'documents.access', 'documents.management',
                            'shipments.operations',
                            'shipments.delivery',
                            'support.access', // ADR-373: Dispatcher can view/create support tickets
                        ],
                        'fields' => [
                            // identity
                            ['code' => 'job_title', 'required' => true, 'visible' => true, 'order' => 0, 'group' => 'identity', 'scope' => 'company_user'],
                            ['code' => 'birth_date', 'visible' => true, 'order' => 1, 'group' => 'identity', 'scope' => 'company_user'],
                            ['code' => 'nationality', 'visible' => true, 'order' => 2, 'group' => 'identity', 'scope' => 'company_user'],
                            // contact
                            ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 10, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'address', 'visible' => true, 'order' => 11, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'emergency_contact_name', 'required' => true, 'visible' => true, 'order' => 12, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'emergency_contact_phone', 'required' => true, 'visible' => true, 'order' => 13, 'group' => 'contact', 'scope' => 'company_user'],
                            // hr
                            ['code' => 'hire_date', 'visible' => true, 'order' => 20, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'contract_type', 'required' => true, 'visible' => true, 'order' => 21, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'employee_status', 'visible' => true, 'order' => 22, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'social_security_number', 'required' => true, 'visible' => true, 'order' => 23, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'iban', 'required' => true, 'visible' => true, 'order' => 24, 'group' => 'hr', 'scope' => 'company_user'],
                            // driving — hidden for dispatcher
                            ['code' => 'license_number', 'visible' => false, 'order' => 30, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'license_category', 'visible' => false, 'order' => 31, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'license_expiry', 'visible' => false, 'order' => 32, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'adr_certified', 'visible' => false, 'order' => 33, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'vehicle_type', 'visible' => false, 'order' => 34, 'group' => 'driving', 'scope' => 'company_user'],
                            // dispatch
                            ['code' => 'geographic_zone', 'visible' => true, 'order' => 40, 'group' => 'dispatch', 'scope' => 'company_user'],
                            ['code' => 'work_schedule', 'visible' => true, 'order' => 41, 'group' => 'dispatch', 'scope' => 'company_user'],
                            ['code' => 'work_mode', 'visible' => true, 'order' => 42, 'group' => 'dispatch', 'scope' => 'company_user'],
                        ],
                        'doc_config' => [
                            ['code' => 'id_card', 'visible' => true, 'order' => 0],
                            ['code' => 'driving_license', 'visible' => false, 'order' => 10],
                            ['code' => 'medical_certificate', 'visible' => false, 'order' => 20],
                        ],
                    ],
                    'driver' => [
                        'name' => 'Driver',
                        'archetype' => 'field_worker',
                        'dashboard_widgets' => ['compliance.rate', 'deliveries.my_today', 'deliveries.next', 'deliveries.completed_today'],
                        'bundles' => [
                            'theme.full',
                            'members.team_access',
                            'settings.company_info',
                            'documents.access',
                            'shipments.delivery',
                        ],
                        'fields' => [
                            // identity
                            ['code' => 'job_title', 'visible' => false, 'order' => 0, 'group' => 'identity', 'scope' => 'company_user'],
                            ['code' => 'birth_date', 'required' => true, 'visible' => true, 'order' => 1, 'group' => 'identity', 'scope' => 'company_user'],
                            ['code' => 'nationality', 'visible' => true, 'order' => 2, 'group' => 'identity', 'scope' => 'company_user'],
                            // contact
                            ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 10, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'address', 'required' => true, 'visible' => true, 'order' => 11, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'emergency_contact_name', 'required' => true, 'visible' => true, 'order' => 12, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'emergency_contact_phone', 'required' => true, 'visible' => true, 'order' => 13, 'group' => 'contact', 'scope' => 'company_user'],
                            // hr
                            ['code' => 'hire_date', 'visible' => true, 'order' => 20, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'contract_type', 'required' => true, 'visible' => true, 'order' => 21, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'employee_status', 'visible' => true, 'order' => 22, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'social_security_number', 'required' => true, 'visible' => true, 'order' => 23, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'iban', 'required' => true, 'visible' => true, 'order' => 24, 'group' => 'hr', 'scope' => 'company_user'],
                            // driving
                            ['code' => 'license_number', 'visible' => true, 'order' => 30, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'license_category', 'visible' => true, 'order' => 31, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'license_expiry', 'visible' => true, 'order' => 32, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'adr_certified', 'visible' => true, 'order' => 33, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'vehicle_type', 'visible' => true, 'order' => 34, 'group' => 'driving', 'scope' => 'company_user'],
                            // dispatch — hidden for driver
                            ['code' => 'geographic_zone', 'visible' => false, 'order' => 40, 'group' => 'dispatch', 'scope' => 'company_user'],
                            ['code' => 'work_schedule', 'visible' => false, 'order' => 41, 'group' => 'dispatch', 'scope' => 'company_user'],
                            ['code' => 'work_mode', 'visible' => false, 'order' => 42, 'group' => 'dispatch', 'scope' => 'company_user'],
                        ],
                        'doc_config' => [
                            ['code' => 'id_card', 'visible' => true, 'order' => 0],
                            ['code' => 'driving_license', 'visible' => true, 'required' => true, 'order' => 10],
                            ['code' => 'medical_certificate', 'visible' => true, 'required' => true, 'order' => 20],
                        ],
                    ],
                    'ops_manager' => [
                        'name' => 'Operations Manager',
                        'archetype' => 'management',
                        'is_administrative' => true,
                        'dashboard_widgets' => ['compliance.rate', 'compliance.pending', 'compliance.roles', 'shipments.today', 'shipments.in_transit', 'shipments.late', 'drivers.active'],
                        'bundles' => [
                            'theme.full',
                            'members.team_access', 'members.team_management', 'members.sensitive_data',
                            'settings.company_info',
                            'documents.access', 'documents.management',
                            'roles.governance',
                            'jobdomain.info',
                            'shipments.operations',
                            'shipments.administration',
                        ],
                        'fields' => [
                            // identity
                            ['code' => 'job_title', 'required' => true, 'visible' => true, 'order' => 0, 'group' => 'identity', 'scope' => 'company_user'],
                            ['code' => 'birth_date', 'visible' => true, 'order' => 1, 'group' => 'identity', 'scope' => 'company_user'],
                            ['code' => 'nationality', 'visible' => true, 'order' => 2, 'group' => 'identity', 'scope' => 'company_user'],
                            // contact
                            ['code' => 'phone', 'required' => true, 'visible' => true, 'order' => 10, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'address', 'visible' => true, 'order' => 11, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'emergency_contact_name', 'required' => true, 'visible' => true, 'order' => 12, 'group' => 'contact', 'scope' => 'company_user'],
                            ['code' => 'emergency_contact_phone', 'required' => true, 'visible' => true, 'order' => 13, 'group' => 'contact', 'scope' => 'company_user'],
                            // hr
                            ['code' => 'hire_date', 'visible' => true, 'order' => 20, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'contract_type', 'required' => true, 'visible' => true, 'order' => 21, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'employee_status', 'visible' => true, 'order' => 22, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'social_security_number', 'required' => true, 'visible' => true, 'order' => 23, 'group' => 'hr', 'scope' => 'company_user'],
                            ['code' => 'iban', 'required' => true, 'visible' => true, 'order' => 24, 'group' => 'hr', 'scope' => 'company_user'],
                            // driving — partial visibility for ops_manager (overview)
                            ['code' => 'license_number', 'visible' => false, 'order' => 30, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'license_category', 'visible' => true, 'order' => 31, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'license_expiry', 'visible' => false, 'order' => 32, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'adr_certified', 'visible' => false, 'order' => 33, 'group' => 'driving', 'scope' => 'company_user'],
                            ['code' => 'vehicle_type', 'visible' => true, 'order' => 34, 'group' => 'driving', 'scope' => 'company_user'],
                            // dispatch — partial visibility for ops_manager (overview)
                            ['code' => 'geographic_zone', 'visible' => true, 'order' => 40, 'group' => 'dispatch', 'scope' => 'company_user'],
                            ['code' => 'work_schedule', 'visible' => false, 'order' => 41, 'group' => 'dispatch', 'scope' => 'company_user'],
                            ['code' => 'work_mode', 'visible' => false, 'order' => 42, 'group' => 'dispatch', 'scope' => 'company_user'],
                        ],
                        'doc_config' => [
                            ['code' => 'id_card', 'visible' => true, 'order' => 0],
                            ['code' => 'driving_license', 'visible' => false, 'order' => 10],
                            ['code' => 'medical_certificate', 'visible' => false, 'order' => 20],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get a single definition by key.
     */
    public static function get(string $key): ?array
    {
        $definition = static::definitions()[$key] ?? null;
        if ($definition === null) {
            return null;
        }

        // Merge CORE_BILLING_FIELDS (consistent with sync())
        $coreFields = static::CORE_BILLING_FIELDS;
        $jobdomainFields = $definition['default_fields'] ?? [];
        $coreCodes = array_column($coreFields, 'code');
        $jobdomainOnly = array_filter($jobdomainFields, fn ($f) => ! in_array($f['code'], $coreCodes));
        $definition['default_fields'] = array_merge($coreFields, array_values($jobdomainOnly));

        return $definition;
    }

    /**
     * Sync definitions to the jobdomains DB table.
     * Persists default_modules and default_fields to DB columns.
     */
    public static function sync(): void
    {
        foreach (static::definitions() as $key => $definition) {
            // Merge core billing fields with jobdomain-specific fields
            $coreFields = static::CORE_BILLING_FIELDS;
            $jobdomainFields = $definition['default_fields'] ?? [];

            // Deduplicate: jobdomain-specific fields override core if same code
            $coreCodes = array_column($coreFields, 'code');
            $jobdomainOnly = array_filter($jobdomainFields, fn ($f) => ! in_array($f['code'], $coreCodes));

            $mergedFields = array_merge($coreFields, array_values($jobdomainOnly));

            Jobdomain::updateOrCreate(
                ['key' => $key],
                [
                    'label' => $definition['label'],
                    'description' => $definition['description'] ?? null,
                    'default_modules' => $definition['default_modules'] ?? [],
                    'default_fields' => $mergedFields,
                    'default_roles' => $definition['default_roles'] ?? [],
                    'default_documents' => $definition['default_documents'] ?? [],
                    // M5 (ADR-728) — propositions métier Workforce (presets importables par la Company).
                    'workforce_presets' => $definition['workforce_presets'] ?? static::workforcePresetsFor($key),
                ],
            );
        }
    }

    /**
     * M5 (ADR-728) — presets Workforce proposés par JobDomain (contrat versionné, référencés par CODE,
     * résolus vers les identifiants Company à l'import). Platform-controlled (code-seeded via sync()).
     * Les semaines types proposées sont PAR BESOINS (aucun salarié dans un preset).
     */
    public static function workforcePresetsFor(string $key): array
    {
        if ($key !== 'logistique') {
            return [];
        }

        return [
            'schema_version' => 1,
            'coverage_categories' => [
                ['code' => 'route', 'name' => 'Route', 'color' => '#4b6bfb'],
                ['code' => 'preparation', 'name' => 'Préparation', 'color' => '#0ea5a3'],
                ['code' => 'livraison', 'name' => 'Livraison', 'color' => '#8b5cf6'],
                ['code' => 'formation', 'name' => 'Formation', 'color' => '#e0892a'],
            ],
            'activity_presets' => [
                ['code' => 'brief', 'name' => 'Brief équipe', 'activity_type' => 'brief', 'coverage_category_code' => 'route', 'default_duration_minutes' => 30, 'priority' => 'normal'],
                ['code' => 'chargement', 'name' => 'Chargement du véhicule', 'activity_type' => 'chargement', 'coverage_category_code' => 'preparation', 'default_duration_minutes' => 90, 'priority' => 'high'],
                ['code' => 'tournee', 'name' => 'Route / tournée', 'activity_type' => 'tournee', 'coverage_category_code' => 'route', 'default_duration_minutes' => 210, 'priority' => 'normal'],
                ['code' => 'livraison_client', 'name' => 'Livraison client', 'activity_type' => 'livraison', 'coverage_category_code' => 'livraison', 'default_duration_minutes' => 180, 'priority' => 'high'],
                ['code' => 'inventaire', 'name' => 'Inventaire', 'activity_type' => 'inventaire', 'coverage_category_code' => 'preparation', 'default_duration_minutes' => 120, 'priority' => 'normal'],
                ['code' => 'formation_secu', 'name' => 'Formation sécurité', 'activity_type' => 'formation', 'coverage_category_code' => 'formation', 'default_duration_minutes' => 120, 'priority' => 'critical'],
            ],
            'schedule_templates' => [
                [
                    'code' => 'shift_preparation', 'name' => 'Shift préparation', 'start_time' => '06:00', 'end_time' => '12:00',
                    'break_duration_minutes' => 30, 'color' => '#0ea5a3', 'coverage_category_code' => 'preparation',
                    'composition' => [
                        'activities' => [
                            ['preset_code' => 'brief', 'offset_minutes' => 0, 'duration_minutes' => 30],
                            ['preset_code' => 'chargement', 'offset_minutes' => 30, 'duration_minutes' => 120],
                            ['preset_code' => 'inventaire', 'offset_minutes' => 210, 'duration_minutes' => 150],
                        ],
                        'breaks' => [['offset_minutes' => 180, 'duration_minutes' => 30]],
                    ],
                ],
                [
                    'code' => 'shift_journee', 'name' => 'Shift journée', 'start_time' => '09:00', 'end_time' => '17:00',
                    'break_duration_minutes' => 60, 'color' => '#4b6bfb', 'coverage_category_code' => 'route',
                    'composition' => [
                        'activities' => [
                            ['preset_code' => 'brief', 'offset_minutes' => 0, 'duration_minutes' => 30],
                            ['preset_code' => 'tournee', 'offset_minutes' => 30, 'duration_minutes' => 180],
                            ['preset_code' => 'tournee', 'offset_minutes' => 270, 'duration_minutes' => 210],
                        ],
                        'breaks' => [['offset_minutes' => 210, 'duration_minutes' => 60]],
                    ],
                ],
                [
                    'code' => 'shift_livraison', 'name' => 'Shift livraison soir', 'start_time' => '14:00', 'end_time' => '20:00',
                    'break_duration_minutes' => 30, 'color' => '#8b5cf6', 'coverage_category_code' => 'livraison',
                    'composition' => [
                        'activities' => [['preset_code' => 'livraison_client', 'offset_minutes' => 0, 'duration_minutes' => 180]],
                        'breaks' => [],
                    ],
                ],
                [
                    'code' => 'shift_formation', 'name' => 'Formation', 'start_time' => '09:00', 'end_time' => '13:00',
                    'break_duration_minutes' => 0, 'color' => '#e0892a', 'coverage_category_code' => 'formation',
                    'composition' => ['activities' => [['preset_code' => 'formation_secu', 'offset_minutes' => 0, 'duration_minutes' => 120]], 'breaks' => []],
                ],
            ],
            'week_templates' => [
                [
                    'code' => 'semaine_logistique', 'name' => 'Semaine logistique (par besoins)', 'strategy' => 'needs',
                    'lines' => [
                        ['day_index' => 0, 'schedule_template_code' => 'shift_preparation', 'kind' => 'needs', 'headcount' => 3],
                        ['day_index' => 0, 'schedule_template_code' => 'shift_journee', 'kind' => 'needs', 'headcount' => 4],
                        ['day_index' => 1, 'schedule_template_code' => 'shift_preparation', 'kind' => 'needs', 'headcount' => 3],
                        ['day_index' => 1, 'schedule_template_code' => 'shift_journee', 'kind' => 'needs', 'headcount' => 4],
                        ['day_index' => 2, 'schedule_template_code' => 'shift_journee', 'kind' => 'needs', 'headcount' => 4],
                        ['day_index' => 3, 'schedule_template_code' => 'shift_journee', 'kind' => 'needs', 'headcount' => 4],
                        ['day_index' => 4, 'schedule_template_code' => 'shift_livraison', 'kind' => 'needs', 'headcount' => 2],
                    ],
                ],
            ],
        ];
    }
}
