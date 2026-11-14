<?php

namespace App\Core\Navigation;

use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;

/**
 * ADR-775 (Company Experience 2.0 / UX0) — CONTEXTUAL ACTIONS.
 *
 * A module contributes the everyday actions it makes possible (« Ajouter une personne », « Demander
 * un document »…) to an INTENT SPACE, through its manifest (`Capabilities::$actions`). A host — the
 * home page, a space header, a person file — asks for the actions of ITS space and context ; it
 * never lists modules, and there is no global button holding every feature of the product.
 *
 * Action shape (manifest) :
 *   key               unique, `<space>.<verb>` ; label = i18n key `actions.<key>` on the frontend
 *   space             intent space the action belongs to (NavGroupRegistry)
 *   icon              tabler icon
 *   to                route location ({name, params?, query?}) ; null when the host opens it in place
 *   context           'space' (default : available at space level) | 'person' | … (needs a subject)
 *   permission        required permission (AND)            ┐ same semantics as nav items :
 *   anyPermission     at least one of these (OR)           │ an action the user cannot perform is
 *   excludePermission hidden when the user holds it        ┘ never returned — no 403 behind a button
 *   home              offered on the home page
 *   primary           the main action of its space
 *   sort              order inside the space
 *
 * Filtering = active module + RBAC. Nothing here widens a permission : the target route keeps its
 * own guard, the action only stops being proposed to someone who would be refused.
 */
class ActionBuilder
{
    /** @return array<int, array<string, mixed>> */
    public static function forCompany(Company $company, ?array $permissions = null): array
    {
        $actions = collect(ModuleRegistry::forScope('company'))
            ->filter(fn (ModuleManifest $m) => ModuleGate::isActive($company, $m->key))
            ->flatMap(fn (ModuleManifest $m) => $m->capabilities->actions)
            ->values();

        if ($permissions !== null) {
            $set = array_flip($permissions);
            $actions = $actions->filter(function (array $a) use ($set) {
                if (! empty($a['permission']) && ! isset($set[$a['permission']])) {
                    return false;
                }
                if (! empty($a['anyPermission']) && array_intersect_key(array_flip($a['anyPermission']), $set) === []) {
                    return false;
                }

                return empty($a['excludePermission']) || ! isset($set[$a['excludePermission']]);
            });
        } else {
            // Owner (bypass) holds every permission : `excludePermission` variants are redundant for them.
            $actions = $actions->filter(fn (array $a) => empty($a['excludePermission']));
        }

        return $actions
            ->filter(fn (array $a) => NavGroupRegistry::space($a['space'] ?? '') !== null)
            ->sortBy(fn (array $a) => [array_search($a['space'], NavGroupRegistry::spaceKeys(), true), $a['sort'] ?? 100])
            ->map(fn (array $a) => [
                'key' => $a['key'],
                'space' => $a['space'],
                'icon' => $a['icon'] ?? 'tabler-plus',
                'to' => $a['to'] ?? null,
                'context' => $a['context'] ?? 'space',
                'home' => (bool) ($a['home'] ?? false),
                'primary' => (bool) ($a['primary'] ?? false),
            ])
            ->values()
            ->all();
    }
}
