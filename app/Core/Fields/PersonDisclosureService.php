<?php

namespace App\Core\Fields;

use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-796 (prérequis B / C) — the PERSON decides which of their existing account values a company may read
 * (ambiguous historical values are never attributed to an employer automatically). Every disclosure is
 * traced (company, field, source, purpose, date) ; a withdrawal is RECORDED for HR review, never applied
 * automatically (the applicable legal basis may require the employer to keep the data).
 */
final class PersonDisclosureService
{
    public function __construct(private Company $company, private User $user) {}

    /** Codes holding an account value this company cannot read (yet). */
    public function undisclosed(): array
    {
        return collect((new EmployeeFieldStore($this->company))->cellsFor(null, $this->user))
            ->filter(fn ($c) => $c['not_disclosed'])->keys()->values()->all();
    }

    /** @return list<string> codes shared now */
    public function share(array $codes): array
    {
        $allowed = array_intersect($codes, $this->undisclosed());
        $defs = (new EmployeeFieldStore($this->company))->definitions()->keyBy('code');
        foreach ($allowed as $code) {
            DB::table('person_field_disclosures')->insertOrIgnore(['user_id' => $this->user->id, 'field_definition_id' => $defs[$code]->id,
                'company_id' => $this->company->id, 'source' => 'self_confirmed', 'purpose' => 'shared_by_person', 'created_at' => now(), 'updated_at' => now()]);
        }

        return array_values($allowed);
    }

    public function requestWithdrawal(string $code, ?string $reason): void
    {
        $def = (new EmployeeFieldStore($this->company))->definitions()->firstWhere('code', $code);
        $n = $def ? DB::table('person_field_disclosures')->where(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'field_definition_id' => $def->id])
            ->update(['withdrawal_requested_at' => now(), 'withdrawal_reason' => $reason ? mb_substr($reason, 0, 200) : null, 'updated_at' => now()]) : 0;
        if (! $n) {
            throw ValidationException::withMessages(['code' => __('fields.not_disclosed')]);
        }
    }
}
