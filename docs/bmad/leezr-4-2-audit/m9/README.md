# Leezr 4.2 — M9 : assainissement technique (ADR-848)

Mission : corriger la dette **démontrée**, par petits lots testés, sans perte de fonctionnalité.
Source de l'inventaire : `05-technical-cleanup.md`, `01-inventory.md`, `08-roadmap.md`.
Travail local sur `dev`, recette sur le clone marqué `leezr_audit842` (sociétés fictives seulement).
Aucun push, aucun déploiement, aucune donnée supprimée, aucune migration réécrite.

## 1. Inventaire avant / après

| Réf. | Élément | Avant | Après | Classe |
|---|---|---|---|---|
| TEC-09 | Contrôleurs > 250 lignes | 13 (782 → 261 lignes) ; 3 échecs historiques `ControllerSizeInvariantTest` | **0** ; les 4 tests de l'invariant sont verts, **aucune liste d'exceptions élargie** | B (consolidé) |
| TEC-09 | `DB::` dans des contrôleurs hors liste | 4 | 0 | B |
| TEC-09 | `->save()` dans des contrôleurs hors liste | 7 | 0 | B |
| SEC-11 / TEC-05 | `GET /api/whoami` « temporaire » | actif, sans appel, écrivait l'e-mail dans les journaux | **supprimé** (`/me` couvre le besoin) | A |
| TEC-04 | `public/mockServiceWorker.js.disabled` | fichier inerte | **supprimé** | A |
| TEC-02 | Laboratoires `ui-lab`, `company/dev-lab`, `platform/presentation-lab` | compilés dans le build de production (masqués par `devOnly`) | exclus du build de production, disponibles en développement | B |
| §4 | Dépendances front sans référence | 9 | **retirées** (jwt-decode, prismjs, vue-prism-component, roboto-fontface, 4 extensions tiptap, rollup-plugin-visualizer) | A |
| §4 | `tsx`, `@stylistic/stylelint-*`, `stylelint` | candidats | **conservés** : `build:icons` et la configuration stylelint s'en servent | E |
| — | Imports morts (front, hors `@core`/`@layouts`) | 8 | 0 | A |
| TEC-08 | `/api/company/billing/timeline` | — | déjà corrigé (aucune occurrence) | E |
| TEC-06 | `GET/PUT /api/platform/world-settings` | — | conservé : protégé par permission, aucun écran | D |
| TEC-07 | Routes sans référence statique | 5 | `pay-now`, `billing-day`, `disclosures`, `overstaffing-preview` testées et conservées ; `register/funnel-track` voir §4 | C/D |
| TEC-01 | 16 pages-alias | — | conservées (liens d'e-mails, période de 12 mois de l'audit) | C |
| TEC-10/11 | Rôles et sociétés de test locaux | — | non touchés (données, décision séparée) | C |
| TEC-12/13/14/15/16 | Configuration historique, champs legacy, permissions inertes, `workforce_referral`, MRR | — | hors périmètre M9, ou déjà traités (MRR : M4) | C |

## 2. Éléments supprimés et justification

- **`GET /api/whoami`** (route et méthode `AuthController::whoami`) :
  - diagnostic marqué « temporaire » ;
  - aucun appelant front, aucun test ;
  - doublon de `/api/me` ;
  - inscrivait l'e-mail de l'appelant dans les journaux serveur à chaque appel ;
  - aucune procédure d'incident ne s'en sert (recherche dans `docs/` et `scripts/`).
  - Test : `Adr848TechnicalCleanupTest` (404 et `/me` toujours en 200).
- **`public/mockServiceWorker.js.disabled`** : inerte depuis le LOT 1, aucune référence.
- **9 dépendances front** : aucune référence dans le code, les configurations ou les scripts ; build vérifié après retrait.
- **8 imports morts** : vérifiés un par un, en tenant compte de l'usage en template, en kebab-case et par décomposition `...nom`.

Rien n'a été supprimé côté base de données, migrations, données métier, scripts d'incident ou de restauration.

## 3. Doublons consolidés

