<?php

namespace App\Core\Storage;

use App\Core\Billing\CompanyEntitlements;
use App\Core\Documents\CompanyDocument;
use App\Core\Documents\MemberDocument;
use App\Core\Models\Company;

/**
 * ADR-169 Phase 4 / ADR-174: Storage quota enforcement.
 * Sums both member_documents and company_documents.
 */
class StorageQuotaService
{
    public static function usage(Company $company): array
    {
        $usedBytes = self::totalUsedBytes($company);
        $limitGb = CompanyEntitlements::storageQuotaGb($company);
        $limitBytes = $limitGb * 1024 * 1024 * 1024;

        return [
            'used_bytes' => $usedBytes,
            'limit_bytes' => $limitBytes,
            'used_display' => self::formatBytes($usedBytes),
            'limit_display' => "{$limitGb} GB",
            'percentage' => $limitBytes > 0 ? round($usedBytes / $limitBytes * 100, 1) : 0,
            'warning' => $limitBytes > 0 && ($usedBytes / $limitBytes) >= 0.8,
            'blocked' => $limitBytes > 0 && $usedBytes >= $limitBytes,
        ];
    }

    /**
     * ADR-173: Check if a delta (bytes change) is allowed by the storage quota.
     *
     * Convention: limitBytes = 0 means unlimited (never blocked).
     * A negative delta (smaller replacement) is always allowed.
     * The check is on the PROJECTED final state, not the current usage.
     */
    public static function checkDelta(Company $company, int $delta): array
    {
        $usedBytes = self::totalUsedBytes($company);
        $limitGb = CompanyEntitlements::storageQuotaGb($company);
        $limitBytes = $limitGb * 1024 * 1024 * 1024;

        $projectedBytes = $usedBytes + $delta;
        $remainingBytes = $limitBytes > 0 ? max(0, $limitBytes - $usedBytes) : PHP_INT_MAX;

        // Convention: 0 = unlimited → always allowed
        // Negative delta → always allowed (replacement with smaller file)
        $allowed = $limitBytes === 0 || $delta <= 0 || $projectedBytes <= $limitBytes;

        return [
            'allowed' => $allowed,
            'used_bytes' => $usedBytes,
            'limit_bytes' => $limitBytes,
            'projected_bytes' => $projectedBytes,
            'remaining_bytes' => $remainingBytes,
            'used_display' => self::formatBytes($usedBytes),
            'limit_display' => $limitBytes > 0 ? "{$limitGb} GB" : 'Unlimited',
        ];
    }

    private static function totalUsedBytes(Company $company): int
    {
        $memberBytes = (int) MemberDocument::where('company_id', $company->id)->sum('file_size_bytes');
        $companyBytes = (int) CompanyDocument::where('company_id', $company->id)->sum('file_size_bytes');

        return $memberBytes + $companyBytes;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2).' GB';
    }
}
