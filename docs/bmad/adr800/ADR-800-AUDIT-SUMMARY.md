# ADR-800 : audit global de rationalisation, synthèse décisionnelle

## État initial

- Branche `dev`, SHA **`c3f468fa3d6a9197891191ead2c29fc202165579`**, date 2026-11-16.
- Non commités, et préservés : `CLAUDE.md`, `typed-router.d.ts`, `docs/bmad/DMS-ARCHITECTURE.md`.
- **Aucun code modifié, aucune migration, aucune donnée modifiée.** Seuls les livrables de `docs/bmad/adr800/` et l'entrée ADR ont été ajoutés.

### Vérifications non destructives exécutées

- `route:list --json`, `schedule:list`, `artisan list`.
- `ModuleRegistry::definitions()`.
- Lectures SQL en lecture seule : permissions, rôles, presets, flags, modules de la société 1.
- Recette navigateur **lecture seule**, avec toutes les requêtes API non-GET bloquées, en chauffeur `bob@leezr.test`.
- Inventaires automatisés : pages, routes et liens, littéraux d'API, références de classes, composants importés, permissions vérifiées.

## Verdict

Leezr n'a **pas** de moteur dupliqué dangereux côté données.

Le contrôle d'accès API est cohérent : aucune mutation Company sans permission, hors actions personnelles, et une source unique des permissions (le manifest).

La complexité vient de **trois accumulations** :

1. **Des surfaces en double** laissées en alias après chaque refonte : 8 routes redondantes, 6 parcours redondants, 192 sous-composants exposés comme routes.
2. **Des chemins parallèles sans date de bascule** :
   - 2 créations de société ;
   - 3 sources de rôles initiaux ;
   - 2 sources de presets de planning ;
   - 3 moteurs d'exigences.

   Le chemin versionné ADR-793→799 est **prêt mais vide** : 0 preset publié et 0 lien JobDomain sur DEV.
3. **Des droits et options qui ne font rien**, ou des fonctions construites mais sans écran :
   - 9 permissions sans effet ;
   - 23 endpoints testés sans interface ;
   - un moteur de feature flags sans aucun lecteur.

