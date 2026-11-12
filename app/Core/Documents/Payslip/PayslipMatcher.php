<?php

namespace App\Core\Documents\Payslip;

use App\Core\Workforce\Employee;

/**
 * ADR-762 (DMS M5) — rapprochement SÛR d'un bulletin à un Employee de la Company COURANTE.
 *
 * Règle de sécurité absolue : seule une correspondance DÉTERMINISTE unique (matricule exact) est
 * « certaine ». Un nom (ou un score IA) ne suffit JAMAIS à publier — il mène au mieux à « probable »
 * qui exige une revue humaine. Matricule dupliqué / homonymes → « ambiguous ». Aucun candidat → « none ».
 *
 * @return array{employee_id:?int, confidence:string, candidates:list<int>}
 */
class PayslipMatcher
{
    public function match(int $companyId, ?string $matricule, ?string $name): array
    {
        // 1. Déterministe : matricule exact unique → CERTAIN.
        $matricule = $matricule !== null ? trim($matricule) : null;
        if ($matricule !== '' && $matricule !== null) {
            $byMatricule = Employee::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('employee_number', $matricule)
                ->get();

            if ($byMatricule->count() === 1) {
                return ['employee_id' => (int) $byMatricule->first()->id, 'confidence' => PayslipImportItem::C_CERTAIN, 'candidates' => [(int) $byMatricule->first()->id]];
            }
            if ($byMatricule->count() > 1) {
                // Matricule dupliqué → jamais de publication automatique.
                return ['employee_id' => null, 'confidence' => PayslipImportItem::C_AMBIGUOUS, 'candidates' => $byMatricule->pluck('id')->map('intval')->all()];
            }
            // Matricule présent mais inconnu → on retombe sur le nom (au mieux « probable »).
        }

        // 2. Nom (normalisé, EXACT uniquement — pas de fuzzy) → PROBABLE si unique, AMBIGUOUS si homonymes.
        $name = $name !== null ? self::normalize($name) : null;
        if ($name) {
            $candidates = Employee::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->get()
                ->filter(fn ($e) => self::normalize($e->first_name.' '.$e->last_name) === $name
                    || self::normalize($e->last_name.' '.$e->first_name) === $name)
                ->values();

            if ($candidates->count() === 1) {
                return ['employee_id' => (int) $candidates->first()->id, 'confidence' => PayslipImportItem::C_PROBABLE, 'candidates' => [(int) $candidates->first()->id]];
            }
            if ($candidates->count() > 1) {
                return ['employee_id' => null, 'confidence' => PayslipImportItem::C_AMBIGUOUS, 'candidates' => $candidates->pluck('id')->map('intval')->all()];
            }
        }

        // 3. Aucun candidat.
        return ['employee_id' => null, 'confidence' => PayslipImportItem::C_NONE, 'candidates' => []];
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s+/', ' ', $s);
        // Translittération basique des accents (comparaison robuste).
        $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);

        return $s;
    }
}
