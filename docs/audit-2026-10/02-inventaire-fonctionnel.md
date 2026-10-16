# Livrable 2 — Inventaire fonctionnel réel (Master Audit 360°, octobre 2026)

> Méthode : vérification route ↔ composant frontend ↔ endpoint backend ↔ données réelles. Lecture seule. « Une page qui s'affiche n'est pas une fonctionnalité qui marche. »

## Synthèse

Le socle technique est **réel et câblé** : ~250 endpoints échantillonnés, seulement **3 endpoints fantômes** détectés. La quasi-totalité des pages frappent de vraies routes backend qui persistent en base. Les défaillances sont **concentrées**, pas diffuses :
1. Finition produit **Workforce** : les 6 P0 connus sont **tous toujours présents (0/6 corrigés)**.
2. Espace **employé (self-service)** réduit à 1 page qui renvoie vers des écrans admin.
3. **Socle horizontal ADR-563 : 0/6 modules** existants.
4. **1 seule verticale** vivante (logistique) ; 3 add-ons logistiques = coquilles vides toujours vendables.

## PLATFORM (~25 domaines) — globalement OUI

Tous les domaines frappent de vraies routes (`routes/platform.php`). Pages routées et câblées : dashboard, companies (suspend/plan/wallet), billing SaaS (factures/dunning/ledger/metrics), operations (alerts/automations/realtime), access/rôles, catalog (plans/modules/jobdomains), international (markets/translations), email, support, users, documents, documentation, settings.
- **PARTIEL** : `ai/` (10 composants `_*.vue`, pas de page routée propre — hébergé dans operations) ; `international`/`email` (sous-composants hébergés, endpoints OK) ; `jobdomains` (infra riche mais 3 endpoints fantômes, n'alimente qu'1 domaine).

## COMPANY (~18 domaines) — globalement OUI

Pages câblées : dashboard, billing abonnement + paiement, shipments (créer/assigner/statut), my-deliveries, workflows, documents (+export conformité CSV), members, modules (enable/quote), support, audit, notifications, profile, roles, plan.
- **PARTIEL** : workforce (~25 pages, endpoints réels mais 6 P0 non corrigés — voir ci-dessous).

## EMPLOYEE (self-service) — PARTIEL / cassé

1 seule page réelle : `workforce/me.vue` (pointage + contrat + solde congés + bulletins + documents). Backend OK. **Mais** : i18n cassé (clés brutes) + les 3 CTA (« demander congé », « voir paie », « voir documents ») **redirigent vers les écrans admin**, pas vers du self-service.

## Workforce — les 6 P0 produit : 0/6 corrigés

| # | P0 | Verdict | Preuve |
|---|---|---|---|
| a | Salaire en **centimes** exposé | TOUJOURS PRÉSENT | `_EmployeeContract.vue:348-374` ; `fr.json:5924-5927` (« 250000 = 2 500,00 € ») |
| b | **9 clés i18n `workforceMe.*`** manquantes (FR+EN+AR) | TOUJOURS PRÉSENT | `me.vue:596-661` ; absentes des 3 locales → clés brutes à l'écran |
| c | Bouton « Modifier » employé = `currentTab=0` | TOUJOURS PRÉSENT | `employees/[id].vue:126` (UX trompeuse, double clic) |
| d | Congé employé → page **admin** | TOUJOURS PRÉSENT | `me.vue:572` → `company-workforce-leave` (liste de tous les employés) |
| e | Bulletins générés par **ID de run tapé** | TOUJOURS PRÉSENT | `_PayslipActionCards.vue:13-97` (`draftRunId` saisi main) |
| f | Validation `.validate()` bloquante | ABSENTE (non corrigé) | 0 occurrence `.validate()` dans `pages/company/workforce/` |

Backend Workforce : endpoints réels et persistants (`company.php:408-563`). Ce ne sont **pas** des coquilles — ce sont des défauts de finition produit rendant l'expérience inutilisable en l'état.

## Modules horizontaux ADR-563 — 0/6

| Module | Frontend | Backend | Preuve |
|---|---|---|---|
| contacts | NON | NON | aucune page/route/migration (hits = `email_contacts`, sans rapport) |
| invoicing (prio #1) | NON | NON | tous les « invoice » = facturation SaaS, pas facturation client |
| catalog | NON | NON | seul catalog SaaS (plans/modules) existe |
| scheduling | NON | NON | faux positifs (ScheduledDebit, WorkforcePlanning) |
| inventory | NON | NON | 0 résultat |
| projects | NON | NON | 0 résultat |

## Boutons morts / données fictives confirmés

1. `company/workforce/index.vue:225` — `complianceWarnings = ref([])` **jamais peuplé** → bandeau conformité jamais affiché (code mort présenté comme feature).
2. `company/workforce/index.vue:365` — flag `comingSoon` jamais défini dans `modules` → badge mort.
3. `jobdomains.store.js:71,78,82` — 3 endpoints fantômes (`POST /fields`, `DELETE /fields/{id}`, `POST /documents/types`) → 404/405 (legacy, vrais écrans via `fields.store.js`).
4. `_PlanBadgeWidget.vue:19-22` — noms de plans en dur en fallback (mineur).

## Verticales vivantes : 1 (logistique)

Logistique réelle = `shipments` + `my-deliveries` (front+back+DB complets). `fleet`/`tracking`/`analytics` = manifestes vides (`navItems:[]`, `routeNames:[]`, aucune route/contrôleur/migration) **mais toujours vendables** (`is_sellable=true` + `addon_pricing` en DB).

## Parcours de bout en bout

**Utilisables** : gestion entreprises (platform), billing SaaS + paiement, expéditions logistiques, mes livraisons, workflows, documents + export, membres, support, audit, notifications, CRUD workforce back-office.
**Cassés/trompeurs** : self-service employé (i18n + renvois admin), saisie contrat/salaire (centimes, sans validation), génération bulletins (ID tapé), bandeau conformité (mort), édition champs jobdomain (endpoints fantômes), modules horizontaux (inexistants).
