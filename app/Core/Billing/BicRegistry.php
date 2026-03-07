<?php

namespace App\Core\Billing;

class BicRegistry
{
    /**
     * Common European bank institution codes (first 4 chars of BIC/SWIFT).
     * Used to resolve human-readable bank names from Stripe's bank_code.
     */
    private const BANKS = [
        // France
        'BNPA' => 'BNP Paribas',
        'SOGE' => 'Société Générale',
        'CRLY' => 'LCL',
        'CEPA' => "Caisse d'Épargne",
        'AGRI' => 'Crédit Agricole',
        'CMCI' => 'CIC',
        'CCBP' => 'Banque Populaire',
        'BRED' => 'BRED',
        'LAYD' => 'La Banque Postale',
        'CMCF' => 'Crédit Mutuel',
        'HSBC' => 'HSBC',
        'PSSTFRPP' => 'La Banque Postale',

        // Allemagne
        'DEUT' => 'Deutsche Bank',
        'COBA' => 'Commerzbank',
        'HYVE' => 'HypoVereinsbank',
        'GENO' => 'Volksbank',
        'COBADE' => 'Commerzbank',

        // Pays-Bas
        'INGB' => 'ING',
        'RABO' => 'Rabobank',
        'ABNA' => 'ABN AMRO',

        // Belgique
        'GEBA' => 'BNP Paribas Fortis',
        'KRED' => 'KBC',
        'BELF' => 'Belfius',

        // Espagne
        'BBVA' => 'BBVA',
        'CAIX' => 'CaixaBank',
        'BSCH' => 'Santander',

        // Italie
        'BCIT' => 'Intesa Sanpaolo',
        'UNIE' => 'UniCredit',

        // Luxembourg
        'BGLLLULL' => 'BGL BNP Paribas',
        'CELL' => 'Banque de Luxembourg',

        // International
        'BNPA' => 'BNP Paribas',
        'REVO' => 'Revolut',
        'TRWI' => 'Wise',
        'BUNQ' => 'bunq',
        'N26' => 'N26',
    ];

    /**
     * Resolve a bank name from a BIC/institution code.
     * Stripe returns the institution code (first 4+ chars of the BIC).
     */
    public static function resolve(string $bankCode): ?string
    {
        $code = strtoupper(trim($bankCode));

        // Try exact match first (for longer codes)
        if (isset(self::BANKS[$code])) {
            return self::BANKS[$code];
        }

        // Try first 4 chars (standard institution code)
        $key = substr($code, 0, 4);

        return self::BANKS[$key] ?? null;
    }
}
