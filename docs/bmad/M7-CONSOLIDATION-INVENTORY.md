# M7 — Inventaire de consolidation Company + DMS (après ADR-765, SHA 8683bda5)

> Objectif M7 : **faire disparaître la fragmentation** de l'espace Company sans créer de nouvelles
> fonctionnalités. Règle d'or : **ne rien supprimer avant d'avoir prouvé la parité fonctionnelle**.
> Les anciennes routes sont conservées (deep-links) ; redirection uniquement quand la parité est démontrée ;
> sinon **alias temporaire documenté** (Section 6 de la commande).

## 0. Méthode

Inventaire produit par 5 explorations lecture-seule (nav, pages/routes, surfaces DMS M0→M6, parité People,
RBAC/DocumentAccessPolicy). Factuel, références `fichier:ligne`. Aucune modification pendant l'inventaire.

## 1. Architecture de navigation (état actuel)

Nav Company **100% manifest-driven** : `NavBuilder` (`app/Core/Navigation/NavBuilder.php`) agrège les
`navItems` déclarés par chaque `*Module.php` (`Capabilities::navItems`), applique les gatings
(module `ModuleGate`, `permission`/`anyPermission`/`excludePermission`, `plans`, `jobdomains`, surface/roleLevel),
et sérialise des groupes (`NavGroupRegistry`). Le front (`useCompanyNav.js`) n'ajoute **aucun** item.
Les **onglets** des espaces `:tab` sont en dur dans les `[tab].vue` (pas manifest-driven) ; seuls
`headerWidgets`/`settingsPanels`/`footerLinks` sont des contributions transversales déclaratives.

### Entrées de nav Company actuelles (extrait pertinent M7)

| Item | Route | Module | Permission | Groupe |
|---|---|---|---|---|
| Collaborators (`people`) | `company-people` | `core.members` | any(`members.view`,`workforce.view`,`roles.view`) | company |
| **Roles** (`company-roles`) | `company-roles` | `core.roles` | `roles.view` | company |
| **Documents** (`documents`) | `company-documents-tab` | `core.documents` | `documents.view` | company |
| **Documents** (`workforce-documents`) | `company-workforce-documents` | `workforce_documents` | `workforce_documents.view` | workforce |
| My Space (`workforce-me`) | `company-workforce-me` | `workforce` | — | account |
| (Time/Planning/Leave/Payroll/Organization/HR Settings) | … | workforce* | … | workforce |

### Doublons de navigation identifiés

1. **DEUX entrées « Documents »** : Core (`company-documents-tab`, groupe company, `documents.view`) **et**
   Workforce RH (`company-workforce-documents`, groupe workforce, `workforce_documents.view`). → **Fragmentation #1**.
2. **Roles séparé** de Collaborators : `company-roles` est une entrée distincte, alors que People **réutilise
   déjà** `roles.vue` comme onglet « Rôles & autorisations ». → **Doublon de nav** (parité déjà prouvée).
3. **Members / Employees** : **déjà** sans entrée de nav (consolidés dans `people` depuis ADR-754). Routes
   `company-members`, `company-members-id`, `company-workforce-employees[-id]` restent deep-linkables.

## 2. Surfaces DMS (M0→M6) — où vit chaque capacité

| Capacité | Surface front | Backend | Permission | Localisation |
|---|---|---|---|---|
| Coffre Company | onglet `vault` de `company-documents-tab` | `CompanyDocumentController` | `documents.view`/`.manage` | Core |
| Docs collaborateurs | fiche (Member/Person/Self) | `MemberDocumentController`/`PersonDocumentController`/`SelfDocumentController` | `documents.*` | Core |
| Demandes | onglet `requests` | `DocumentRequestController` | `documents.manage` | Core |
| Campagnes | onglet `campaigns` | `DocumentCampaignController` | `documents.manage` | Core |
| Conformité | onglet `compliance` | `DocumentComplianceController` | `documents.view` | Core |
| Import bulletins | onglet `payslips` | `PayslipImportController` | `payslips.import/review/publish/download` | Core |
| À signer / Signatures | `company-workforce-signature` (embarqué) | `SignatureController` + webhooks | `workforce_signature.*` | Workforce |
| Types & paramètres | onglet `settings` | `CustomDocumentTypeController`/`DocumentSettingController`/`CompanyDocumentActivationController` | `documents.configure` | Core + Platform catalog |
| Docs générés RH (registre) | `company-workforce-documents` | `WorkforceDocumentController` | `workforce_documents.*` | Workforce (génère PDF via Core) |
| Versions/expirations/validation | endpoints `.../versions`, settings, `.../review` | `DocumentVersioningService`/`DocumentLifecycleService` | `documents.view`/`.manage` | Core |

