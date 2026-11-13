<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * M7 LOT 4 §10 — scalability of the Company / People / Documents / My-space read paths.
 *
 * Each operation is measured through the real HTTP path at TWO population sizes (SMALL, LARGE) :
 * number of SQL queries, wall time, payload size. The contract asserted here is about QUERIES —
 * the only measure that is stable across machines : a page must not issue more queries because
 * the company is bigger (no N+1). Times and sizes are printed (`M7LOT4_PERF_REPORT=1`) and bounded
 * loosely, as a guard against an order-of-magnitude regression, not as a benchmark.
 *
 * A second company holds the same kind of data : no measured payload may contain any of it.
 */
class M7Lot4PerformanceTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private const SMALL = 40;
    private const LARGE = 800;
    /**
     * N+1 found by this test and NOT fixed in this lot (file outside the scope of the fix) — listed
     * with its cause and capped at its measured value. Removing an entry is the acceptance test of its fix.
     */
    /** Documented N+1 not fixed in this lot (none left : the request queue was fixed in ADR-770). */
    private const KNOWN_N_PLUS_ONE = [];

    private const MODULES = ['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave'];

    private Company $company;
    private Company $foreign;
    private User $owner;
    private CompanyRole $staff;
    private int $seq = 0;
    /** @var array<string, array<int, array{queries:int, ms:float, bytes:int}>> */
    private static array $report = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Notification::fake();
        Storage::fake('local');
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => self::MODULES]);

        [$this->company, $this->owner, $this->staff] = $this->makeCompany('Perf Co');
        [$this->foreign] = $this->makeCompany('Foreign Co');
        $this->populate($this->foreign, 25, 'FOREIGN');
    }

    /** @return array{0: Company, 1: User, 2: CompanyRole} */
    private function makeCompany(string $name): array
    {
        $company = Company::create(['name' => $name, 'slug' => 'perf-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($company);
        foreach (self::MODULES as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $company->id, 'module_key' => $m], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->setUpCompanyRbac($company);
        $staff = CompanyRole::create(['company_id' => $company->id, 'key' => 'staff', 'name' => 'Staff', 'is_administrative' => false]);
        $owner = User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        foreach (DocumentType::where('is_system', true)->get() as $i => $type) {
            DocumentTypeActivation::create(['company_id' => $company->id, 'document_type_id' => $type->id, 'enabled' => true, 'order' => $i * 10]);
        }

        return [$company, $owner, $staff];
    }

    /**
     * Adds `$n` people : every one has an HR record ; 1 in 2 also has an account (membership) ;
     * 1 in 4 a contract, a personal document, a document request and two payslips ; plus n/10
     * accounts without HR record. Bulk inserts — the population itself is not what is measured.
     */
    private function populate(Company $company, int $n, string $marker = 'P'): void
    {
        $now = now()->toDateTimeString();
        $roleId = CompanyRole::where('company_id', $company->id)->where('key', 'staff')->value('id');
        $typeId = DocumentType::where('code', 'id_card')->value('id');
        $deptId = DB::table('workforce_departments')->insertGetId(['company_id' => $company->id, 'name' => "Dept {$marker} ".(++$this->seq), 'created_at' => $now, 'updated_at' => $now]);

        $users = [];
        for ($i = 0; $i < $n + intdiv($n, 10); $i++) {
            $k = ++$this->seq;
            $users[] = ['first_name' => "{$marker}First{$k}", 'last_name' => "{$marker}Last{$k}", 'email' => strtolower($marker)."{$k}@perf.test", 'password' => 'x', 'password_set_at' => $i % 3 ? $now : null, 'created_at' => $now, 'updated_at' => $now];
        }
        $withAccount = intdiv($n, 2);
        $needed = array_slice($users, 0, $withAccount + intdiv($n, 10));
        foreach (array_chunk($needed, 200) as $chunk) {
            DB::table('users')->insert($chunk);
        }
        $userIds = DB::table('users')->whereIn('email', array_column($needed, 'email'))->orderBy('id')->pluck('id')->all();

        $memberships = [];
        foreach ($userIds as $uid) {
            $memberships[] = ['company_id' => $company->id, 'user_id' => $uid, 'role' => 'user', 'company_role_id' => $roleId, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($memberships, 200) as $chunk) {
            DB::table('memberships')->insert($chunk);
        }

        $employees = [];
        for ($i = 0; $i < $n; $i++) {
            $k = ++$this->seq;
            $employees[] = [
                'company_id' => $company->id, 'user_id' => $userIds[$i] ?? null, 'employee_number' => "{$marker}-{$k}",
                'first_name' => "{$marker}Emp{$k}", 'last_name' => "{$marker}Name{$k}", 'email' => strtolower($marker)."emp{$k}@perf.test",
                'status' => 'active', 'hire_date' => '2025-01-01', 'department_id' => $deptId, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        // only the first $withAccount employees are linked ; the trailing accounts stay without HR record
        foreach ($employees as $i => &$e) {
            if ($i >= $withAccount) {
                $e['user_id'] = null;
            }
        }
        unset($e);
        foreach (array_chunk($employees, 200) as $chunk) {
            DB::table('workforce_employees')->insert($chunk);
        }
        $empRows = DB::table('workforce_employees')->where('company_id', $company->id)->where('employee_number', 'like', "{$marker}-%")->orderByDesc('id')->limit($n)->get(['id', 'user_id']);

        $contracts = $docs = $requests = $payslips = [];
        foreach ($empRows as $i => $e) {
            if ($i % 4 !== 0) {
                continue;
            }
            $contracts[] = ['company_id' => $company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'weekly_hours' => 35, 'start_date' => '2025-01-01', 'status' => 'active', 'is_current' => true, 'created_at' => $now, 'updated_at' => $now];
            $subjectType = $e->user_id ? 'user' : 'employee';
            $subjectId = $e->user_id ?: $e->id;
            $docs[] = ['company_id' => $company->id, 'user_id' => $e->user_id, 'document_subject_type' => $subjectType, 'document_subject_id' => $subjectId, 'document_type_id' => $typeId, 'file_path' => "perf/{$e->id}.pdf", 'file_name' => "{$marker}-id.pdf", 'file_size_bytes' => 1000, 'mime_type' => 'application/pdf', 'created_at' => $now, 'updated_at' => $now];
            $requests[] = ['company_id' => $company->id, 'user_id' => $e->user_id, 'document_subject_type' => $subjectType, 'document_subject_id' => $subjectId, 'document_type_id' => $typeId, 'status' => 'submitted', 'requested_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            foreach (['2026-01', '2026-02'] as $period) {
                $payslips[] = ['company_id' => $company->id, 'document_subject_type' => $subjectType, 'document_subject_id' => $subjectId, 'period' => $period, 'file_path' => "perf/p{$e->id}.pdf", 'file_name' => "{$marker}-{$period}.pdf", 'file_sha256' => hash('sha256', $marker.$e->id.$period), 'version_no' => 1, 'source' => 'external_import', 'created_at' => $now];
            }
        }
        foreach ([['workforce_employment_contracts', $contracts], ['member_documents', $docs], ['document_requests', $requests], ['published_payslips', $payslips]] as [$table, $rows]) {
            foreach (array_chunk($rows, 200) as $chunk) {
                $this->insertKnownColumns($table, $chunk);
            }
        }
    }

    /** Insert keeping only the columns that exist (the fixture stays valid if a table gains / lacks an optional column). */
    private function insertKnownColumns(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $cols = array_flip(\Illuminate\Support\Facades\Schema::getColumnListing($table));
        DB::table($table)->insert(array_map(fn ($r) => array_intersect_key($r, $cols), $rows));
    }

    /** @return array{queries:int, ms:float, bytes:int, json:mixed, raw:string} */
    private function measure(string $label, int $population, callable $request): array
    {
        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $t = microtime(true);
        $res = $request();
        $ms = (microtime(true) - $t) * 1000;
        $queries = count(DB::getQueryLog());
        if (getenv('M7LOT4_PERF_DUMP') === $label) {
            $shapes = [];
            foreach (DB::getQueryLog() as $q) {
                $shape = preg_replace('/\s+/', ' ', substr($q['query'], 0, 150));
                $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
            }
            arsort($shapes);
            fwrite(STDERR, "\n[{$label} @ {$population}]\n");
            foreach (array_slice($shapes, 0, 8, true) as $shape => $count) {
                fwrite(STDERR, sprintf("%5d × %s\n", $count, $shape));
            }
        }
        DB::disableQueryLog();
        $res->assertOk();
        $raw = (string) $res->getContent();

        $this->assertStringNotContainsString('FOREIGN', $raw, "{$label} : another company's data in the payload");
        self::$report[$label][$population] = ['queries' => $queries, 'ms' => round($ms, 1), 'bytes' => strlen($raw)];

        return ['queries' => $queries, 'ms' => $ms, 'bytes' => strlen($raw), 'json' => $res->json(), 'raw' => $raw];
    }

    private function api(string $url)
    {
        return $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id])->getJson($url);
    }

    /**
     * Runs every read operation at the current population and returns the measures.
     *
     * @return array<string, array{queries:int, ms:float, bytes:int, json:mixed, raw:string}>
     */
    private function runAll(int $population): array
    {
        $linked = DB::table('workforce_employees')->where('company_id', $this->company->id)->whereNotNull('user_id')->orderBy('id')->first();
        $withDocs = DB::table('published_payslips')->where('company_id', $this->company->id)->where('document_subject_type', 'user')->value('document_subject_id');
        $selfUser = User::find($withDocs);

        $ops = [
            'people.list' => fn () => $this->api('/api/company/people?per_page=20'),
            'people.list.page5' => fn () => $this->api('/api/company/people?per_page=20&page=2'),
            'people.search' => fn () => $this->api('/api/company/people?per_page=20&search=PEmp'),
            'people.filter+sort' => fn () => $this->api('/api/company/people?per_page=20&employment_status=active&access_state=invited&sort_by=name&sort_dir=desc'),
            'people.file' => fn () => $this->api("/api/company/people/employee-{$linked->id}"),
            'people.history' => fn () => $this->api("/api/company/people/employee-{$linked->id}/history"),
            'people.payslips' => fn () => $this->api("/api/company/people/user-{$withDocs}/payslips"),
            'members.directory' => fn () => $this->api('/api/company/members'),
            'profile.space' => fn () => $this->api('/api/profile/space'),
            'self.payslips' => fn () => $this->actingAs($selfUser)->withHeaders(['X-Company-Id' => $this->company->id])->getJson('/api/profile/payslips'),
            'self.documents' => fn () => $this->actingAs($selfUser)->withHeaders(['X-Company-Id' => $this->company->id])->getJson('/api/profile/documents'),
            'documents.compliance' => fn () => $this->api('/api/company/documents/compliance'),
            'documents.requests' => fn () => $this->api('/api/company/document-requests/queue'),
            'documents.activity' => fn () => $this->api('/api/company/documents/activity'),
            'documents.vault' => fn () => $this->api('/api/company/documents'),
            'campaigns.list' => fn () => $this->api('/api/company/document-campaigns'),
            'campaigns.preview' => fn () => $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id])
                ->postJson('/api/company/document-campaigns/preview', ['document_type_code' => 'id_card', 'targeting' => ['mode' => 'all']]),
            'payslip.batches' => fn () => $this->api('/api/company/payslip-imports'),
            'workforce.employees' => fn () => $this->api('/api/workforce/employees?perPage=20'),
        ];
        $out = [];
        foreach ($ops as $label => $op) {
            $out[$label] = $this->measure($label, $population, $op);
        }

        return $out;
    }

    public function test_read_paths_do_not_issue_more_queries_when_the_company_grows(): void
    {
        $this->populate($this->company, self::SMALL);
        $small = $this->runAll(self::SMALL);

        $this->populate($this->company, self::LARGE - self::SMALL);
        $large = $this->runAll(self::LARGE);

        if (getenv('M7LOT4_PERF_REPORT')) {
            fwrite(STDERR, "\n".sprintf("%-22s | %14s | %16s | %18s\n", 'operation', 'queries S→L', 'ms S→L', 'bytes S→L'));
            foreach (self::$report as $label => $by) {
                fwrite(STDERR, sprintf("%-22s | %5d → %-6d | %6.1f → %-7.1f | %7d → %-8d\n", $label, $by[self::SMALL]['queries'], $by[self::LARGE]['queries'], $by[self::SMALL]['ms'], $by[self::LARGE]['ms'], $by[self::SMALL]['bytes'], $by[self::LARGE]['bytes']));
            }
        }

        // ── No N+1 : same number of queries at 40 and at 800 people (± a small constant). ──
        $growth = [];
        foreach ($large as $label => $m) {
            $delta = $m['queries'] - $small[$label]['queries'];
            if ($delta > 3 && ! isset(self::KNOWN_N_PLUS_ONE[$label])) {
                $growth[] = "{$label}: {$small[$label]['queries']} → {$m['queries']} queries";
            }
        }
        $this->assertSame([], $growth, 'query count grows with the population (N+1)');

        // Known, QUALIFIED N+1 (not hidden) : capped so that it cannot get worse unnoticed.
        foreach (self::KNOWN_N_PLUS_ONE as $label => $known) {
            $this->assertLessThanOrEqual($known['cap'], $large[$label]['queries'], "{$label} : known N+1 got worse ({$known['cause']})");
        }

        // ── Absolute query budgets at 800 people (current values + headroom ; a regression must be deliberate). ──
        $budgets = [
            'people.list' => 25, 'people.search' => 25, 'people.filter+sort' => 25, 'people.file' => 90, 'campaigns.preview' => 40, 'people.history' => 25,
            'people.payslips' => 25, 'members.directory' => 30, 'documents.vault' => 40, 'documents.activity' => 30, 'profile.space' => 25, 'self.payslips' => 20, 'self.documents' => 30,
            'documents.compliance' => 80, 'campaigns.list' => 20, 'payslip.batches' => 20, 'workforce.employees' => 25,
        ];
        foreach ($budgets as $label => $max) {
            $this->assertLessThanOrEqual($max, $large[$label]['queries'], "{$label} : query budget");
        }

        // ── Bounded pages : a paginated list never returns the population. ──
        $this->assertCount(20, $large['people.list']['json']['data']);
        $this->assertSame(self::LARGE + intdiv(self::LARGE, 10) + 1, $large['people.list']['json']['total']); // HR records + account-only + owner
        $this->assertLessThan(15_000, $large['people.list']['bytes'], 'people page payload is bounded');
        $this->assertLessThan($small['people.list']['bytes'] * 1.5 + 2000, $large['people.list']['bytes'], 'people page payload does not grow with the company');
        $this->assertLessThan(8_000, $large['profile.space']['bytes']);
        $this->assertLessThan(60_000, $large['people.file']['bytes']);

        // ── Loose time guards at 800 people (sqlite in memory ; an order-of-magnitude alarm, not a benchmark). ──
        foreach (['people.list' => 1500, 'people.search' => 1500, 'people.file' => 1500, 'profile.space' => 500, 'self.payslips' => 500] as $label => $maxMs) {
            $this->assertLessThan($maxMs, $large[$label]['ms'], "{$label} : time at ".self::LARGE.' people');
        }

        // ── Known structural limits, MEASURED rather than hidden (see the report). ──
        //    members.directory and documents.compliance are unpaginated by design : their payload grows
        //    linearly. They are bounded here at the size this lot was validated for.
        $this->assertLessThan(400_000, $large['members.directory']['bytes'], 'unpaginated directory : validated up to ~450 accounts');

    }

    public function test_people_list_in_memory_pagination_stays_correct_and_bounded_at_scale(): void
    {
        // PeopleListReadModel loads the company population then filters / sorts / paginates in PHP.
        // This is the structural limit of the unified list : it is MEASURED here at 3 000 people.
        $this->populate($this->company, 3000);

        $m = $this->measure('people.list@3000', 3000, fn () => $this->api('/api/company/people?per_page=20'));
        $this->assertCount(20, $m['json']['data']);
        $this->assertSame(3000 + 300 + 1, $m['json']['total']);
        $this->assertLessThanOrEqual(25, $m['queries'], 'still a constant number of queries');
        $this->assertLessThan(15_000, $m['bytes']);
        $this->assertLessThan(4000, $m['ms'], 'in-memory aggregation of 3 300 people');

        $last = $this->measure('people.list@3000.last', 3000, fn () => $this->api('/api/company/people?per_page=100&page=34'));
        $this->assertCount(1, $last['json']['data']);

        // One person = one row : linked accounts are not listed twice.
        $refs = [];
        foreach ([1, 2, 3] as $page) {
            $refs = array_merge($refs, array_column($this->api("/api/company/people?per_page=100&page={$page}")->json('data'), 'person_ref'));
        }
        $this->assertSame(count($refs), count(array_unique($refs)));

        if (getenv('M7LOT4_PERF_REPORT')) {
            fwrite(STDERR, sprintf("\npeople.list@3000 : %d queries, %.1f ms, %d bytes, peak memory %.1f MB\n", $m['queries'], $m['ms'], $m['bytes'], memory_get_peak_usage(true) / 1048576));
        }
    }
}
