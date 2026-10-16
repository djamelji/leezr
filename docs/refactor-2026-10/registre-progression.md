# Registre de progression — Master Refactor & Harmonization 360° (octobre 2026)

> Mission d'harmonisation (pas de nouvelles fonctionnalités métier). 1 lot = 1 objectif = 1 commit local. Pas de push, rien de destructif. Baseline de référence : **2955 tests OK / 12 skipped / 0 échec**, build clean.
> Principe : corriger le confirmé et localisé ; **différer** les refactors à risque ou à décision produit pour protéger la baseline verte.

## Portée exclue (par consigne)
- Pas de développement des 6 modules horizontaux (ADR-563).
- Pas de nouveau JobDomain.
- Pas de reconstruction si une correction localisée suffit.
- Pas de push, pas d'opération destructive distante.

## Plan de lots

| Lot | Objectif | État |
|---|---|---|
| L0a | Commit documentation audit 360° + vision | — |
| L0b | Commit travail UI/i18n Workforce en attente (ADR-558/559/560) | — |
| L1 | Sécurité & séparation d'environnement (seeders env-guard, secret→env, garde migrate:fresh prod) | — |
| L2 | i18n : clés manquantes (9 `workforceMe.*`, 5 EN billing) | — |
| L3 | Bug `watch({debounce})` → `watchDebounced` (6 pages) | — |
| L4 | Backend atomicité (transactions upsert) + code mort (endpoints fantômes, bandeau mort) | — |
| LF | Suite complète + build + rapport final | — |

## Travaux DIFFÉRÉS (DEFER) — justification
- **Centimes → euros (Workforce)** : changement de comportement transverse (contrat/paie + tests) → non localisé, risque de régression sur la baseline. Nécessite un lot dédié testé.
- **Self-service congé employé scoped** : nouvelle surface/parcours = proche du développement de fonctionnalité → hors périmètre harmonisation.
- **Frontière Core (sortir `app/Core/Workforce/`, ADR-562)** : refactor structurel large → lot dédié après sécurisation de la baseline.
- **Workforce async (jobs `ShouldQueue`)** : changement de comportement + UX de progression → décision produit + tests dédiés.
- **SSL dev ré-émission / rotation secret** : actions ops (root/panneau ISPConfig / rotation côté fournisseur) — hors code, signalées à l'utilisateur.

## Journal des lots
(rempli au fur et à mesure)
