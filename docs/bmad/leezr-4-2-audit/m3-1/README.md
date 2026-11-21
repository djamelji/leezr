# Leezr 4.2 — M3.1 : rôles Chauffeur historiques, droits en attente, Réglages Workforce en lecture seule (ADR-840)

Base : clone marqué `leezr_audit842` **uniquement**. Aucune écriture sur DEV distant, PROD ou une base historique réelle.

## 1. Rôles Chauffeur — diff exact (avant → après), appliqué sur le clone seulement

Source : `scripts/audit842-driver-role-diff.php` (lecture seule) → `driver-roles-before.json`, `driver-roles-after-apply.json`, `driver-roles-after-restore.json`. Correction : `rbac:driver-role-remediate` (ADR-825, une transaction par rôle, audit `role.remediation_applied` avant/après + lot).

| Société | Rôle | Membres | Personnalisations | Avant | Retiré | Après |
|---|---|---|---|---|---|---|
| 1 Leezr Logistics | 7 | 12 | 0 | members.view, settings.view, shipments.manage_status, shipments.view_own, theme.manage, theme.view | **−** members.view, settings.view | shipments.manage_status, shipments.view_own, theme.manage, theme.view |
| 2 Trial Logistics | 2 | 0 | 0 | members.view, settings.view, shipments.manage_status, shipments.view_own, theme.manage, theme.view | **−** members.view, settings.view | shipments.manage_status, shipments.view_own, theme.manage, theme.view |
| 3 Pending Approval Co | 12 | 0 | 0 | members.view, settings.view, shipments.manage_status, shipments.view_own, theme.manage, theme.view | **−** members.view, settings.view | shipments.manage_status, shipments.view_own, theme.manage, theme.view |
| 31 [M6E LOAD] 5 collaborateurs | 29 | 0 | 0 | members.view, settings.view, shipments.manage_status, shipments.view_own, theme.manage, theme.view | **−** members.view, settings.view | shipments.manage_status, shipments.view_own, theme.manage, theme.view |
| 32 [M6E LOAD] 25 collaborateurs | 34 | 0 | 0 | members.view, settings.view, shipments.manage_status, shipments.view_own, theme.manage, theme.view | **−** members.view, settings.view | shipments.manage_status, shipments.view_own, theme.manage, theme.view |
| 33 [M6E LOAD] 50 collaborateurs | 39 | 0 | 0 | members.view, settings.view, shipments.manage_status, shipments.view_own, theme.manage, theme.view | **−** members.view, settings.view | shipments.manage_status, shipments.view_own, theme.manage, theme.view |
| 34 [M6E LOAD] 100 collaborateurs | 44 | 0 | 0 | members.view, settings.view, shipments.manage_status, shipments.view_own, theme.manage, theme.view | **−** members.view, settings.view | shipments.manage_status, shipments.view_own, theme.manage, theme.view |
| 101 E2E Recette 8091 433828 | 171 | 1 | 0 | documents.view, members.view, settings.view, shipments.manage_status, shipments.view_own, theme.manage, theme.view, workforce.timesheet_submit, workforce.timesheet_view | **−** members.view, settings.view, documents.view | shipments.manage_status, shipments.view_own, theme.manage, theme.view, workforce.timesheet_submit, workforce.timesheet_view |

- Conservé partout : `shipments.view_own`, `shipments.manage_status` (livraisons et changements de statut), `theme.*` ; société 101 : `workforce.timesheet_view` / `timesheet_submit` (pointage du salarié) conservés.
- `documents.view` (société 101) = coffre documentaire **de l'entreprise** : non nécessaire au chauffeur ; ses propres documents restent accessibles par « Mon espace » (`/api/profile/documents`, sans permission) — vérifié en navigateur.
- Personnalisations propres aux sociétés : **aucune** (journal des rôles) ; un rôle personnalisé serait ignoré sauf `--role=<id>` explicite.
- Rôle de la société fictive 105 : déjà conforme.

### Procédure vérifiée sur le clone

1. Simulation (défaut, aucune écriture) → 8 `would_apply`, 1 conforme.
2. `--apply --confirm-database=leezr_audit842` → lot `drv-…-acy9r9` : 8 appliqués ; état = colonne « Après » (identique au diff attendu pour les 8 rôles).
3. Retour arrière : `--restore=<lot>` (simulation) puis `--restore=<lot> --apply --confirm-database=…` → 8 restaurés ; état **identique** à « Avant » pour les 8 rôles (`clone-restore-*.json`, comparaison automatique).
4. Nouvelle application → lot `drv-20261121160612-kqhg3s` ; `rbac:driver-role-audit` après : `driver-role-audit-after.json`.

**Pour DEV / PROD (nouveau GO requis, base par base)** : simulation → `--apply --confirm-database=<base>` → conserver l'identifiant de lot → recette chauffeur → en cas de besoin `--restore=<lot> --apply --confirm-database=<base>`.

## 2. Droits en attente de confirmation (ADR-808)

- Le serveur transmet, à côté des droits effectifs, `pending_permissions` = droits **stockés** sur le rôle **et** en attente (`PendingModuleAccess::suspendedFor`, source existante). Ils ne servent qu'à expliquer : `hasPermission()` ne les lit jamais ; l'API continue de refuser.
- Page demandée avec un droit en attente → « Accès en attente de validation — Votre accès à cette fonctionnalité attend la validation d'un administrateur de votre entreprise. » (distinct de « Accès refusé »). Un droit que le rôle n'a pas reste un refus simple.
- Après confirmation par l'administrateur (revue groupée existante), la page en attente relit les droits (le cache client de 5 min est invalidé par le même chemin que les événements temps réel) et rouvre la page demandée : vérifié en navigateur.

## 3. Réglages Workforce — UXC-07

Avec `workforce.view` sans `workforce.manage` : bandeau « Lecture seule », champs verrouillés, aucun bouton Enregistrer / Confirmer / assistant ; sections Contrats et Signature : commandes masquées sans leur droit propre (au lieu de grisées). Permissions des rôles **inchangées** ; le serveur refuse toujours l'écriture (403 vérifié).

## Recette navigateur (`tests/e2e/audit842-m31-recipe.spec.js`)

- PHASE=before (rôles historiques non corrigés) : 2/2 — chauffeur société 1 lit l'annuaire (200), chauffeur société 101 lit l'annuaire et le coffre (200) : défaut reproduit.
- PHASE=after (corrigés sur le clone) : 4/4 — livraisons conservées ; annuaire, réglages et coffre refusés (page et API) ; documents personnels conservés ; autre société refusée ; droits en attente : état distinct, API refusée, ouverture après confirmation ; manager en lecture seule, écriture refusée par le serveur. Aucune erreur JS ni refus d'API inattendu.

## Décisions restantes

- **GO DEV** puis **GO PROD** pour la correction des 8 rôles (la base DEV/PROD peut différer du clone : relancer d'abord la simulation sur la base cible).
- Société 101 (recette E2E) : confirmer le retrait de `documents.view` si ce rôle sert à d'autres recettes.
