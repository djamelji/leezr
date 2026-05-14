<?php

namespace App\Core\Workforce\Dsn;

use App\Core\Workforce\DsnDeclaration;
use Illuminate\Support\Facades\Storage;

/**
 * Pre-flight safety checks before DSN submission.
 *
 * Pure read-only service — ZERO DB writes, ZERO side effects.
 * All checks are based on declaration state + stored validation results.
 *
 * Sprint 6.8 — ADR-526
 */
class DsnPreflightChecker
{
    /**
     * Run all pre-flight checks on a declaration.
     */
    public function check(DsnDeclaration $declaration): PreflightResult
    {
        $errors = [];
        $warnings = [];

        // 1. Status must be 'exported'
        if ($declaration->status !== DsnDeclaration::STATUS_EXPORTED) {
            $errors[] = "Declaration status is '{$declaration->status}', expected 'exported'.";
        }

        // 2. File must exist
        if (empty($declaration->file_path)) {
            $errors[] = 'No DSN file path set on declaration.';
        } elseif (! Storage::disk('local')->exists($declaration->file_path)) {
            $errors[] = "DSN file not found at '{$declaration->file_path}'.";
        }

        // 3. Payload hash must be set
        if (empty($declaration->payload_hash)) {
            $errors[] = 'No payload hash — declaration may not have been properly exported.';
        }

        // 4. No blocking validation errors
        $validationEntries = $declaration->validation_errors ?? [];
        $blockingValidation = array_filter(
            $validationEntries,
            fn ($e) => ($e['severity'] ?? 'error') === 'error'
        );

        if (! empty($blockingValidation)) {
            $errors[] = 'Declaration has ' . count($blockingValidation) . ' blocking validation error(s).';
        }

        // Warnings from validation are passed through
        $validationWarnings = array_filter(
            $validationEntries,
            fn ($e) => ($e['severity'] ?? 'error') === 'warning'
        );
        foreach ($validationWarnings as $w) {
            $warnings[] = $w['message'] ?? 'Validation warning';
        }

        // 5. Payload snapshot must exist (for hash verification)
        if (empty($declaration->payload_snapshot)) {
            $errors[] = 'No payload snapshot stored — cannot verify integrity.';
        }

        // 6. SIRET check from snapshot
        $snapshot = $declaration->payload_snapshot ?? [];
        $companySiret = $snapshot['company']['siret'] ?? null;

        if (empty($companySiret)) {
            $errors[] = 'Company SIRET missing from payload snapshot.';
        } elseif (! DsnCompanyResolver::validateSiret($companySiret)) {
            $errors[] = "Company SIRET '{$companySiret}' fails Luhn checksum.";
        }

        // 7. Employee NIR checks from snapshot
        $employees = $snapshot['employees'] ?? [];
        if (empty($employees)) {
            $errors[] = 'No employees in payload snapshot.';
        } else {
            foreach ($employees as $i => $empBlock) {
                $nir = $empBlock['identity']['nir'] ?? null;
                $name = ($empBlock['identity']['lastName'] ?? '') . ' ' . ($empBlock['identity']['firstName'] ?? '');

                if (empty($nir)) {
                    $errors[] = "Employee {$name}: NIR missing.";
                } elseif (! DsnEmployeeResolver::validateNir($nir)) {
                    $errors[] = "Employee {$name}: NIR '{$nir}' fails modulo-97 check.";
                }
            }
        }

        // 8. CTP mapping completeness from snapshot
        foreach ($employees as $empBlock) {
            $contributions = $empBlock['contributionLines'] ?? [];
            foreach ($contributions as $line) {
                $code = $line['code'] ?? '';
                if (empty($code) || DsnCtpMapping::isCsgCrds($code)) {
                    continue;
                }
                if (DsnCtpMapping::get($code) === null) {
                    $errors[] = "Contribution code '{$code}' has no CTP mapping.";
                }
            }
        }

        // 9. Hash integrity — verify stored hash matches DSN file on disk
        if (! empty($declaration->payload_hash) && ! empty($declaration->file_path)) {
            $disk = Storage::disk('local');
            if ($disk->exists($declaration->file_path)) {
                $recomputedHash = hash('sha256', $disk->get($declaration->file_path));
                if ($recomputedHash !== $declaration->payload_hash) {
                    $warnings[] = 'Payload hash differs from file on disk — file may have been modified after export.';
                }
            }
        }

        if (empty($errors)) {
            return PreflightResult::ready($warnings);
        }

        return PreflightResult::blocked($errors, $warnings);
    }
}
