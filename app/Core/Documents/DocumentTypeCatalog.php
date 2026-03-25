<?php

namespace App\Core\Documents;

use App\Core\Fields\TagDictionary;

/**
 * ADR-169 Phase 3: Code-driven document type catalog.
 * Bootstrap seed only — DB is the runtime source of truth (ADR-182).
 * sync() is additive: creates missing types, never overwrites existing.
 */
class DocumentTypeCatalog
{
    public static function all(): array
    {
        return [
            // ─── Member-scope documents ─────────────────────────
            [
                'code' => 'id_card',
                'label' => 'Identity Card',
                'scope' => DocumentType::SCOPE_COMPANY_USER,
                'validation_rules' => [
                    'applicable_markets' => ['FR'],
                    'max_file_size_mb' => 10,
                    'accepted_types' => ['pdf', 'jpg', 'jpeg', 'png'],
                ],
            ],
            [
                'code' => 'driving_license',
                'label' => 'Driving License',
                'scope' => DocumentType::SCOPE_COMPANY_USER,
                'validation_rules' => [
                    'tags' => [TagDictionary::DRIVING],
                    'max_file_size_mb' => 10,
                    'accepted_types' => ['pdf', 'jpg', 'jpeg', 'png'],
                ],
            ],
            [
                'code' => 'medical_certificate',
                'label' => 'Medical Certificate',
                'scope' => DocumentType::SCOPE_COMPANY_USER,
                'validation_rules' => [
                    'tags' => [TagDictionary::DRIVING],
                    'max_file_size_mb' => 10,
                    'accepted_types' => ['pdf', 'jpg', 'jpeg', 'png'],
                ],
            ],
            [
                'code' => 'rib',
                'label' => 'RIB / Bank Details',
                'scope' => DocumentType::SCOPE_COMPANY_USER,
                'validation_rules' => [
                    'max_file_size_mb' => 5,
                    'accepted_types' => ['pdf', 'jpg', 'jpeg', 'png'],
                ],
            ],
            [
                'code' => 'social_security',
                'label' => 'Social Security Card',
                'scope' => DocumentType::SCOPE_COMPANY_USER,
                'validation_rules' => [
                    'applicable_markets' => ['FR'],
                    'max_file_size_mb' => 5,
                    'accepted_types' => ['pdf', 'jpg', 'jpeg', 'png'],
                ],
            ],
            [
                'code' => 'residence_permit',
                'label' => 'Residence Permit',
                'scope' => DocumentType::SCOPE_COMPANY_USER,
                'validation_rules' => [
                    'max_file_size_mb' => 10,
                    'accepted_types' => ['pdf', 'jpg', 'jpeg', 'png'],
                ],
            ],
            [
                'code' => 'diploma',
                'label' => 'Diploma / Certification',
                'scope' => DocumentType::SCOPE_COMPANY_USER,
                'validation_rules' => [
                    'max_file_size_mb' => 10,
                    'accepted_types' => ['pdf', 'jpg', 'jpeg', 'png'],
                ],
            ],
            [
                'code' => 'criminal_record',
                'label' => 'Criminal Record Extract',
                'scope' => DocumentType::SCOPE_COMPANY_USER,
                'validation_rules' => [
                    'max_file_size_mb' => 5,
                    'accepted_types' => ['pdf'],
                ],
            ],
            [
                'code' => 'work_contract',
                'label' => 'Employment Contract',
                'scope' => DocumentType::SCOPE_COMPANY_USER,
                'validation_rules' => [
                    'max_file_size_mb' => 10,
                    'accepted_types' => ['pdf'],
                ],
            ],

            // ─── Company-scope documents ────────────────────────
            [
                'code' => 'kbis',
                'label' => 'K-bis Extract',
                'scope' => DocumentType::SCOPE_COMPANY,
                'validation_rules' => [
                    'applicable_markets' => ['FR'],
                    'max_file_size_mb' => 5,
                    'accepted_types' => ['pdf'],
                ],
            ],
            [
                'code' => 'insurance_certificate',
                'label' => 'Insurance Certificate',
                'scope' => DocumentType::SCOPE_COMPANY,
                'validation_rules' => [
                    'max_file_size_mb' => 10,
                    'accepted_types' => ['pdf'],
                ],
            ],
            [
                'code' => 'transport_license',
                'label' => 'Transport License',
                'scope' => DocumentType::SCOPE_COMPANY,
                'validation_rules' => [
                    'required_by_modules' => ['logistics_fleet'],
                    'applicable_markets' => ['FR'],
                    'max_file_size_mb' => 10,
                    'accepted_types' => ['pdf'],
                ],
            ],
            [
                'code' => 'company_insurance',
                'label' => 'Professional Liability Insurance',
                'scope' => DocumentType::SCOPE_COMPANY,
                'validation_rules' => [
                    'max_file_size_mb' => 10,
                    'accepted_types' => ['pdf'],
                ],
            ],
            [
                'code' => 'company_registration',
                'label' => 'Company Registration',
                'scope' => DocumentType::SCOPE_COMPANY,
                'validation_rules' => [
                    'max_file_size_mb' => 10,
                    'accepted_types' => ['pdf'],
                ],
            ],
        ];
    }

    /**
     * ADR-182: Seed-only sync — creates missing types, never overwrites existing.
     * DB is the runtime source of truth; platform admin edits survive re-seed.
     */
    public static function sync(): void
    {
        foreach (static::all() as $entry) {
            DocumentType::firstOrCreate(
                ['code' => $entry['code']],
                [
                    'label' => $entry['label'],
                    'scope' => $entry['scope'],
                    'validation_rules' => $entry['validation_rules'],
                    'is_system' => true,
                ],
            );
        }
    }
}