| Doublon | Copies | Implémentation de référence |
|---|---|---|
| Rendu PDF des factures | 2 (la copie Platform était **dégradée** : paiements échoués inclus, sans typographie ni police allégée) | `App\Core\Billing\Documents\InvoicePdfRenderer` (Company et Platform produisent le même document) |
| Périmètre d'écriture de la planification | 3 (`ShiftController`, `WeekTemplateController`, `ScheduledActivityController`) | trait `PlanningScope` |
| « Exécuter puis enregistrer l'opération annulable » (ADR-722) | 5 | `PlanningOperationRecorder::attachCreated` |
| Réponse « refus métier → 422 » (et sureffectif → 422 avec charge utile) | une par action, dans tous les contrôleurs Workforce | trait `RespondsToDomainRefusals` (`domain`, `planningDomain`) |
| Copies privées de `WorkforceAccess::scopeIdsOrNull` | 3 | règle unique existante |
| Nouveau lien de signature et invitation (signataire déjà invité) | 2, identiques (relance et réinitialisation de clé) | `SignerInvitationIssuer` |
| `assertSubjectScope` / `subjectInScope` | 2 implémentations de la même règle | la garde réutilise le test (mêmes messages 403) |
| Masquage de la rémunération et recherche du modèle de contrat | 2 | `ContractPreviewService` |
| Résolution du document courant d'un membre | 3 | une méthode privée |
| Couleurs des statuts d'expédition, couleurs de gravité d'audit (front) | 3 + 3 | `utils/shipments.js`, `utils/auditSeverity.js` (+ test JS) |

**Quasi-doublons conservés volontairement** (fusionner changerait un comportement) :
- `UploadOwnDocumentUseCase` / `UploadMemberDocumentUseCase` : quota par différence, fichier unique et transaction pour le dépôt par le salarié lui-même.
- `SendSignatureRequestUseCase::inviteSigner` / `RecordSignerActionUseCase::inviteNext` : machine d'états avec événement ; seul le premier réinitialise `token_revoked_at`.
- `OpenMySignatureUseCase` : lien en libre-service, sans e-mail.

## 4. Éléments conservés et raisons

- **`POST /api/register/funnel-track`** (D) : alimente le tableau de bord de l'entonnoir d'inscription Platform (étapes, abandons), mais **aucun écran ne l'appelle** ; les indicateurs « commencées / abandonnées » restent donc à 0. Endpoint public : ses métadonnées sont désormais **bornées** (20 valeurs scalaires de 255 caractères au plus). Décision à prendre : réinstrumenter l'inscription, ou retirer l'endpoint et ces indicateurs.
- **`onboarding:cleanup-fixtures`** (C) : procédure ADR-796.1 sûre (identifiants explicites, simulation par défaut) ; conservée.
- **Commandes `e2e:*`** (C) : déjà limitées aux clones marqués (`RecipeCommand`, ADR-827).
- **Clés de traduction** (D) : nombreuses clés construites dynamiquement (`` t(`…${state}`) ``) ; aucune suppression sans analyse dédiée.
- **Pages-alias, routes publiques de signature, onboarding public, webhooks, `/_recipe/identity`, `public/webhook.php`** (C) : liens déjà envoyés, procédures documentées.
- **`@core` / `@layouts`** : non modifiés.

## 5. Résolution des trois échecs `ControllerSizeInvariantTest`

Méthode : déplacer **tel quel** le traitement vers le service, le cas d'usage ou le modèle de lecture existant du module, consolider les copies, ou découper par responsabilité. Les adresses, noms de route, middlewares, permissions, validations et réponses sont conservés. **Aucune liste d'exceptions n'a grandi ; une s'est réduite** (`TenantScopeBypassGuardTest` : `SignatureController` retiré, le chargement déplacé est désormais refiltré par `company_id`).

