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
- **ADR-631c (BILL-010)** : admin Platform factures corrigé — statut canonique `void` (filtre + libellé + garde d'avoir ; fallback = statut brut, plus de « Brouillon » trompeur) ; export Prometheus comptait `voided` (bucket toujours 0) → corrigé en `void`+uncollectible+written_off. Preuve `BillingMetricsExportVoidTest`.
- **ADR-631d** : protection avoir **vérifiée côté backend** (pas que frontend) — `issueCreditNote` → `assertFinalized` + `assertNotVoided` (teste `voided_at`, pas le littéral) ; `refund` exige `status==='paid'`. Verrouillé par `CreditNoteVoidProtectionTest` (void/draft/refund-void → exception + facture & ledger intacts).
- **ADR-631e — 7B tarifaire bouclé** : plans (liste HT + détail HT/gouvernance ADR-588 contractuel vs public), markets (TVA), paiements (encaissé par statut, pas de mislabel) **déjà fiables** ; addons qualifiés HT (mention unique). **Autorisations gated** (manage_plans/billing/modules/markets). **Finding** : aucune période de validité de prix dans le modèle — validité contractuelle = snapshot abonnement ; prix public = catalogue courant (pas d'historique). Non fabriqué.
- **LOT 7B : TERMINÉ.**

## LOT 7C — recouvrement / opérations (impact avant confirmation) · ADR-631f
- **Dunning** : retry/escalate montraient déjà l'impact (montant/conséquence, ADR-446) ; **write-off** corrigé → alerte « créance de {montant} retirée de l'AR, enregistrée en perte ».
- **Scheduled-debits / credit-notes / wallets** : lecture seule (pas de mutation à prévisualiser ici ; émission avoir gardée ADR-631d côté factures).
- **Coupons** : backend désactive-si-utilisé / supprime-si-inutilisé ; dialogue rendu **conforme** (titre/texte/bouton/toast conditionnels + alerte impact). Verrouillé `CouponDeleteBehaviorTest`.
- **LOT 7C : TERMINÉ.**

## LOT 7D — supervision / gouvernance (diagnostic sans SQL) · ADR-631g
- Forensics (timeline+snapshots+export), governance (périodes/drift/freeze), ledger (balance+écritures expandables), reconciliation (gaps jamais masqués + anomalies liées à la facture + aging) : **déjà diagnosticables**.
- **Gap recovery corrigé** : dead-letters n'exposaient que l'erreur tronquée → ajout d'une **vue d'inspection** (erreur complète + **payload JSON**) avant replay. Payload déjà renvoyé par l'API → correctif frontend, verrouillé `DeadLetterPayloadExposedTest`.
- **LOT 7D : TERMINÉ.**

## LOT 7E — paramètres financiers (contrôle exhaustif) · ADR-631h
- Audit vérifié des **33 paramètres** → **28 consommés** (consommateur backend identifié, cf. `docs/bmad/billing-config-audit-7E.md`), **5 décoratifs** (proration_strategy, addon_billing_interval, payment_method_expiry_check_days, payment_required, annual_only) → **retirés de l'UI** (clés conservées en base, round-trip).
- Dimensions documentées : source (`PlatformBillingPolicy` singleton), permission (`manage_billing`), validation (Rule::in + invariants use case), traçabilité (`BILLING_POLICY_UPDATED` + diff), portée globale, impact forward-only (ADR-587/593).
- **Bug fatal corrigé** : `cancelPreview` référençait `App\Core\Billing\BillingPolicy` (inexistant) → lit désormais `PlatformBillingPolicy::instance()->downgrade_timing` (même source que la résiliation). Verrouillé `CancelPreviewPolicyTest`.
- **LOT 7E : TERMINÉ.** → **LOT 7 PLATFORM COMPLET** (7A cockpit, 7B revenus, 7C recouvrement, 7D supervision, 7E config).

## LOT 5 — automatisations (supervision) · ADR-631i
- 10 commandes planifiées (`routes/console.php`, `withoutOverlapping`) auditées : renew/dunning/reconcile/recover-webhooks/recover-checkouts/check-dlq/collect-scheduled/expire-trials/check-trial-expiring/check-expiring-cards. Détail : `docs/bmad/billing-automations-audit-5.md`.
- **Gap corrigé** : 4 commandes (collect-scheduled SEPA, expire-trials, check-trial-expiring, check-expiring-cards) tournaient sans heartbeat → instrumentées. Supervision via `BillingJobHeartbeat` → onglet recovery + Prometheus. Dry-run n'enregistre pas de heartbeat. Verrouillé `BillingAutomationHeartbeatTest`.
- **LOT 5 : TERMINÉ.**

## LOT 8 — matrice multi-persona · ADR-631j
- `billing-persona-matrix.spec.js` (5 tests, **DEV, SHA servi**, 5/5) : cockpit responsive+dark (0 débordement, KPI présent), recovery dead-letter inspect (payload visible, conditionnel+log), subscriptions blocked-renewal flag (conditionnel+log), company owner overview responsive+dark, provider internal → aucun CTA paiement en ligne.
- Dimensions couvertes : rendu + interaction + responsive + dark + persona + validation financière. Données absentes → **journalisées** (pas de skip silencieux).
- **LOT 8 : TERMINÉ.**

---
## BILLING 3.0 — ÉTAT GLOBAL
- **LOT 7 Platform (7A-7E) : TERMINÉ** · **LOT 5 automatisations : TERMINÉ** · **LOT 8 matrice : TERMINÉ**.
- Lots antérieurs : LOT1 (intégrité abonnements), LOT2 (vérité financière), LOT3 (characterization PricingEngine), LOT4 (HT/TTC + PDF), LOT6 (UX Company) — déjà livrés.
- ADR de ce cycle : 631 (7A), 631b (7B montants), 631c/d (void + protection avoir backend), 631e (tarifaire/HT), 631f (7C impact), 631g (7D dead-letter), 631h (7E config), 631i (LOT5 heartbeats), 631j (LOT8 matrice).
- Garde-fous respectés tout le cycle : aucun push main, aucun déploiement PROD, aucune écriture financière réelle, ADR-575/593 intacts (aucun renouvellement rétroactif, aucune confirmation auto de prix provisional).

## Point de reprise (précis)
Prochain incrément : **LOT 7B (suite)** — audit visuel + vérité des écrans plans / addons-pricing / invoices / payments. Puis **LOT 7C** (encaissement/recouvrement : dunning, scheduled-debits, credit-notes, coupons, wallets), **7D** (gouvernance/supervision : forensics, governance, ledger, recovery, reconciliation), **7E** (configuration : vérification poussée de chaque paramètre financier). Puis **LOT 5** (automatisations), **LOT 8** (matrice multi-persona).
~~Prochain incrément : **LOT 6b**~~ — refonte des écrans Company restants (`_BillingPlan` comparaison/changement, `_BillingInvoices` liste, `invoices/[id]` détail, `_BillingPaymentMethods`, `pay.vue`, portefeuille/timeline, résiliation) avec HT/TTC qualifié + Playwright par écran (owner). Puis **LOT 7** (15 onglets Platform, en réutilisant la recette), **LOT 5** (audit automatisations), **LOT 8** (personas + matrice complète).
Recette backend prête (`BillingRecetteSeeder`, 3 cas, seedé sur DEV). Persona navigateur disponible : `owner@leezr.test` = owner de company #1 (Pro active) sur DEV/local.

## Playwright (preuves accumulées)
| Spéc | Écran | Personas | Statut |
|---|---|---|---|
| `company-billing-overview.spec.js` | Overview Company | owner | ✅ DEV (rendu 3 sections, 0 erreur JS, dark mode) |
| `platform-billing-cockpit.spec.js` | Cockpit + tariff-review + subscriptions | admin | ✅ **DEV SHA 63a5220** (3/3, 0 erreur JS ; **contenu** : carte KPI « renouvellements bloqués » présente) |
| `platform-billing-tabs.spec.js` | 12 onglets Platform | admin | ✅ DEV (rendu + nav, 0 erreur JS) |

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
