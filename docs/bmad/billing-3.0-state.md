# Billing 3.0 — État de reprise (Livraison B)

> État vivant du chantier Billing 3.0. Mis à jour à chaque sous-lot. Permet de reprendre sans perte
> de contexte. **Aucun push main / déploiement PROD sans autorisation.** Gardes ADR-575/593 intactes.

## Commits (ordre chronologique)
| SHA | Lot | ADR | Portée | Statut |
|---|---|---|---|---|
| 7e12386 | 2 | ADR-614 | Vérité financière : alertes honnêtes, comptage factures parentes, bannière « à venir » | ✅ sur DEV |
| 77b4d7d | 1 | ADR-615 | Validation 4 dimensions abonnement (tests) | ✅ sur DEV |
| 5d471ff | 4a | ADR-616 | Formateur monétaire unique + colonnes factures TTC | ✅ sur DEV |
| 43a4733 | 3a | ADR-617 | Caractérisation renew≡PricingEngine (monthly, plan+addon) | ✅ sur DEV |
| (ce commit) | 3b | ADR-617b | Caractérisation étendue : yearly, multi-addons, coupon | 🟡 local |

## Matrice de caractérisation LOT 3 (renew vs PricingEngine)
| Scénario | Couvert | Résultat |
|---|---|---|
| Mensuel plan + 1 addon | ✅ | égal (4800 HT / 5760 TTC) |
| Idempotence (3 runs) | ✅ | 1 facture |
| Prix provisional | ✅ | non facturé (différé) |
| Annuel plan + addon | ✅ | égal |
| Multi-addons (3) | ✅ | égal (8600 HT) |
| Coupon attaché | ✅ | égal |
| **Exonération / reverse-charge B2B intra-UE** | ❌ non couvert | à tester (nécessite n° TVA + market intra-UE + VIES) |
| **Changement de Market en cours de contrat** | ❌ non couvert | à tester |
| **Prorata de période de transition (billing_anchor_day)** | ❌ non couvert | à tester |

**Décision ADR-617** : duplication du code de renouvellement conservée (aucune divergence de montant démontrée sur les cas couverts). Unification `renew → PricingEngine` **différée** (changerait les libellés de lignes ; risque sans gain). **Ne pas généraliser** : les 3 cas non couverts ci-dessus doivent être testés avant toute refactorisation du moteur.

## Statut des lots
| Lot | Description | Statut |
|---|---|---|
| 1 | Intégrité abonnements (4 dimensions) | ✅ validé (tests) |
| 2 | Vérité financière (alertes/comptage) | ✅ fait + DEV |
| 3 | Caractérisation checkout/preview/renew | ✅ fait (3 cas restants documentés, non couverts) |
| 4 | Fiscalité HT/TTC complète (toutes pages + PDF) | 🟡 partiel (4a : formateur + liste factures) — RESTE : overview, plan, addons, paiements, avoirs, portefeuille, PDF, onglets Platform |
| 5 | Automatisations (renew/dunning/webhooks/reconcile/reprise) | ⏳ à auditer/tester |
| 6 | Refonte UX/UI Company (12+ écrans) | ⏳ non commencé |
| 7 | Refonte UX/UI Platform (15 onglets + connexes) | ⏳ non commencé |
| 8 | Recette Playwright 6 personas + matrice | ⏳ non commencé (recette backend prête via BillingRecetteSeeder) |

## Recette
- `database/seeders/BillingRecetteSeeder.php` : 3 cas (Overdue Provisional = Leezr-like ; Healthy Paid ; Overdue Failed). Local/DEV only (skip PROD). Seedé + vérifié sur DEV.
- **À compléter LOT 8** : personas (Platform super-admin/finance/restreint, Company owner/admin/employee), market intra-UE pour la fiscalité, moyens de paiement présents/absents.

## Point de reprise
Prochain incrément : **LOT 4 (suite)** — qualification HT/TVA/TTC sur overview Company, plan, addons, paiements, portefeuille, puis onglets Platform, puis PDF ; vérifier contrats cents/euros par composant. Puis LOT 5, 6, 7, 8.
