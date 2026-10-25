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
| 4 | Fiscalité HT/TTC complète (toutes pages + PDF) | 🟡 partiel (4a formateur+liste factures ; 4b finance-summary overview TTC) — RESTE : plan, addons, paiements, avoirs, portefeuille, PDF, onglets Platform |
| 5 | Automatisations (renew/dunning/webhooks/reconcile/reprise) | ⏳ à auditer/tester |
| 6 | Refonte UX/UI Company (12+ écrans) | 🟡 en cours (6a overview ✅DEV ; 6b Factures void/action ✅ ; 6c Plan HT ✅ ; 6d addons : read model activation/droit/souscription + prix figé ✅) — 6e détail facture/PDF : fiabilité ✅ (invariants+historique+isolation), produit ⏳ (render+PDF nav) — RESTE : 6f paiements/moyens/portefeuille/résiliation, 6g finition UX |
| 7 | Refonte UX/UI Platform (15 onglets + connexes) | ⏳ non commencé |
| 8 | Recette Playwright 6 personas + matrice | ⏳ non commencé (recette backend prête via BillingRecetteSeeder) |

## Recette
- `database/seeders/BillingRecetteSeeder.php` : 3 cas (Overdue Provisional = Leezr-like ; Healthy Paid ; Overdue Failed). Local/DEV only (skip PROD). Seedé + vérifié sur DEV.
- **À compléter LOT 8** : personas (Platform super-admin/finance/restreint, Company owner/admin/employee), market intra-UE pour la fiscalité, moyens de paiement présents/absents.

## Commits (suite)
| SHA | Lot | Portée | Statut |
|---|---|---|---|
| d3eaa13 | 3b | caractérisation étendue + doc d'état | ✅ sur DEV |
| c1a04b5 | 4b | finance-summary overview TTC | ✅ sur DEV |
| 97f01b9 | 6a | hiérarchie overview (subscription→échéance→situation) + E2E | ✅ **sur DEV, vérifié navigateur (2/2)** |

**Tous les commits Billing 3.0 sont sur `origin/dev` (HEAD DEV = `97f01b9`). `main`/PROD intacts.**

## Point de reprise (précis)
Prochain incrément : **LOT 6b** — refonte des écrans Company restants (`_BillingPlan` comparaison/changement, `_BillingInvoices` liste, `invoices/[id]` détail, `_BillingPaymentMethods`, `pay.vue`, portefeuille/timeline, résiliation) avec HT/TTC qualifié + Playwright par écran (owner). Puis **LOT 7** (15 onglets Platform, en réutilisant la recette), **LOT 5** (audit automatisations), **LOT 8** (personas + matrice complète).
Recette backend prête (`BillingRecetteSeeder`, 3 cas, seedé sur DEV). Persona navigateur disponible : `owner@leezr.test` = owner de company #1 (Pro active) sur DEV/local.

## Playwright (preuves accumulées)
| Spéc | Écran | Personas | Statut |
|---|---|---|---|
| `company-billing-overview.spec.js` | Overview Company | owner | ✅ DEV (rendu 3 sections, 0 erreur JS, dark mode) |

## Double suivi (par écran)
| Écran | Fiabilité métier | Qualité produit |
|---|---|---|
| Overview | ✅ (ADR-614/615) | ✅ Playwright DEV |
| Factures liste | ✅ (ADR-619) | ✅ Playwright |
| Plan | ✅ | ✅ Playwright (HT) |
| Addons catalogue | ✅ (ADR-621) | ⏳ UI 6g |
| Détail facture + PDF | ✅ (ADR-622) | ⏳ render+PDF nav |
| Paiements : bouton gaté capacité provider | ✅ (ADR-623) | ⏳ parcours pay/wallet/résiliation nav |
