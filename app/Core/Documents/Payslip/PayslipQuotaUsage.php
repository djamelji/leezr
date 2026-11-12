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

    // ADR-767 — the migration (dms_m51) creates `payslip_quota_usage` (singular). Without this the model
    // resolved to `payslip_quota_usages`, a table that does not exist: every REAL ingestion crashed on
    // `bump()` (never seen by the earlier suites, which bypassed the ingestor).
    protected $table = 'payslip_quota_usage';

    // Per-batch limits — SINGLE source of truth (the validator and the HTTP layer both read these).
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
