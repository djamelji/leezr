<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Support\SupportTicket;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-801 — rationalisation P0.
 *  P0-1 `_*.vue` are never routes (router exclusion) ;
 *  P0-2 every guarded Platform page declares the permission its API requires, and the API refuses without it ;
 *  P0-3 assignment of a support ticket = `assign_support` (or `manage_support`, compatibility) ; assign_support
 *       alone opens nothing else.
 */
class Adr801RationalizationP0Test extends TestCase
{
    use RefreshDatabase;

    /** Platform pages without a permission, by design (each one justified in ADR-801). */
    private const PLATFORM_PAGES_WITHOUT_PERMISSION = [
        'index.vue',            // dispatcher : fallback of refused pages, routes to the first accessible entry
        'account/[tab].vue',    // personal account (me/*) : no permission on the API either
        'presentation-lab.vue', // DEV harness (ADR-748), backlog P3-1
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function userWith(array $permissionKeys): PlatformUser
    {
        $role = PlatformRole::create(['key' => 'r_'.uniqid(), 'name' => 'R']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $permissionKeys)->pluck('id')->all());
        $u = PlatformUser::create(['first_name' => 'P', 'last_name' => 'U', 'email' => uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $u->roles()->attach($role->id);

        return $u;
    }

    private function ticket(): SupportTicket
    {
        $company = Company::create(['name' => 'Sup Co', 'slug' => 'sup-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        return SupportTicket::create(['company_id' => $company->id, 'created_by_user_id' => User::factory()->create()->id, 'subject' => 'Help']);
    }

    /** @return array<string, string> relative path => definePage(...) source */
    private function pageMetas(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'vue' || str_starts_with($file->getFilename(), '_')) {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            $i = strpos($src, 'definePage(');
            $meta = '';
            if ($i !== false) {
                $depth = 0;
                for ($j = $i + strlen('definePage'); $j < strlen($src); $j++) {
                    $depth += $src[$j] === '(' ? 1 : ($src[$j] === ')' ? -1 : 0);
                    if ($depth === 0) {
                        break;
                    }
                }
                $meta = substr($src, $i, $j - $i + 1);
            }
            $out[ltrim(str_replace(base_path($dir), '', $file->getPathname()), '/')] = $meta;
        }
        ksort($out);

        return $out;
    }

    // ── P0-1 ─────────────────────────────────────────────────────────────

    public function test_router_excludes_page_local_sub_components(): void
    {
        $vite = file_get_contents(base_path('vite.config.js'));
        $this->assertMatchesRegularExpression("/exclude:\s*\[\s*'\*\*\/_\*\.vue'\s*\]/", $vite, 'VueRouter must exclude **/_*.vue');

        // A `_*.vue` is a component : it must never carry route metadata (it would be silently ignored).
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('resources/js/pages')));
        $offenders = [];
        foreach ($it as $file) {
            if ($file->isFile() && str_starts_with($file->getFilename(), '_') && str_contains(file_get_contents($file->getPathname()), 'definePage(')) {
                $offenders[] = $file->getFilename();
            }
        }
        $this->assertSame([], $offenders);
    }

    // ── P0-2 ─────────────────────────────────────────────────────────────

    public function test_every_guarded_platform_page_declares_a_permission_that_exists(): void
    {
        $known = PlatformPermission::pluck('key')->all();
        $missing = [];
        $unknown = [];
        $metas = $this->pageMetas('resources/js/pages/platform');
        $this->assertGreaterThanOrEqual(25, count($metas), 'the scan must actually see the Platform pages');
        // ADR-834 : the companies hub is also the entry point of creation operators (their own companies only)
        $this->assertStringContainsString("anyPermission: ['view_companies', 'create_companies']", $metas['companies/index.vue']);
        foreach ($metas as $page => $meta) {
            if (str_contains($meta, 'public: true') || in_array($page, self::PLATFORM_PAGES_WITHOUT_PERMISSION, true)) {
                continue;
            }
            if (! preg_match("/\bpermission:\s*'([^']+)'/", $meta, $single) && ! preg_match('/anyPermission:\s*\[([^\]]+)\]/', $meta, $any)) {
                $missing[] = $page;

                continue;
            }
            $keys = isset($single[1]) ? [$single[1]] : array_map(fn ($k) => trim($k, " '\""), explode(',', $any[1]));
            foreach (array_filter($keys) as $k) {
                if (! in_array($k, $known, true)) {
                    $unknown[] = "$page:$k";
                }
            }
            unset($single, $any);
        }
        $this->assertSame([], $missing, 'Platform pages without permission metadata');
        $this->assertSame([], $unknown, 'Permission keys absent from the platform catalogue');
    }

    /** API side of the 12 pages secured in ADR-801 : a Platform user WITHOUT the permission is refused. */
    public function test_platform_page_apis_refuse_a_user_without_the_permission(): void
    {
        $user = $this->userWith(['view_billing']); // unrelated permission : not a super-admin, not empty
        $endpoints = [
            '/api/platform/platform-users',        // access (manage_platform_users)
            '/api/platform/roles',                 // access (manage_roles)
            '/api/platform/activity',              // access (view_audit_logs)
            '/api/platform/modules',               // catalog (manage_modules)
            '/api/platform/support/tickets',       // communications / support (manage_support)
            '/api/platform/documentation/articles',// communications (manage_documentation)
            '/api/platform/companies',             // companies (view_companies)
            '/api/platform/dashboard/attention',   // dashboard (view_dashboard)
            '/api/platform/documents',             // documents (manage_document_catalog)
            '/api/platform/markets',               // markets (manage_markets)
            '/api/platform/email/health',          // messaging (manage_email)
            '/api/platform/system/health',         // operations (view_system_health)
            '/api/platform/alerts',                // operations (view_alerts)
            '/api/platform/general-settings',      // settings (manage_theme_settings)
        ];
        foreach ($endpoints as $uri) {
            $this->actingAs($user, 'platform')->getJson($uri)->assertForbidden();
        }
        // Unauthenticated : never 200.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/platform/companies')->assertUnauthorized();
    }

    public function test_platform_page_api_allows_a_user_with_the_permission(): void
    {
        $this->actingAs($this->userWith(['view_companies']), 'platform')->getJson('/api/platform/companies')->assertOk();
        $this->actingAs($this->userWith(['manage_markets']), 'platform')->getJson('/api/platform/markets')->assertOk();
    }

    // ── P0-3 ─────────────────────────────────────────────────────────────

    public function test_assign_support_alone_can_assign_and_nothing_else(): void
    {
        $ticket = $this->ticket();
        $assigner = $this->userWith(['assign_support']);
        $agent = $this->userWith(['manage_support']); // ADR-801.1 : the assignee must be able to take charge

        $this->actingAs($assigner, 'platform')->putJson("/api/platform/support/tickets/{$ticket->id}/assign", ['platform_user_id' => $agent->id])->assertOk();
        $this->assertSame($agent->id, $ticket->fresh()->assigned_to_platform_user_id);

        foreach ([
            ['get', '/api/platform/support/tickets'],
            ['get', '/api/platform/support/metrics'],
            ['get', "/api/platform/support/tickets/{$ticket->id}"],
            ['put', "/api/platform/support/tickets/{$ticket->id}/resolve"],
            ['put', "/api/platform/support/tickets/{$ticket->id}/close"],
            ['put', "/api/platform/support/tickets/{$ticket->id}/priority"],
            ['get', "/api/platform/support/tickets/{$ticket->id}/messages"],
            ['post', "/api/platform/support/tickets/{$ticket->id}/messages"],
            ['post', "/api/platform/support/tickets/{$ticket->id}/internal-notes"],
        ] as [$method, $uri]) {
            $this->actingAs($assigner, 'platform')->json($method, $uri, ['priority' => 'high', 'body' => 'x'])->assertForbidden();
        }
        $this->assertNotSame('resolved', $ticket->fresh()->status);
    }

    public function test_manage_support_keeps_assignment_and_admin_actions(): void
    {
        $ticket = $this->ticket();
        $manager = $this->userWith(['manage_support']);

        $this->actingAs($manager, 'platform')->putJson("/api/platform/support/tickets/{$ticket->id}/assign")->assertOk();
        $this->actingAs($manager, 'platform')->getJson('/api/platform/support/tickets')->assertOk();
        $this->actingAs($manager, 'platform')->putJson("/api/platform/support/tickets/{$ticket->id}/resolve")->assertOk();
    }

    public function test_assignment_refused_without_either_support_permission(): void
    {
        $ticket = $this->ticket();
        $this->actingAs($this->userWith(['view_dashboard']), 'platform')
            ->putJson("/api/platform/support/tickets/{$ticket->id}/assign")->assertForbidden();
        $this->assertNull($ticket->fresh()->assigned_to_platform_user_id);
    }
}
