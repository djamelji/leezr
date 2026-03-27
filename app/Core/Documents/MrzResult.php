<?php

namespace App\Core\Documents;

/**
 * Structured result from MRZ parsing.
 */
final class MrzResult
{
    public function __construct(
        public readonly ?string $documentType,   // P (passport), ID, etc.
        public readonly ?string $country,
        public readonly ?string $lastName,
        public readonly ?string $firstName,
        public readonly ?string $documentNumber,
        public readonly ?string $nationality,
        public readonly ?string $birthDate,      // YYYY-MM-DD
        public readonly ?string $expiryDate,     // YYYY-MM-DD
        public readonly ?string $sex,
        public readonly ?string $optionalData,
    ) {}

    /**
     * Build from rakibdevs/mrz-parser output.
     */
    public static function fromParsed(array $parsed): self
    {
        return new self(
            documentType: $parsed['type'] ?? null,
            country: $parsed['issuer'] ?? ($parsed['country'] ?? null),
            lastName: $parsed['last_name'] ?? ($parsed['surname'] ?? null),
            firstName: $parsed['first_name'] ?? ($parsed['given_name'] ?? null),
            documentNumber: $parsed['card_no'] ?? ($parsed['document_number'] ?? null),
            nationality: $parsed['nationality'] ?? null,
            birthDate: self::formatDate($parsed['date_of_birth'] ?? ($parsed['birth_date'] ?? null)),
            expiryDate: self::formatDate($parsed['date_of_expiry'] ?? ($parsed['expiry_date'] ?? null)),
            sex: $parsed['gender'] ?? ($parsed['sex'] ?? null),
            optionalData: $parsed['personal_number'] ?? ($parsed['optional_data'] ?? null),
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'document_type' => $this->documentType,
            'country' => $this->country,
            'last_name' => $this->lastName,
            'first_name' => $this->firstName,
            'document_number' => $this->documentNumber,
            'nationality' => $this->nationality,
            'birth_date' => $this->birthDate,
            'expiry_date' => $this->expiryDate,
            'sex' => $this->sex,
            'optional_data' => $this->optionalData,
        ], fn ($v) => $v !== null);
    }

    private static function formatDate(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        // MRZ dates are YYMMDD — convert to YYYY-MM-DD
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $raw, $m)) {
            $year = (int) $m[1];
            // MRZ convention: 00-30 = 2000-2030, 31-99 = 1931-1999
            $fullYear = $year <= 30 ? 2000 + $year : 1900 + $year;

            return sprintf('%04d-%02d-%02d', $fullYear, (int) $m[2], (int) $m[3]);
        }

        return $raw;
    }
}
