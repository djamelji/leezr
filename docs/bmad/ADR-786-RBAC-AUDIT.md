# ADR-786 — Audit RBAC Company · exposition des données · préparation préproduction

> Base `9b7d1420` · DEV uniquement · aucun push, aucune PROD, aucun VPS, aucune migration · rôles existants **non modifiés**.
> Preuves : `docs/bmad/adr786/` (captures `before-*` / `after-*`, rapports JSON par profil) · sonde HTTP (lecture seule, chaque requête dans une transaction annulée, file/mail/notifications/stockage simulés) · tests `tests/Feature/Adr786RbacAuditTest.php`, `tests/e2e/adr786-rbac-recette.spec.js`.

Méthode : pour chaque constat, quatre couches vérifiées séparément — permissions attribuées (base DEV), visibilité UI (navigateur réel), autorisation HTTP (appel direct), données réellement renvoyées (contenu des réponses).

## 1. Diagnostic du compte « Bobo »

Aucun utilisateur nommé « Bobo » n'existe en DEV. Le livreur de démonstration est identifié par ses rattachements, pas par son nom :

| Élément | Valeur |
|---|---|
| Utilisateur | id 3, `bob@leezr.test` (Bob Dupont) |
| Company | 1 — « Leezr Logistics E2E » (seule appartenance) |
| Membership | id 4, `role = user`, rôle Company **Driver** (id 7, système, `is_administrative = false`, archétype `field_worker`) |
| Fiche salarié | Employee id 3, company 1, `user_id = 3`, actif, sans manager |
| Permissions effectives | `documents.view`, `members.view`, `members.invite`, `settings.view`, `shipments.manage_status`, `shipments.view_own`, `theme.manage`, `theme.view` |
| Aucune permission | `workforce.*`, `payslips.*`, `billing.*`, `roles.*`, `modules.manage`, `documents.manage`, `members.manage`, `members.sensitive_read` |

Origine : préréglage Driver du JobDomain `logistique` (`JobdomainRegistry`, bundles `members.team_view`, `settings.company_info`, `documents.access`, `shipments.delivery`, `theme.full`). `permissions:reconcile --company=1` (simulation) montre un seul écart : **`members.invite` en trop** — ADR-777 a retiré l'invitation du préréglage pour les nouvelles entreprises sans modifier les rôles existants.

## 2. « Voir la paie »

| Question | Réponse |
|---|---|
| Emplacement | Mon activité (`/company/workforce/me`), carte « Mes bulletins de paie », bouton bas de carte |
| Composant | `resources/js/pages/company/workforce/me.vue` — bouton affiché **sans condition** |
| Destination | `company-workforce-payroll` = **paie collective de l'entreprise** (cas 3), page gardée `workforce_payroll` + `workforce.payroll_prepare` |
| Serveur | `GET /api/workforce/payroll` → `use-permission,workforce.payroll_prepare` → **403** pour Bob ; écriture `POST` → 403 |
| Observé | clic → redirection `/company/403` ; aucune donnée de paie collective renvoyée |
| Verdict | **défaut de visibilité, pas de fuite** : action proposée sans droit, refusée par le serveur |