**Chevauchements** : (a) deux nav Documents ; (b) bulletins en double voie — import externe Core (`payslips.*`)
vs génération interne Workforce (`workforce_documents.generate`) — **voies distinctes légitimes**, pas un doublon
d'action (la génération a été explicitement retirée de la page Documents RH → renvoi vers Paie) ; (c) 3 surfaces de
consultation docs collaborateur (Member/Person/Self) sur le **même** modèle Core `MemberDocument`/`DocumentSubject`.

## 3. RBAC & DocumentAccessPolicy (invariants à préserver)

`DocumentAccessPolicy::canAccess(actor, company, subject, typeCode, operation)` — ordre : tenant → self (tous ses
docs) → `documents.manage` (admin) → **décision par catégorie** :
- **CAT_PAYROLL** (`payslip*`) → `payslips.download` OU `workforce.compensation_read` OU `workforce.payroll_validate/prepare`.
- **CAT_IDENTITY_FINANCIAL** (`id_card`,`rib`,`social_security`,`criminal_record`) → `documents.view_confidential` OU `workforce.sensitive_read`.
- **CAT_STANDARD** → `documents.view` OU management direct (`manager_id`, 1 niveau).

**Invariants (Section 9)** — déjà appliqués au niveau API/ReadModels :
- Voir docs standards **≠** voir bulletins (catégories disjointes ; bulletin jamais ouvert par `documents.view`).
- `documents.manage`/`.configure` **ne donnent aucun** droit `payslips.*` (publication paie gardée explicitement).
- Manager limité à son périmètre `manager_id`, **CAT_STANDARD uniquement** (jamais bulletins/identité des subordonnés).

**Point de vigilance UI (critique pour M7)** : la **visibilité d'un onglet** ne doit jamais élargir l'accès. Un
onglet « Documents » visible sur `documents.view` ne doit pas exposer une sous-section Bulletins/Confidentiel sans
le droit paie/confidentiel réel. Les ReadModels filtrent par `canAccess` ; l'UI doit gater l'affichage sur les
**mêmes** permissions et **ne jamais rediriger** un user vers une page qu'il n'a pas le droit de voir (nouveau 403 interdit).

## 4. Parité People — matrice (ce qui bloque le remplacement des anciennes routes)

`/company/people` (liste unifiée, onglets Personnes + Rôles) et `/company/people/:personRef` (fiche) **existent**.
La fiche est **majoritairement en lecture seule** et délègue les mutations lourdes aux anciennes pages.

| Opération | Dans People | Ancienne page | Parité |
|---|---|---|---|
| Lister / rechercher / filtrer / fiche | ✅ | members/employees | **OK** |
| Rôles & permissions (RBAC) | ✅ (réutilise `roles.vue`) | `company-roles` | **OK — dédup immédiate** |
| Changer rôle d'une personne | ✅ (si membership) | members/[id] | OK |
| Planning / Temps / Congés / Docs (DMS) / Historique | ✅ (composants réutilisés) | employees/[id] | OK |
| Grant/suspend/revoke/resend accès | ⚠️ seulement si Employee | employees/[id] | **GAP** (compte-seul) |
| Inviter un membre | ❌ (redirige) | members/index | **GAP** |
| Créer un employé | ❌ (redirige) | employees/index | **GAP** |
| Éditer identité / infos perso | ❌ lecture seule | members/[id], employees/[id] | **GAP** |
| Champs dynamiques / custom fields | ❌ | members | **GAP** |
| Credentials (reset/set password) | ❌ | members/[id] | **GAP** |
| Éditer / générer contrat, avenants | ❌ lecture seule | employees/[id] | **GAP** |
| Signatures | ❌ | employees/[id] | **GAP** |
| Bulletins / paie (endpoint existe) | ❌ UI | documents/payroll | **GAP** |
| Import / Export / Actions groupées | ❌ | employees/index | **GAP** |
| Supprimer un membre / quota | ❌ | members/index | **GAP** |

**Backend** : `Membership`(compte/accès) + `CompanyRole` + `Employee`(RH, `user_id` **nullable** → employé sans
compte possible). Pas de modèle Invitation dédié (invitation = Membership+User sans `password_set_at`). People
résout par `personRef` = `user-{id}` | `employee-{id}`, lien `user_id` **explicite** (jamais par nom/email, ADR-752).

