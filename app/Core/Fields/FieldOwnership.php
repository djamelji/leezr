<?php

namespace App\Core\Fields;

/**
 * ADR-795 (L5) — canonical OWNER of a company-user field value.
 *
 *  - PERSON : identity and personal data of the human being (birth, nationality, social security,
 *    bank account, personal contacts, driving qualifications). With an account they live on the User
 *    (shared by the person's companies, unchanged, ADR-752→755) ; WITHOUT account they live on the
 *    company's Employee record — never pushed to a global identity by a company.
 *  - EMPLOYMENT : data that only makes sense inside ONE company (position title, contract type, hiring,
 *    organisation, work schedule) and every company-specific or module-created definition → always the
 *    Employee record (isolated per company).
 *
 * An explicit `validation_rules.owner` (person | employment) on a definition wins.
 */
final class FieldOwnership
{
    public const PERSON = 'person';
    public const EMPLOYMENT = 'employment';

    public const PERSON_CODES = [
        'phone', 'address', 'gender', 'birth_date', 'birth_city', 'birth_department', 'birth_country', 'nationality',
        'social_security_number', 'iban', 'emergency_contact_name', 'emergency_contact_phone',
        'personal_address_street', 'personal_address_postal_code', 'personal_address_city', 'personal_address_country_code',
        'license_number', 'license_category', 'license_expiry', 'adr_certified',
    ];

    public static function of(FieldDefinition $definition): string
    {
        $declared = $definition->validation_rules['owner'] ?? null;
        if (in_array($declared, [self::PERSON, self::EMPLOYMENT], true)) {
            return $declared;
        }
        if ($definition->company_id !== null) {
            return self::EMPLOYMENT;
        }

        return in_array($definition->code, self::PERSON_CODES, true) ? self::PERSON : self::EMPLOYMENT;
    }
}
