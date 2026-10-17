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
| Membres (liste) | [NAV] | rendu |
| Modules (liste/activation) | [NAV][CODE] | rendu ; activation submit [NON] NAV |
| Documents | [NAV] | rendu |
| Workforce (index, employees, time, leave, payroll, dsn, me) | [NAV] | rendu propre (7 pages) |
| Création/édition/suppression (persistance) | [TEST] | **[NON] en NAV** (évite pollution données partagées) |
| Accès restreint si abo expiré | [TEST] | RestrictedBusinessAccessTest |

## PLATFORM — `admin@leezr.com`
| Parcours | Niveau | Note |
|---|---|---|
| Connexion platform | [NAV] | smoke staging |
| Companies | [NAV] | rendu |
| JobDomains | [NAV] | rendu |
| Markets | [NAV] | rendu |
| Catalog | [NAV] | rendu |
| Documentation | [NAV] | rendu |
| Billing / coupons | [NAV] | **ANO-002** (2× 401 à confirmer) |
| Automations (cockpit scheduler) | [TEST][API] | ADR-572 ; [NON] en NAV cette passe |
| Plans / Modules / Users / Roles / Settings | [CODE] | **[NON]** en NAV |
| Alerts / Notifications | [CODE] | **[NON]** en NAV |

## EMPLOYEE / USER — `bob@leezr.test`
| Parcours | Niveau | Note |
|---|---|---|
| RBAC : pas de gestion membres | [NAV] | route gardée (vérifié) |
| Self-service (pointage/pause/congé/documents) | [TEST][CODE] | rendu [NAV] via /workforce/me ; submit [NON] NAV |
| Responsive mobile dashboard | [NAV] | pas de débordement |

## TRANSVERSE
| Domaine | Niveau | Note |
|---|---|---|
| Auth / sessions (Sanctum SPA) | [NAV][TEST] | login OK multi-rôles |
| Isolation multi-tenant | [TEST][CODE] | CompanyScope + tests tenancy |
| RBAC / entitlements | [NAV][TEST] | partiel NAV, solide TEST |
| Billing | [NAV][TEST] | ANO-001 corrigé |
| Automations / scheduler | [TEST][API] | ADR-572 |
| Documents / Media / Fields / Audit / Markets / JobDomains / Modules | [CODE][TEST] | **[NON]** en NAV exhaustif |

**Synthèse couverture** : 19 pages **[NAV]** prouvées (staging, lecture seule) ; cœur billing **[TEST]** renforcé ; de larges pans Platform (plans, modules, users, roles, settings, alerts) restent **[CODE]/[NON]** en navigateur → lots d'audit suivants.
