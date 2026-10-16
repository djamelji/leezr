<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every VAT exemption reason the backend can emit (TaxContextResolver) must have a
 * `billing.tax_exemption.{reason}` label in EVERY shipped locale — otherwise the
 * billing UI (register summary, next-invoice, invoice detail, plan change) shows a
 * raw i18n key or an untranslated fallback. Enumerates the reasons from source so a
 * newly-added reason without a translation fails this test.
 */
class TaxExemptionI18nCoverageTest extends TestCase
{
    /** @return string[] distinct non-null exemption reasons emitted by the resolver */
    private function backendReasons(): array
    {
        $source = file_get_contents(base_path('app/Modules/Core/Billing/Services/TaxContextResolver.php'));
        preg_match_all("/exemptionReason:\s*'([a-z_]+)'/", $source, $m);

        return array_values(array_unique($m[1]));
    }

    private function locale(string $code): array
    {
        return json_decode(
            file_get_contents(base_path("resources/js/plugins/i18n/locales/{$code}.json")),
            true
        );
    }

    public function test_backend_emits_the_expected_reasons(): void
    {
        // Guard the enumeration itself (keeps this test meaningful if the resolver changes).
        $this->assertEqualsCanonicalizing(
            ['export_extra_eu', 'reverse_charge_intra_eu'],
            $this->backendReasons(),
        );
    }

    public function test_every_reason_is_translated_in_every_locale(): void
    {
        $reasons = $this->backendReasons();
        $this->assertNotEmpty($reasons);

        foreach (['en', 'fr', 'ar'] as $code) {
            $messages = $this->locale($code);

            foreach ($reasons as $reason) {
                $key = "billing.tax_exemption.{$reason}";
                $this->assertArrayHasKey($key, $messages, "Missing {$key} in {$code}.json");
                $this->assertNotEmpty($messages[$key], "Empty {$key} in {$code}.json");
            }
        }
    }
}
