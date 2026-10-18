# Matrice des parcours × rôle × méthode de vérification

> Légende : **[NAV]** navigateur (E2E) · **[API]** appel API réel · **[TEST]** test automatisé · **[CODE]** lecture de code · **[NON]** non vérifié. Un parcours peut cumuler plusieurs niveaux.

## COMPANY — `owner@leezr.test` (JobDomain logistique)
| Parcours | Niveau | Note |
|---|---|---|
| Connexion + redirection dashboard | [NAV] | smoke staging |
| Dashboard rendu | [NAV] | propre |
| Billing — plan / overview (prochaine facture) | [NAV][TEST] | **ANO-001 corrigé** ; renewal_overdue testé |
| **Profil entreprise — mise à jour** | **[NAV]** | **ANO-004** : bouton save gelé → corrigé ; CRUD navigateur→persistance vérifié (`profile-crud.spec.js`) |
| Billing — factures (liste/détail) | [TEST][CODE] | couvert PHPUnit ; [NON] en NAV |
| Membres — liste + **création** | **[NAV]** | **CRUD navigateur→persistance vérifié** (`company-crud.spec.js`) ; data-test ajouté au select rôle |
| **Membres — éditer rôle + supprimer (+ protection owner)** | **[NAV][persistance][API]** | lifecycle complet (`company-member-lifecycle.spec.js`) ; **ANO-012** (update partiel) corrigé ; owner non supprimable |
| Modules (liste/activation) | [NAV][CODE] | rendu ; activation submit [NON] NAV |
| **Modules — activer/désactiver (add-on + entitlement)** | **[NAV][persistance][API]** | round-trip complet (`company-module-toggle.spec.js`) : `logistics_tracking` available→active (flux devis `PUT /enable`) → désactivé (`PUT /disable`) ; état vérifié via `/api/modules` ; base restaurée ; pas d'anomalie |
| Documents (coffre) — **upload + suppression** | **[NAV][persistance]** | upload multipart réel → `POST /company/documents/{code}`, persistance vérifiée, puis suppression (`DELETE`) (`company-documents.spec.js`) ; pas d'anomalie |
| Workforce (index, employees, time, leave, payroll, dsn, me) | [NAV] | rendu propre (7 pages) |
| **Workforce — embauche→contrat→activation→planning** | **[NAV][persistance]** | **workflow manager complet** (`workforce-employee-lifecycle.spec.js`) ; **ANO-009** (shift 422) + **ANO-010** (contrat draft non activable) corrigés |
| **Workforce — timesheet générer→soumettre→approuver→verrouiller** | **[NAV][persistance][API]** | cycle complet + **interdiction post-lock prouvée API** (`workforce-timesheet-workflow.spec.js`) ; **ANO-013** (détail inactionnable) corrigé ; rejouable (reset ciblé) |
| Création/édition (persistance) | **[NAV]**(profil, membre) + [TEST] | profil + membre vérifiés navigateur→DB ; suppression/autres [NON] NAV |
| Accès restreint si abo expiré | [TEST] | RestrictedBusinessAccessTest |

## PLATFORM — `admin@leezr.com`
| Parcours | Niveau | Note |
|---|---|---|
| Connexion platform | [NAV] | smoke staging |
| Companies — liste + **suspend/réactiver** | **[NAV]** | CRUD navigateur→persistance (`platform-crud.spec.js`) ; confirm dialog ; création = via inscription (pas d'UI platform) |
| JobDomains | [NAV] | rendu |
| Markets | [NAV] | rendu |
| Catalog | [NAV] | rendu |
| Documentation | [NAV] | rendu |
| Billing / coupons | [NAV] | **ANO-002** (2× 401 à confirmer) |
| Automations (cockpit scheduler) | [TEST][API] | ADR-572 ; [NON] en NAV cette passe |
| **Access — Rôles (créer/éditer/supprimer)** | **[NAV][persistance]** | CRUD complet navigateur→DB (`platform-rbac-workflow.spec.js`) |
| **Access — Utilisateurs (créer + attribuer rôle)** | **[NAV][persistance]** | **ANO-011** corrigé (drawer orphelin câblé) ; création + attribution vérifiées |
| **RBAC — enforcement effectif (permissions UI = API)** | **[NAV][API]** | utilisateur sans permission → **403** sur `/platform-users` ; admin → **200** |
| Plans / Modules / Settings | [CODE] | **[NON]** en NAV |
| Alerts / Notifications | [CODE] | **[NON]** en NAV |

## EMPLOYEE / USER — `bob@leezr.test`
| Parcours | Niveau | Note |
|---|---|---|
| RBAC : pas de gestion membres | [NAV] | route gardée (vérifié) |
| **Self-service congé — demande** | **[NAV]** | **ANO-007** corrigé ; drawer self-service → 201 ; workflow complet |
| **Congé — ledger/solde/permissions (approbation/refus)** | **[NAV][API][persistance]** | `workforce-leave-ledger.spec.js` : approbation **réserve** (available −N, reserved +N), refus **inchangé**, salarié **interdit d'approuver (403)** ; **ANO-015** (bucket annuel négatif) documenté |
| **Self-service pointage — clock-in→pause→reprise→clock-out** | **[NAV][persistance]** | **workflow complet** (`workforce-clock-workflow.spec.js`) ; **ANO-008** (clock-in 422, `source` divergent) corrigé |
| Responsive mobile dashboard | [NAV] | pas de débordement |

## TRANSVERSE
| Domaine | Niveau | Note |
|---|---|---|
| Auth / sessions (Sanctum SPA) | [NAV][TEST] | login OK multi-rôles |
| Isolation multi-tenant | [TEST][CODE] | CompanyScope + tests tenancy |
| RBAC / entitlements | [NAV][TEST] | partiel NAV, solide TEST |
| **RBAC — enforcement API workforce (defense-in-depth)** | **[NAV][API][TEST]** | **ANO-014** corrigé : salarié sans permission → **403** sur management ; owner → 200 (`workforce-authz.spec.js` + `SecurityInvariantTest`) |
| Billing | [NAV][TEST] | ANO-001 corrigé |
| Automations / scheduler | [TEST][API] | ADR-572 |
| Documents / Media / Fields / Audit / Markets / JobDomains / Modules | [CODE][TEST] | **[NON]** en NAV exhaustif |

**Synthèse couverture** : 19 pages **[NAV]** prouvées (staging, lecture seule) ; cœur billing **[TEST]** renforcé ; de larges pans Platform (plans, modules, users, roles, settings, alerts) restent **[CODE]/[NON]** en navigateur → lots d'audit suivants.
