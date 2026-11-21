# Leezr 4.2 — M3 : permissions, navigation, fonctionnalités indisponibles (ADR-839)

Base isolée : clone marqué `leezr_audit842`. Fixtures : `scripts/audit842-m2-fixture.php`, `scripts/audit842-m3-fixture.php` (sociétés fictives 105 et 106 uniquement ; réglage catalogue « Paie ≥ Business » sur le clone seulement). Recette : `tests/e2e/audit842-m3-recipe.spec.js` (7/7).

## Anomalies corrigées

| Réf. audit | Avant | Après | Pages / API |
|---|---|---|---|
| UXC-02 | Adresse d'un module non actif → retour **silencieux** à l'accueil (toast anglais) | Page explicite « Le module « X » n'est pas activé » + dépendances (✓/○) + « Activer dans Modules » (seulement avec `modules.manage`), sinon « demandez à un administrateur » | `guards.js`, `/company/module-unavailable` ; `GET /api/modules` (catalogue existant) |
| UXC-05 / FUN-03 | Aucune explication pour un module hors offre | « Le module « X » n'est pas inclus dans votre offre — disponible à partir de l'offre Business » + « Voir les offres » (seulement avec `billing.manage`) ; rien n'est activé, aucun contournement du moteur Billing | idem + onglet Facturation › Offre existant |
| UXP-04 | Page Platform interdite → renvoi **silencieux** ailleurs (toast anglais) | `/platform/403` : « Accès réservé — votre profil Platform ne donne pas accès à cette page » + Retour + « Aller à mon espace » ; espace désactivé expliqué | `guards.js`, `pages/platform/403.vue` |
| UXP-03 / SEC-08 | Profil Finance : 22 refus 403 (onglets Activité, Funnel, Coupons, Revue tarifaire, listes plans/modules) | Onglets affichés seulement avec la permission de leur API ; onglet atteint par son adresse → « Onglet réservé » sans appel ; formulaire Coupons ne charge plans/modules qu'avec les droits correspondants. **0 refus** sur 13 onglets Facturation + tableau de bord | `dashboard/[tab].vue`, `billing/[tab].vue`, `_BillingCouponsTab.vue` |
| (crawl p-creator) | Opérateur « création seule » ouvrant une autre fiche : toast + renvoi vers une liste interdite | « Fiche non accessible », aucune donnée de la société | `companies/[id]/[[tab]].vue` |
| UXC-03 | Puce « Configuration historique » | « Configuré » | i18n |
| (403 Company) | Redirection automatique au bout de 5 s | Explication stable, Retour + « Aller à mon accueil » | `pages/company/403.vue` |

## Rôle Chauffeur — SEC-04 / FUN-04 (aucune application)

Profil de moindre privilège (preset `logistique_rbac` v4, ADR-823) : `theme.view`, `theme.manage`, `shipments.view_own`, `shipments.manage_status`. Testé en navigateur (société fictive 105) : livraisons accessibles, annuaire refusé (page et API), autre société refusée.

Simulation `rbac:driver-role-remediate` (mode simulation, **aucune écriture**) sur le clone — `driver-role-simulation.json`, `driver-role-audit.json` :

| Société | Rôle | Membres | Retrait proposé |
|---|---|---|---|
| 1 Leezr Logistics | driver (7) | **12** | members.view, settings.view |
| 2 Trial Logistics | driver (2) | 0 | members.view, settings.view |
| 3 Pending Approval Co | driver (12) | 0 | members.view, settings.view |
| 31–34 [M6E LOAD] | driver | 0 | members.view, settings.view |
| 101 E2E Recette 8091 | driver (171) | 1 | members.view, settings.view, documents.view |
| 105 Recette 834 | driver (191) | — | déjà conforme |

Conséquences métier vérifiées : le parcours chauffeur (Mes livraisons, Mon espace) n'utilise ni l'annuaire ni les réglages ; le chauffeur perd la liste des collègues et leurs e-mails (but recherché). Société 101 : `documents.view` en plus — à confirmer (accès au coffre documentaire de l'entreprise). **Application uniquement sur autorisation explicite** (`--apply --confirm-database=<base>`, restauration par lot `--restore`), base par base.

## Risques résiduels

- Un rôle dont les droits sont **en attente de confirmation** (ADR-808, sociétés créées depuis Platform) voit « Accès refusé — votre rôle… » alors que la vraie cause est « accès à confirmer par votre administrateur » (constaté avec le chauffeur de la société 105).
- Widgets du tableau de bord : couverts seulement ceux relevés par l'audit, pas de déclaration générique widget ↔ permission.
- UXC-07 (Réglages Workforce en lecture seule pour le manager) non traité.
- 429 sur `/api/public/world|theme` lors de rechargements massifs (limitation de débit existante, sans lien avec les permissions).
