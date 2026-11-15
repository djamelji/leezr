# ADR-793 — L3 : presets versionnés (RBAC, Organisation, Exigences, Planning)

**Date** : 2026-11-15 · **Base** : `76fd3ce4` · **Environnement** : DEV local uniquement · **Statut** : livré, checkpoint L3 (L4 non commencé)

## 1. Architecture des presets

Un preset est un **gabarit versionné** d'une famille (`family_key`). Une famille a une seule version publiée, des brouillons modifiables et des versions archivées. Il n'agit jamais seul : une société ne reçoit un preset que par un **aperçu en lecture seule** suivi d'une **application explicite, sélective, idempotente et tracée**.

```
JobDomain ──recommande──▶ famille de preset (jobdomain_preset_links)
                                │
                     versions : draft → published → archived   (VersionedPresetLifecycle)
                                │
              PresetService ── adapter de famille (rbac | organization | requirements | planning)
                                │
             preview(company)  (lecture seule)   apply(company, sélection)  (transaction + verrou société)
                                │
              preset_applications (provenance)  +  audit `preset.applied`
```

Rien n'est branché sur la création d'une Company, ni sur un moteur opérationnel.

## 2. Mécanismes réutilisés (pas de second moteur)

| Famille | Moteur réutilisé | Apport L3 |
|---|---|---|
| Organisation | `OrganizationPreset` + `OrganizationPresetService` (ADR-636/638) | cycle de vie extrait dans le trait `VersionedPresetLifecycle` ; `apply()` accepte une version explicite (sinon comportement historique inchangé) |
| Planning | `WorkforcePresetImportService` | `withPresets(content)` : importe une version donnée au lieu des `workforce_presets` du JobDomain |
| Exigences | registre L1 `requirements` + `EffectiveRequirementResolver` (ADR-791/792) | publication = gabarits `company_id null` sourcés `preset` ; application = copies société `derived_from_id` |
| RBAC | `ModuleRegistry::resolveBundles` + `CompanyRole` | rôles composés de bundles déclarés dans les manifests |

## 3. Schéma (migration `2026_11_23_000001_create_presets_tables`, non destructive)

- `presets` : `key` unique, `family_key`, `kind` (rbac|requirements|planning), `label`, `description`, `jobdomain_key`, `market_key`, `version`, `status`, `content` json, `requires_modules` json, `created_by`, `updated_by`, `published_at` ; unique (`family_key`, `version`).
- `preset_applications` : `company_id`, `kind`, `family_key`, `preset_key`, `preset_version`, `selection`, `summary`, `applied_by_type` (platform|company|system), `applied_by`.
- `jobdomain_preset_links` : `jobdomain_key`, `market_key`, `kind`, `family_key`, `sort_order` ; unique.
- Organisation : table `organization_presets` existante, inchangée.

## 4. Versions

- Une version publiée ou archivée est **immuable au niveau modèle** : contenu, famille, numéro et clé gelés (`DomainException` au `save`).
- `publishVersion()` archive l'ancienne version publiée de la famille (une seule publiée).
- `nextVersion()` crée un brouillon `{famille}_v{n+1}` ; en cas de créations simultanées, l'unicité (famille, version) arbitre et le perdant retente avec le numéro suivant (5 essais).
- `diff(from, to)` : éléments ajoutés / retirés / modifiés, par clé d'élément, entre deux versions d'une même famille.

## 5. Aperçu et application

- **Aperçu** : `missing_modules`, `last_applied_version`, et chaque élément avec son statut : `new`, `identical`, `customized`, `conflict`, `requires_confirmation`, `not_applicable`. N'écrit rien.
- **Application** : version publiée uniquement ; modules requis actifs ; transaction avec verrou de la ligne société (applications concurrentes sérialisées) ; par défaut seuls les éléments `new` ; les éléments `requires_confirmation` exigent d'être sélectionnés nominativement ; jamais de modification ni de suppression d'un élément existant ou personnalisé. Rejouer = aucun doublon.
- **Provenance** : une ligne `preset_applications` par application + audit `preset.applied` (sévérité `warning` pour RBAC).
- Une nouvelle version n'est **jamais appliquée automatiquement** : l'aperçu signale l'écart (`last_applied_version` < version publiée).

