<?php

namespace App\Core\Documents;

use Illuminate\Support\Facades\Log;
use Rakibdevs\MrzParser\MrzParser as BaseMrzParser;

/**
 * MRZ (Machine Readable Zone) parser wrapper.
 * Detects and parses MRZ lines from OCR text output.
 *
 * MRZ = source of truth for identity documents (100% reliable, instant, free).
 * Supports: TD1 (ID cards), TD2 (visas), TD3 (passports), MRVA/MRVB.
 */
class MrzParser
{
    /**
     * Try to detect and parse MRZ from OCR text.
     * Returns null if no MRZ pattern found.
     */
    public function parse(string $ocrText): ?MrzResult
    {
        $mrzLines = $this->extractMrzLines($ocrText);

        if (! $mrzLines) {
            return null;
        }

        try {
            $parser = new BaseMrzParser();
            $parsed = $parser->parse($mrzLines);

            if (! $parsed || empty($parsed)) {
                return null;
            }

            return MrzResult::fromParsed($parsed);
        } catch (\Throwable $e) {
            Log::info('MrzParser: parse failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Extract MRZ lines from OCR text.
     * MRZ lines contain only uppercase letters, digits, and '<' filler characters.
     *
     * TD1 (ID cards): 3 lines of 30 chars
     * TD3 (passports): 2 lines of 44 chars
     * TD2: 2 lines of 36 chars
     */
    private function extractMrzLines(string $text): ?string
    {
        // Normalize: replace common OCR mistakes
        $text = str_replace(['«', '»', '‹', '›'], '<', $text);

        // Find lines that look like MRZ (uppercase + digits + '<', length 30-44)
        $lines = preg_split('/\r?\n/', $text);
        $mrzCandidates = [];

        foreach ($lines as $line) {
            $cleaned = trim($line);
            $cleaned = preg_replace('/\s+/', '', $cleaned);

            // MRZ line: only A-Z, 0-9, < — and between 28-44 chars
            if (preg_match('/^[A-Z0-9<]{28,44}$/', $cleaned)) {
                $mrzCandidates[] = $cleaned;
            }
        }

        if (empty($mrzCandidates)) {
            return null;
        }

        // TD1: 3 lines of 30 chars
        if (count($mrzCandidates) >= 3) {
            $td1 = array_slice($mrzCandidates, 0, 3);
            if (strlen($td1[0]) === 30 && strlen($td1[1]) === 30 && strlen($td1[2]) === 30) {
                return implode("\n", $td1);
            }
        }

        // TD3: 2 lines of 44 chars
        if (count($mrzCandidates) >= 2) {
            $td3 = array_slice($mrzCandidates, 0, 2);
            if (strlen($td3[0]) === 44 && strlen($td3[1]) === 44) {
                return implode("\n", $td3);
            }
        }

        // TD2: 2 lines of 36 chars
        if (count($mrzCandidates) >= 2) {
            $td2 = array_slice($mrzCandidates, 0, 2);
            if (strlen($td2[0]) === 36 && strlen($td2[1]) === 36) {
                return implode("\n", $td2);
            }
        }

        return null;
    }
}
