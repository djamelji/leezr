# Leezr 4.0 — Correction de SystemSeeder : préservation des décisions Platform (ADR-832)

> Branche `dev` locale. Aucun push, aucun déploiement, aucune connexion distante, aucune écriture sur les sociétés
> réelles ni sur la base DEV locale (tous les essais sur des clones marqués). Objectif : **un déploiement met à jour
> le logiciel sans écraser les décisions prises depuis Platform.**

## 1. Synthèse

| | Avant | Après |
|---|---|---|
| Règle | `updateOrCreate` / `sync` : la valeur du code gagne toujours | propriété **par champ** : le code ne réécrit que ce qu'il détient ; une valeur administrée n'évolue que si personne ne l'a modifiée depuis la dernière écriture du code |
| 10 décisions Platform fictives sur clone du DEV local | **8 écrasées** (prix, essai, TVA statut et marché, JobDomain, règle de paiement manuel, permission retirée du rôle admin, nom du rôle, libellé de notification) | **10 préservées**, signalées comme conflits |
| Nouveaux éléments (module, permissions, thème) | installés | installés ; nouvelles permissions Platform offertes au rôle `admin` |
| Données Company, presets publiés | non écrits | non écrits (empreintes identiques) |
| 2ᵉ exécution | réécrivait `module_contributions` (ordre des clés JSON) | **aucune écriture** |
| Conflit code ↔ configuration | invisible (écrasement silencieux) | journal `system_sync.conflict`, sortie console, rapport JSON, commande d'alignement explicite |
| Déploiement DEV | aucune synchronisation (le DEV n'a jamais reçu les nouvelles définitions) | `system:sync` à chaque déploiement (sûr) |
| Déploiement production | `db:seed --class=SystemSeeder` à chaque déploiement (écrasait) | `system:sync --force --json` (préserve, rapporte) |

## 2. Écritures identifiées (LOT 1) et propriétaires réels

Méthode : lecture de chaque composant appelé par `SystemSeeder` **et** recherche, pour chaque table, d'un éditeur
réel (contrôleurs et cas d'usage Platform, import, commandes). Un champ est « administré » si Platform peut le changer
par un parcours réel ; il est « code » si aucun parcours ne le modifie et que le logiciel en dépend.

| Composant | Table | A — définition système (code) | B — configuration administrée (Platform) | Création seule | Éditeur Platform constaté |
|---|---|---|---|---|---|
| `PlatformPermissionCatalog` | `platform_permissions` | clé, libellé, module, `is_admin` | — | — | aucun |
| `PlatformSeeder` | `platform_roles` | clé `super_admin`/`admin` | — | nom | `UpsertPlatformRoleUseCase` (nom, permissions) |
| `PlatformSeeder` | `platform_role_permission` (admin) | — | **ensemble des permissions** : chaque permission offerte une fois | — | idem |
| `PlatformSeeder` | `platform_users` (admin@) | e-mail | — | prénom/nom (C : appartient au titulaire) ; mot de passe déjà protégé | profil |
| `PlatformSeeder` | `platform_settings`, polices | — | — | déjà création seule | Paramètres |
| `PlanRegistry` | `plans` | clé | nom, niveau, description, prix, populaire, essai, libellés, limites | `is_active` (déjà) | `UpsertPlanUseCase` (tous champs, ADR-588) |
| `ModuleRegistry` | `platform_modules` | nom, description, ordre (manifeste ; Platform affiche ses *overrides*) | — | activation globale | `TogglePlatformModuleUseCase`, `UpdateModuleConfigUseCase` (colonnes *override* / tarification, jamais écrites ici) |
| `ModuleProductConfigSeeder` | `platform_modules` (commercial) | — | — | déjà « initialise seulement » | idem |
| `NotificationTopicRegistry` | `notification_topics` | description, catégorie, portée | libellé, icône, sévérité, canaux, ordre, activation | — | `PlatformNotificationTopicController` |
| `JobdomainRegistry` | `jobdomains` | clé | libellé, description, modules par défaut ; rôles/champs/documents/presets Workforce historiques (lecture seule depuis ADR-797 sauf `jobdomains.legacy_editing`) | disponibilité à l'inscription (déjà) | `UpdateJobdomainUseCase` |
| `CompanyPermissionCatalog` | `company_permissions` | clé, libellé, module, `is_admin` | — | — | aucun |
| `FieldDefinitionCatalog` | `field_definitions` (Platform) | `is_system`, `created_by_platform` ; **structurel** : portée, type | libellé, traductions, règles, options, ordre | — | `UpsertPlatformFieldUseCase` |
| `DocumentTypeCatalog` | `document_types` | — | — | déjà `firstOrCreate` | Documents système |
| `ModuleContributionCatalog` | `module_contributions`, modèles `requirements` | versionné par empreinte (ADR-794) ; propositions jamais imposées | — | — | aucun (versions) |
| `MarketRegistry` | `languages` | — | nom, nom natif, ordre | actif, défaut | `LanguageController` |
| `MarketRegistry` | `markets` | drapeau (rempli s'il est vide) | nom, devise, **TVA (bps)**, locale, fuseau, indicatif, ordre, UE | actif, défaut | `MarketCrudController`, import Platform (`importFromArray`, TVA comprise) |
| `MarketRegistry` | `legal_statuses` | — | nom, description, TVA applicable, **taux de TVA**, ordre | défaut | `LegalStatusController` |
| `MarketRegistry` | `market_language` | — | ensemble des langues : chaque langue offerte une fois | — | marchés |
| `PaymentModuleSeeder` | `platform_payment_modules` (interne) | nom, description | — | installé, actif, santé, ordre | `PaymentGovernanceCrudService` (activer/désactiver, santé) |
| `PaymentModuleSeeder` | `platform_payment_method_rules` (manuel) | — | — | priorité, actif | idem (`updateRule`) |
| `MarketRuleSetSeeder`, `WorkforcePayrollRuleSeeder` | `market_rule_sets` | **référence légale** (valeur, source, référence), versionnée par `effective_from` | — | — | **aucun** (les sociétés adaptent par leurs propres réglages) |
| `ConventionCollectiveSeeder` | `convention_collectives`, `…_rules` | référence légale | — | — | **aucun** |
| Bulletins (`PayslipDraft…`, `PayslipOfficialFr…`) | `document_templates` | — | — | déjà « si absent » | — |
| `ContractTemplateSeeder` | `document_templates` (démo Platform) | identité, type, `is_system` | nom, description, corps, schéma, mise en page, métadonnées | — | — (versionné : une évolution = nouvelle version) |
| `cleanupStalePermissions` | permissions retirées du code | suppression des clés absentes du catalogue (inertes) | — | — | — ; désormais **signalée** dans le rapport |

**C — données historiques** : sociétés, abonnements, utilisateurs, rôles et modules des sociétés, contrats, documents,
factures, presets versionnés (`presets`), réglages Workforce des sociétés : **aucun composant ne les écrit** (vérifié par
le code et par empreintes `CHECKSUM TABLE` sur clone). Exceptions préexistantes réservées à `local/testing` : Stripe de
test et carte de test de la société de démonstration (`PaymentModuleSeeder`).

Deux pièges de classement évités : les règles de marché et de paie **ne sont pas** administrables (aucun éditeur :
classées code et seulement signalées quand elles changent) ; à l'inverse, le taux de TVA des marchés n'a pas d'écran
d'édition mais **est** modifiable par l'import Platform (classé administré).

## 3. Responsabilités corrigées (LOT 2)

`App\Core\System\SystemSync` (Core, sans métier) — un seul mécanisme, réutilisé par les registres existants (aucun
deuxième moteur de configuration, aucun nouveau registre) :

- `reconcile(subject, Model, match, code, administered, createOnly, structural)` — par champ :
  - **code** : aligné ; changement signalé `code_updated` ;
  - **administré** : à la création, écrit et mémorisé (`system_sync_baselines` : empreinte de la valeur du code
    appliquée) ; ensuite : égal au code → rien ; égal à la **dernière valeur du code** → suit l'évolution (`evolved`) ;
    sinon **conservé** + `conflict` (`baseline: administered` = modifié dans Platform, `unknown` = installation
    antérieure à ADR-832) ;
  - **création seule** : jamais réécrit ; **structurel** : écart signalé (`structural_conflict`), jamais appliqué.
- `offerMembers(...)` — relations ensemblistes (permissions du rôle `admin`, langues d'un marché) : membre créé par
  ce passage ou propriétaire créé → attaché ; membre retiré après avoir été offert → **reste retiré**
  (`preserved_removal`) ; membre absent de provenance inconnue → `conflict`.
- Comparaison canonique (conversions du modèle, nombres, booléens, clés JSON triées) : MySQL réordonne les clés JSON ;
  sans cela tout paraîtrait modifié.
- Recherche par la représentation **stockée** de la clé (dates `effective_from`).

Exigences du LOT 2 : (1) installation des définitions manquantes ✔ ; (2) mise à jour de ce que le code détient, règle
documentée ci-dessus ✔ ; (3)(4) configurations, prix, paramètres, permissions personnalisées jamais réinitialisés en
silence ✔ ; (5) presets versionnés non écrits ✔ ; (6) aucune donnée Company ✔ ; (7) idempotence ✔ ; (8) conflits
visibles ✔. Pas de `firstOrCreate` généralisé : une évolution du code d'une valeur **non modifiée** reste appliquée
(test `test_an_unchanged_value_follows_a_code_evolution`).

Correctif connexe : `ModuleContributionCatalog` ne réécrit plus `payload` quand l'empreinte est identique (idempotence
MySQL).

## 4. Séparation des opérations (LOT 3)

| Opération | Outil | Comportement |
|---|---|---|
| Installation d'une plateforme vierge | `migrate` puis `system:sync` (ou `db:seed --class=SystemSeeder`, conservé) | tout est créé ; références mémorisées ; 0 conflit |
| Synchronisation au déploiement | `system:sync --force --json=<rapport>` (`deploy_release.sh`, production et DEV) | installe et aligne le code ; conserve l'administré ; rapporte |
| Simulation | `system:sync --dry-run [--json]` | transaction annulée : rien n'est conservé |
| Modification volontaire d'une configuration | écrans Platform (inchangés) | la valeur devient « administrée » : aucun déploiement ne la remet au code |
| Migration exceptionnelle et contrôlée | `system:sync --adopt=<sujet>:<clé>:<champ>` (journalisé `system_sync.adopted`) **ou** migration de données dédiée (modèle ADR-823 `logistics_rbac_preset_least_privilege`) | acte délibéré, simulable |

Compatibilité : `SystemSeeder` garde son nom et son point d'entrée (`DatabaseSeeder`, `db:seed`, tests existants) ;
`deploy.sh` (ancien script racine, non utilisé par le workflow) appelle encore `db:seed --class=SystemSeeder`, ce qui
applique désormais la même règle. Les commandes et tests qui appellent un registre seul (`PlanRegistry::sync()` …)
suivent la même règle sans rapport.

Exécution automatique en production : un déploiement ordinaire **ne peut plus** effacer une modification Platform.
Premier passage sur la production : toute valeur différente du code sera conservée (`unknown`), donc sans effet sur
les tarifs, taxes ou rôles en place ; les écarts seront listés dans `shared/storage/logs/system-sync-<horodatage>.json`.

## 5. Tests (LOT 4)

### 5.1 PHPUnit — `tests/Feature/Adr832SystemSyncTest.php` (6 tests, 51 assertions)

| Test | Prouve |
|---|---|
| `blank_install_creates_everything_and_a_second_run_changes_nothing` | installation vierge, 0 conflit, admin reçoit toutes les permissions ; 2ᵉ passage : aucune entrée, empreintes de 21 tables identiques |
| `platform_decisions_survive_a_deployment_and_conflicts_are_reported` | prix (par le vrai cas d'usage), TVA d'un statut, libellé et modules d'un JobDomain, thème, champ, module désactivé, permission retirée du rôle admin, nom du rôle, rôle personnalisé, règle de paiement manuel → **préservés** ; nouvelle permission Platform/Company et nouveau module **installés**, nouvelle permission offerte à admin ; presets et tables Company identiques ; conflits signalés ; 3ᵉ passage sans écriture |
| `an_unchanged_value_follows_a_code_evolution` | une valeur non modifiée suit l'évolution du code (`evolved`) |
| `historical_install_without_baseline_preserves_then_adopt_applies_on_purpose` | base antérieure : conservé (`unknown`) ; `--adopt` aligne ; plus de conflit ensuite |
| `structural_divergence_is_reported_never_applied` | type de champ divergent : signalé, non changé |
| `dry_run_keeps_nothing` | `--dry-run` annule tout ; rapport JSON produit |

### 5.2 Clones MySQL (`docs/bmad/leezr-4-0/systemseeder/clone-sync-test.sh`, preuves dans `…/systemseeder/evidence/`)

| Clone | Scénario | Résultat |
|---|---|---|
| `leezr_clone_blank832` | base vide : 349 migrations, 3 passages | passage 1 : 200 créations, 46 attributions, **0 conflit** ; passages 2 et 3 : **aucune table modifiée** |
| `leezr_clone_sync832b` | copie du DEV local (configurations historiquement administrées) + 10 décisions Platform fictives, **nouveau code** | **10/10 préservées** ; ajoutés : `workforce_referral`, `workforce_referral.manage/refer/review`, `workforce.view_all`, thème de parrainage ; tables modifiées : `company_permissions`, `notification_topics`, `platform_modules`, `system_sync_baselines` seulement ; presets et tables Company identiques ; 2ᵉ passage : aucune table modifiée → **vert** |
| `leezr_clone_sync832old2` | même scénario, **ancien code** (worktree `1f2246bd`, autoload propre) | **8 décisions écrasées** (`old-behaviour-administered-diff.txt`) ; 15 tables catalogue réécrites |
| `leezr_clone_sync832b` | `--dry-run --adopt=jobdomains:logistique:default_roles` | 1 `adopted`, rien conservé (vérifié en base) |
| `leezr_clone_devremote_sim832b` | `clone-test.sh` complet sur dump simulé du DEV (procédure prévue pour la copie réelle) | **vert** : intégrité, restauration (197 tables, comptages identiques), migration, schéma et index identiques, 0 ligne perdue, 0 valeur administrée remplacée, 2ᵉ passage sans écriture ; 3 ARB |

Deux essais écartés, et pourquoi : (1) premier passage `leezr_clone_sync832` — rouge à cause du défaut d'idempotence
`module_contributions` (corrigé, puis clone neuf) ; (2) `leezr_clone_sync832old` — l'« ancien code » chargeait en
réalité le nouveau (lien symbolique `vendor` → autoload du dépôt principal) : résultat invalide, refait sur
`…old2` avec un autoload propre au worktree. Premier `clone-test.sh` (`…devremote_sim832`) : écart d'index dû à la
référence choisie (base vierge ≠ DEV migré : nom d'index historique `wf_sig_enroll_company_idx`), refait avec la bonne
référence.

### 5.3 Non-régression

- Tests ciblés (registres, champs, notifications, presets, tarification, taxe, permissions Company, fixtures DEV,
  contributions de modules) : 165 verts.
- Suite complète : 4773 réussis, 3 échecs historiques `ControllerSizeInvariant` (§9.1).
- `pnpm build` : propre (aucun fichier front modifié).
- Les 465 E2E n'ont pas été relancés (consigne) : aucun code front ni parcours HTTP modifié.

## 6. Compatibilité historique

- Installations existantes (DEV local, DEV distant, production) : aucune référence au premier passage → **toute
  valeur différente du code est conservée** ; seules les valeurs égales au code sont mémorisées. Aucun risque de
  remise à zéro au premier déploiement.
- Écarts historiques réels du DEV local (non fictifs), signalés et non appliqués :
  1. `jobdomains:logistique:default_roles` — chauffeur recommandé avec `members.team_view`, `settings.company_info`,
     `documents.access` (code : `theme.full`, `shipments.delivery`, ADR-823). L'ancien code l'aurait aligné
     d'office ; ne concerne que les **nouvelles** sociétés.
  2. `platform_role_permission:admin` — `view_system_health`, `view_usage_monitoring` absentes du rôle `admin`
     (l'ancien code les aurait réattribuées).
- La table `system_sync_baselines` est additive (migration `2026_12_13_000001`, `down()` = suppression de la seule
  table de référence ; aucune donnée métier).

## 7. Impact sur le déploiement DEV (LOT 5)

- **Décision fondée sur les tests : oui, la synchronisation système doit tourner automatiquement au déploiement DEV**
  — étape `[5.6] system:sync --force --json` ajoutée à `deploy/deploy_release.sh` (effective au premier push autorisé).
  Raisons : elle ne peut plus effacer une configuration, elle est idempotente, elle ne touche ni sociétés ni presets,
  et le DEV distant n'a jamais reçu les définitions nouvelles (module, permissions, thèmes).
- Production : `[5.5]` passe de `db:seed --class=SystemSeeder` à `system:sync --force --json` (même code, rapport en plus).
- `clone-test.sh` étape 5 = exactement ce que fera le déploiement ; critères : KO si table hors catalogue modifiée,
  valeur administrée remplacée ou 2ᵉ passage non vide ; ARB par conflit.
- Outils livrés conservés : `remote-audit.sh` (inchangé), `remote-backup.sh` (inchangé), `clone-test.sh` (étape 5
  adaptée, revalidée). Rien n'a été exécuté à distance.
- Plan mis à jour : `docs/bmad/LEEZR-4-0-DEV-SYNC-PLAN.md` §0, §4, §5.3, §6, §8, §10, §11 ;
  recette `post-deploy-recette.md` (P0.6, A2, A4).

## 8. Risques résiduels

| Risque | Niveau | Traitement |
|---|---|---|
| Conflits listés à chaque déploiement tant qu'aucune décision n'est prise | faible | lisibles (journal, JSON) ; `--adopt` ou laisser |
| Valeur Platform égale par hasard à une ancienne valeur du code : suivrait une évolution ultérieure | faible | inhérent à la règle à trois points ; évolution signalée `evolved` dans le rapport |
| Écrasement par d'autres chemins hors `SystemSeeder` (import de marchés, `leezr:dev-bootstrap` en `local`, migrations de données) | faible | actes explicites, hors déploiement ordinaire |
| Suppression des permissions retirées du code (`cleanupStalePermissions`) : retire aussi leurs liaisons de rôles | faible (permissions inertes) | conservé, désormais signalé (`removed`) |
| Pas d'écran Platform des conflits | faible | non créé (UI absente du stock Vuexy ; à consigner si souhaité) |
| `deploy_release.sh` modifié : premier usage réel au prochain push | moyen | `clone-test.sh` étape 5 sur la copie réelle avant le push |
| Copie réelle du DEV distant non testée | moyen | dépend de l'accès autorisé (`remote-backup.sh` → `clone-test.sh`) |

## 9. Validation

- `Adr832SystemSyncTest` : 6/6 (51 assertions) ; ciblés : 165/165.
- Suite complète : 4773 réussis / 12 ignorés / 3 échecs historiques (§9.1).
- `pnpm build` : OK.

### 9.1 Suite complète

`php artisan test` (après tous les correctifs) : **4773 réussis, 12 ignorés, 3 échecs** — les 3 échecs historiques `ControllerSizeInvariantTest` (liste identique à RC2, hors périmètre). Aucune régression.

## 10. Décisions nécessitant autorisation

1. Pousser `dev` (= déploiement, avec la nouvelle étape `system:sync`) — après les étapes du plan (audit, sauvegarde,
   `clone-test.sh` vert sur la copie réelle).
2. Adopter `jobdomains:logistique:default_roles` (recommandé, nouvelles sociétés seulement) — DEV local et distant.
3. Réattribuer ou non `view_system_health` / `view_usage_monitoring` au rôle Platform `admin`.
4. Tout autre conflit révélé par la copie réelle du DEV distant (conservé par défaut).
5. Déploiement production : passage à `system:sync` (premier passage = relevé des écarts, aucun écrasement).
6. Migrer la base DEV locale (`leezr`) pour la nouvelle table — non fait ici (aucune écriture DEV) ; `REFDB` de
   `clone-test.sh` = un clone migré en attendant.
7. Suppression des clones de cette mission : `leezr_clone_sync832`, `…832b`, `…832old`, `…832old2`,
   `leezr_clone_blank832`, `leezr_clone_devsim832`, `leezr_clone_devremote_sim832`, `…832b` (et dumps associés
   dans `storage/app/recipe-clones/`).
