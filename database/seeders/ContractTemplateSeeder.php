<?php

namespace Database\Seeders;

use App\Core\Documents\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * ADR-647 (W-CONTRACT-1) — modèle de contrat de DÉMONSTRATION au catalogue Platform.
 *
 * Marqué `example_unvalidated` : Leezr ne garantit AUCUNE conformité juridique. Les données
 * (market/type) sont des exemples — la résolution métier ne code jamais FR/CDI en dur.
 * Idempotent (updateOrCreate par code+version).
 */
class ContractTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $body = <<<'HTML'
<h1>Contrat de travail à durée indéterminée</h1>
<p>Entre les soussignés :</p>
<p><strong>{{ company.name }}</strong>, SIRET {{ company.siret }}, ci-après « l'Employeur »,</p>
<p>Et <strong>{{ employee.full_name }}</strong>, ci-après « le Collaborateur »,</p>
<h2>Article 1 — Engagement</h2>
<p>Le Collaborateur {{ employee.full_name }} (réf. {{ employee.employee_number }}) est engagé à compter du {{ contract.start_date_formatted }}.</p>
<h2>Article 2 — Durée du travail</h2>
<p>La durée hebdomadaire de travail est fixée à {{ contract.weekly_hours }} heures ({{ contract.work_model }}).</p>
<h2>Article 3 — Type de contrat</h2>
<p>Le présent contrat est conclu en {{ contract.contract_type_label }}.</p>
<p><em>Document d'exemple non validé juridiquement — à adapter par l'entreprise.</em></p>
HTML;

        DocumentTemplate::updateOrCreate(
            ['company_id' => null, 'code' => 'contract_cdi_fr_demo', 'version' => 1],
            [
                'platform_template_id' => null,
                'name' => 'Contrat CDI (exemple)',
                'description' => 'Modèle de démonstration — non validé juridiquement.',
                'subject_type' => 'contract',
                'body_html' => $body,
                'variables_schema' => [],
                'is_system' => true,
                'is_active' => true,
                'paper_size' => 'a4',
                'paper_orientation' => 'portrait',
                'metadata' => [
                    'contract_type' => 'cdi',
                    'market_key' => 'FR',
                    'jobdomain_key' => null,
                    'legal_status' => 'example_unvalidated',
                    'status' => 'published',
                    'is_default' => false,
                ],
            ],
        );
    }
}
