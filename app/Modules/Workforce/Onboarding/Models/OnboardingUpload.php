<?php

namespace App\Modules\Workforce\Onboarding\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-796.1 — a file deposit from the public portal, held in QUARANTINE (private disk, never served)
 * until a clean analysis. Only then does it enter the DMS (single deposit path). Infected / indeterminate
 * deposits never become documents ; their files are deleted, their metadata kept for traceability.
 */
class OnboardingUpload extends Model
{
    public const QUARANTINED = 'quarantined';   // awaiting analysis (or engine unavailable : retried)
    public const INGESTED = 'ingested';         // clean → DMS document (pending HR review)
    public const INFECTED = 'infected';         // refused, files deleted
    public const SCAN_FAILED = 'scan_failed';   // indeterminate after max attempts : never usable
    public const SUPERSEDED = 'superseded';     // replaced by a later deposit before analysis

    /** States that block an HR validation of the file (nothing usable yet, or refused). */
    public const BLOCKING = [self::QUARANTINED, self::SCAN_FAILED, self::INFECTED];

    protected $fillable = ['company_id', 'onboarding_invitation_id', 'document_code', 'files', 'status', 'scan_engine', 'scan_signature',
        'scan_attempts', 'last_error', 'scanned_at', 'member_document_id', 'files_deleted_at'];

    protected $casts = ['files' => 'array', 'scanned_at' => 'datetime', 'files_deleted_at' => 'datetime'];
}
