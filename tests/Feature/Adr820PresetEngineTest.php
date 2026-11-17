<?php

namespace Tests\Feature;

use App\Core\Documents\DocumentResolverService;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Navigation\NavBuilder;
use App\Core\Presets\PresetApplication;
use App\Core\Presets\PresetService;
use App\Core\Requirements\RequirementAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-820 — the Platform versioned presets are THE initialisation of a new company : the published library
 * (catalog) and RBAC presets are applied through the real registration, without any implicit obligation ;
 * unpublished versions are never applied ; existing companies are untouched ; the JobDomain experience profile
 * orders the navigation without adding nor removing an entry.
 */
class Adr820PresetEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        Jobdomain::where('key', 'logistique')->update(['default_modules' => ['core.theme', 'core.members', 'core.settings', 'core.documents', 'logistics_shipments']]);
        $rbac = PresetService::createDraft('rbac', ['family_key' => 'lg_rbac', 'label' => 'Rôles', 'jobdomain_key' => 'logistique', 'content' => ['roles' => [
            ['key' => 'owner', 'name' => 'Propriétaire', 'is_administrative' => true, 'bundles' => ['members.team_management', 'roles.governance']],
            ['key' => 'driver', 'name' => 'Chauffeur', 'bundles' => ['shipments.delivery']]]]], null);
        PresetService::publish($rbac, null);
    }

    private function library(array $content, bool $publish = true)
    {
        $p = PresetService::createDraft('catalog', ['family_key' => 'lg_lib', 'label' => 'Bibliothèque', 'jobdomain_key' => 'logistique', 'content' => $content], null);
        if ($publish) {
            PresetService::publish($p, null);
        }

        return $p;
    }

    private function register(string $email): Company
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');
        $id = $this->postJson('/api/register', ['first_name' => 'R', 'last_name' => 'U', 'email' => $email, 'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong', 'company_name' => 'Reg '.$email, 'jobdomain_key' => 'logistique', 'plan_key' => 'starter'])
            ->assertCreated()->json('company.id');

        return Company::findOrFail($id);
    }

    private function docActivation(Company $c, string $code): ?DocumentTypeActivation
    {
        return DocumentTypeActivation::where('company_id', $c->id)->where('document_type_id', DocumentType::where('code', $code)->where('is_system', true)->value('id'))->first();
    }

    public function test_a_library_item_never_carries_an_obligation(): void
    {
        $this->expectException(ValidationException::class);
        $this->library(['documents' => [['code' => 'kbis', 'subject' => 'company', 'required' => true]]]);
    }

    public function test_published_library_is_applied_at_registration_without_implicit_obligation(): void
    {
        $existing = Company::create(['name' => 'Ancienne', 'slug' => 'old-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'starter']);
        JobdomainGate::assignToCompany($existing, 'logistique');
        $before = FieldActivation::where('company_id', $existing->id)->count();

        $this->library(['fields' => [['code' => 'phone', 'subject' => 'person', 'included' => true], ['code' => 'iban', 'subject' => 'person', 'included' => false],
            ['code' => 'company_phone', 'subject' => 'company', 'included' => true]],
            'documents' => [['code' => 'id_card', 'subject' => 'person', 'included' => true], ['code' => 'kbis', 'subject' => 'company', 'included' => true],
                ['code' => 'insurance_certificate', 'subject' => 'company', 'included' => true]]]);
        PresetService::setRecommendations('logistique', [['kind' => 'catalog', 'family_key' => 'lg_lib'], ['kind' => 'rbac', 'family_key' => 'lg_rbac']]);

        $c = $this->register('lib820@example.test');
        $this->assertEqualsCanonicalizing(['catalog', 'rbac'], PresetApplication::where('company_id', $c->id)->pluck('kind')->all(), 'published presets applied, traced');
        $codes = FieldActivation::where('company_id', $c->id)->where('enabled', true)->with('definition')->get()->map(fn ($a) => FieldDefinition::find($a->field_definition_id)->code)->all();
        $this->assertEqualsCanonicalizing(['phone', 'company_phone'], $codes, 'only the published library (suggestion « iban » not activated)');
        foreach (['id_card', 'kbis', 'insurance_certificate'] as $code) {
            $this->assertNotNull($this->docActivation($c, $code), "{$code} available in the catalogue");
            $this->assertFalse((bool) $this->docActivation($c, $code)->required_override, "{$code} never required by its presence in the catalogue");
        }
        $this->assertSame(RequirementAuthority::POSITION, RequirementAuthority::of($c->id), 'the position decides, never the access role');
        $required = array_filter(DocumentResolverService::subjectMap($c->id, 'driver', null), fn ($e) => $e['required']);
        $this->assertSame([], $required, 'a new company requires nothing until a process is configured');
        $this->assertSame($before, FieldActivation::where('company_id', $existing->id)->count(), 'existing company untouched');

        // A new library version published after creation never changes the company.
        $v2 = \App\Core\Presets\Preset::where('family_key', 'lg_lib')->sole()->nextVersion(null);
        PresetService::updateDraft($v2, ['content' => ['fields' => [['code' => 'birth_date', 'subject' => 'person', 'included' => true]]]], null);
        PresetService::publish($v2->fresh(), null);
        $this->assertSame(2, FieldActivation::where('company_id', $c->id)->count(), 'no automatic re-application');
    }

    public function test_unpublished_library_falls_back_to_the_historical_path_without_obligation(): void
    {
        $this->library(['fields' => [['code' => 'phone', 'subject' => 'person']]], false);
        PresetService::setRecommendations('logistique', [['kind' => 'rbac', 'family_key' => 'lg_rbac']]);
        $c = $this->register('draft820@example.test');
        $this->assertSame(0, PresetApplication::where('company_id', $c->id)->count(), 'a draft is never applied');
        $this->assertSame(RequirementAuthority::ROLE, RequirementAuthority::of($c->id), 'historical path : historical authority');
        $this->assertSame(0, DocumentTypeActivation::where('company_id', $c->id)->where('required_override', true)->count(), 'ADR-820 : no obligation from the domain, even on the historical path');
    }

    public function test_experience_profile_orders_navigation_without_changing_entries(): void
    {
        $c = Company::create(['name' => 'Nav', 'slug' => 'nav-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'starter']);
        JobdomainGate::assignToCompany($c, 'logistique');
        $keys = fn () => collect(NavBuilder::forCompany($c, null, 'management'))->where('placement', 'nav')
            ->map(fn ($g) => [$g['key'], NavBuilder::leafKeys([$g])])->values()->all();
        $plain = $keys();
        $target = collect($plain)->last(fn ($g) => count($g[1]) > 1);
        $this->assertNotNull($target);
        $this->assertNotSame($target[0], $plain[0][0]);
        Jobdomain::where('key', 'logistique')->update(['experience' => ['group_order' => [$target[0]], 'entry_points' => [end($target[1])]]]);
        $shaped = $keys();
        $this->assertSame($target[0], $shaped[0][0], 'business group first');
        $this->assertSame(end($target[1]), $shaped[0][1][0], 'entry point first in its group');
        $flat = fn ($g) => collect($g)->flatMap(fn ($x) => $x[1])->sort()->values()->all();
        $this->assertSame($flat($plain), $flat($shaped), 'no entry added nor removed');
    }
}
