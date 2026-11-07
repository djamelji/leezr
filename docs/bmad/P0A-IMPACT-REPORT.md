# P0-A — Rapport d'impact sur les Companies existantes (ADR-732)

> **Mesuré sur DEV le 2026-11-07 (`dev @ 89767a7`). Aucune donnée mutée, supprimée ou remboursée.**
> Objectif (§6) : avant de sécuriser le catalogue, identifier les Companies réellement concernées par
> les 3 modules fictifs (`logistics_fleet`, `logistics_tracking`, `logistics_analytics`).

## Constats (requêtes directes DB)

| Signal | logistics_fleet | logistics_tracking | logistics_analytics | Lecture |
|---|---|---|---|---|
| `company_modules` (cache d'activation) | 3 | 3 | 3 | lignes de CACHE, non autoritatives |
| **`company_module_activation_reasons`** (source de vérité) | **0** | **0** | **0** | **aucune activation réelle** via le gate |
| `company_addon_subscriptions` (actives) | 0 | **1** | 0 | **1 abonnement addon** (donnée DEV) |
| `invoice_lines` mentionnant le module | 0 | 0 | 0 | **aucune facture** |
| `platform_modules.is_sellable` | 1 | 1 | 1 | flags seedés (override par l'autorité) |
| `platform_modules.addon_pricing` | présent | présent | présent | prix seedé (neutralisé au catalogue) |

## Interprétation

- **Aucune activation réelle** : `CompanyModuleActivationReason` (source de vérité, `ModuleGate`) = 0 pour les 3. Les 3 lignes `company_modules` sont du **cache dérivé** sans reason → non autoritatives.
- **1 abonnement addon `logistics_tracking`** existe sur une Company DEV (donnée de recette/démo), **sans facture associée**.
- **Zéro impact facturation** (0 `invoice_lines`).

## Décision de sûreté (appliquée)

- L'autorité `ModuleCommercialPolicy` gate **le catalogue (offre) + l'activation (achat)** → **futurs** achats uniquement.
- **`EntitlementResolver` NON modifié** → **aucune révocation** des droits/abonnements déjà attribués (l'abonnement DEV `logistics_tracking` **reste intact et fonctionnel**).
- **Aucune suppression** de ligne, **aucun remboursement**, **aucune mutation** (prouvé par le test `authority_does_not_mutate_data`).

## Procédure de remédiation séparée (à décider — hors P0-A)

1. **DEV** : l'unique `CompanyAddonSubscription` `logistics_tracking` est une donnée de recette → à purger par le reseed DEV normal (non bloquant, aucun client).
2. **PROD** (le jour venu) : rejouer ce rapport sur la base de production **avant** toute propagation. Si des abonnements/factures réels existent sur les 3 modules :
   - ne **pas** révoquer silencieusement ;
   - proposer une **migration/avoir** explicite (geste commercial) ou une bascule vers un module réel ;
   - traiter via un chantier de remédiation dédié avec validation économique et juridique.
3. **Garde-fou futur** : l'invariant CI (`ModuleCommercialPolicyP0ATest::invariant...`) empêche qu'un nouveau module `available` sans capacité réelle devienne commercialisable.
