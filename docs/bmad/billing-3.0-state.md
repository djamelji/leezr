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
| 7 | Refonte UX/UI Platform (15 onglets + connexes) | 🟡 en cours (7.1 cockpit KPI blocked_renewals + facturé≠encaissé ✅ ; 7.2 renouvellements en revue surfacés tariff-review ✅ ; rendu dashboard/tariff-review/subscriptions E2E) — 7.3-7.5 : **15 onglets rendus sans erreur JS** (E2E) ✅ — RESTE produit : actions sensibles + conséquences contractuelles, tests multi-persona/permissions → LOT 8 |
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

## LOT 7A — pilotage financier / cockpit (vérité financière)
- **Cockpit déjà substantiel** : MRR/ARR **contractuels** (KPI), revenu **facturé** (drilldown `revenue`, ADR-584), **créances** (drilldown `receivables` : brut − write-offs = net, **réconcilié au grand livre AR**), churn, conversion trial, tendance MRR (historique réel, aucune donnée fabriquée). Règle « facturé ≠ encaissé » tenue (drilldowns séparés, jamais fusionnés).
- **ADR-631** : `blocked_renewals` promu **métrique de premier plan** (`BillingMetricsCalculationService::calculate()` + carte KPI cockpit, verte à 0 / warning si >0). Séparé de dunning (créances échues) et paiements échoués. Lecture seule, aucune confirmation auto de prix provisional. ADR-575/593 intacts.
- Preuves : PHPUnit `BillingCockpitKpiTest::test_metrics_payload_exposes_blocked_renewals` ✅ (1 provisional + 1 fiable → 1 bloqué) ; build clean ; `BillingCockpitKpiTest` 3/3 vert.
- Niveau de couverture : **donnée + rendu**. Interaction (clic drilldown)/responsive/dark/permission multi-persona → LOT 8.

## LOT 7B — revenus / abonnements (vérité des montants)
- **Revue tarifaire** (`_BillingTariffReviewTab`) : déjà de qualité — contracté vs public, provenance colorée (warning/error), raison, échéance ; dialogue de confirmation **avec preuve obligatoire (≥3)**, avertissement de conséquence, concurrency-safe (409), **zéro facture créée**, aucune confirmation auto. Fiabilité ✅.
- **ADR-631b** : liste des abonnements (`_BillingSubscriptionsTab`) corrigée — l'estimation du prochain renouvellement utilise le **prix contracté** (`plan_price_cents`), plus le prix public ; drapeau `renewal_blocked` exposé + **icône warning/tooltip** sur les abonnements à prix non fiable (ne facturera pas, ADR-593). Vérité d'affichage, aucune action.
- Preuves : `PlatformSubscriptionsReadTruthTest` (3) ✅ + non-régression (13) ✅ + build clean.
- Reste 7B à auditer visuellement : plans, addons (pricing), invoices, payments.

## Point de reprise (précis)
Prochain incrément : **LOT 7B (suite)** — audit visuel + vérité des écrans plans / addons-pricing / invoices / payments. Puis **LOT 7C** (encaissement/recouvrement : dunning, scheduled-debits, credit-notes, coupons, wallets), **7D** (gouvernance/supervision : forensics, governance, ledger, recovery, reconciliation), **7E** (configuration : vérification poussée de chaque paramètre financier). Puis **LOT 5** (automatisations), **LOT 8** (matrice multi-persona).
~~Prochain incrément : **LOT 6b**~~ — refonte des écrans Company restants (`_BillingPlan` comparaison/changement, `_BillingInvoices` liste, `invoices/[id]` détail, `_BillingPaymentMethods`, `pay.vue`, portefeuille/timeline, résiliation) avec HT/TTC qualifié + Playwright par écran (owner). Puis **LOT 7** (15 onglets Platform, en réutilisant la recette), **LOT 5** (audit automatisations), **LOT 8** (personas + matrice complète).
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
| Détail facture + PDF | ✅ (ADR-622) | ✅ (ADR-625/626/627) parcours complet LOCAL+DEV — **PDF Poppins premium local, 19,7Ko, DEV 11,6s** |
| Paiements / wallet / résiliation | ✅ (ADR-623) | ✅ (ADR-628) rendu+nav (methods/activity/pay, 0 erreur JS, gating internal) — parcours data (wallet/cancel) → LOT 8 |

## Observations perf
- **BILL-perf-PDF** : ✅ **CORRIGÉ (ADR-626+627)** — fetch Google supprimé ; **Poppins premium bundlé** (repo, OFL) + subsetting → 882 KB→**18-20 KB**, ~80-370 ms, **aucun réseau** ; chroot/font-cache réglé pour déploiements atomiques. Vérifié DEV (téléchargement 11,6s). Tests `BillingPdfPerfTest`.

## Matrice qualité — points de vérification ouverts (non bloquants)
- **PDF factures longues / annexes / sauts de page / alignements** : vérification visuelle à mener (facture multi-lignes + annexes A/B). Statut : ⏳ ouvert (n'empêche pas les autres lots).
- **Décomposition du parcours Playwright PDF (~11,6 s DEV)** : génération PDF mesurée **≈ 0,37 s** (tinker DEV) ; le reste ≈ login persona (~3–5 s, waitForURL + attente) + 3 navigations `networkidle` (~2 s chacune) + transfert PDF (léger, 19,7 Ko). → **La génération/transfert PDF est marginale (<1 s)** ; le temps est dominé par login + navigations de test, pas par le PDF. Statut : documenté.

## LOT 7 — permissions (fiabilité)
- `BillingPlatformPermissionTest` ✅ : lecture refusée sans `view_billing` (403) ; mutation (void) refusée sans `manage_billing` (403) + facture **intacte** (aucun effet financier sur requête refusée). Isolation/permissions Platform confirmées côté serveur.
