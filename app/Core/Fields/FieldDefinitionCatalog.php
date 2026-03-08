<?php

namespace App\Core\Fields;

/**
 * Single source of truth for system field definitions.
 * Pattern: same as PermissionCatalog, ModuleRegistry.
 *
 * sync() is idempotent and safe for any environment.
 * System fields cannot be deleted. scope/type are never modified on existing fields.
 */
class FieldDefinitionCatalog
{
    public static function all(): array
    {
        return [
            // Company scope
            [
                'code' => 'siret',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'SIRET',
                'translations' => ['en' => 'SIRET', 'fr' => 'SIRET'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['required' => true, 'max' => 14, 'applicable_markets' => ['FR']],
                'default_order' => 10,
            ],
            [
                'code' => 'vat_number',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'VAT Number',
                'translations' => ['en' => 'VAT Number', 'fr' => 'N° TVA'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 20],
                'default_order' => 20,
            ],
            [
                'code' => 'legal_name',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'Legal Name',
                'translations' => ['en' => 'Legal Name', 'fr' => 'Raison sociale'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 200, 'category' => 'billing'],
                'default_order' => 25,
            ],
            [
                'code' => 'legal_form',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'Legal Form',
                'translations' => ['en' => 'Legal Form', 'fr' => 'Forme juridique'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 50],
                'default_order' => 30,
            ],
            [
                'code' => 'billing_address',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'Street',
                'translations' => ['en' => 'Street', 'fr' => 'Rue'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 500, 'category' => 'billing'],
                'default_order' => 40,
            ],
            [
                'code' => 'billing_complement',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'Address line 2',
                'translations' => ['en' => 'Address line 2', 'fr' => "Complément d'adresse"],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 200, 'category' => 'billing'],
                'default_order' => 45,
            ],
            [
                'code' => 'billing_city',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'City',
                'translations' => ['en' => 'City', 'fr' => 'Ville'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 100, 'category' => 'billing'],
                'default_order' => 50,
            ],
            [
                'code' => 'billing_postal_code',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'Postal Code',
                'translations' => ['en' => 'Postal Code', 'fr' => 'Code postal'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 20, 'category' => 'billing'],
                'default_order' => 55,
            ],
            [
                'code' => 'billing_region',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'Region',
                'translations' => ['en' => 'Region', 'fr' => 'Région'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 100, 'category' => 'billing'],
                'default_order' => 58,
            ],
            [
                'code' => 'billing_email',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'Email',
                'translations' => ['en' => 'Email', 'fr' => 'Email'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 254, 'pattern' => 'email', 'category' => 'billing'],
                'default_order' => 60,
            ],

            // Company address
            [
                'code' => 'company_address',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'Street',
                'translations' => ['en' => 'Street', 'fr' => 'Rue'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 500, 'category' => 'address'],
                'default_order' => 100,
            ],
            [
                'code' => 'company_complement',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'Address line 2',
                'translations' => ['en' => 'Address line 2', 'fr' => "Complément d'adresse"],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 200, 'category' => 'address'],
                'default_order' => 110,
            ],
            [
                'code' => 'company_city',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'City',
                'translations' => ['en' => 'City', 'fr' => 'Ville'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 100, 'category' => 'address'],
                'default_order' => 120,
            ],
            [
                'code' => 'company_postal_code',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'Postal Code',
                'translations' => ['en' => 'Postal Code', 'fr' => 'Code postal'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 20, 'category' => 'address'],
                'default_order' => 130,
            ],
            [
                'code' => 'company_region',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'Region',
                'translations' => ['en' => 'Region', 'fr' => 'Région'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 100, 'category' => 'address'],
                'default_order' => 140,
            ],

            // Company contact
            [
                'code' => 'company_phone',
                'scope' => FieldDefinition::SCOPE_COMPANY,
                'label' => 'Phone',
                'translations' => ['en' => 'Phone', 'fr' => 'Téléphone'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 20, 'pattern' => 'phone', 'category' => 'contact'],
                'default_order' => 150,
            ],

            // Company User scope
            [
                'code' => 'phone',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Phone',
                'translations' => ['en' => 'Phone', 'fr' => 'Téléphone'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 20, 'pattern' => 'phone', 'category' => 'base'],
                'default_order' => 10,
            ],
            [
                'code' => 'job_title',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Job Title',
                'translations' => ['en' => 'Job Title', 'fr' => 'Intitulé de poste'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 100, 'category' => 'base'],
                'default_order' => 20,
            ],

            // ── Base commune (all employees) ──────────────────────
            [
                'code' => 'address',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Postal Address',
                'translations' => ['en' => 'Postal Address', 'fr' => 'Adresse postale'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 500, 'category' => 'base'],
                'default_order' => 30,
            ],
            [
                'code' => 'birth_date',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Date of Birth',
                'translations' => ['en' => 'Date of Birth', 'fr' => 'Date de naissance'],
                'type' => FieldDefinition::TYPE_DATE,
                'validation_rules' => ['category' => 'base'],
                'default_order' => 40,
            ],
            [
                'code' => 'nationality',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Nationality',
                'translations' => ['en' => 'Nationality', 'fr' => 'Nationalité'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 100, 'category' => 'base'],
                'default_order' => 50,
            ],
            [
                'code' => 'social_security_number',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Social Security Number',
                'translations' => ['en' => 'Social Security Number (NIR)', 'fr' => 'N° de sécurité sociale (NIR)'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 20, 'sensitive' => true, 'category' => 'hr'],
                'default_order' => 60,
            ],
            [
                'code' => 'iban',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'IBAN',
                'translations' => ['en' => 'IBAN', 'fr' => 'IBAN'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 34, 'sensitive' => true, 'category' => 'hr'],
                'default_order' => 70,
            ],
            [
                'code' => 'contract_type',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Contract Type',
                'translations' => ['en' => 'Contract Type', 'fr' => 'Type de contrat'],
                'type' => FieldDefinition::TYPE_SELECT,
                'options' => ['CDI', 'CDD', 'Interim', 'Stage', 'Alternance'],
                'validation_rules' => ['applicable_markets' => ['FR'], 'category' => 'hr'],
                'default_order' => 80,
            ],
            [
                'code' => 'hire_date',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Hire Date',
                'translations' => ['en' => 'Hire Date', 'fr' => "Date d'embauche"],
                'type' => FieldDefinition::TYPE_DATE,
                'validation_rules' => ['category' => 'hr', 'required_by_jobdomains' => ['logistique']],
                'default_order' => 90,
            ],
            [
                'code' => 'employee_status',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Status',
                'translations' => ['en' => 'Status', 'fr' => 'Statut'],
                'type' => FieldDefinition::TYPE_SELECT,
                'options' => ['active', 'inactive', 'on_leave', 'suspended'],
                'validation_rules' => ['category' => 'hr', 'required_by_jobdomains' => ['logistique']],
                'default_order' => 100,
            ],
            [
                'code' => 'emergency_contact_name',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Emergency Contact Name',
                'translations' => ['en' => 'Emergency Contact Name', 'fr' => "Contact d'urgence (nom)"],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 200, 'category' => 'base'],
                'default_order' => 110,
            ],
            [
                'code' => 'emergency_contact_phone',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Emergency Contact Phone',
                'translations' => ['en' => 'Emergency Contact Phone', 'fr' => "Contact d'urgence (tél.)"],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 20, 'pattern' => 'phone', 'category' => 'base'],
                'default_order' => 120,
            ],

            // ── Driver-specific ───────────────────────────────────
            [
                'code' => 'license_number',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'License Number',
                'translations' => ['en' => 'License Number', 'fr' => 'N° de permis'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 30, 'category' => 'domain', 'required_by_modules' => ['logistics_fleet'], 'tags' => [TagDictionary::DRIVING]],
                'default_order' => 200,
            ],
            [
                'code' => 'license_category',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'License Category',
                'translations' => ['en' => 'License Category', 'fr' => 'Catégorie de permis'],
                'type' => FieldDefinition::TYPE_SELECT,
                'options' => ['B', 'C', 'CE', 'D', 'DE'],
                'validation_rules' => ['category' => 'domain', 'required_by_modules' => ['logistics_fleet'], 'tags' => [TagDictionary::DRIVING]],
                'default_order' => 210,
            ],
            [
                'code' => 'license_expiry',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'License Expiry',
                'translations' => ['en' => 'License Expiry', 'fr' => 'Expiration du permis'],
                'type' => FieldDefinition::TYPE_DATE,
                'validation_rules' => ['category' => 'domain', 'required_by_modules' => ['logistics_fleet'], 'tags' => [TagDictionary::DRIVING]],
                'default_order' => 220,
            ],
            [
                'code' => 'adr_certified',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'ADR Certified',
                'translations' => ['en' => 'ADR Certified', 'fr' => 'Certification ADR'],
                'type' => FieldDefinition::TYPE_BOOLEAN,
                'validation_rules' => ['category' => 'domain'],
                'default_order' => 230,
            ],
            [
                'code' => 'vehicle_type',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Vehicle Type',
                'translations' => ['en' => 'Vehicle Type', 'fr' => 'Type de véhicule'],
                'type' => FieldDefinition::TYPE_SELECT,
                'options' => ['VL', 'PL', 'SPL', 'Frigorifique', 'Citerne'],
                'validation_rules' => ['category' => 'domain', 'required_by_modules' => ['logistics_fleet'], 'tags' => [TagDictionary::DRIVING]],
                'default_order' => 240,
            ],

            // ── Dispatcher-specific ───────────────────────────────
            [
                'code' => 'geographic_zone',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Geographic Zone',
                'translations' => ['en' => 'Geographic Zone', 'fr' => 'Zone géographique'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 200, 'category' => 'domain', 'required_by_modules' => ['logistics_tracking'], 'tags' => [TagDictionary::DISPATCHING]],
                'default_order' => 330,
            ],
            [
                'code' => 'work_schedule',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Work Schedule',
                'translations' => ['en' => 'Work Schedule', 'fr' => 'Planning horaires'],
                'type' => FieldDefinition::TYPE_SELECT,
                'options' => ['day', 'evening', 'night', 'rotating'],
                'validation_rules' => ['category' => 'domain', 'tags' => [TagDictionary::DISPATCHING, TagDictionary::DRIVING]],
                'default_order' => 340,
            ],
            [
                'code' => 'work_mode',
                'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => 'Work Mode',
                'translations' => ['en' => 'Work Mode', 'fr' => 'Mode de travail'],
                'type' => FieldDefinition::TYPE_SELECT,
                'options' => ['on_site', 'hybrid', 'remote'],
                'validation_rules' => ['category' => 'domain', 'tags' => [TagDictionary::DISPATCHING]],
                'default_order' => 350,
            ],

            // Platform User scope
            [
                'code' => 'internal_note',
                'scope' => FieldDefinition::SCOPE_PLATFORM_USER,
                'label' => 'Internal Note',
                'translations' => ['en' => 'Internal Note', 'fr' => 'Note interne'],
                'type' => FieldDefinition::TYPE_STRING,
                'validation_rules' => ['max' => 500],
                'default_order' => 10,
            ],
        ];
    }

    /**
     * Sync definitions to DB (idempotent).
     *
     * Only updates safe fields (label, validation_rules, options, default_order).
     * Never modifies scope or type of existing fields.
     */
    public static function sync(): void
    {
        foreach (static::all() as $field) {
            FieldDefinition::updateOrCreate(
                ['company_id' => null, 'code' => $field['code']],
                [
                    'scope' => $field['scope'],
                    'label' => $field['label'],
                    'translations' => $field['translations'] ?? null,
                    'type' => $field['type'],
                    'validation_rules' => $field['validation_rules'] ?? null,
                    'options' => $field['options'] ?? null,
                    'is_system' => true,
                    'created_by_platform' => true,
                    'default_order' => $field['default_order'] ?? 0,
                ],
            );
        }
    }
}
