<?php

namespace App\Core\Documents\Payslip;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-763 (DMS M5.1) — compteurs d'usage de la distribution de bulletins externes (indépendant de
 * `workforce_payroll`). Limites minimales appliquées côté serveur avec refus explicites.
 */
class PayslipQuotaUsage extends Model
{
    use BelongsToCompany;

    // Limites par défaut (par lot). La capacité est activée par les permissions payslips.*.
    public const MAX_FILES_PER_BATCH = 20;
    public const MAX_PAGES_PER_BATCH = 3000;

    protected $fillable = ['company_id', 'files_imported', 'pages_processed', 'ocr_pages'];

    protected $casts = [
        'files_imported' => 'integer',
        'pages_processed' => 'integer',
        'ocr_pages' => 'integer',
    ];

    public static function bump(int $companyId, int $filesImported = 0, int $pagesProcessed = 0, int $ocrPages = 0): void
    {
        $row = static::firstOrCreate(['company_id' => $companyId]);
        $row->increment('files_imported', $filesImported);
        $row->increment('pages_processed', $pagesProcessed);
        if ($ocrPages) {
            $row->increment('ocr_pages', $ocrPages);
        }
    }

    public static function forCompany(int $companyId): self
    {
        return static::firstOrCreate(['company_id' => $companyId]);
    }
}
