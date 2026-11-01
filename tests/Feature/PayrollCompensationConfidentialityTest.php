<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\Employee;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRODUCT-UX-2 / LOT C2 (ADR-680) — confidentialité salariale CÔTÉ SERVEUR.
 *
 * Gate de sécurité : un utilisateur sans `workforce.compensation_read` ne doit JAMAIS
 * recevoir de montant salarial confidentiel via l'API Payroll/DSN, quel que soit son
 * accès `payroll_prepare` / `payroll_validate` / `payroll_export`. Tests NÉGATIFS :
 * on vérifie l'absence effective des champs ET montants dans le JSON, pas juste le statut.
 */
class PayrollCompensationConfidentialityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $otherCompany;
    private Employee $employee;
    private PayrollRun $run;
    private PayrollLine $line;

    /** Champs confidentiels qui ne doivent JAMAIS apparaître sans compensation_read. */
    private const CONFIDENTIAL_FIELDS = [
        'total_gross_cents', 'base_salary_cents', 'gross_basis_cents', 'gross_breakdown',
        'compensation_snapshot', 'gross_total_cents', 'contributions_employee_cents',
        'contributions_employer_cents', 'total_cost_employer_cents', 'taxable_income_cents',
        'tax_cents', 'net_before_tax_cents', 'net_payable_cents', 'net_social_cents',
        'benefits_cents', 'deductions_cents', 'contribution_lines', 'tax_breakdown',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_payroll', 'workforce_documents']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_payroll', 'name' => 'Payroll', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_documents', 'name' => 'Documents', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();

        $this->company = $this->makeCompany('conf-co');
        $this->otherCompany = $this->makeCompany('other-co');
        \App\Core\Modules\ModuleRegistry::clearCache();

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'first_name' => 'Marie', 'last_name' => 'Curie',
            'employee_number' => 'EMP-CONF', 'hire_date' => '2024-01-01', 'status' => Employee::STATUS_ACTIVE,
        ]);

        // Run VALIDÉ avec une ligne + un calcul portant de vrais montants (à masquer).
        $this->run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
            'currency' => 'EUR', 'status' => PayrollRun::STATUS_VALIDATED, 'employee_count' => 1,
            'total_gross_cents' => 520000, 'total_worked_minutes' => 9000,
        ]);
        $period = \App\Core\Workforce\TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'status' => 'locked',
        ]);
        $this->line = PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'payroll_run_id' => $this->run->id, 'employee_id' => $this->employee->id,
            'timesheet_period_id' => $period->id,
            'worked_minutes' => 9000, 'total_overtime_minutes' => 120, 'planned_minutes' => 9000,
            'base_salary_cents' => 500000, 'gross_basis_cents' => 520000,
            'gross_breakdown' => ['base' => 500000, 'overtime' => 20000],
            'compensation_snapshot' => ['base_salary_cents' => 500000, 'compensation_type' => 'monthly'],
            'timesheet_snapshot' => [], 'anomalies' => null,
        ]);
        PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'payroll_line_id' => $this->line->id, 'status' => 'validated',
            'rule_version' => 'FR-2026-test', 'plafond_ss_monthly_cents' => 393000,
            'gross_total_cents' => 520000, 'contributions_employee_cents' => 110000, 'contributions_employer_cents' => 210000,
            'total_cost_employer_cents' => 730000, 'taxable_income_cents' => 430000, 'tax_cents' => 15000,
            'net_before_tax_cents' => 410000, 'net_payable_cents' => 395000, 'net_social_cents' => 420000,
            'benefits_cents' => 0, 'deductions_cents' => 0,
            'contribution_lines' => [['label' => 'Maladie', 'base_cents' => 520000, 'rate_bps' => 700, 'employee_cents' => 0, 'employer_cents' => 36400]],
            'tax_breakdown' => ['rate_bps' => 350, 'tax_cents' => 15000],
            'benefit_lines' => [], 'deduction_lines' => [], 'relief_lines' => [], 'calculation_snapshot' => [],
            'blocking_anomalies' => null, 'calculated_at' => now(),
        ]);

        Model::reguard();
    }

    private function makeCompany(string $slug): Company
    {
        $c = Company::create(['name' => $slug, 'slug' => $slug, 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_payroll', 'workforce_documents'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $c->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        return $c;
    }

    /** User + role(perms) + membership dans $company. */
    private function userWith(array $permKeys, Company $company = null): User
    {
        $company ??= $this->company;
        $user = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $permKeys)->pluck('id')->toArray());
        Membership::create(['user_id' => $user->id, 'company_id' => $company->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $user;
    }

    private function owner(Company $company = null): User
    {
        $company ??= $this->company;
        $user = User::factory()->create();
        Membership::create(['user_id' => $user->id, 'company_id' => $company->id, 'role' => 'owner']);

        return $user;
    }

    private function apiGet(User $u, string $path, Company $company = null)
    {
        return $this->actingAs($u)->getJson('/api'.$path, ['X-Company-Id' => ($company ?? $this->company)->id]);
    }

    private function assertNoConfidential($res): void
    {
        $body = $res->getContent();
        foreach (self::CONFIDENTIAL_FIELDS as $field) {
            $this->assertStringNotContainsString($field, $body, "Le champ confidentiel « {$field} » ne doit pas sortir sans compensation_read.");
        }
        // Et aucune des valeurs numériques sensibles ne doit apparaître.
        foreach (['500000', '520000', '395000', '730000', '210000'] as $amount) {
            $this->assertStringNotContainsString($amount, $body, "Le montant {$amount} ne doit pas fuiter.");
        }
    }

    // ───────────────────────── Personas ─────────────────────────

    /** 1. Owner → voit les montants (bypass). */
    public function test_owner_sees_amounts(): void
    {
        $owner = $this->owner();
        $this->apiGet($owner, "/workforce/payroll/{$this->run->id}")->assertOk()->assertSee('total_gross_cents');
        $this->apiGet($owner, "/workforce/payroll/{$this->run->id}/lines/{$this->line->id}")->assertOk()->assertSee('net_payable_cents');
    }

    /** 2. RH préparation SANS lecture salariale → 200 mais aucun montant. */
    public function test_preparer_without_compensation_read_gets_no_amounts(): void
    {
        $prep = $this->userWith(['workforce.payroll_prepare']);

        foreach ([
            "/workforce/payroll",
            "/workforce/payroll/statistics",
            "/workforce/payroll/{$this->run->id}",
            "/workforce/payroll/{$this->run->id}/lines",
            "/workforce/payroll/{$this->run->id}/lines/{$this->line->id}",
            "/workforce/payroll/{$this->run->id}/calculations",
        ] as $path) {
            $res = $this->apiGet($prep, $path);
            $res->assertOk();
            $this->assertNoConfidential($res);
            $res->assertJsonFragment(['compensation_hidden' => true]);
        }
    }

    /** 3. Gestionnaire de paie (prepare + compensation_read) → voit les montants. */
    public function test_payroll_manager_with_compensation_read_sees_amounts(): void
    {
        $mgr = $this->userWith(['workforce.payroll_prepare', 'workforce.compensation_read']);
        $this->apiGet($mgr, "/workforce/payroll/{$this->run->id}")->assertOk()->assertSee('total_gross_cents');
        $this->apiGet($mgr, "/workforce/payroll/{$this->run->id}/lines/{$this->line->id}")->assertOk()->assertSee('net_payable_cents');
        $this->apiGet($mgr, "/workforce/payroll/{$this->run->id}/calculations")->assertOk()->assertSee('contributions_employer_cents');
    }

    /** 4. Manager temps (time_manage, PAS payroll) → 403 sur la paie. */
    public function test_timesheet_manager_cannot_reach_payroll(): void
    {
        $tm = $this->userWith(['workforce.time_manage', 'workforce.timesheet_approve']);
        $this->apiGet($tm, '/workforce/payroll')->assertForbidden();
        $this->apiGet($tm, "/workforce/payroll/{$this->run->id}")->assertForbidden();
        $this->apiGet($tm, "/workforce/payroll/{$this->run->id}/lines/{$this->line->id}")->assertForbidden();
    }

    /** 5. Salarié (leave_request) → 403 sur la paie. */
    public function test_employee_cannot_reach_payroll(): void
    {
        $emp = $this->userWith(['workforce.leave_request']);
        $this->apiGet($emp, '/workforce/payroll')->assertForbidden();
        $this->apiGet($emp, "/workforce/payroll/{$this->run->id}")->assertForbidden();
    }

    /** 6. Utilisateur Company sans accès Payroll → 403. */
    public function test_company_user_without_payroll_access_forbidden(): void
    {
        $none = $this->userWith(['workforce.view']);
        $this->apiGet($none, '/workforce/payroll')->assertForbidden();
    }

    /** 7. Utilisateur d'une AUTRE company → pas d'accès au run (tenant). */
    public function test_other_company_user_cannot_access_run(): void
    {
        // Même avec toutes les permissions dans SA company, le run appartient à une autre company.
        $outsider = $this->userWith(['workforce.payroll_prepare', 'workforce.compensation_read'], $this->otherCompany);
        $res = $this->apiGet($outsider, "/workforce/payroll/{$this->run->id}", $this->otherCompany);
        $this->assertContains($res->status(), [403, 404], 'cross-tenant run access must be denied');
    }

    /** Accès direct à un identifiant de ligne par un préparateur non autorisé → masqué. */
    public function test_direct_line_access_is_masked_for_preparer(): void
    {
        $prep = $this->userWith(['workforce.payroll_prepare']);
        $res = $this->apiGet($prep, "/workforce/payroll/{$this->run->id}/lines/{$this->line->id}");
        $res->assertOk();
        $this->assertNoConfidential($res);
    }

    /**
     * C3 hardening : un champ monétaire AJOUTÉ (hors denylist) est quand même retiré
     * grâce à la détection par motif (_cents/_bps/_lines/_breakdown/_snapshot/_payload),
     * alors qu'une nouvelle clé NON monétaire est préservée.
     */
    public function test_newly_added_monetary_field_is_stripped_by_pattern(): void
    {
        $payload = [
            'id' => 7,
            'status' => 'computed',
            'worked_minutes' => 9000,            // preparation data → kept
            'employee' => ['id' => 3, 'first_name' => 'X'],
            'surprise_bonus_cents' => 99999,     // NEW amount, not in denylist → stripped
            'custom_rate_bps' => 1234,           // NEW rate → stripped
            'mystery_lines' => [['amount' => 1]],// NEW amount collection → stripped
            'future_snapshot' => ['salary' => 1],// NEW snapshot → stripped
            'new_safe_label' => 'visible',       // NEW non-monetary key → kept
            'timesheet_snapshot' => ['m' => 1],  // time data → kept (safe-listed)
        ];

        $masked = \App\Modules\Workforce\Security\PayrollConfidentiality::scrub($payload, false);
        $json = json_encode($masked);

        foreach (['surprise_bonus_cents', '99999', 'custom_rate_bps', '1234', 'mystery_lines', 'future_snapshot'] as $leak) {
            $this->assertStringNotContainsString((string) $leak, $json, "pattern strip failed for {$leak}");
        }
        $this->assertStringContainsString('new_safe_label', $json);
        $this->assertStringContainsString('worked_minutes', $json);
        $this->assertStringContainsString('timesheet_snapshot', $json);
        $this->assertTrue($masked['compensation_hidden']);

        // Authorized viewer: nothing stripped.
        $full = \App\Modules\Workforce\Security\PayrollConfidentiality::scrub($payload, true);
        $this->assertSame($payload, $full);
    }

    /**
     * C3 : un bulletin (document payslip, subject payroll_line) contient le salaire —
     * son téléchargement NE DOIT PAS contourner compensation_read via l'espace documents.
     */
    public function test_payslip_download_requires_compensation_read(): void
    {
        $doc = $this->makePayslipDoc();

        $noComp = $this->userWith(['workforce.payroll_prepare', 'workforce_documents.view']);
        $this->apiGet($noComp, "/workforce/documents/{$doc->id}/download")->assertForbidden();

        // Comp user passes the compensation gate (404 car pas de fichier réel, mais PAS 403).
        $comp = $this->userWith(['workforce.payroll_prepare', 'workforce.compensation_read', 'workforce_documents.view']);
        $this->assertNotSame(403, $this->apiGet($comp, "/workforce/documents/{$doc->id}/download")->status());
    }

    /** C3 : la liste des documents d'un run masque generation_snapshot (montants) sans compensation_read. */
    public function test_payroll_run_documents_mask_snapshot_without_comp(): void
    {
        $this->makePayslipDoc();

        $noComp = $this->userWith(['workforce.payroll_prepare', 'workforce_documents.view']);
        $res = $this->apiGet($noComp, "/workforce/documents/payroll-run/{$this->run->id}");
        $res->assertOk();
        $this->assertStringNotContainsString('generation_snapshot', $res->getContent());
        $this->assertStringNotContainsString('777777', $res->getContent());
    }

    private function makePayslipDoc(): \App\Core\Documents\GeneratedDocument
    {
        Model::unguard();
        $tpl = \App\Core\Documents\DocumentTemplate::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'code' => 'payslip_draft_fr', 'name' => 'Bulletin',
            'subject_type' => 'payroll_line', 'body_html' => '<p>{{net}}</p>', 'variables_schema' => [],
            'version' => 1, 'is_active' => true,
        ]);
        $doc = \App\Core\Documents\GeneratedDocument::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'document_template_id' => $tpl->id, 'template_version' => 1,
            'subject_type' => 'payroll_line', 'subject_id' => $this->line->id, 'generated_by' => $this->owner()->id,
            'generated_at' => now(), 'file_path' => 'payslips/test-c3.pdf', 'file_size_bytes' => 10,
            'generation_snapshot' => ['net_payable_cents' => 777777, 'base_salary_cents' => 500000],
            'signature_status' => 'none',
        ]);
        Model::reguard();

        return $doc;
    }

    /** DSN : payload_snapshot (salaires) masqué sans compensation_read ; visible avec. */
    public function test_dsn_payload_snapshot_masked_without_compensation_read(): void
    {
        Model::unguard();
        $decl = DsnDeclaration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'payroll_run_id' => $this->run->id,
            'declaration_type' => 'monthly', 'period_month' => '2026-01', 'status' => 'draft',
            'payload_hash' => str_repeat('a', 64), 'generated_at' => now(),
            'payload_snapshot' => ['employees' => [['nir' => '1', 'gross_total_cents' => 520000, 'net_payable_cents' => 395000]]],
        ]);
        Model::reguard();

        // Sans compensation_read (mais payroll_export) → pas de payload_snapshot ni montants.
        $exporter = $this->userWith(['workforce.payroll_export']);
        $res = $this->apiGet($exporter, "/workforce/dsn/{$decl->id}");
        $res->assertOk();
        $this->assertStringNotContainsString('payload_snapshot', $res->getContent());
        $this->assertStringNotContainsString('520000', $res->getContent());
        $res->assertJsonFragment(['compensation_hidden' => true]);

        // Avec compensation_read → payload visible.
        $full = $this->userWith(['workforce.payroll_export', 'workforce.compensation_read']);
        $this->apiGet($full, "/workforce/dsn/{$decl->id}")->assertOk()->assertSee('payload_snapshot');
    }
}
