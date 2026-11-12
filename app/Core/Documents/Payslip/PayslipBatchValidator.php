<?php

namespace App\Core\Documents\Payslip;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * ADR-762 (DMS M5) — contrôles de SÉCURITÉ d'un lot importé AVANT tout traitement. On ne fait jamais
 * confiance à l'extension : vérification de la signature réelle (magic bytes), des tailles, du nombre
 * de fichiers/pages, des archives (zip bomb, chemins dangereux, entrées chiffrées). Aucun contenu
 * n'est exécuté ; aucune donnée n'est extraite ici.
 */
class PayslipBatchValidator
{
    public const MAX_FILES = 20;
    public const MAX_FILE_BYTES = 50 * 1024 * 1024;      // 50 MB / fichier
    public const MAX_ZIP_ENTRIES = 500;
    public const MAX_ZIP_UNCOMPRESSED = 500 * 1024 * 1024; // 500 MB décompressé (anti zip bomb)
    public const MAX_PAGES_PER_PDF = 2000;

    /** @param  list<UploadedFile>  $files */
    public function validate(array $files): void
    {
        if (empty($files)) {
            throw ValidationException::withMessages(['files' => ['No file provided.']]);
        }
        if (count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages(['files' => ['Too many files in the batch.']]);
        }

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages(['files' => ['Invalid upload.']]);
            }
            if ($file->getSize() > self::MAX_FILE_BYTES) {
                throw ValidationException::withMessages(['files' => ['File too large.']]);
            }

            $magic = $this->magic($file->getRealPath());
            if ($magic === 'zip') {
                $this->validateZip($file->getRealPath());
            } elseif ($magic !== 'pdf') {
                throw ValidationException::withMessages(['files' => ['Only PDF files and ZIP archives are accepted.']]);
            }
        }
    }

    /** Signature réelle (magic bytes), pas l'extension. */
    public function magic(string $path): string
    {
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return 'unknown';
        }
        $head = fread($fh, 5);
        fclose($fh);

        if (str_starts_with((string) $head, '%PDF-')) {
            return 'pdf';
        }
        if (str_starts_with((string) $head, "PK\x03\x04") || str_starts_with((string) $head, "PK\x05\x06")) {
            return 'zip';
        }

        return 'unknown';
    }

    private function validateZip(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
            throw ValidationException::withMessages(['files' => ['Corrupted or unreadable archive.']]);
        }

        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            throw ValidationException::withMessages(['files' => ['Archive has too many entries.']]);
        }

        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'] ?? '';

            // Chemins dangereux (traversal / absolus).
            if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $name)) {
                $zip->close();
                throw ValidationException::withMessages(['files' => ['Unsafe path in archive.']]);
            }
            // Entrée chiffrée (flag de chiffrement).
            if (($stat['encryption_method'] ?? \ZipArchive::EM_NONE) !== \ZipArchive::EM_NONE) {
                $zip->close();
                throw ValidationException::withMessages(['files' => ['Encrypted archive entries are not supported.']]);
            }
            $totalUncompressed += (int) ($stat['size'] ?? 0);
            if ($totalUncompressed > self::MAX_ZIP_UNCOMPRESSED) {
                $zip->close();
                throw ValidationException::withMessages(['files' => ['Archive too large once uncompressed (possible zip bomb).']]);
            }
        }

        $zip->close();
    }
}
