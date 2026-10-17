# Parcours d'activation des modules + divergence DEV/PROD

> Déclencheur : « Workforce marche en dev, pas en prod ». Audit du **parcours complet** d'activation des modules (Company) + de la cohérence de données entre environnements. Verdict : **parcours sain et bien testé** ; la différence DEV/PROD est une **divergence de données de seed**, pas un bug.

## 1. Parcours d'activation — cartographie & verdict

**Source de vérité backend** : `ModuleCatalogReadModel::forCompany()` → par module : `display_state`, `is_active`, `is_entitled`, `entitlement_reason`, `min_plan`, `addon_pricing`, `requires`, `dependents`, `pricing_mode`.

**Moteur** : `ModuleActivationEngine` — modèle par **graphe de raisons** (`REASON_DIRECT` / `REASON_REQUIRED`) :
- **Activation** : cascade-active les `requires` entitled (ajoute REASON_REQUIRED). Bloque (422) seulement si un require est **non entitled** ou **non activé globalement** — pas s'il est simplement « pas encore actif ».
- **Désactivation** : retire la raison DIRECT puis `cleanupOrphans` → un module encore requis par un dépendant actif **reste actif** (garde la raison REQUIRED) ; il n'est désactivé que s'il perd **toutes** ses raisons. → impossible de casser un dépendant en désactivant sa dépendance.
- Champs auto-activés (ADR-168b), events `ModuleEnabled` dispatchés hors transaction.

**Couverture tests (existante, solide)** : `ModuleActivationEngineTest` (transitive requires, raisons, orphelins), `ModuleEntitlementEnforcementTest` (gates plan/jobdomain, display states, reconcile), `ModuleDependencyTest`, `GlobalModuleRouteCoverageTest` (toute route module gated). → **[TEST]** fort.

**Verdict** : le parcours (disponibilité → entitlement → dépendances → activation/désactivation → nav → accès) est **cohérent**. Aucun P0/P1 démontré.

## 2. Distinction des 4 états demandée (#2)

| État | Traité par | Rendu UI |
|---|---|---|
| **Non activé (activable)** | `display_state = AVAILABLE` | chip « Disponible » + bouton Activer |
| **Non autorisé par le plan** | `display_state = LOCKED_PLAN` (+ `upgrade_target_plan`) / `LOCKED_ADDON` / `CONTACT_SALES` | chip verrou + CTA upgrade/achat |
| **Incompatible JobDomain** | **exclu du catalogue** (`ModuleCatalogReadModel` filtre) | **masqué** (choix design : 1 Company = 1 JobDomain → non pertinent non affiché) |
| **Bloqué par dépendance** | **N/A** — le moteur **cascade** les dépendances entitled ; les `requires` sont affichés en chips (`dependencyLabel`) avec leur état (✓ actif / à activer) | chips dépendances ; activer cascade |