L'écart **« module activé ≠ module prêt »** est structurel : rien n'attribue de droits ni ne lance de réglage à l'activation. Le socle d'un assistant commun existe déjà (bundles, contributions, `settingsPanels`, l'assistant Workforce comme modèle). **Aucun nouveau moteur n'est nécessaire.**

## Ce qu'il faut garder, fusionner, retirer

| Question | Réponse courte |
|---|---|
| Pages à garder | Les 60 pages actives utiles et les 19 techniques (FRONTEND-INVENTORY) |
| Pages à fusionner | `members` et `workforce/employees` → `people` ; `workforce/documents`, `signature`, `contract/templates` → `documents/:tab` ; `organization-presets` → onglet JobDomain ; onglet `worktime` → `_WorkingTimeSetup` |
| Pages à supprimer à terme | Les listes historiques `members` / `employees` et `platform/organization-presets/*`, après redirection ; les labos réservés au DEV ; 18 composants jamais montés |
| Services backend à conserver | Tous les moteurs en production : `ModuleActivationEngine`, `PresetService` et adapters, `WorkforceRuleResolver`, `ShiftRuleValidator`, `DocumentResolverService`, `JobdomainGate` (jusqu'à P2-1) |
| Moteurs dupliqués à retirer | `DependencyResolver` ; à terme, les lectures historiques de `JobdomainGate` (après P2-1) et deux des trois moteurs d'exigences (après P2-2, sur GO) ; `FeatureFlag` (décision) |
| Unifier la configuration des modules | Assistant commun : `setupSteps` dans le manifest, étape « Accès » par capacité, composants de réglage existants, état `company_module_setup` additif, ouverture après activation (TARGET §3) |
| Simplifier les permissions | Vue normale par capacité (bundles existants) et mode avancé conservé ; retrait ou branchement des 9 permissions inertes ; les permissions de population restent distinctes |
| JobDomain et presets | Le JobDomain recommande, le preset propose (versionné), la société confirme. Une source par famille ; édition de tous les presets dans la fiche JobDomain ; catalogue en lecture |

## Arbitrages demandés au propriétaire

1. **P0** : GO pour P0-1 (exclusion des `_*.vue`), P0-2 (gardes Platform) et P0-3 (`assign_support`).
2. **Overlays de marché** : réintégrer comme variante de preset, ou déprécier ?
3. **Les 9 permissions inertes** : retirer, ou brancher (liste dans MODULE-RBAC-MAP §2) ? Le nettoyage des pivots touche des données Company.
4. **Fonctions construites sans écran** (revue des bulletins, campagnes, relance antivirus, `reset-key`, partage de champs) : exposer ou déprécier ?
5. **Moteur `FeatureFlag`** : retirer (recommandé) ou adopter ?
6. **Bascule de l'initialisation versionnée (P2-1)** : publier les presets `logistique` depuis l'historique ?
7. **Exigences (P2-2)** : ouvrir un lot de bascule mesurée, aujourd'hui interdit ?
8. **Hygiène DEV (P2-6)** et désactivation de `logistics_fleet` pour la société 1 ?

## Contrôle final : totaux

| Élément | Total | Nature |
|---|---|---|
| Fichiers de pages routés | 290, dont 98 pages réelles et 192 sous-composants `_*.vue` | Exhaustif |
| Routes frontend | 290 générées + 10 redirections manuelles = 300 | Exhaustif |
| Routes API | 896 `api/*` (+ 7 web) | Exhaustif |
| Modules | 51 (25 Company, 26 Platform ; 14 core, 12 addon, 14 internal, 11 platform) | Exhaustif |
| Permissions | 118 (75 Company, 43 Platform) ; 78 bundles | Exhaustif |
| Services (`*Service.php`) | 128 ; plus 62 ReadModels, 160 UseCases, 27 Resolvers, 15 Adapters | Exhaustif par nom de fichier ; « métier » non qualifié un à un |
| Pages ou routes doublons prouvées | 8 routes redondantes, 8 cas de doublon (D1–D8) | Exhaustif pour les pages réelles |
| Endpoints orphelins confirmés | 1 mort + 4 orphelins confirmés ; 23 orphelins UI | Exhaustif (896/896 classés) |
| Code mort confirmé | 7 classes PHP, 18 composants Vue, 1 endpoint, 1 moteur sans lecteur (`FeatureFlag`) | Classes et composants : exhaustif (statique) |
| Éléments suspects | 9 classes « mort probable » (dont `DependencyResolver`), 2 classes testées seulement, 12 endpoints orphelins à confirmer avant retrait | Partiel : méthodes de stores et clés i18n non inventoriées |
| Permissions sans effet | 9 (+ `jobdomain.manage`, inerte en pratique) | Exhaustif |
| Flags historiques | 7 flags d'environnement de comportement + 1 moteur de flags (0 flag) | Exhaustif dans `config/` |
| Parcours redondants | 6 | Sur les 12 parcours A–L |
| Mécanismes parallèles | 7 (M1–M7) | Exhaustif pour le périmètre audité |

**Catégorisation.**
- Chacune des 290 entrées frontend et des 896 endpoints a **une** catégorie explicite.
- Pour les classes, seules celles sans référence (139) sont classées : 82 commandes et 39 modules, auto-découverts et légitimes, plus les 18 autres.
- Les autres classes sont référencées : elles sont présumées utilisées, sans preuve d'usage à l'exécution.

## Limites

- Aucune télémétrie de navigation ni de logs d'accès frontend n'existe : « non lié » ne veut pas dire « jamais visité ».
- Les URL d'API construites par variable ont été vérifiées cas par cas pour les 72 endpoints sans littéral, pas pour les 821 autres. Ceux-ci sont consommés au moins une fois, mais leur appelant peut être un composant non monté.
- La recette navigateur couvre l'exposition des routes `_*.vue`, pas l'ensemble des parcours A–L.

## Livrables

- `FRONTEND-INVENTORY.md`
- `BACKEND-INVENTORY.md`
- `MODULE-RBAC-MAP.md`
- `DUPLICATIONS-AND-DEAD-CODE.md`
- `PRODUCT-JOURNEYS.md`
- `TARGET-ARCHITECTURE.md`
- `RATIONALIZATION-BACKLOG.md`
- ce document.

Checkpoint ADR-800 : **en attente d'arbitrage. ADR-801 n'est pas commencé.**
