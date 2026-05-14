<?php

namespace App\Core\Workforce\Dsn;

/**
 * Serializes a DsnPayload into NEODES Phase 3 DSN text format.
 *
 * Pure static service — ZERO DB access, ZERO side effects.
 * Output is a text string in ISO-8859-1 encoding with comma-separated
 * rubrique/value pairs, values in single quotes.
 *
 * Format per line: S21.G00.30.001,'value'
 *
 * Blocs implemented:
 *   S21.G00.06 — Établissement d'affectation
 *   S21.G00.11 — Changement (stub — no changes in v1)
 *   S21.G00.30 — Individu (employee identity)
 *   S21.G00.40 — Contrat
 *   S21.G00.50 — Versement individu (payment)
 *   S21.G00.51 — Rémunération (remuneration lines)
 *   S21.G00.78 — Base assujettie (CSG/CRDS)
 *   S21.G00.81 — Cotisation individuelle
 *
 * Sprint 6.3 — ADR-521
 */
class DsnBlockSerializer
{
    const SERIALIZER_VERSION = 'dsn-serial-v1';

    /**
     * Serialize a complete DsnPayload into DSN text.
     * Returns UTF-8 string — caller converts to ISO-8859-1 if needed for transmission.
     */
    public static function serialize(DsnPayload $payload): string
    {
        $lines = [];

        // S21.G00.06 — Établissement
        $lines = array_merge($lines, self::serializeEtablissement($payload->company, $payload->periodStart, $payload->periodEnd));

        // S21.G00.22 — Cotisations agrégées CTP
        foreach ($payload->ctpAggregates as $aggregate) {
            $lines = array_merge($lines, self::serializeCtpAggregate($aggregate));
        }

        // Per-employee blocs
        foreach ($payload->employees as $employeeBlock) {
            // S21.G00.30 — Individu
            $lines = array_merge($lines, self::serializeIndividu($employeeBlock->identity));

            // S21.G00.40 — Contrat
            $lines = array_merge($lines, self::serializeContrat($employeeBlock->contract));

            // S21.G00.50 — Versement
            $lines = array_merge($lines, self::serializeVersement($employeeBlock->payment));

            // S21.G00.51 — Rémunération
            foreach ($employeeBlock->remunerationLines as $remLine) {
                $lines = array_merge($lines, self::serializeRemuneration($remLine));
            }

            // S21.G00.78 — Bases assujetties CSG/CRDS
            foreach ($employeeBlock->csgCrdsLines as $csgLine) {
                $lines = array_merge($lines, self::serializeBaseAssujettie($csgLine));
            }

            // S21.G00.81 — Cotisations individuelles
            foreach ($employeeBlock->contributionLines as $contribLine) {
                $lines = array_merge($lines, self::serializeCotisationIndividuelle($contribLine));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Count total lines that would be generated.
     */
    public static function lineCount(DsnPayload $payload): int
    {
        return substr_count(self::serialize($payload), "\n") + 1;
    }

    // ── Bloc serializers ──

    /**
     * S21.G00.06 — Établissement d'affectation des salariés
     */
    private static function serializeEtablissement(DsnCompanyData $company, string $periodStart, string $periodEnd): array
    {
        $lines = [];

        // 001 - NIC (5 derniers chiffres du SIRET)
        $lines[] = self::line('S21.G00.06.001', $company->nic ?? '');
        // 002 - Adresse voie
        $lines[] = self::line('S21.G00.06.002', $company->addressStreet ?? '');
        // 003 - Code postal
        $lines[] = self::line('S21.G00.06.003', $company->addressPostalCode ?? '');
        // 004 - Localité
        $lines[] = self::line('S21.G00.06.004', $company->addressCity ?? '');
        // 005 - Complément d'adresse
        if ($company->addressComplement) {
            $lines[] = self::line('S21.G00.06.005', $company->addressComplement);
        }
        // 008 - Code APE/NAF
        $lines[] = self::line('S21.G00.06.008', $company->nafCode ?? '');
        // 017 - Effectif moyen
        if ($company->averageHeadcount !== null) {
            $lines[] = self::line('S21.G00.06.017', (string) $company->averageHeadcount);
        }
        // 300 - Code IDCC convention collective
        if ($company->idcc) {
            $lines[] = self::line('S21.G00.06.300', $company->idcc);
        }

        return $lines;
    }

    /**
     * S21.G00.22 — Cotisation agrégée (CTP)
     */
    private static function serializeCtpAggregate(DsnCtpAggregate $aggregate): array
    {
        $lines = [];

        // 001 - Code de cotisation (CTP)
        $lines[] = self::line('S21.G00.22.001', $aggregate->ctpCode);
        // 003 - Qualifiant d'assiette: '920' = assiette plafonnée
        $lines[] = self::line('S21.G00.22.003', '920');
        // 004 - Montant d'assiette
        $lines[] = self::line('S21.G00.22.004', self::formatCents($aggregate->baseCents));
        // 005 - Montant de cotisation (total employee + employer)
        $totalCotisation = $aggregate->employeeCents + $aggregate->employerCents;
        $lines[] = self::line('S21.G00.22.005', self::formatCents($totalCotisation));
        // 006 - Effectif
        $lines[] = self::line('S21.G00.22.006', (string) $aggregate->employeeCount);

        return $lines;
    }

    /**
     * S21.G00.30 — Individu (employee identity)
     */
    private static function serializeIndividu(DsnEmployeeData $employee): array
    {
        $lines = [];

        // 001 - NIR
        $lines[] = self::line('S21.G00.30.001', self::formatNir($employee->nir ?? ''));
        // 002 - Nom de famille
        $lines[] = self::line('S21.G00.30.002', mb_strtoupper($employee->lastName));
        // 004 - Prénom
        $lines[] = self::line('S21.G00.30.004', $employee->firstName);
        // 005 - Sexe (01=M, 02=F)
        $lines[] = self::line('S21.G00.30.005', $employee->dsnGenderCode() ?? '');
        // 006 - Date de naissance
        $lines[] = self::line('S21.G00.30.006', self::formatDate($employee->birthDate));
        // 007 - Lieu de naissance (commune)
        if ($employee->birthCity) {
            $lines[] = self::line('S21.G00.30.007', $employee->birthCity);
        }
        // 008 - Code département de naissance
        if ($employee->birthDepartment) {
            $lines[] = self::line('S21.G00.30.008', $employee->birthDepartment);
        }
        // 009 - Code pays de naissance
        $lines[] = self::line('S21.G00.30.009', $employee->birthCountry ?? '');
        // 011 - Adresse voie
        $lines[] = self::line('S21.G00.30.011', $employee->addressStreet ?? '');
        // 013 - Code postal
        $lines[] = self::line('S21.G00.30.013', $employee->addressPostalCode ?? '');
        // 014 - Localité
        $lines[] = self::line('S21.G00.30.014', $employee->addressCity ?? '');
        // 015 - Code pays
        $lines[] = self::line('S21.G00.30.015', $employee->addressCountryCode ?? 'FR');

        return $lines;
    }

    /**
     * S21.G00.40 — Contrat
     */
    private static function serializeContrat(array $contract): array
    {
        $lines = [];

        // 001 - Date de début de contrat
        $lines[] = self::line('S21.G00.40.001', self::formatDate($contract['start_date'] ?? ''));
        // 002 - Statut du salarié (convention) — default '01' CDI
        $lines[] = self::line('S21.G00.40.002', $contract['status_code'] ?? '01');
        // 007 - Nature du contrat
        $lines[] = self::line('S21.G00.40.007', $contract['nature_code'] ?? '01');
        // 009 - Numéro de contrat
        if (! empty($contract['contract_number'])) {
            $lines[] = self::line('S21.G00.40.009', $contract['contract_number']);
        }
        // 011 - Quotité de travail de référence de l'entreprise (en centièmes)
        $lines[] = self::line('S21.G00.40.011', $contract['company_weekly_hours_hundredths'] ?? '3500');
        // 012 - Quotité de travail du contrat (en centièmes)
        $lines[] = self::line('S21.G00.40.012', $contract['contract_weekly_hours_hundredths'] ?? '3500');
        // 019 - Code PCS-ESE
        if (! empty($contract['pcs_code'])) {
            $lines[] = self::line('S21.G00.40.019', $contract['pcs_code']);
        }
        // 040 - Code IDCC
        if (! empty($contract['idcc'])) {
            $lines[] = self::line('S21.G00.40.040', $contract['idcc']);
        }

        return $lines;
    }

    /**
     * S21.G00.50 — Versement individu
     */
    private static function serializeVersement(array $payment): array
    {
        $lines = [];

        // 001 - Date de versement
        $lines[] = self::line('S21.G00.50.001', self::formatDate($payment['payment_date'] ?? ''));
        // 002 - Rémunération nette fiscale
        $lines[] = self::line('S21.G00.50.002', self::formatCents($payment['net_taxable_cents'] ?? 0));
        // 004 - Montant net versé
        $lines[] = self::line('S21.G00.50.004', self::formatCents($payment['net_payable_cents'] ?? 0));
        // 006 - Taux de PAS (en %)
        $lines[] = self::line('S21.G00.50.006', self::formatBpsAsPercent($payment['tax_rate_bps'] ?? 0));
        // 007 - Type de taux
        $lines[] = self::line('S21.G00.50.007', $payment['tax_rate_type'] ?? '01');
        // 008 - Montant du PAS
        $lines[] = self::line('S21.G00.50.008', self::formatCents($payment['tax_cents'] ?? 0));

        return $lines;
    }

    /**
     * S21.G00.51 — Rémunération
     */
    private static function serializeRemuneration(array $remLine): array
    {
        $lines = [];

        // 001 - Date de début de période de paie
        $lines[] = self::line('S21.G00.51.001', self::formatDate($remLine['period_start'] ?? ''));
        // 002 - Date de fin de période de paie
        $lines[] = self::line('S21.G00.51.002', self::formatDate($remLine['period_end'] ?? ''));
        // 010 - Numéro du contrat
        if (! empty($remLine['contract_number'])) {
            $lines[] = self::line('S21.G00.51.010', $remLine['contract_number']);
        }
        // 011 - Type de rémunération
        $lines[] = self::line('S21.G00.51.011', $remLine['type_code'] ?? '001');
        // 012 - Nombre d'heures
        if (isset($remLine['hours'])) {
            $lines[] = self::line('S21.G00.51.012', self::formatDecimal($remLine['hours'], 2));
        }
        // 013 - Montant
        $lines[] = self::line('S21.G00.51.013', self::formatCents($remLine['amount_cents'] ?? 0));

        return $lines;
    }

    /**
     * S21.G00.78 — Base assujettie (CSG/CRDS)
     */
    private static function serializeBaseAssujettie(array $csgLine): array
    {
        $lines = [];

        // 001 - Code de base assujettie
        $lines[] = self::line('S21.G00.78.001', $csgLine['base_code'] ?? '');
        // 002 - Date de début de période de rattachement
        $lines[] = self::line('S21.G00.78.002', self::formatDate($csgLine['period_start'] ?? ''));
        // 003 - Date de fin de période de rattachement
        $lines[] = self::line('S21.G00.78.003', self::formatDate($csgLine['period_end'] ?? ''));
        // 004 - Montant
        $lines[] = self::line('S21.G00.78.004', self::formatCents($csgLine['amount_cents'] ?? 0));

        return $lines;
    }

    /**
     * S21.G00.81 — Cotisation individuelle
     */
    private static function serializeCotisationIndividuelle(array $contribLine): array
    {
        $lines = [];

        // 001 - Code de cotisation
        $lines[] = self::line('S21.G00.81.001', $contribLine['code'] ?? '');
        // 002 - Identifiant organisme
        if (! empty($contribLine['organisme_id'])) {
            $lines[] = self::line('S21.G00.81.002', $contribLine['organisme_id']);
        }
        // 003 - Montant d'assiette
        $lines[] = self::line('S21.G00.81.003', self::formatCents($contribLine['base_cents'] ?? 0));
        // 004 - Montant de cotisation salarié
        $lines[] = self::line('S21.G00.81.004', self::formatCents($contribLine['employee_cents'] ?? 0));
        // 005 - Montant de cotisation employeur
        if (isset($contribLine['employer_cents'])) {
            $lines[] = self::line('S21.G00.81.005', self::formatCents($contribLine['employer_cents']));
        }

        return $lines;
    }

    // ── Formatting helpers ──

    /**
     * Format a single DSN line: rubrique,'value'
     */
    private static function line(string $rubrique, string $value): string
    {
        // DSN values must be single-quoted, no internal single quotes
        $sanitized = str_replace("'", ' ', $value);

        return "{$rubrique},'{$sanitized}'";
    }

    /**
     * Format cents as DSN decimal string (e.g. 123456 → '1234.56').
     */
    public static function formatCents(int $cents): string
    {
        $euros = $cents / 100;

        return number_format($euros, 2, '.', '');
    }

    /**
     * Format basis points as DSN percent string (e.g. 1150 → '11.50').
     */
    public static function formatBpsAsPercent(int $bps): string
    {
        $percent = $bps / 100;

        return number_format($percent, 2, '.', '');
    }

    /**
     * Format a decimal number with specified precision.
     */
    public static function formatDecimal(float $value, int $precision = 2): string
    {
        return number_format($value, $precision, '.', '');
    }

    /**
     * Format a date string from Y-m-d to ddmmyyyy (DSN format).
     */
    public static function formatDate(?string $date): string
    {
        if (empty($date)) {
            return '';
        }

        $parts = explode('-', $date);
        if (count($parts) !== 3) {
            return $date;
        }

        return $parts[2] . $parts[1] . $parts[0]; // ddmmyyyy
    }

    /**
     * Format NIR: remove spaces and dashes for DSN.
     */
    public static function formatNir(?string $nir): string
    {
        if ($nir === null) {
            return '';
        }

        return str_replace([' ', '-', '.'], '', $nir);
    }
}