| Contrôleur | Avant → après | Responsabilité extraite |
|---|---|---|
| `ShiftController` | 782 → 242 | découpé : `ShiftQueryController` (lectures), `ShiftOperationController` (annuler/rétablir, copies, application groupée, duplication), `ShiftReplacementController` (ADR-717) ; `CopyShiftsUseCase::weekOperations` |
| `SignatureController` | 686 → 247 | `SignatureVerificationReport`, `SignatureOperationsReadModel`, `SignatureSettingsService::options/withFormats/rules`, `CreateSignatureRequestUseCase::rules/executeValidated`, `SignerEnrollmentService::adminResetKeyAndReinvite`, `SignerInvitationIssuer` |
| `MemberDocumentController` | 353 → 245 | `UploadMemberDocumentUseCase` + résolution unique |
| `EmployeeController` | 343 → 248 | `EmployeeAccessController` (accès à l'espace collaborateur), `CreateEmployeeData::fromValidated` |
| `EmailInboxController` | 327 → 234 | `EmailInboxReadModel` |
| `WeekTemplateController` | 316 → 209 | `WeekTemplateEditor` (transactions, plus de `DB::` ni `->save()`) |
| `LeaveController` | 312 → 249 | `LeaveRequestReadModel::impactedShifts`, gardes et recherches uniques |
| `TimeEntryController` | 309 → 249 | `RecordManualTimeEntryUseCase` |
| `TimesheetController` | 295 → 245 | `WorkTimeComplianceChecker::summarize` |
| `ContractController` | 294 → 243 | `ContractPreviewService` |
| `EmployeeSelfController` | 287 → 234 | `BreakResumeGuard` |
| `DocumentRequestController` | 278 → 242 | `RemindDocumentRequestUseCase` |
| `CompanyBillingController` | 261 → 147 | `InvoicePdfRenderer` |

`DB::` et `->save()` hors liste :
- `SignatureWebhookController` : transaction déplacée vers `ApplyProviderWebhookOutcomeUseCase::processEvent` ;
- `SignerController` : `SignatureSigner::issueChallenge()` ;
- `ActivityPreset`, `CoverageCategory` et `WorkforceConfig` : `update()`, la même opération Eloquent ;
- `WorkingTimeSetupService::save` renommé `saveValues` : c'est un service, pas un modèle, et le test le confondait avec une sauvegarde Eloquent.

## 6. Sécurité liée au nettoyage

- Endpoint de diagnostic supprimé (SEC-11).
- Endpoint public d'entonnoir borné.
- `scripts/dev/seed_email_recette.php` supprimait des fils par préfixe de sujet sur **n'importe quelle base** : il refuse désormais tout ce qui n'est pas un clone marqué (règle ADR-827/831).
- Chargement de document dans la vérification de signature refiltré par société.
- Aucune vulnérabilité critique découverte.

## 7. Tests

- **PHPUnit** : nouveau `Adr848TechnicalCleanupTest`. Tests adaptés, sans changement de comportement :
  - `BillingPdfPerfTest` : la méthode a été déplacée ;
  - `Adr799WorkingTimeFrameworkTest` : `saveValues` ;
  - `TenantScopeBypassGuardTest` : liste d'exceptions réduite.
- **Tests JS** : `shared-status-colors.test.mjs`.
- **Suite complète** : 4 882 tests verts, 13 ignorés, **0 échec**.
- **Tests JS** : 39 sur 39.
- **Build** : propre (`pnpm build`) ; les laboratoires sont absents du build de production.

## 8. Recette navigateur

`tests/e2e/audit842-m9-recipe.spec.js` (fixture `scripts/audit842-m9-fixture.php` : contrat fictif pour M7-A, société 106) :
- **Company** :
  - écrans et API de planification (semaine, tableau, statistiques) ;
  - chaîne annulable réelle : création, annulation, rétablissement, annulation ;
  - aperçu de copie de semaine ;
  - module Signature inactif expliqué ;
  - Documents ;
  - fiche collaborateur et accès ;
  - **PDF de facture réellement téléchargé (`%PDF-`)**.
- **Platform** :
  - messagerie (liste, non-lus) ;
  - **même PDF de facture côté Platform** ;
  - fiche entreprise (supervision fin de contrat) ;
  - RBAC : un opérateur en lecture seule est refusé en écriture et sur le PDF.

Toutes les recettes Leezr 4.2 (M1 à M9) ont été rejouées comme non-régression.

- **Rejeu enchaîné** : 30 tests passent, 8 échouent et 3 sont ignorés.
- **Qualification des 8 échecs** (aucun ne vient de M9) :
  - effets d'ordre : la recette M2 change le mot de passe de `member842`, et M1 dépend de l'état de sa fixture ;
  - M3 attendait encore l'onglet Coupons hors de son groupe (regroupement M5, ADR-842) ;
  - M2 comptait les e-mails sur une page Mailpit plafonnée à 50.
- **Correctifs** : M3 et M2 alignés sur le comportement réel.
- **Après correction** : chaque recette, précédée de sa fixture, passe (M1 4/4, M2 3/3, M3 7/7, M3.1 4/4, M6 4/4, M8.1 2/2, M8.2 2/2, M9 2/2 ; les autres passaient déjà).

## 9. Risques résiduels

- `register/funnel-track` : indicateurs d'abandon d'inscription à 0 tant que l'inscription n'est pas réinstrumentée (décision D).
- Clés de traduction mortes non traitées (analyse dynamique nécessaire).
- Quasi-doublons de signature conservés (voir §3) : une consolidation demanderait de revoir la machine d'états des signataires.
- Les routes Planning, Signature et Employés pointent vers de nouveaux contrôleurs ; les adresses et les noms sont inchangés, mais tout outil externe qui référencerait les noms de classes devrait être mis à jour (aucun connu).
