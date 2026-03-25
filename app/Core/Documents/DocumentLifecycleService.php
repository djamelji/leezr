<?php

namespace App\Core\Documents;

use Carbon\Carbon;

/**
 * ADR-384: Pure lifecycle status computation for documents.
 *
 * This service computes the lifecycle_status of a document based on its
 * upload state and expiration date. It is PURE (no DB reads, no mutations,
 * no side effects) and can be called from any context.
 *
 * Statuses:
 *   - missing:       no file uploaded
 *   - valid:         file exists, not expired (or no expiration date)
 *   - expiring_soon: file exists, expires within threshold (default 30 days)
 *   - expired:       file exists, expiration date has passed
 */
class DocumentLifecycleService
{
    public const STATUS_MISSING = 'missing';

    public const STATUS_VALID = 'valid';

    public const STATUS_EXPIRING_SOON = 'expiring_soon';

    public const STATUS_EXPIRED = 'expired';

    public const DEFAULT_EXPIRING_SOON_DAYS = 30;

    /**
     * Compute lifecycle status from upload data.
     *
     * @param  array|null  $upload  The upload array (with 'expires_at' ISO8601 string or null)
     * @param  int  $expiringSoonDays  Threshold in days for "expiring_soon" (default 30)
     */
    public static function computeStatus(?array $upload, int $expiringSoonDays = self::DEFAULT_EXPIRING_SOON_DAYS): string
    {
        if ($upload === null) {
            return self::STATUS_MISSING;
        }

        $expiresAt = $upload['expires_at'] ?? null;

        if ($expiresAt === null) {
            return self::STATUS_VALID;
        }

        $expiration = Carbon::parse($expiresAt);

        if ($expiration->isPast()) {
            return self::STATUS_EXPIRED;
        }

        if ($expiration->lte(Carbon::now()->addDays($expiringSoonDays))) {
            return self::STATUS_EXPIRING_SOON;
        }

        return self::STATUS_VALID;
    }

    /**
     * Compute lifecycle status directly from a Carbon date (for contexts
     * where the upload array is not available, e.g. CompanyDocumentReadModel).
     *
     * @param  bool  $hasUpload  Whether a file exists
     * @param  Carbon|null  $expiresAt  The expiration date
     * @param  int  $expiringSoonDays  Threshold in days
     */
    public static function computeFromDate(bool $hasUpload, ?Carbon $expiresAt, int $expiringSoonDays = self::DEFAULT_EXPIRING_SOON_DAYS): string
    {
        if (! $hasUpload) {
            return self::STATUS_MISSING;
        }

        if ($expiresAt === null) {
            return self::STATUS_VALID;
        }

        if ($expiresAt->isPast()) {
            return self::STATUS_EXPIRED;
        }

        if ($expiresAt->lte(Carbon::now()->addDays($expiringSoonDays))) {
            return self::STATUS_EXPIRING_SOON;
        }

        return self::STATUS_VALID;
    }

    /**
     * All possible lifecycle statuses (for validation / enum checks).
     */
    public static function allStatuses(): array
    {
        return [
            self::STATUS_MISSING,
            self::STATUS_VALID,
            self::STATUS_EXPIRING_SOON,
            self::STATUS_EXPIRED,
        ];
    }
}
