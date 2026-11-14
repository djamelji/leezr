# ADR-784 — Stabilisation finale Company Experience 2.0 : intégrité, sécurité, non-régression

> SHA de départ : `b58ae6d1` · SHA final : voir checkpoint · DEV uniquement · aucune migration, aucun moteur nouveau ; calculs de paie, cotisations, congés, Billing et écritures comptables non modifiés.

## 1. DSN — identité employeur

**Constat** : `DsnCompanyResolver` lisait uniquement les colonnes `companies.siret / naf_code / address_*`, qu'aucun formulaire n'alimente ; le profil Company (source des contrats depuis ADR-783 et des factures) n'était pas lu. Le validateur DSN ne contrôlait que le SIRET : une déclaration sans adresse ni NAF pouvait être marquée « validée » — un fichier incomplet présenté comme valide.

**Correction** :
- `DsnCompanyResolver::resolve` lit `CompanyLegalIdentity` : profil Company d'abord, colonnes historiques en repli, rien d'inventé. Champs repris du profil, de même sens juridique : raison sociale (`legal_name`, repli sur le nom), SIRET (NIC dérivé), rue, complément, code postal, ville. **Restent sur leurs colonnes** (aucun équivalent dans le profil) : code NAF/APE, code INSEE, effectif moyen, IDCC.
- `DsnCompanyResolver::identityIssues` : présence (SIRET, NAF, rue, code postal, ville — liste préexistante `missingMandatoryFields`) + formats (validateurs existants : SIRET Luhn, NAF `9999A`).
- `ExportPayrollDsnUseCase` : contrôle **avant toute écriture** (aucun fichier, aucune déclaration, aucune suppression d'une déclaration rejetée) → `DsnEmployerIdentityException`. Les déclarations exportées/transmises restent renvoyées telles quelles (immuables). Les erreurs propres aux salariés continuent d'être enregistrées en brouillon (inchangé).
- API : 422 `dsn_employer_identity` + champs en cause (`missing` / `invalid`) + lien vers le profil quand le champ y est saisissable ; message explicite quand le NAF manque (non saisissable aujourd'hui).
- L'interface DSN affiche déjà honnêtement le mode de transmission (simulation, fichier local, bac à sable — ADR-681) : inchangé.

**Tests** (`ExportPayrollDsnUseCaseTest`, +6) : profil complet écrit dans le fichier (NIC, rue, code postal ; colonne historique ignorée) ; données historiques seules → DSN valide ; identité incomplète → champs listés, aucun fichier, aucune déclaration ; SIRET et NAF invalides refusés ; profil d'une autre entreprise jamais lu ; ancien test « SIRET manquant → brouillon enregistré » remplacé par la nouvelle règle. DSN : 118 + 29 verts ; contrats (ADR-783) : verts.

## 2. Source canonique des données légales

`App\Company\Fields\ReadModels\CompanyLegalIdentity` — utilisée par : génération documentaire (`DocumentVariableResolver`), identité employeur (`/workforce/settings`), refus de contrat (`contractRefusal`), DSN. La facturation lisait déjà les mêmes champs du profil. **Non migré** : `GenerateOfficialPayslipsUseCase` (bulletins officiels) lit encore `companies.siret / naf_code` (périmètre paie, non modifié).

## 3. Audit différentiel de sécurité (surfaces ADR-775 → 783)

| Surface | Contrôles | Résultat |
|---|---|---|
| Recherche documentaire `/company/documents/search` | droits par source, cloisonnement, métadonnées seulement | conforme (tests UX3) |
| Actions contextuelles | filtrées module + RBAC, routes gardées | conforme (UX0) |
| Self-service `/workforce/me/*` | fiche de la personne connectée, paramètres ignorés | conforme (UX4, UX4.1) |
| Soldes, statistiques, dépôt d'absence | `WorkforceAccess` (vue globale, soi, rapports) | conforme (UX4.1, UX5) |
| Rémunération (contrats générés) | instantané masqué sans droit rémunération | conforme (ADR-771) |
| Rôles, Réglages (hub) | entrées = gardes des pages cibles | conforme (UX5) |
| Préférences (`config/week-start`) | lecture `workforce.view`, écriture `workforce.manage` | conforme |
| **Catalogue Modules `GET /api/modules`** | lu par tout membre (les écrans s'en servent) | **défaut corrigé** : montants contractés et abonnements payants servis à tout membre (ex. conducteur) → désormais réservés à `modules.manage` / `billing.manage` ; prouvé par contre-épreuve |
| Notifications de paiement | client / Platform, idempotence, traductions | conforme (UX5) |
| Changement d'entreprise | navigation et hub suivent l'entreprise | conforme (UX0, UX5) |
| Identité employeur `/workforce/settings` | `workforce.view` ; identité légale publique (raison sociale, SIRET, adresse) | accepté |

Tests HTTP de sécurité (filtre Ux, Adr77x/78x, Security, Tenant, Isolation, Scope, Confidential, Dsn, Notification) : 801 réussis, 5 ignorés (préexistants). `Adr784SecurityTest` : 3/3.

## 4. Dette d'architecture (`ControllerSizeInvariantTest`)

Les 3 tests (taille > 250 lignes ; `DB::` en contrôleur ; `->save()` en contrôleur) échouent depuis avant UX0.

| Origine | Fichiers |
|---|---|
| **Préexistante, inchangée** | `ShiftController` 781, `SignatureController` 686 (+`save()`), `MemberDocumentController` 353, `EmployeeController` 343, `EmailInboxController` 327, `WeekTemplateController` 317 (+`DB::`, `save()`), `TimeEntryController` 309, `TimesheetController` 295, `DocumentRequestController` 278, `CompanyBillingController` 261, `PersonDocumentController` 258 ; `save()` dans `WorkforceConfigController`, `ActivityPresetController`, `CoverageCategoryController`, `SignerController` ; `DB::` dans `SignatureWebhookController` |
| **Réduite pendant CE 2.0** | `EmployeeSelfController` 322 → 287 (ADR-780) |
| **Aggravée pendant CE 2.0** | `ContractController` 291 → 294 (contrôle d'identité ramené à un appel, ADR-784) ; `LeaveController` 309 → 312 (contrôles de périmètre ADR-780/781) |

Dangerosité : `DB::` dans `SignatureWebhookController` (transaction de webhook) et `WeekTemplateController` sont les plus sensibles (logique transactionnelle hors use case). Aucun seuil modifié, aucune exclusion ajoutée. Une mise en conformité complète est un chantier d'extraction (use cases) à planifier.

## 5. Stabilité des recettes

- 429 sur `/api/login` : rafales de connexions des recettes. `loginCompany` respecte désormais `Retry-After` ; les recettes UX0 à UX5 ne comptent plus ce refus réessayé comme une erreur produit (toute autre réponse ≥ 400 reste comptée). Limitation de débit inchangée.

## 6. Données de recette

Préservées et vérifiées : documents générés UX3 (ids 26 à 33), demandes annulées de Bob (68, 69, 71, 73), entreprise de recette Beta Services (aucun site résiduel). Aucune génération de contrat ni de DSN en DEV partagé (documents immuables / déclarations) : couverture par tests HTTP sur base isolée.

## 6 bis. Résultats

| Étape | Résultat |
|---|---|
| Tests ciblés DSN + contrats | 161/161 ; DSN `ExportPayrollDsnUseCaseTest` 29/29 |
| Tests HTTP de sécurité | 801 réussis, 5 ignorés ; `Adr784SecurityTest` 3/3 (contre-épreuve : échoue sur le code d'avant) |
| Recette navigateur lot 1 (Accueil, Navigation, Équipe, Documents, Réglages, Modules ; 8 profils ; sans Workforce ; changement d'entreprise ; mobile) | 38/38 |
| Recette navigateur lot 2 (Planning & temps hors Bob, tiroirs, dialogues, planning historique) | 16/16 |
| Suite PHPUnit complète (une fois, après toutes les modifications) | 4317 réussis, 12 ignorés, **3 échecs = `ControllerSizeInvariantTest` (dette historique, §4)** |
| Build final | propre |

**Non exécutés en navigateur** (écritures irréversibles en DEV partagé, aucun environnement isolé navigateur) : génération de contrat (parcours exécutés UX2), dépôts/demandes/génération (parcours exécutés UX3), signature sandbox de bout en bout, génération DSN, parcours salarié de Bob. Couverture HTTP sur base isolée : contrats, signature (ADR-771/772), DSN, dépôts et demandes (UX3), notifications de paiement simulées (UX5). « Employé sans compte » couvert par UX2/UX4 (profils, création de créneaux et d'absences sur une fiche sans compte).

## 7. Blocages pour la préproduction

1. **Code NAF/APE non saisissable** dans Leezr : la DSN est désormais (à juste titre) bloquée pour toute entreprise sans NAF ; il faut un champ de profil et sa reprise (décision produit).
2. Bulletins officiels : lisent encore les colonnes `siret / naf_code` (même cause que la DSN).
3. Signature électronique : prestataire de test uniquement (ADR-765) ; transmission DSN non réelle (ADR-681).
4. Stripe sandbox et worker de staging (ADR-737) : inchangés.
5. Dette d'architecture du §4.

## 8. Verdict

🟢 **GO DEV** · 🟡 **GO préparation préproduction** sous réserve de traiter les blocages 1 et 2 (NAF saisissable, bulletins officiels sur l'identité canonique) · 🔴 **NO-GO PROD** (blocages 3 à 5).
