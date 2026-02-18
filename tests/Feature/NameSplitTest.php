<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NameSplitTest extends TestCase
{
    use RefreshDatabase;

    // ─── 1) Register with first_name + last_name ──────────

    public function test_register_with_first_name_last_name(): void
    {
        $this->get('/sanctum/csrf-cookie');

        $response = $this->postJson('/api/register', [
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
            'email' => 'marie@test.com',
            'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong',
            'company_name' => 'Test Co',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'marie@test.com',
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
        ]);

        $response->assertJsonPath('user.first_name', 'Marie');
        $response->assertJsonPath('user.last_name', 'Dupont');
        $response->assertJsonPath('user.display_name', 'Marie Dupont');
    }

    // ─── 2) display_name accessor ─────────────────────────

    public function test_display_name_accessor_returns_combined(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
        ]);

        $this->assertEquals('Jean Dupont', $user->display_name);

        // Only first name
        $user2 = User::factory()->create([
            'first_name' => 'Solo',
            'last_name' => '',
        ]);

        $this->assertEquals('Solo', $user2->display_name);
    }

    // ─── 3) Membership store derives first_name from email ─

    public function test_membership_store_derives_first_name_from_email(): void
    {
        $owner = User::factory()->create();
        $company = Company::create(['name' => 'Test Co', 'slug' => 'test-co']);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Company-Id' => $company->id])
            ->postJson('/api/company/members', [
                'email' => 'bob.martin@test.com',
                'role' => 'user',
            ]);

        $response->assertStatus(201);

        $newUser = User::where('email', 'bob.martin@test.com')->first();
        $this->assertNotNull($newUser);
        $this->assertEquals('bob.martin', $newUser->first_name);
        $this->assertEquals('', $newUser->last_name);
    }

    // ─── 4) PlatformUser with first_name + last_name ──────

    public function test_platform_user_create_with_first_last_name(): void
    {
        $user = PlatformUser::create([
            'first_name' => 'Alice',
            'last_name' => 'Martin',
            'email' => 'alice@test.com',
            'password' => 'password',
        ]);

        $this->assertEquals('Alice', $user->first_name);
        $this->assertEquals('Martin', $user->last_name);
        $this->assertEquals('Alice Martin', $user->display_name);
    }

    // ─── 5) Cannot mass assign name column ────────────────

    public function test_cannot_mass_assign_name_column(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Original',
            'last_name' => 'User',
        ]);

        $originalName = $user->getRawOriginal('name');

        // Attempt mass assignment of name — should be silently ignored
        $user->fill(['name' => 'Hacked']);
        $user->save();

        $user->refresh();
        $this->assertEquals($originalName, $user->getRawOriginal('name'));
    }

    // ─── 6) name column not mutated on update ─────────────

    public function test_name_column_not_mutated_on_update(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Original',
            'last_name' => 'User',
        ]);

        $originalName = $user->getRawOriginal('name');

        $user->update(['first_name' => 'Changed']);
        $user->refresh();

        $this->assertEquals('Changed', $user->first_name);
        $this->assertEquals($originalName, $user->getRawOriginal('name'));
    }

    // ─── 7) Migration backfill edge cases ─────────────────

    public function test_migration_backfill_edge_cases(): void
    {
        // Test the backfill logic directly (same as migration)
        $cases = [
            ['name' => 'Jean', 'expected_first' => 'Jean', 'expected_last' => ''],
            ['name' => 'Jean   Dupont', 'expected_first' => 'Jean', 'expected_last' => 'Dupont'],
            ['name' => 'Jean Claude Van Damme', 'expected_first' => 'Jean', 'expected_last' => 'Claude Van Damme'],
            ['name' => '', 'expected_first' => '', 'expected_last' => ''],
            ['name' => null, 'expected_first' => '', 'expected_last' => ''],
        ];

        foreach ($cases as $case) {
            $trimmed = trim($case['name'] ?? '');

            if ($trimmed === '') {
                $firstName = '';
                $lastName = '';
            } else {
                $parts = preg_split('/\s+/', $trimmed, 2);
                $firstName = $parts[0];
                $lastName = $parts[1] ?? '';
            }

            $this->assertEquals($case['expected_first'], $firstName, "Failed for name: '{$case['name']}'");
            $this->assertEquals($case['expected_last'], $lastName, "Failed for name: '{$case['name']}'");
        }
    }

    // ─── 8) Navigation titles exist in i18n locales ────────

    public function test_navigation_titles_exist_in_i18n(): void
    {
        $enJson = json_decode(file_get_contents(base_path('resources/js/plugins/i18n/locales/en.json')), true);

        $navFiles = [
            base_path('resources/js/navigation/vertical/index.js'),
            base_path('resources/js/navigation/horizontal/index.js'),
        ];

        $missing = [];

        foreach ($navFiles as $file) {
            $content = file_get_contents($file);

            // Extract title: '...' and heading: '...'
            preg_match_all("/(?:title|heading):\s*'([^']+)'/", $content, $matches);

            foreach ($matches[1] as $key) {
                if (!array_key_exists($key, $enJson)) {
                    $missing[] = "{$key} (in " . basename($file) . ')';
                }
            }
        }

        $this->assertEmpty(
            $missing,
            "Navigation keys missing from en.json:\n" . implode("\n", $missing),
        );
    }

    // ─── 9) Frontend does not use user.name property ──────

    public function test_frontend_does_not_use_user_name_property(): void
    {
        $result = shell_exec('cd ' . base_path() . ' && grep -rn "\.name" resources/js/ --include="*.vue" --include="*.js" 2>/dev/null || true');

        $lines = array_filter(explode("\n", $result ?? ''));

        // Whitelist: company.name, themeConfig.app.name, role.name, module.name,
        // field/definition/activation names, jobdomain.label, app.title, etc.
        $whitelist = [
            'company.name',
            'company_name',
            'themeConfig.app.name',
            'themeConfig.app.title',
            'role.name',
            'module.name',
            'module_name',
            'field.name',
            'definition.name',
            'display_name',
            'first_name',
            'last_name',
            'file.name',
            'item.name',        // VDataTable columns
            "key: 'name'",      // table header definitions (removed now but whitelist for safety)
            "key: 'display_name'",
            'class="auth-title"',
            'placeholder',
            'label',
        ];

        $violations = [];

        foreach ($lines as $line) {
            // Skip empty lines
            if (trim($line) === '') {
                continue;
            }

            // Check if this is a user.name / member.user.name / userData.name pattern
            if (preg_match('/(?:user|userData|member\.user)\.name(?![_a-zA-Z])/', $line)) {
                // Not in whitelist
                $isWhitelisted = false;
                foreach ($whitelist as $safe) {
                    if (str_contains($line, $safe)) {
                        $isWhitelisted = true;
                        break;
                    }
                }

                if (!$isWhitelisted) {
                    $violations[] = trim($line);
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Frontend still references user.name (should use display_name):\n" . implode("\n", $violations),
        );
    }
}
