<?php

namespace App\Core\Documentation;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ADR-787 — help center contextualised by REAL access : effective permissions and active modules of
 * the user in the ACTIVE company (X-Company-Id, membership verified). Never by role name.
 *
 * A topic or an article may carry `required_permissions` (any of) and `required_modules` (all of).
 * Topic rules apply to its articles. Rules are evaluated for company users only : anonymous visitors
 * keep the public audience, platform admins keep the platform audience (both unchanged).
 * Without a verifiable company context, a company user gets NO restricted content (deny by default).
 */
final class HelpCenterAccess
{
    /** Default rules for the shipped content, keyed by topic slug or « topic/article ». */
    public const DEFAULT_RULES = [
        // Guide Entreprise — administration content
        'demarrage' => ['permissions' => ['settings.manage']],
        'gestion-entreprise' => ['permissions' => ['settings.manage', 'roles.manage', 'audit.view']],
        'membres' => ['permissions' => ['members.manage', 'roles.manage']],
        'documents' => ['permissions' => ['documents.manage', 'documents.configure']],
        'modules' => ['permissions' => ['modules.manage'], 'modules' => ['core.modules']],
        'facturation' => ['permissions' => ['billing.manage'], 'modules' => ['core.billing']],
        'support' => ['permissions' => ['support.view', 'support.create'], 'modules' => ['core.support']],
        // Public content shown to company users only when relevant
        'tarification' => ['permissions' => ['billing.manage']],
        'faq/comment-annuler-mon-abonnement' => ['permissions' => ['billing.manage']],
        'faq/comment-contacter-le-support' => ['permissions' => ['support.view', 'support.create']],
        // Espace salarié — each article follows the module it documents
        'mon-espace-salarie/consulter-mon-planning' => ['modules' => ['workforce_planning']],
        'mon-espace-salarie/pointer-mes-heures' => ['modules' => ['workforce']],
        'mon-espace-salarie/demander-une-absence' => ['modules' => ['workforce_leave']],
        'mon-espace-salarie/mes-documents-et-bulletins' => ['modules' => ['core.documents']],
    ];

    private function __construct(private ?User $user, private ?Company $company, private bool $companyAudience) {}

    public static function fromRequest(Request $request, string $audience): self
    {
        $user = Auth::guard('web')->user();
        $company = null;
        $companyId = (int) $request->header('X-Company-Id');
        if ($user && $companyId > 0) {
            $candidate = Company::find($companyId);
            if ($candidate && $user->membershipFor($candidate)) {
                $company = $candidate; // membership verified : never trust the header alone
            }
        }

        return new self($user, $company, $audience === 'company');
    }

    /** True when the topic (and, if given, the article) is relevant AND accessible. */
    public function allows(DocumentationTopic $topic, ?DocumentationArticle $article = null): bool
    {
        if (! $this->companyAudience) {
            return true;
        }

        return $this->passes($topic->required_permissions, $topic->required_modules)
            && ($article === null || $this->passes($article->required_permissions, $article->required_modules));
    }

    private function passes(?array $permissions, ?array $modules): bool
    {
        if (empty($permissions) && empty($modules)) {
            return true;
        }
        if (! $this->user || ! $this->company) {
            return false;
        }
        foreach ($modules ?? [] as $module) {
            if (! ModuleGate::isActive($this->company, $module)) {
                return false;
            }
        }
        if (empty($permissions) || $this->user->isOwnerOf($this->company)) {
            return true;
        }
        foreach ($permissions as $permission) {
            if ($this->user->hasCompanyPermission($this->company, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the support action may be offered (module active + support permission). */
    public function canContactSupport(): bool
    {
        return $this->companyAudience && $this->passes(['support.view', 'support.create'], ['core.support']);
    }

    /**
     * Applies DEFAULT_RULES where no rule is set yet and adds the employee topic when missing.
     * Idempotent, never overwrites a rule edited by the platform, never deletes content.
     */
    public static function applyDefaults(): void
    {
        $data = require database_path('seeders/data/help-center-employee.php');
        $group = DocumentationGroup::where('slug', $data['group_slug'])->first();
        if ($group && ! DocumentationTopic::where('slug', $data['topic']['slug'])->exists()) {
            $topic = DocumentationTopic::create([
                'title' => $data['topic']['title'], 'slug' => $data['topic']['slug'], 'description' => $data['topic']['description'],
                'icon' => $data['topic']['icon'], 'group_id' => $group->id, 'audience' => 'company', 'is_published' => true, 'sort_order' => -1,
            ]);
            foreach ($data['topic']['articles'] as $i => $a) {
                DocumentationArticle::create(['topic_id' => $topic->id, 'title' => $a['title'], 'slug' => $a['slug'], 'excerpt' => $a['excerpt'],
                    'content' => $a['content'], 'audience' => 'company', 'is_published' => true, 'sort_order' => $i]);
            }
        }

        foreach (self::DEFAULT_RULES as $key => $rule) {
            [$topicSlug, $articleSlug] = array_pad(explode('/', $key, 2), 2, null);
            $topic = DocumentationTopic::where('slug', $topicSlug)->first();
            $target = $topic && $articleSlug ? $topic->articles()->where('slug', $articleSlug)->first() : $topic;
            if ($target && $target->required_permissions === null && $target->required_modules === null) {
                $target->forceFill(['required_permissions' => $rule['permissions'] ?? null, 'required_modules' => $rule['modules'] ?? null])->save();
            }
        }
    }
}