API Platform (permission `manage_jobdomains`) : `GET/POST /presets`, `PUT /presets/{kind}/{id}`, `POST …/publish|archive|new-version`, `GET …/diff`, `GET …/preview/{company}`, `POST …/apply/{company}`, `GET/PUT /jobdomains/{jobdomain}/preset-recommendations`. Aucune route Company.

## 6. Adapters de famille

- **RBAC** (`{roles:[{key,name,is_administrative,bundles}]}`) : bundles validés contre les manifests. Rôle existant = `identical` ou `customized`, **jamais modifié**. Nouveau rôle administratif ou portant une permission `is_admin` = `requires_confirmation`.
- **Organisation** : validation `OrganizationPresetStructureValidator`, aperçu `diffAgainst`, application via `OrganizationPresetService::apply($company, $actor, $version)`. Sélection partielle refusée (le moteur historique applique l'ensemble, de façon non destructive).
- **Exigences** : cibles (champ/document) existantes ; niveau `recommended`, `default` ou `configurable_required` uniquement — **jamais `imperative`** (qui exige base légale vérifiée, ADR-791) ; postes par `preset_item_key`.
- **Planning** : codes uniques, références d'activités valides ; aperçu et import par le moteur existant.

## 7. Lien JobDomain

Le JobDomain **recommande** des familles (par marché éventuel), il ne les applique pas (P3 : il assemble). Les onglets et colonnes historiques du JobDomain (`default_roles`, `default_fields`, `default_documents`, `doc_config`, `workforce_presets`) sont **conservés tels quels** ; aucun JobDomain historique n'est migré. Correspondance cible (lot ultérieur) : `default_roles` → preset RBAC ; `default_fields` / `default_documents` / `doc_config` → preset Exigences ; `workforce_presets` → preset Planning ; organisation déjà versionnée.

## 8. Articulation avec les exigences (L1/L2)

À la publication, les gabarits de la famille sont créés dans le registre (`company_id null`, `source = preset`, `source_ref = famille`, `source_version`), les gabarits de la version précédente sont remplacés. Le résolveur L1 traite déjà les gabarits comme non opérationnels ; il signale `update_available` sans l'appliquer. L'application crée des copies société dérivées ; une copie personnalisée ou une dispense société n'est jamais écrasée (`customized` / `conflict`). Le moteur opérationnel ADR-790 reste la seule décision appliquée (mode parallèle maintenu, testé : aucun effet opérationnel, y compris salariés sans compte ou sans poste).

## 9. Sécurité RBAC

Aucune élévation silencieuse : un preset ne modifie jamais un rôle existant, n'ajoute jamais de permission à un rôle existant, et un rôle sensible n'est créé que sur sélection explicite. L'application est auditée en `warning`. Les utilisateurs Company n'ont pas accès aux routes de presets (401/403 testé).

## 10. Rapport qualité des postes (`jobroles:quality-report`)

Commande en lecture seule (`--company=*`, `--details`). L'archétype RBAC sert uniquement d'indice (poste de niveau encadrement avec rôle terrain, ou l'inverse) ; aucune correction automatique. Société 1 (DEV) :

| Indicateur | Valeur |
|---|---|
| Salariés | 52 |
| Avec poste | 7 (tous « Responsable d'exploitation ») |
| Sans poste | 45 |
| Poste suspect | 6 |
| Sans compte | 36 |
| Multi-société | 0 |
| Règles historiques disponibles | 15 |
| À valider par un humain | 51 |

Conclusion : les exigences par poste (L2/L3) n'auront d'effet réel qu'après une qualification humaine des postes. C'est un préalable à toute bascule.

## 11. Tests

- `tests/Feature/Adr793PresetsTest.php` : 7/7 (couvrant les 18 scénarios du brief) : cycle de vie, immuabilité, diff ; références invalides ; aperçu, application, personnalisation et absence d'effet opérationnel des exigences ; RBAC sans élévation ; adapters Organisation et Planning ; recommandations, modules requis, isolation, autorisation ; utilisateur Company refusé.
- Tests liés (Organisation, presets Workforce, ADR-79x) : 67/67 après extraction du trait.
- Concurrence (MySQL, base jetable `leezr_adr793_conc` clonée du schéma DEV puis supprimée) : 12 applications parallèles → 12 OK, 3 lignes société (3 cibles distinctes), 12 traces, aucun doublon ; 10 `nextVersion` parallèles (après ajout du retry) → 10 OK, aucun numéro dupliqué (8 tirs sans retry : 2 violations d'unicité propres, aucune version orpheline).
- Suite complète (une seule exécution) : 4411 réussis, 12 ignorés, 5 échecs :
  - 3 échecs historiques `ControllerSizeInvariantTest` (inchangés ; `PlatformPresetController` = 98 lignes, aucun `->save()`, aucune façade DB, non concerné) ;
  - `TenantScopeBypassGuardTest` : **causé par ce lot** (verrou société écrit `withoutGlobalScopes()->whereKey()` dans `PresetService`). Corrigé sans toucher l'allowlist (`Company::query()->where('id')->lockForUpdate()`, `Company` n'a pas de scope tenant), fichier de garde + tests L3 relancés : 9/9 ;
  - `ExternalPadesFlowTest::evidence package integrity and isolated restore` : passe en isolation (20/20) ; aucun fichier de ce lot dans ce périmètre ; instabilité à surveiller, non masquée.

## 12. Compatibilité historique

Aucun JobDomain, rôle, poste, exigence opérationnelle ni demande DMS modifié en DEV. `OrganizationPresetService::apply()` sans version explicite garde son comportement ; `WorkforcePresetImportService` sans `withPresets()` aussi. Registre DEV : aucune ligne société créée par ce lot.

## 13. Limites

- Pas d'interface Company ni Platform dédiée (API seulement) ; build inchangé.
- Organisation : sélection partielle non supportée.
- Défaut **pré-existant** : un `migrate:fresh` MySQL 9.6 échoue sur `2026_10_30_150000_add_signer_identity_to_signers` (nom d'index > 64 caractères). Non corrigé dans ce lot, à traiter séparément.
- Les gabarits d'exigences publiés restent sans effet tant que le mode parallèle n'est pas basculé.

## 14. Lot suivant

L4 : contributions des modules (presets déclarés par les manifests), puis mapping des colonnes historiques des JobDomains vers les familles versionnées. Non commencé.

## 15. Résultat et SHA

Suite : §11. Commit : `git log --grep ADR-793` sur `dev` (SHA rapporté au checkpoint).

---

## Addendum ADR-793.1 — stabilisation L3 avant L4 (2026-11-15, base `819abd65`)

### 1. Tenant scope
- **Cause observée** : le verrou société de `PresetService::apply()` était écrit `Company::withoutGlobalScopes()->whereKey()`, forme non couverte par l'exemption « racine tenant » de `TenantScopeBypassGuardTest` (qui n'exempte que `Company::withoutGlobalScopes()->find|first`).
- **Correctif (présent dans `819abd65`)** : `Company::query()->where('id', …)->lockForUpdate()`. `Company` n'a aucun scope global ; le SQL émis est identique (`select * from companies where id = ? for update`), donc verrouillage, sérialisation et idempotence inchangés : le test de concurrence (12 applications parallèles, aucun doublon) reste valable sans rejeu. Allowlist de la garde **inchangée** (aucun fichier de test de garde modifié). Les `Company::withoutGlobalScopes()->findOrFail` du contrôleur Platform relèvent de l'exemption racine voulue.
- **Tests** : garde + isolation tenant + ADR-733 + presets : 119/119.

### 2. ExternalPadesFlowTest (échec vu une fois en suite complète)
- **Non reproduit** par l'ordre : groupe signature (105 tests) en ordres défaut, inverse, 2× aléatoire, puis suite complète rejouée : tout vert.
- **Cause prouvée par mesure** : le test vérifiait « aucun secret » par `assertStringNotContainsString('otp'|'token', strtolower(json_encode($pkg)))`. Le dossier embarque des **blobs cryptographiques publics aléatoires** (certificat PEM du signataire, PDF base64). Sonde sur 400 dossiers : **9 contenaient « otp »** (2,25 %), tous dans le champ `pem` (ex. `…Hvotp+IN…`, `…KoTPDIp…`) — faux positif, aucune fuite. Pas d'état partagé, de fixture, de fichier temporaire ni de dépendance temporelle en cause.
- **Correctif (test uniquement, règles de signature intactes)** : la recherche de mots `token`/`otp` est conservée sur **tout le contenu textuel** ; seuls le PDF base64 et les PEM sont remplacés après avoir vérifié que chaque `pem` est un `BEGIN CERTIFICATE` (public). Assertions **ajoutées** : aucun nom de champ exporté ne contient `token|otp|secret|private|challenge` ; les valeurs secrètes réelles des signataires (`token_hash`, `challenge`) sont absentes du dossier ; `PRIVATE KEY` toujours interdit sur le JSON complet.
- **Preuve** : 300 exécutions consécutives du test : 300/300 (45 000 assertions), là où l'ancien oracle aurait échoué ~7 fois.

### 3. Migration MySQL `2026_10_30_150000_add_signer_identity_to_signers`
- **Cause** : nom automatique `workforce_signature_enrollments_signature_signer_id_created_at_index` = 68 caractères > 64. Sur MySQL, la table et ses clés étrangères étaient créées, puis l'ajout d'index échouait ; au rejeu, la garde `hasTable` sortait et la migration était enregistrée. **Les bases existantes (dont DEV) n'avaient donc jamais eu les deux index** (composite et `company_id`), seulement les index implicites des clés étrangères.
- **Correctif rétrocompatible** :
  - migration d'origine : noms explicites courts `wf_sig_enroll_signer_created_idx`, `wf_sig_enroll_company_idx` (seules les bases neuves l'exécutent ; une base existante ne la rejoue pas) ;
  - nouvelle migration additive `2026_11_23_000002_repair_signature_enrollment_indexes` : ajoute uniquement l'index absent, **détecté par colonnes** (quel que soit son nom), ne supprime ni ne renomme rien, ne touche aucune ligne ; `down()` sans effet ; rejouable.
- **Validation sur bases MySQL 9.6 jetables (supprimées ensuite)** :
  - base neuve : 320 migrations, 0 en attente, 0 erreur ; index aux noms courts ;
  - base déjà migrée (schéma DEV cloné + table `migrations` + lignes signataires/enrôlements) : seule la réparation s'exécute, ajoute l'index composite (`company_id` déjà couvert par l'index de clé étrangère), checksum des données identique avant/après, second `migrate` = « Nothing to migrate », `up()` rejoué 2× sans doublon.
- **DEV local** : réparation appliquée (additive), checksum signataires/enrôlements/demandes identique avant/après, deux clés étrangères intactes (l'index composite sert désormais celle de `signature_signer_id`). Aucun `migrate:fresh` sur DEV.
- **Test** : `Adr7931SignatureEnrollmentIndexRepairTest` 2/2 (noms ≤ 64, recréation des index manquants, idempotence).

### 4. Suite finale (une exécution)
4415 réussis, 12 ignorés, **3 échecs = dette historique `ControllerSizeInvariantTest`** (inchangée). Aucun autre échec. Build non requis (aucun frontend).

### 5. SHA
Commit de stabilisation : `git log --grep "ADR-793.1"` sur `dev` (SHA rapporté au checkpoint). L4 non commencé.