Les accès personnels sont distincts et conservés :
- ses bulletins générés : listés dans la même carte (`/api/workforce/me/documents`, propriété) ;
- ses bulletins publiés : Mon espace › Mes bulletins (`/api/profile/payslips`, propriété, téléchargement des siens uniquement — celui d'un collègue → 404) ;
- sa rémunération : aucune vue personnelle n'existe ; `workforce.compensation_*` refusé (403) ;
- rémunération / bulletins d'autrui : `people/{collègue}/payslips` → 403 (politique documentaire ADR-757/758, catégorie PAIE).

Correction : le bouton n'est affiché qu'avec le module paie actif **et** `workforce.payroll_prepare` (même règle que la page).

## 3. Permissions effectives et matrice RBAC réelle

Profils DEV company 1 (rôles réels, pas leur nom) : Owner (`owner@`), Dispatcher-admin (`admin@`, Alice, salariée), Driver (`bob@`), Salarié (`salarie@` : `leave_request`, `timesheet_submit`, `timesheet_view`), Manager (`manager@` : planning + congés + validation temps, **sans fiche salarié**), RH sans paie (`rh-nopay@` : RH complet + rémunération, sans `payroll_*`), Consultation (`consultation@` : `workforce.view`), Paie (`pay@` : `payroll_*` + `compensation_read`), Ops (`ops@`). Variantes : manager d'équipe réel (`collab99150@test.leezr`, rapports directs), owner Beta Services (`ownerb@`, company 5, modules RH désactivés).

Matrice mesurée par appel direct (124 routes GET sans paramètre × 9 profils, puis 35 URL directes vers les objets d'un collègue × 7 profils) — extrait des familles sensibles :

| Fonction (portée) | Owner | Admin | Driver | Salarié | Manager | RH sans paie | Consult. | Paie |
|---|---|---|---|---|---|---|---|---|
| Paie collective — lire / créer | ✅ | 403 | 403 | 403 | 403 | 403 | 403 | ✅ |
| Lignes / calculs d'un run | ✅ | 403 | 403 | 403 | 403 | 403 | 403 | ✅ |
| DSN | ✅ | 403 | 403 | 403 | 403 | 403 | 403 | ✅ |
| Bulletins d'un collègue (liste) | ✅ | 403 | 403 | 403 | 403 | ✅ (compensation_read) | 403 | ✅ |
| Mes bulletins (soi) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Fiche RH d'un collègue / contrats / dossier | ✅ | 403 | 403 | 403 | 403 | 403* | 403 | 403 |
| Soldes / congé d'un collègue | ✅ | 403 | 403 | 403 | ✅ (leave_view_all) | ✅ | 403 | 403 |
| Pointages en cours (avant → après) | tous | 403 | 403 | **autrui → soi** | tous | tous | 403 | 403 |
| Planning (équipe) | ✅ | 403 | 403 | 403 | ✅ | ✅ | 403 | 403 |
| Membres — liste / fiche | ✅ | ✅ | ✅ (members.view) | 403 | 403 | 403 | 403 | 403 |
| Membres — modifier / retirer | ✅ | ✅ | 403 | 403 | 403 | 403 | 403 | 403 |
| Inviter | ✅ | ✅ | ✅ rôle non admin ; **422** rôle admin | 403 | 403 | 403 | 403 | 403 |
| Rôles / permissions | ✅ | 403 | 403 | 403 | 403 | 403 | 403 | 403 |
| Documents — conformité, activité (Company) | ✅ | ✅ | ✅ (documents.view) | 403 | 403 | 403 | 403 | 403 |
| Pièces confidentielles d'autrui (ID, RIB) | ✅ | ✅ | 403 / « Accès réservé » | 403 | 403 | 403 | 403 | 403 |
| Facturation, abonnement, factures | ✅ | 403 | 403 | 403 | 403 | 403 | 403 | 403 |
| Modules — catalogue (sans montants) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Modules — activer / devis contractuel | ✅ | 403 | 403 | 403 | 403 | 403 | 403 | 403 |
| Réglages — profil entreprise (écrire) | ✅ | 403 | 403 | 403 | 403 | 403 | 403 | 403 |
| Organisation — modifier | ✅ | 403 | 403 | 403 | 403 | ✅ | 403 | 403 |

\* Alice n'a pas de rattachement manager : la facette RH reste bornée au périmètre (ADR-770). Le tableau exhaustif des 124 routes × 9 profils est dans la sortie de la sonde (rapport de session) ; la matrice multi-facettes de référence reste `M7Lot4RbacMatrixTest` (10 profils, ADR-770), toujours verte.

Séparations vérifiées : `members.view` ≠ `members.invite` (l'invitation d'un rôle administratif est refusée, ADR-752) ; `workforce.view` ≠ `workforce.manage` (organisation, fiches) ; `compensation_read` ≠ `compensation_manage` ; documents personnels (propriété, aucune permission) ≠ coffre Company ; bulletins personnels ≠ paie collective ; Billing Company (`billing.manage`) ≠ encaissements Platform (routes Platform hors contexte Company) ; consultation du catalogue Modules ≠ gestion (`modules.manage`, montants masqués ADR-784).

## 4. Défauts démontrés

| # | Couche | Défaut | Profils | Gravité |
|---|---|---|---|---|
| D1 | API — **fuite** | `GET /workforce/time-entries/active` et `/anomalies` sans périmètre : un salarié avec `timesheet_view` recevait les pointages en cours (heures d'arrivée, pauses, noms) de toute l'entreprise | Salarié | **haute** |
| D2 | API | `GET /feature-flags` → 500 pour tout utilisateur (`user->company_id` inexistant) et ignorait la société active | tous | moyenne |
| D3 | UI | `v-can` inopérant sur un élément portant une classe `d-flex` (`display: flex !important` l'emporte) → boutons Modifier / Supprimer visibles sans `workforce.manage` (4 usages : Organisation, Planning) — refusés en 403 par le serveur | Consultation et tout profil sans le droit | moyenne |
| D4 | UI | « Voir la paie » → paie collective, sans condition | Driver, Salarié, tout salarié | moyenne |
| D5 | UI | « Voir tous mes documents » → registre RH administratif (`workforce_documents.view`) au lieu des documents personnels | tout salarié | faible |
| D6 | API + UI | Fiche d'un collègue : la facette accès (ouverte par `members.view`, donc au Driver) listait les **clés de permissions** du collègue, en code brut | Driver | moyenne (minimisation) |
| D7 | UI | Libellé brut `documents.state.restricted` sur les pièces non accessibles | Driver | faible |
| D8 | UI | Menu « Réglages » visible mais hub réservé aux rôles administratifs → 403 ; une rubrique unique s'affichait dans une colonne de 232 px | Consultation | faible |
| D9 | UI | Paramètres RH › Contrats : bouton profil conditionné à `settings.company_management` (clé de bundle, jamais une permission) ; lien « Compléter le profil » sans contrôle de `settings.view` | RH, Manager | faible |
| D10 | UI | Registre RH : bouton « Aller à la paie » sans `workforce.payroll_prepare` | RH sans paie | faible |
| D11 | UI | Mon activité sans fiche salarié : deux 404 parasites (`me/documents`, `me/shifts`) | Manager, RH sans paie, Paie | faible |

Aucune fuite multi-tenant : un non-membre reçoit 403 (« You are not a member of this company »), les objets d'une autre société 404 ; un module désactivé 403.

## 5. Corrections appliquées (code existant réutilisé, aucune permission retirée)

| # | Correction |
|---|---|
| D1 | `WorkforceAccess::scopeRows()` (même règle que le cockpit : « voir tout » ou soi + rapports directs) appliqué à `active` et `anomalies` ; taille de `TimeEntryController` inchangée (309) |
| D2 | `CompanyFeatureFlagController` lit la société active (`SetCompanyContext`) |
| D3 | directive `v-can` : `display: none !important` (global, 4 usages corrigés) |
| D4 | bouton affiché seulement avec module paie + `workforce.payroll_prepare` |
| D5 | lien vers Mon espace › Mes documents |
| D6 | `effective_permissions` renvoyé seulement avec `roles.view` (serveur) ; bloc masqué sinon (UI) |
| D7 | libellé « Accès réservé » / « Restricted access » |
| D8 | hub Réglages sans exigence de rôle administratif (il ne présente que des entrées déjà filtrées par le serveur ; chaque cible garde sa garde) ; mise en page une colonne quand une seule rubrique |
| D9 | `settings.view` pour voir, `settings.manage` pour modifier |
| D10 | bouton conditionné à `workforce.payroll_prepare` |
| D11 | appels personnels seulement si une fiche salarié existe |

## 6. Tests

| Niveau | Résultat |
|---|---|
| `Adr786RbacAuditTest` (11) : Bobo ses bulletins (liste + téléchargement) / bulletin d'un collègue 404 ; paie collective, DSN, bulletins d'autrui refusés ; fiche RH d'un collègue refusée (5 routes) ; membres, rôles, réglages, factures refusés, rôle admin à l'invitation 422 ; fiche collègue sans permissions sans `roles.view`, avec `roles.view` ; IBAN jamais renvoyé sans `sensitive_read` ; pointages en cours et anomalies : soi seulement, « voir tout » conservé, manager direct = ses rapports ; feature flags par société active (A et B) ; contexte d'une autre société 403 ; objets d'une autre société 404 | 11/11 |
| Détection sur le code précédent (corrections serveur retirées temporairement) | 4 tests échouent (D1 ×2, D2, D6) puis repassent |
| Régression ciblée : cockpit pointage (B2, G5) | 30/30 avec ADR-786 |
| E2E `adr786-rbac-recette.spec.js` : livreur (pas de paie collective, documents personnels, 403 direct), fiche collègue sans permissions ni clé brute, consultation (Réglages accessible, organisation en lecture seule), propriétaire (actions conservées) | 4/4 |
| Audit navigateur (`adr786-rbac-audit.spec.js`) : 17 pages × 7 profils, desktop ; Bob mobile 390 px | rapports `report-*.json` |
| Suite PHPUnit complète | 4348 réussis, 12 ignorés, 3 échecs historiques |

## 7. Multi-tenant et variantes

Non-membre → 403 sans donnée ; Beta Services (owner, company 5) sur 13 identifiants de la company 1 → 404 ou 403 (module inactif) ; utilisateur multi-société : feature flags résolus par société active ; Employee sans compte : fiche accessible aux seuls profils RH ; User sans Employee (Manager, Paie) : Mon activité sans appels parasites ; Company sans Workforce : 403 « module inactif » ; permissions personnalisées : rôles de recette (consultation, rh-nopay, pay) testés tels quels.

## 8. Captures

`docs/bmad/adr786/` : `before-bob-*` (Accueil, Mon activité avec « Voir la paie », fiche Alice avec permissions et libellé brut, …), `before-salarie-*`, `before-consultation-*` (organisation avec Modifier/Supprimer), `after-bob-*` (desktop et mobile), `after-consultation-*` (Réglages, organisation en lecture seule), `after-owner-*`.

## 9. Risques restants (non corrigés — décision requise, rôles non modifiés)

1. **Rôle Driver de la company 1 : `members.invite`** (hérité, ADR-777). Effet observé : « Ajouter une personne » et « Gérer l'accès » proposés à un livreur, invitation d'un rôle non administratif possible. Ajustement proposé : retirer `members.invite` du rôle Driver via Réglages › Rôles (ou `permissions:reconcile --company=<id> --apply` après revue, car l'application réaligne **tous** les rôles du préréglage et ajouterait `documents.view_confidential` à Owner, Manager, Dispatcher et Ops).
2. **Préréglage Driver : `members.view` et `documents.view`.** Par conception (bundles `members.team_view`, `documents.access`), un livreur consulte l'annuaire, la fiche des collègues (rôle, état du compte, e-mail, champs non sensibles du profil : date de naissance, adresse, contacts d'urgence — IBAN et NIR restent masqués) et le tableau de conformité documentaire de l'entreprise (métadonnées, aucune pièce confidentielle). Pour un livreur, c'est plus que nécessaire. Proposition : un bundle « annuaire » limité (nom, rôle, contact professionnel) et retirer `documents.access` du préréglage terrain — décision produit, à acter dans un ADR dédié avant PROD.
3. Centre d'aide : le lien « Contacter le support » mène à une page qui exige `support.view` (403 pour un livreur). Mineur, page partagée Platform/Company.
4. Catalogue de permissions DEV en retard sur le code (4 clés : `payslips.import/review/publish`, `workforce.coverage_manage`) — synchronisé par la simulation `permissions:reconcile` (écriture non destructive du catalogue, aucune attribution).

## 10. Résultats de validation

Suite PHPUnit complète (une fois, après les dernières modifications serveur) : **4348 réussis, 12 ignorés, 3 échecs historiques `ControllerSizeInvariantTest`** (inchangés ; `TimeEntryController` maintenu à 309 lignes). Build final : propre.

## 11. Préparation préproduction — audit en lecture seule

Rien n'a été déployé ; aucun accès au VPS ; staging réel **non vérifié**.

| Sujet | Constat (code et documentation locale) | Statut |
|---|---|---|
| Variables d'environnement | `.env.example` (53 clés) ne couvre pas ~30 clés utilisées : `STRIPE_KEY/SECRET/WEBHOOK_SECRET`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`, `SESSION_DOMAIN`, `SIGNATURE_EXTERNAL_*` (8), `DSN_NE_*` (10), `LEEZR_EMAIL_INBOUND_SECRET`, `BILLING_ALERT_*`, `PLATFORM_EMAIL`, `ANTHROPIC_*`, `REALTIME_*`. `.env.production.example` date de février 2026 | à compléter |
| `APP_DEBUG` | doit être `false` : en debug, les 403 exposent `debug{ability,key,company_id,user_id}` et les 404 la classe et le modèle (observé en DEV) | prérequis |
| Migrations | 13 nouvelles depuis le dernier déploiement PROD (`15656c4`), aucune destructive par son nom ; ordre = ordre des fichiers ; précédent : migrations exécutées hors pipeline lors du déploiement de novembre | à rejouer en staging |
| Sanctum / sessions / cookies | SPA cookie ; `stateful` et `SESSION_DOMAIN` à aligner sur `dev.leezr.com` ; cookie `secure` et `same_site` à fixer explicitement | à vérifier en staging |
| Temps réel | **pas de Reverb** : flux SSE/polling maison (`config/realtime.php`, pilote `null` par défaut, Redis si activé) | à décider |
| Files et workers | 4 files : `default`, `billing`, `billing-slow`, `ai` ; un worker doit les écouter toutes ; constat novembre : workers systemd non distincts staging/PROD | bloquant |
| Planificateur | 27 tâches (`routes/console.php`), dont facturation, relances, expirations, congés, boîte e-mail ; constat novembre : planificateur PROD absent ; `scheduler:heartbeat` disponible | bloquant |
| Stockage | documents, bulletins, DSN sur le disque `local` (privé) dans `shared/storage` ; servi par route PHP (ADR-401) ; droits `web3:client1` | à vérifier |
| DMS / OCR | `tesseract` et `python3` détectés par `command -v` ; `pdfsig` pour la signature (`SIGNATURE_PDFSIG_PATH`) ; `open_basedir` ISPConfig | à vérifier en staging |
| SMTP / e-mail entrant | `MAIL_*` ; IMAP `email:fetch-inbox` (ADR-740) ; secret entrant | à configurer |
| Stripe | clés sandbox absentes (constat ADR-737) ; webhooks Stripe et signature externe : secrets requis, fail-closed | bloquant |
| Logs / supervision | canal `stack` ; alertes facturation par webhook/e-mail optionnelles ; runbook `scheduler-monitoring.md` | partiel |
| Sauvegardes / restauration | **aucune procédure documentée** (base, `shared/storage`, documents immuables) | bloquant |
| Secrets et fichiers | `shared/.env` hors release ; clé SSH root pour le déploiement (ADR-076) ; accès SSH partagé (constat novembre) | risque |
| Dépendances externes | Stripe, Net-Entreprises (DSN : non transmise réellement), prestataire de signature (test), fournisseur IA (Anthropic/Ollama), SMTP/IMAP | partiel |
| `company:complete-legal-identity` | à lancer d'abord avec `--dry-run`, puis sans ; non destructif, idempotent (ADR-785) | prêt |
| `permissions:reconcile` | à lancer en simulation par entreprise ; ne pas utiliser `--apply` en masse (voir §9) | prêt (simulation) |

## 12. Prérequis opérationnels (ordre proposé)

1. Compléter `.env` staging (liste §11), `APP_DEBUG=false`, cookies `secure`.
2. Sauvegarde base + `shared/storage`, puis test de restauration.
3. Migrations (13), `FieldDefinitionCatalog::sync` / catalogue de permissions (via `permissions:reconcile` en simulation).
4. `company:complete-legal-identity --dry-run`, puis exécution.
5. Workers sur les 4 files, planificateur avec heartbeat, distincts par environnement.
6. Décision sur les rôles Driver (§9.1, §9.2) avant ouverture à de vrais livreurs.

## 13. Blocages de sécurité avant PROD

- Décision sur le périmètre du rôle Driver (`members.invite` hérité ; `members.view`/`documents.view` du préréglage).
- `APP_DEBUG=false` vérifié sur l'environnement cible.
- Sauvegarde et restauration documentées et testées.
- Workers et planificateur séparés par environnement.

## 14. Verdict

🟢 **GO DEV** · 🟡 **GO préparation préproduction sous réserve** (décision rôle Driver, sauvegardes, variables d’environnement, workers et planificateur) · 🔴 **NO-GO PROD**.