**Observations (P3, non bloquantes, documentées)** :
- **Incompatible JobDomain = masqué**, pas affiché « grisé/incompatible ». Défendable (ne pollue pas), mais ne « distingue » pas visuellement cet état → à acter comme choix produit.
- **`DependencyResolver::canActivate`** (utilitaire testé, **non câblé** au flux) applique une règle **plus stricte** que le moteur (« requires déjà actifs » vs cascade). Aucun impact utilisateur (non utilisé pour gater l'UI), mais **piège latent** : ne pas l'adopter comme garde d'activation sans l'aligner sur la cascade du moteur.

## 3. Divergence DEV/PROD (#3) — ⚠️ CORRECTION : elle masquait bien un **vrai dysfonctionnement (P1)**

> Mon premier diagnostic (« entitled, juste pas activé ») reposait sur `DependencyResolver::canActivate` — qui **ne vérifie PAS l'entitlement** (seulement les `requires` actifs). Vérification corrigée via `EntitlementResolver::check` + `display_state` : **le diagnostic initial était incomplet**.

**État réel mesuré (lecture seule) :**
| | Staging | **Prod** |
|---|---|---|
| workforce `is_entitled` | force-activé (DevSeeder) | **`false` (`not_available`)** |
| workforce `display_state` | `active` | **`contact_sales`** |
| modules workforce activés | 5 | **0** |

**Cause racine (démontrée)** : les modules sellable (workforce + logistics_*) sont `type: addon` ; leur **config commerciale** (`is_sellable` + `addon_pricing`) était définie **uniquement dans `DevSeeder::seedModuleConfigs()`** — qui ne tourne **que sur staging**. `SystemSeeder` (prod) ne la posait pas → sur prod, ces add-ons n'ont **aucun `addon_pricing`** → `EntitlementResolver` = `not_available` → `display_state = contact_sales` → **invendables et inactivables** pour un vrai client. Staging (DevSeeder) les force-activait, **masquant** la panne.

→ **P1 : la divergence de seeders masquait une fonctionnalité produit indisponible en prod** (exactement le risque visé par la consigne #3).

**Correction (sans inventer de prix — valeurs existantes promues)** :
- Config extraite de DevSeeder vers un seeder **système idempotent** `ModuleProductConfigSeeder` (données produit, pas démo).
- **Appelé par `SystemSeeder`** → prod ET staging reçoivent la même config catalogue. DevSeeder délègue à ce seeder (plus de duplication).
- Effet (prouvé par test) : workforce passe de `contact_sales` → **`available`** (entitled pour `pro` via `included: {pro:15}`) → **activable**. Les bases **restent indépendantes** : seules les **données catalogue/produit** sont partagées, pas les données tenant/démo.

**Implication commerciale (à valider avant déploiement prod)** : au prochain déploiement, workforce + logistics_* deviennent des add-ons **vendables/inclus** en prod avec les prix du pilote (29€, 19€, per-seat…). Ces valeurs **préexistaient** (non inventées) ; leur promotion en prod relève d'une **validation produit** → commit **local**, non déployé.

**Tests** : `WorkforceModuleActivationJourneyTest` (sans config → `contact_sales` = bug prod ; avec config → `available` = corrigé ; SystemSeeder câblé).

## 4. Plan de régularisation — Leezr Logistics (PROD) — **non exécuté**

La company démo PROD est de la donnée DevSeeder **incohérente** (héritée) : (a) workforce non activé, (b) abonnement figé `is_current=NULL` (ANO-001). **Aucune modification d'abonnement/finance exécutée** (consigne).

**Plan proposé (sur autorisation, traçable, non destructif) :**
1. **Modules** — activer via le moteur (respecte deps + entitlements, idempotent, non financier) :
   ```php
   $c = App\Core\Models\Company::where('slug','leezr-logistics')->first();
   App\Core\Modules\ModuleActivationEngine::enable($c, 'workforce_payroll'); // cascade workforce + leave
   // vérifier : ModuleCatalogReadModel::forCompany($c) → workforce* en is_active=true
   ```
2. **Abonnement (financier — différé, hors ce lot)** : `is_current=1` puis `billing:renew` **sous supervision** (cf. ANO-001 §7). **Ne pas exécuter** sans validation explicite (pas de renouvellement rétroactif / modif financière PROD).

**Condition de réussite** : workforce visible/accessible pour owner sur prod **et** « prochaine facture » cohérente — sans réécriture comptable.

## 5. Parcours restant à prouver au navigateur (#5, honnête)
- **CRUD modules réel [NAV]** (activer workforce → nav apparaît → page accessible ; désactiver → nav disparaît) : à exécuter sur une **Company de test dédiée** (données isolées), pas sur la démo partagée. Aujourd'hui **[TEST]** (engine/HTTP) solide, **[NAV] non prouvé**.
- Recommandation : créer une company de test E2E jetable (inscription dédiée) et étendre le harness Playwright au flux activer/désactiver.