> **Mise à jour LOT 2 (ADR-768)** — la matrice ci-dessus décrit l'état AVANT LOT 2. Après LOT 2 : inviter, créer,
> éditer l'identité et les champs, identifiants, accès d'un compte seul, informations RH, contrat (consulter / créer /
> activer / générer), signatures, bulletins et pont compte ↔ fiche RH sont **dans People**. Restent en GAP (alias
> conservés) : import / export / actions groupées et réglage des champs personnalisés. Matrice complète : ADR-768.

## 5. Plan de consolidation M7 (lots, du plus sûr au plus sensible)

### LOT 1 — Dédoublonnages à parité PROUVÉE (sûrs, réversibles) — *ce checkpoint*
- **Roles → People** : retirer l'entrée de nav `company-roles` (People expose déjà l'onglet Rôles via
  `anyPermission` incluant `roles.view` → **aucun changement RBAC, aucun nouveau 403**). `/company/roles` en
  **alias** → redirige vers `/company/people` onglet Rôles (la page reste le composant réutilisé par People via `embedded`).
- **Documents → une entrée** : fusionner les deux nav « Documents » en **une seule** entrée, avec
  `anyPermission(documents.view, workforce_documents.view, workforce_signature.view)` et **onglets gated par
  permission** (chaque onglet exige son droit réel ; fetchs de base gardés derrière `documents.view`). La page
  Documents RH (registre/modèles/signatures) est **réutilisée** en onglet `hr` via `embedded` (pattern existant).
  `/company/workforce/documents` en **alias** → `/company/documents/hr` (pas de 403 : la page unifiée accepte
  `workforce_documents.view`).

### LOT 2 — Fiche Collaborateur pleinement opérable (réutilisation des formulaires existants)
- Brancher dans `/company/people/:personRef` : édition identité (`MemberProfileForm`), credentials, contrat
  (`_EmployeeContract` + génération), signatures (`_EmployeeSignaturesSection`), bulletins (endpoint existant),
  création/invitation en place (drawers existants). Chaque opération gated par sa permission réelle.
- Condition de redirection de `members/[id]` et `workforce/employees/[id]` : parité prouvée sur ces opérations.

### LOT 3 — Self-service cohérent
- Regrouper « Mes documents / Mes demandes / Mes bulletins / Mes signatures » dans l'espace collaborateur
  (`/company/workforce/me`) sans fragmenter `/home` `/profile` `/dashboard`. **Ne pas** fusionner `/home` et `/dashboard`.

> **Mise à jour LOT 3 (ADR-769)** — livré : « Mon espace » Core (`/company/my-space/:tab` : aperçu, documents, bulletins,
> signatures), accueil et pointage pilotés par `/profile/space`, `/company/workforce/me` conservé (« Mon activité »),
> `/account-settings/documents` redirigé. `/home` et `/dashboard` non fusionnés.

### LOT 4 — Redirections finales + recette RBAC par facette + E2E + captures avant/après
- Activer les redirections members/employees **seulement** après parité LOT 2. Matrice de redirections +
  matrice RBAC + recette E2E (company sans workforce, employee sans user, RH sans members, manager limité, multi-company…).

## 6. Matrice de redirections (état cible)

| Ancienne route | Cible | Condition | Statut |
|---|---|---|---|
| `/company/roles` | `/company/people` (onglet Rôles) | parité 100% (même composant) | **LOT 1 — activée** |
| `/company/workforce/documents` | `/company/documents/hr` | page unifiée accepte `workforce_documents.view` | **LOT 1 — activée** |
| `/company/members` | `/company/people` | parité réglage des champs + quota | **alias conservé** (LOT 4) |
| `/company/members/:id` | `/company/people/:personRef` (résolution serveur) | parité édition identité + credentials | **LOT 2 — activée** (`?legacy=1` conserve l'ancienne) |
| `/company/workforce/employees` | `/company/people` | parité import/export/bulk | **alias conservé** (LOT 4) |
| `/company/workforce/employees/:id` | `/company/people/employee-:id` | parité contrat/signatures/onboarding | **LOT 2 — activée** (`?legacy=1` conserve l'ancienne) |

Contraintes : préserver ids/params/deep-links/notifications/permissions ; pas de boucle ; pas de nouveau 403.
