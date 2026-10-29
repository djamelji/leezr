<?php

namespace Tests\Feature;

use App\Core\Documents\DocumentTemplate;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W-CONTRACT-3B (ADR-650) — administration Platform du catalogue de contrats : création, édition
 * sanitée, immutabilité des publiés, versionnement, GOUVERNANCE JURIDIQUE distincte de la
 * publication technique, aperçu PDF, RBAC manage_jobdomains.
 */
class PlatformContractTemplateTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $this->admin = PlatformUser::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'pa-'.uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $this->admin->roles()->attach(PlatformRole::where('key', 'super_admin')->firstOrFail());
    }

    private function api(string $method, string $path, array $body = [])
    {
        return $this->actingAs($this->admin, 'platform')->json($method, '/api/platform/contract-templates'.$path, $body);
    }

    public function test_create_is_draft_and_not_legally_validated(): void
    {
        $res = $this->api('POST', '', ['name' => 'CDI FR', 'contract_type' => 'cdi', 'market_key' => 'FR'])->assertCreated();
        $this->assertSame('draft', $res->json('status'));
        $this->assertSame('example_unvalidated', $res->json('legal_status'), 'jamais validé juridiquement par défaut');
        $this->assertNull($res->json('platform_template_id'));
        $this->assertNull(DocumentTemplate::find($res->json('id'))->company_id);
    }

    public function test_update_sanitizes_content(): void
    {
        $t = $this->api('POST', '', ['name' => 'T'])->json();
        $this->api('PUT', "/{$t['id']}", ['body_html' => '<p>{{ employee.full_name }}</p><script>x()</script>'])->assertOk();
        $saved = DocumentTemplate::find($t['id'])->body_html;
        $this->assertStringNotContainsString('<script', $saved);
        $this->assertStringContainsString('{{ employee.full_name }}', $saved);
    }

    public function test_publish_is_technical_not_legal_validation(): void
    {
        $t = $this->api('POST', '', ['name' => 'T', 'contract_type' => 'cdi'])->json();
        $pub = $this->api('POST', "/{$t['id']}/publish")->assertOk();
        $this->assertSame('published', $pub->json('status'));
        // La publication ne valide PAS juridiquement.
        $this->assertSame('example_unvalidated', $pub->json('legal_status'));
    }

    public function test_legal_validation_is_a_separate_decision(): void
    {
        $t = $this->api('POST', '', ['name' => 'T'])->json();
        $this->api('POST', "/{$t['id']}/legal-status", ['legal_status' => 'validated'])->assertOk()->assertJsonFragment(['legal_status' => 'validated']);
        $this->api('POST', "/{$t['id']}/legal-status", ['legal_status' => 'bogus'])->assertStatus(422);
    }

    public function test_published_is_immutable_new_version_editable(): void
    {
        $t = $this->api('POST', '', ['name' => 'T'])->json();
        $this->api('POST', "/{$t['id']}/publish")->assertOk();
        $this->api('PUT', "/{$t['id']}", ['name' => 'Hack'])->assertStatus(422);
        $v2 = $this->api('POST', "/{$t['id']}/version")->assertCreated()->json();
        $this->assertGreaterThan($t['version'], $v2['version']);
        $this->api('PUT', "/{$v2['id']}", ['name' => 'OK'])->assertOk();
    }

    public function test_preview_pdf_same_pipeline(): void
    {
        $t = $this->api('POST', '', ['name' => 'T'])->json();
        $res = $this->api('POST', '/preview-pdf', ['template_id' => $t['id'], 'body_html' => '<p>{{ employee.full_name }}</p>'])->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_requires_platform_permission(): void
    {
        $noPerm = PlatformUser::create(['first_name' => 'N', 'last_name' => 'P', 'email' => 'np-'.uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $this->actingAs($noPerm, 'platform')->getJson('/api/platform/contract-templates')->assertForbidden();
    }
}
