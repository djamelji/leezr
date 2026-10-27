# BILLING 3.0 — FACTURATION CONSOLIDÉE V2 : ARCHITECTURE RÉVISÉE

> **Conception uniquement — aucune implémentation, aucune écriture moteur/factures/abonnements/données.** Pas de push main, pas de PROD, pas de renouvellement/encaissement/confirmation provisional. ADR-575/593 préservées. Révise `billing-consolidation-design.md` après audit du comportement réel. Date : 2026-10-27.

## Correctif majeur vs. v1 du dossier
Le comportement **réel** audité invalide la proposition « garder la facture en draft jusqu'à la fin de période » : la facturation est **à l'avance** (voir §5). On conserve **émission + finalisation au début de cycle** ; les événements mi-cycle passent par des **événements facturables immuables** (§3) consommés au **prochain** BillingRun.

---

## 1. Diagramme textuel du cycle complet (comportement RÉEL audité)

```
  t0 = début période P            t0+Δ (mi-cycle)              t1 = fin période P / début P+1
   │                                │                               │
   ▼                                ▼                               ▼
 BillingRun(P)                 ModuleEnabled(P)                BillingRun(P+1)   [cron: current_period_end<=now]
 ├─ PricingEngine → PriceBreakdown  ├─ (AUJOURD'HUI) AddonBillingListener         ├─ facture AVANCE période P+1
 │  (plan + addons actifs + coupon) │   crée annexe/facture séparée ❌           │  plan P+1 + modules P+1 (pleins)
 ├─ InvoiceIssuer.createDraft       │                                            │  + PRORATA événements de P (accruals)
 ├─ addLine(plan), addLine(addon…)  ├─ (CIBLE V2) BillableEvent immuable          │  + ajustements + remises
 ├─ applyCoupon                     │   (prorata figé, status=eligible) ✅       ├─ réserve les BillableEvent eligible
 └─ FINALIZE (immuable) → amount_due│   NON facturé maintenant (défaut)          └─ FINALIZE → 1 facture consolidée
    due_at = +invoice_due_days                                                       (lignes = N prestations)
```

**Faits (fichiers:lignes)** :
- Déclenchement renouvellement : `current_period_end <= now()` (`BillingRenewCommand:60`). Catch-up si cron en retard.
- Nouvelle période : `newPeriodStart = current_period_end` (`:206`), avance par `billing_anchor_day`/interval (`:207-246`).
- Facture = période **suivante** `[current_period_end → newPeriodEnd]` (`:256`), **finalisée immédiatement** (`:361`).
- Anti-cascade ADR-575 : si `newPeriodEnd` déjà passé → `deferred`, **aucune facture rétroactive** (`:264-283`).
- Blocage ADR-593 : `! hasReliableContractedPrice()` → `pricing_review_required`, pas de facturation (`:182+`).
- Addon mi-cycle : `AddonBillingListener:145-157` crée annexe/facture séparée (le fragmenteur), **sans vérifier le blocage plan**.

---

## 2. Matrice ACTIVATION × FACTURATION (options réelles + cible)

**Activation réellement implémentée** : immédiate (`ModuleEnabled` → `AddonBillingListener`) ; programmée (`PlanChangeIntent` pour les changements de plan, `scheduled_at`) ; au prochain cycle (trial guard : addon activé en trial → facturé au 1ᵉʳ renouvellement post-trial, `AddonBillingListener:110-119`). Il **n'existe pas** aujourd'hui de programmation générique « activer au prochain cycle » pour un module hors trial → à concevoir si besoin (ne pas inventer comme existant).

| Activation | Facturation | Date service | Début facturation | Période facturée | Émission | Exigibilité | Prorata | Paiement | Documents |
|---|---|---|---|---|---|---|---|---|---|
| Immédiate | **Prochaine conso.** (cible défaut) | t0+Δ | t0+Δ | prorata [t0+Δ→t1] puis plein P+1 | BillingRun(P+1) | +due_days après P+1 | oui (floor) | sur conso. | **0 immédiat, +0 ligne sur conso.** |
| Immédiate | **Immédiate** (contrat l'exige) | t0+Δ | t0+Δ | prorata [t0+Δ→t1] | maintenant | +due_days | oui | immédiat (wallet/provider) | **1 doc hors-cycle** |
| Programmée (t') | Prochaine conso. | t' | t' | prorata/plein selon t' | BillingRun suivant | — | selon t' | sur conso. | 0 immédiat |
| Au prochain cycle | Prochaine conso. | t1 | t1 | plein P+1 | BillingRun(P+1) | +due_days | non | sur conso. | 0 immédiat |

> **Défaut cible** = activation immédiate + facturation sur la **prochaine facture consolidée** (prorata reporté). Facturation **immédiate** réservée aux cas où le contrat l'exige.

---

## 3. Modèle d'événements facturables immuables (BillableEvent)

Table `billable_events` (conception) :
```
id, company_id, subscription_id, currency, type(plan_activation|module_activation|module_deactivation|
plan_change|adjustment|discount), module_key?, source_ref (ex. ModuleEnabled id),
service_date, service_period_start, service_period_end, contractual_period_start, contractual_period_end,
price_snapshot_cents, proration_num, proration_den, rounding('floor'), amount_ht_cents, tax_context_snapshot,
status(eligible|reserved|invoiced|cancelled|compensated), reserved_by_run_id?, invoice_line_id?,
idempotency_key (unique), compensation_of_event_id?, created_at
```

**Cycle de vie** : `créé` → `eligible` → `reserved`(par un BillingRun) → `invoiced`(lié à `invoice_line_id`) → terminal. Alternatif : `cancelled`/`compensated` (par un **événement de compensation tracé**, jamais une suppression).

**Garanties** :
- **Immutabilité** : un BillableEvent facturé n'est jamais modifié ; une correction = **nouvel** événement `compensated`/`adjustment` référençant l'original.
- **Idempotence** : `idempotency_key` unique (ex. `evt:{source_ref}` ou `module:{company}:{module}:{period}`) → une activation ne crée qu'un événement, même si la commande est relancée.
- **Concurrence** : réservation atomique `status: eligible → reserved WHERE id IN(...) AND status='eligible'` (verrou optimiste) ; seul le BillingRun ayant réservé peut facturer. Un second run concurrent ne réserve rien de déjà réservé → **pas de double facturation**.
- **Reprise après erreur** : échec au milieu d'un run → les événements `reserved` non `invoiced` sont **re-libérés** (`reserved → eligible`) par une reprise idempotente ; la facture draft non finalisée est jetée ; rejouer le run réserve + facture exactement une fois.

**Facture consolidée suivante** contient, **sans fusionner les périodes de service** :
- plan période courante (ligne `plan`, période contractuelle explicite),
- chaque module période courante (ligne `module`/`addon`, période explicite),
- les **proratas du cycle précédent** (BillableEvent `reserved`, chacun avec sa propre période de service),
- ajustements, remises.

---

## 4. Convention de prorata (confirmée sur le code réel) — **À ARBITRER**

Code actuel `ProrationCalculator::compute` (pur, déterministe, cents) :
- `totalDays = round(periodStart.diffInDays(periodEnd))`
- `daysRemaining = round(changeDate.diffInDays(periodEnd))`
- `credit = floor(daysRemaining / totalDays × oldPriceCents)`
- `charge = floor(daysRemaining / totalDays × newPriceCents)` ; `net = charge − credit`
- **Arrondi : `floor`** (favorable au client). Bornes : changement = début de période → plein ; = fin → 0.
- Pour une **activation de module** (pas un changement) : `oldPrice=0` → `credit=0`, `charge=floor(daysRemaining/totalDays × modulePrice)`.

⚠️ **Nuance de bornes à valider avant de figer les exemples** : `diffInDays` est **exclusif**. Pour une activation le **16/10** dans une période **01/10→31/10** (31 j), `daysRemaining = diffInDays(16/10, 31/10) = 15` → **15/31**, pas 16/31.
- Votre exemple « **16/31 jours** » suppose des **bornes inclusives** (du 16 au 31 inclus = 16 jours) → cela nécessiterait `daysRemaining = diffInDays(changeDate, periodEnd) + 1` (changement de convention, à décider).

**Deux options à trancher :**
- **(A) Garder le code actuel** → libellé exact : « prorata du 16/10 au 31/10 (**15/31 jours**) ».
- **(B) Bornes inclusives** → « (**16/31 jours**) » mais nécessite de modifier `ProrationCalculator` (+ re-tester tous les proratas existants).

Je **n'ai rien figé** : les libellés d'exemple ci-dessous utilisent (A) par défaut. Dites-moi A ou B.

---

## 5. Règles d'émission : à l'avance vs à terme échu

- **Comportement actuel = AVANCE** : au passage `current_period_end <= now`, la facture couvre la période **suivante** et est **finalisée immédiatement** (§1). Le plan ET les addons actifs sont sur cette facture (déjà consolidés par `BillingRenewCommand`).
- **Cible** : conserver l'**avance** (émission + finalisation au début du cycle). Les événements survenant **après** finalisation (mi-cycle) ne modifient jamais la facture figée → ils deviennent des **BillableEvent** comptabilisés au **prochain** BillingRun (prorata du cycle écoulé reporté). Pas de draft maintenu mutable.
- **Terme échu** (si un jour requis pour l'usage mesuré/overage) : modélisable par des BillableEvent `eligible` en fin de période consommés par le BillingRun suivant — même mécanique, sans second modèle.

---

## 6. Prix provisional / blocage contractuel — 5 autorisations distinctes

| Autorisation | État bloqué (plan provisional) | Justification |
|---|---|---|
| **Activer un module** | ✅ autorisé | provisionner le service ≠ facturer |
| **Utiliser un module** (entitlement) | ✅ autorisé | ne pas suspendre le service sur un prix non confirmé |
| **Enregistrer une charge différée** (BillableEvent) | ✅ autorisé, `status=eligible` (ou `blocked`) | tracer la prestation sans l'émettre |
| **Émettre une facture** | ❌ **bloqué** (ADR-593) tant que le **plan** n'a pas un prix fiable | aucune facture en contournant le blocage |
| **Collecter un paiement** | ❌ bloqué | rien d'exigible |

**États de blocage** : le `Subscription` porte déjà `pricing_review_required`. On ajoute un **état de cycle** : `cycle_hold` (plan non fiable) → le BillingRun **ne finalise aucune facture** pour ce cycle ; les BillableEvent restent `eligible` (accumulés, non facturés). **Corrige** la violation actuelle (addons facturés malgré le blocage).

**Reprise après revue contractuelle** (prix confirmé → `admin_confirmed`, source fiable) :
- Le blocage lève `cycle_hold` ; le **prochain** BillingRun consolide normalement **en avant**.
- **Aucune facturation rétroactive automatique** : les BillableEvent accumulés pendant le blocage sont présentés à l'**ops** qui décide (facturer / créditer / renoncer) via une procédure tracée — jamais une cascade auto (ADR-575). Pas de confirmation implicite de prix.

➡️ **On ne suspend pas les services** quand le plan est provisional ; on bloque **uniquement l'émission + l'encaissement**, en accumulant des événements traçables.

---

## 7. Exemple de facture consolidée (plan + modules + prorata antérieur)

**Facture `INV-2026-000123` — Leezr Logistics — période 01/11/2026→30/11/2026 — EUR**
| # | Type | Libellé | Période de service | Qté | Prorata | HT | TVA 20% | TTC |
|---|---|---|---|---|---|---|---|---|
| 1 | plan | Abonnement Pro — du 01/11/2026 au 30/11/2026 | 01/11→30/11 | 1 | — | 29,00 | 5,80 | 34,80 |
| 2 | module | Module Planning & Pointage — du 01/11/2026 au 30/11/2026 | 01/11→30/11 | 1 | — | 29,00 | 5,80 | 34,80 |
| 3 | module | Module Documents Employés — du 01/11/2026 au 30/11/2026 | 01/11→30/11 | 1 | — | 9,00 | 1,80 | 10,80 |
| 4 | adjustment | Module Planning & Pointage — prorata du 16/10/2026 au 31/10/2026 (**15/31 j**, conv. A) | 16/10→31/10 | 1 | 15/31 | 14,03 | 2,80 | 16,83 |
| | | | | | **Total HT** | **81,03** | **TVA 16,20** | **TTC 97,23** |

Chaque ligne : type, nom, identifiant module, **période de service explicite**, période contractuelle, **snapshot prix**, qté/unité, num/dén prorata, convention bornes, arrondi floor, HT, remise, taxe (contexte), TTC, **réf. BillableEvent d'origine**. Les périodes de service **ne sont pas fusionnées** (ligne 4 garde 16/10→31/10).

---

## 8. Architecture de compatibilité v1/v2

- `Invoice.billing_model_version` (migration **additive**, défaut `1`). `1` = ADR-328 (parent+annexes). `2` = consolidé.
- **Deux leviers distincts** :
  1. **Flag de CRÉATION** `billing.consolidated.create` (bool) : autorise la **génération de nouveaux documents v2**. OFF → nouveaux cycles créés en v1.
  2. **Traitement PERMANENT** : lecture, PDF, paiement, dunning, avoirs, ledger, réconciliation **gèrent toujours v1 ET v2**, indépendamment du flag. Un document existant n'est jamais orphelin.
- Les read models/PDF **branchent sur `billing_model_version`** : v1 → rendu annexes ; v2 → rendu lignes consolidées.

---

## 9. Stratégie de rollback réellement sûre

- Rollback = **flag de création OFF** → les **nouveaux** cycles repassent en v1. **Aucune réécriture**, aucune fusion, aucune suppression.
- **Les factures v2 déjà émises restent** : consultables, téléchargeables (PDF v2), payables (solde consolidé), réconciliables, compatibles avoirs + dunning, tracées au ledger — **le traitement v2 n'est jamais désactivé**, seule la **création** l'est.
- Donc : séparer formellement « autorisation de créer v2 » (réversible) de « capacité de traiter v2 » (permanente). Test dédié : *facture v2 finalisée puis flag OFF → toujours payable/consultable/réconciliable*.

---

## 10. Plan d'implémentation M0→M10 révisé & ordonnancé

| Lot | Portée | Dépend de | Risque |
|---|---|---|---|
| **M0** | Migration additive : `Invoice.billing_model_version` (défaut 1) + table `billable_events` (vide, inutilisée) | — | faible |
| **M1** | `BillableEvent` service + idempotence + réservation atomique + reprise (tests unitaires, **aucun branchement moteur**) | M0 | faible |
| **M2** | `BillingRun` consolidateur (réutilise `PricingEngine`/`InvoiceIssuer`), **shadow mode** (calcule, ne finalise pas) + marque v2 | M1 | moyen |
| **M3** | `AddonBillingListener` : mi-cycle → **BillableEvent** (au lieu d'annexe) **pour cycles v2**, + **respect du `cycle_hold`** (blocage plan) | M2 | **élevé** |
| **M4** | Génération consolidée au renouvellement (v2) consomme les BillableEvent `reserved` | M3 | moyen |
| **M5** | Facturation **immédiate** hors-cycle (option contrat) : BillableEvent `bill_immediately` → doc v2 isolé, idempotent | M4 | moyen |
| **M6** | Paiement `InvoiceBatchPayService` : facture v2 = 1 solde ; v1 inchangé ; allocation wallet/provider | M4 | moyen |
| **M7** | Ledger (écriture par facture + **analytique par ligne**) + dunning/réconciliation v2 | M4 | moyen |
| **M8** | Read models + PDF v2 (branchement `billing_model_version`, multipage, Poppins) ; v1 préservé | M4 | moyen |
| **M9** | Écrans Company/Platform (liste/détail/paiement) v2 ; recette DEV (matrice §8 doc + ci-dessous) | M8 | moyen |
| **M10** | Déploiement PROD progressif (flag création ON par date d'effet ; historique intact) + rollback flag | tous | **élevé** |

---

## Matrice de tests renforcée (ajouts obligatoires)
Plan+134 modules (1 facture, 135 lignes, 1 solde) · avance · terme échu · activation immédiate+facturation immédiate · activation immédiate+facturation différée · activation programmée+facturation différée · **prorata octobre reporté sur facture novembre** · 2 activations/désactivations d'un même module (événements compensés, non fusionnés) · fin de mois + **février (28/29 j)** · cycles annuels · **plusieurs fuseaux** (ancre/jour calculés en TZ définie) · **arrondis répétés sur des centaines de lignes** (somme des floors ≠ floor de la somme — documenter l'écart) · **échec au milieu d'un BillingRun** (reprise, 0 doublon) · **double exécution concurrente** (réservation atomique) · **facture v2 finalisée puis flag OFF** (toujours payable/consultable) · prix provisional (cycle_hold, 0 facture) · **reprise contractuelle sans rétroactivité** · paiement partiel · wallet · avoir+remboursement · taxes multi-lignes (contexte) · PDF multipage · **historique v1 intact**.

---

## Arbitrages en attente de votre décision (avant ADR + M0)
1. **Convention de prorata** : (A) code actuel `15/31` floor, ou (B) bornes inclusives `16/31` (modifie `ProrationCalculator`).
2. **Défaut de facturation mi-cycle** : reportée sur la prochaine conso. (proposé) — confirmez.
3. **Facturation immédiate** : liste des cas contractuels qui l'exigent (sinon tout est différé).
4. **Déblocage provisional** : procédure ops de traitement des BillableEvent accumulés (facturer/créditer/renoncer) — valider le principe « jamais rétroactif auto ».
5. **Services pendant blocage** : confirmer « activer + utiliser autorisés ; émettre + encaisser bloqués ».

> Dès votre arbitrage, je rédige l'**ADR de décision** (modèle consolidé v2, versionnement, BillableEvent, prorata, provisional) ; **M0 ne démarre qu'après validation**.

---

## PROPOSITION D'ADR (BROUILLON — non intégrée à `04-decisions.md`, en attente de validation)

### ADR-632 (proposé) — Facturation consolidée V2 (une facture par company/devise/cycle/période)

**Statut** : PROPOSÉ (brouillon). À figer après arbitrage des 5 points ci-dessus. **Non appliqué.**

**Contexte.** Le modèle actuel fragmente : `AddonBillingListener` crée un document séparé (annexe/facture) à chaque activation de module mi-cycle, car la facture de période est **finalisée à l'avance** et **immuable** (ADR-328 a introduit l'annexe comme contournement). Conséquences : jusqu'à 135 documents/soldes pour une company à 134 modules, fuites de vérité dans les read models (ADR-614), et facturation des modules **malgré le blocage du plan** (provisional, ADR-593) — cas Leezr Logistics.

**Décision (proposée).**
1. **Modèle consolidé v2** : une facture par (company, devise, cycle, période), N lignes typées (`plan`/`module`/`addon`/`adjustment`/`discount`), réutilisant `PricingEngine`/`InvoiceIssuer`/`PriceBreakdown` existants. **Facturation à l'avance conservée** ; finalisation au début de cycle.
2. **Événements facturables immuables** (`billable_events`) pour tout événement mi-cycle, consommés par le **BillingRun** suivant (prorata reporté) ; idempotence + réservation atomique + reprise. Compensation par événement tracé, jamais suppression.
3. **Prorata** : `ProrationCalculator` day_based **floor** ; convention de bornes **[arbitrage A/B]**.
4. **Blocage provisional** : 5 autorisations distinctes — activer ✅, utiliser ✅, accumuler une charge ✅ ; **émettre ❌**, **encaisser ❌** tant que le plan n'a pas un prix fiable (`cycle_hold`). Déblocage = en avant, **jamais rétroactif auto** (ADR-575), traitement ops des accruals.
5. **Versionnement** `Invoice.billing_model_version` (v1 ADR-328 / v2) ; **flag de création** réversible, **traitement v1+v2 permanent** ; rollback sans réécriture.
6. **Historique intact** : annexes ADR-328 ni fusionnées, ni supprimées, ni renumérotées.

**Conséquences.** Une facture lisible par cycle avec dates/proratas par prestation ; correction du contournement du blocage (impacte le CA reconnu tant que les prix provisional ne sont pas confirmés) ; double modèle v1/v2 encadré ; migration M0→M10 par lots avec rollback par flag.

**Invariants** : §5 du dossier (pas de double facturation, immutabilité, blocage respecté, taxes par contexte, pas de confirmation implicite, pas de rétroactif, pas de calcul front, pas de double paiement, ajustements tracés, partiels imputés).

**Ne modifie pas** : ADR-328 (documents historiques), ADR-575/593. **Préserve** : snapshots, numéros stockés, factures émises.

---

# AUDIT FINAL — faits confirmés sur le code + compléments (avant M0)

## A. Comportement RÉEL confirmé (références code)
| Fait | Référence | Implication |
|---|---|---|
| Renouvellement = **avance**, facture finalisée au début de cycle | `BillingRenewCommand:256,361` | mi-cycle ne peut pas modifier (immuable) |
| Plan + addons actifs consolidés au renouvellement | `BillingRenewCommand:308-337` | consolidation **déjà réelle** au renouvellement |
| **Addon mi-cycle = montant PLEIN, AUCUN prorata** | `AddonBillingListener:160-167` (`$amount` = `amount_cents`, qté 1) | le prorata v2 serait un **NOUVEAU comportement** |
| Addon mi-cycle = période **`[now → now+1 mois]`** (désalignée du plan) | `AddonBillingListener:142-143` | v2 alignerait sur la période du plan = **changement** |
| Addon mi-cycle facturé **immédiatement** (auto-charge) | `AddonBillingListener:170-173` | v2 par défaut **différé** = **changement** |
| `PricingEngine::forCurrentPeriod` = montants **pleins** (pas de prorata) | `PricingEngine:36-73` | prorata **uniquement** pour changements de plan |
| Prorata existe **seulement** pour changement de plan | `PricingEngine:155-175` → `ProrationCalculator` | aucun prorata module/addon aujourd'hui |
| **Fuseau applicatif = UTC** | `config/app.php:68` | bornes de période en UTC, pas de DST |
| Activation **programmée / au-prochain-cycle** de module | **inexistante** (seul `PlanChangeIntent.scheduled_at` pour les plans) | à **développer** (ne pas présenter comme existant) |

### ⚠️ Changements de comportement induits par v2 (décision métier)
Aujourd'hui, un module activé mi-cycle : **document séparé + mois plein depuis l'activation + encaissement immédiat**. En v2 par défaut : **ligne consolidée + prorata jusqu'à la fin du cycle du plan + report sur la prochaine facture**. Ce sont **3 changements réels** (ce que paie le client change), pas de la cosmétique — à valider explicitement.

## B. Point 1 — Activation × Facturation : existant / partiel / à construire
| Capacité | État |
|---|---|
| Activation **immédiate** | ✅ existe (`ModuleEnabled` → `AddonBillingListener`) |
| Activation **programmée** (module) | ❌ à construire (seuls les **changements de plan** ont `scheduled_at`) |
| Activation **au prochain cycle** (module) | ⚠️ partiel : uniquement via le **trial guard** (addon en trial facturé au 1ᵉʳ renouvellement) ; pas de programmation générique |
| Facturation **immédiate** | ✅ existe (auto-charge addon) |
| Facturation **prochaine conso.** | ⚠️ partiel : le renouvellement consolide, mais le mi-cycle crée un doc séparé (pas de report) |
| Facturation **selon politique contractuelle** | ❌ pas de moteur de politique par-contrat ; à construire |

## C. Point 3 — Convention de prorata (complète, reproductible)
- **Source** : `ProrationCalculator` (pur, cents) ; `credit=floor(r/t×oldPrice)`, `charge=floor(r/t×newPrice)`, `net=charge−credit`.
- **Mensuel** : dénominateur `t = diffInDays(periodStart, periodEnd)` = **jours calendaires réels de LA période** → gère nativement **28/29/30/31** (février inclus). Numérateur `r = diffInDays(changeDate, periodEnd)`.
- **Annuel** : même formule, période = 1 an calendaire (365/366 j).
- **Bornes** : `diffInDays` **exclusif** → activation 16/10 (01→31/10) = **15 j** (`r=15`, `t=31`). [Arbitrage A/B §4].
- **Fuseau** : **UTC** (dates `toDateString()` en UTC) → **pas de DST** ; bornes = minuit UTC (nuance : peut différer de minuit local — décision si alignement local requis).
- **Jours calendaires**, pas de secondes. **Arrondi : floor** sur les cents (favorable client).
- **Prorata négatif** (désactivation / downgrade) : `net < 0` → **crédit** (avoir/ligne négative reportée), jamais une facture négative.
- **Quantités** : ligne `qty` (actuellement 1) ; prorata appliqué au montant unitaire snapshot.
- **Coupons/remises** : appliqués **après** le prorata, sur la facture consolidée (ligne `discount`), coupon-aware (`PricingEngine` gère déjà effective price).
- **Taxe** : calculée par `TaxContextResolver` **par ligne** sur le HT proratisé (contexte : domestique/reverse-charge/export).
- **Reproductibilité** : chaque `BillableEvent` fige `price_snapshot_cents`, `service_period_start/end`, `proration_num/den`, `rounding='floor'`, `tax_context_snapshot` → recalcul identique à l'émission.

## D. Point 5 — Machine d'état BillableEvent (complète)
```
CREATED ──(validé/éligible)──▶ ELIGIBLE ──(BillingRun lock atomique)──▶ RESERVED ──(finalize facture)──▶ INVOICED (terminal)
   │                              │                                        │
   │                              ├─(annulation avant résa)──▶ CANCELLED   ├─(échec run)──▶ ELIGIBLE (re-libéré, reprise)
   │                              │                                        │
   └─(compensation : NOUVEL event)──────────────────────────────▶ COMPENSATED (terminal, réf. event d'origine)
```
- **Figé à l'ACTIVATION** : `price_snapshot_cents`, `service_date`, `contractual_period`, `tax_context_snapshot`, `idempotency_key`, `proration_num/den`, `rounding`.
- **Calculé à l'ÉMISSION** : `amount_ht` final (= snapshot × prorata), regroupement d'affichage, total facture, taxe agrégée.
- **Réservation atomique** : `UPDATE … SET status='reserved', reserved_by_run_id=? WHERE id IN(...) AND status='eligible'` (verrou optimiste) → **concurrence sûre** (un seul run réserve).
- **Crash après finalize mais avant marquage `invoiced`** : la facture porte `invoice_line_id`↔`billable_event` ; une reprise idempotente **réconcilie** (si la ligne existe → marquer `invoiced` ; sinon re-libérer). Jamais perdu, jamais doublé.
- **Rejeu BillingRun** : clé `run:{company}:{currency}:{cycle}:{periodStart}` + idempotency par event → rejeu = **no-op** (facture existante retournée).
- **Retry/échec provider** : l'événement reste `invoiced` (document émis) ; l'échec de collecte relève du dunning, pas de l'événement.

## E. Couverture des 14 livrables demandés
1 Comportement réel + refs → §A, §1. · 2 Diagramme cycle → §1. · 3 Matrice activation×facturation → §2 + §B (exemples A-I ci-dessous). · 4 Convention prorata → §C + §4. · 5 Machine d'état BillableEvent → §D + §3. · 6 BillingRun + idempotence → §3, §D. · 7 Facturation immédiate → §6 (politique). · 8 Blocage provisional → §6 (5 autorisations). · 9 Exemples factures + périodes → §7. · 10 Coexistence v1/v2 → §8. · 11 Rollback → §9. · 12 Plan M0-M10 → §10. · 13 Matrice tests → §Matrice + ci-dessous. · 14 Décisions métier → §Arbitrages + §A changements.

### Exemples matrice (A–I) — invariant clé par cas
- **A** immédiate+immédiate : 1 doc hors-cycle (prorata), payable maintenant ; event `bill_immediately`→`invoiced` ; **pas de re-facturation** au cycle suivant (clé idempotence).
- **B** immédiate+prochaine conso. : 0 doc now ; event `eligible` ; prorata sur facture N+1. *(≠ comportement actuel = plein+immédiat)*.
- **C** programmée+à la date : event créé à t', facturé à t' (doc hors-cycle). *(activation programmée module = à construire)*.
- **D** programmée+prochaine conso. : event à t', consommé au BillingRun suivant.
- **E** prochain cycle+avance : activation t1, facturée sur la conso. de t1 (plein, pas de prorata).
- **F** désactivation+avoir : event `module_deactivation`, `net<0` → crédit reporté (ou avoir immédiat si contrat). Jamais de facture négative.
- **G** changement de plan mi-période : `ProrationCalculator` (existant), event `plan_change`, net reporté ou doc selon policy timing.
- **H** activ/désactiv répétées : chaque transition = event distinct **non fusionné** ; compensations tracées ; somme nette correcte.
- **I** 134 modules dates ≠ : 134 events `eligible` de périodes de service différentes → **1 facture conso.** au cycle, 135 lignes (plan + 134), périodes **non fusionnées**, 1 solde.

---

# RÉVISION FINALE — convention temporelle, bornes & ADR-632 complet

## I. Audit des bornes ACTUELLES (incohérences confirmées, refs)
| Chemin | Borne de fin stockée/utilisée | Réf |
|---|---|---|
| Inscription (free) | `now()->addMonth()` → **porte l'heure de now()** | `RegisterCompanyUseCase:136-138` |
| Renouvellement | `newStart = current_period_end` (hérite l'heure) ; `newEnd = start + interval`/anchor | `BillingRenewCommand:206-246` |
| Données legacy/seed | `startOfMonth 00:00 → endOfMonth 23:59:59` (**inclusif, dernière seconde**) | ex. sub#1 PROD `05-01 00:00 → 05-31 23:59:59` |
| Facture | `period_end = $newPeriodEnd->toDateString()` → **00:00, perd l'heure** | `BillingRenewCommand:302` |
| Prorata | `ProrationCalculator` reçoit **timestamps complets** + `round()` → **sensible à l'heure** | `PlanChangeExecutor:117-119`, `ProrationCalculator:57-58` |

➡️ **Conclusion** : 3 représentations coexistent (heure-de-now / 23:59:59 inclusif / 00:00 date) + un `round()` time-sensitive. **Incohérent.** Un simple `+1` ne corrige pas la cause.

## II. Convention cible : intervalles semi-ouverts `[start, end)`
- **Définition** : `start` = minuit UTC du 1ᵉʳ jour ; `end` = **minuit UTC du 1ᵉʳ jour de la période suivante (EXCLUSIF)**. Durée = `end − start` en jours **entiers** (pas de 23:59:59, pas de `round`).
- Octobre : `[2026-10-01 00:00, 2026-11-01 00:00)` = **31 j**. Activation `2026-10-16 00:00` → durée facturable `[2026-10-16, 2026-11-01)` = **16 j** → **16/31** ✅ (sans `+1` arbitraire : conséquence directe de l'exclusivité de `end`).
- **Prorata** = `floor( billableDays / periodDays × prix_snapshot )`, `billableDays = (end − activationStart)` jours entiers, `periodDays = (end − start)`. Mensuel : `periodDays` = jours réels (28/29/30/31). Annuel : 365/366.
- **Fuseau** : UTC (conforme `config/app.php`). Bornes = minuit UTC. Si un alignement minuit **local** est voulu → décision explicite (sinon UTC).
- **Prorata négatif** (désactivation) : jours **non consommés** `[deactivation, end)` → **crédit** `floor(...)`, jamais facture négative.

## III. Stratégie de migration des bornes (sans toucher l'historique)
- **Normalisation au niveau de la DÉFINITION de borne**, pas un `+1` dans le calcul : une période v2 est **toujours** écrite en `[00:00, 00:00_suivant)`.
- **Lecture des périodes historiques (v1)** via un helper unique `PeriodBounds::fromLegacy($start,$end)` : interprète un `end` à `23:59:59` **ou** un timestamp comme **instant inclusif** → `end_exclusive = $end->ceil('day')` (jour suivant 00:00). **Aucune écriture** sur les lignes historiques ; normalisation **à la lecture** pour les calculs cohérents.
- **Cycles existants non modifiés** : la bascule semi-ouverte s'applique aux **nouveaux** cycles v2 (date d'effet). Tests de transition : un abonnement dont le `current_period_end` est `23:59:59` doit, au prochain renouvellement v2, produire une borne `[.., 00:00_suivant)` **contiguë** (pas de trou d'1 s, pas de chevauchement).
- **Non négociable** : ne jamais réécrire silencieusement `current_period_end` des abonnements actifs ; la normalisation est appliquée au **moment du renouvellement v2** (nouvelle période) et **à la lecture** (helper), avec logs.

## IV. Options commerciales définitives (paramètres, portée, défauts)
| Paramètre | Portée | Valeurs | Défaut |
|---|---|---|---|
| `activation_mode` | par module/addon (sur l'intent/événement) | `immediate` \| `scheduled(date)` \| `next_cycle` | `immediate` |
| `billing_mode` | par module/addon | `immediate` \| `deferred_next_invoice` | **`deferred_next_invoice`** |
| `immediate_billing_requires` | politique contrat | drapeau explicite | off |

- **Indépendance** garantie : `activation_mode` ≠ `billing_mode`. **L'activation n'émet jamais une facture** (découplage) — elle crée un `BillableEvent`.
- **Compatibilité existant** : `scheduled` réutilise le pattern `PlanChangeIntent.scheduled_at` (généralisé aux modules) ; `next_cycle` réutilise la logique trial-guard ; `immediate` = chemin actuel mais **sans auto-facturation** (→ event différé par défaut).

## V. Cardinalité des lignes (≥135) & lisibilité
- 1 ligne plan + N lignes modules récurrents + **jusqu'à N lignes de prorata antérieur** + ajustements/remises → **pas de limite arbitraire**.
- **Document comptable unique**, N lignes, **1 solde, 1 règlement**.
- Affichage : **regroupement par famille** + **sous-totaux** (plan / modules récurrents / proratas / remises / taxes) ; **détail comptable jamais masqué**. PDF **multipage paginé** (Poppins local). Read models paginés. Performance : 1 requête lignes + agrégats SQL (pas de calcul front).

## VI. v1/v2 — rollback & version minimale (précisé)
- `billing.consolidated.create` contrôle **uniquement** la création de nouveaux v2.
- **Prérequis de sûreté (ajout Point 7)** : avant d'activer la création v2 en PROD, la version **déployée ET la version cible de rollback** doivent **toutes deux savoir lire/traiter v1 ET v2** (lecture, PDF, paiement, avoir, dunning, ledger, réconciliation). ⇒ **version minimale de rollback = premier release contenant le lecteur v2** (pas une version antérieure). On n'active jamais la création v2 avant que le lecteur v2 soit en PROD depuis ≥ 1 release stable.
- Rollback = **flag création OFF** (nouveaux cycles en v1) ; les v2 déjà émises **restent** traitées. **Jamais** revenir à une version sans lecteur v2 tant que des documents v2 existent.
- Déploiement progressif : (1) release lecteur v1+v2 (création OFF) ; (2) observation ; (3) création v2 ON par date d'effet ; (4) rollback = création OFF (lecteur reste).

## VII. Exemples RECALCULÉS (convention semi-ouverte, floor, TVA 20%)
**Facture émise 2026-11-01 — période `[2026-11-01, 2026-12-01)` (30 j) — EUR**
| # | Type | Libellé | Période service | Prorata | HT | TVA | TTC |
|---|---|---|---|---|---|---|---|
| 1 | plan | Abonnement Pro — du 01/11/2026 au 30/11/2026 | [11-01,12-01) | — | 29,00 | 5,80 | 34,80 |
| 2 | module | Module Planning & Pointage — du 01/11/2026 au 30/11/2026 | [11-01,12-01) | — | 29,00 | 5,80 | 34,80 |
| 3 | module | Module Documents Employés — du 01/11/2026 au 30/11/2026 | [11-01,12-01) | — | 9,00 | 1,80 | 10,80 |
| 4 | adjustment | Module Planning & Pointage — prorata du 16/10/2026 au 31/10/2026 (**16/31 j**) | [10-16,11-01) | 16/31 | `floor(16/31×2900)`=**14,96** | 2,99 | 17,95 |
| | | | | **HT 81,96** | **TVA 16,39** | **TTC 98,35** | |

Calcul prorata ligne 4 : `floor(16/31 × 2900) = floor(1496,77) = 1496` cents = **14,96 €**. TVA `floor(0.20×1496)=299`=2,99. **Somme des floors par ligne** (invariant : total TVA = Σ TVA lignes, pas TVA du total — documenté pour éviter 1 cent d'écart sur 134 lignes).

## VIII. Plan M0→M10 (inchangé §10) + **conditions de GO pour M0**
GO M0 **si et seulement si** :
1. ADR-632 figée (ci-dessous) **validée** par vous.
2. Arbitrages tranchés : convention semi-ouverte ✅ (vous), défaut `deferred_next_invoice` ✅, fuseau UTC (à confirmer), cas de facturation immédiate listés.
3. M0 = **migration additive seule** : `Invoice.billing_model_version` (défaut 1) + table `billable_events` (vide) + helper `PeriodBounds` (lecture) **sans aucun branchement moteur** → **0 changement de comportement**, réversible (drop table + colonne).
4. Tests M0 : migration up/down, aucun chemin existant n'écrit v2, `PeriodBounds::fromLegacy` sur bornes 23:59:59/00:00/timestamp → durées correctes.

---

## ADR-632 (RÉVISÉE, COMPLÈTE — proposée, à figer dans `04-decisions.md` après votre GO)

**ADR-632 — Facturation consolidée V2 + convention temporelle semi-ouverte.** Statut : PROPOSÉ.

**Contexte.** Fragmentation (annexe/facture par module mi-cycle, facturé plein+immédiat), absence de facture plan (provisional bloqué + chemin addon découplé), et **conventions de bornes incohérentes** (heure-de-now / 23:59:59 inclusif / 00:00 date / `round` time-sensitive). Le modèle de données supporte déjà la consolidation (`InvoiceLine` typées, `PricingEngine`, `BillingRenewCommand`).

**Décision.**
1. **Facture consolidée v2** : 1 doc / (company, devise, cycle, période), N lignes typées, émise+finalisée **à l'avance** ; `Invoice.billing_model_version`.
2. **Convention temporelle semi-ouverte `[start, end)`** (end exclusif = minuit du jour suivant, UTC) ; durée = jours entiers ; prorata `floor(billableDays/periodDays × snapshot)` ; négatif → crédit. Normalisation historique **à la lecture** (`PeriodBounds::fromLegacy`), **jamais** de réécriture des cycles existants.
3. **BillableEvent immuable** (machine d'état CREATED→ELIGIBLE→RESERVED→INVOICED + CANCELLED/COMPENSATED) : mi-cycle → event différé (défaut) ; idempotence + réservation atomique + reprise ; figé-à-l'activation vs calculé-à-l'émission.
4. **Activation ⟂ facturation** : `activation_mode∈{immediate,scheduled,next_cycle}`, `billing_mode∈{immediate,deferred_next_invoice}` (défaut deferred). L'activation **n'émet jamais** de facture.
5. **Provisional (5 autorisations)** : activer ✅ / utiliser ✅ / accumuler ✅ / **émettre ❌** / **encaisser ❌** (`cycle_hold`). Déblocage **en avant, jamais rétroactif auto** (ADR-575) ; revue ops tracée. **Pas de suspension technique auto** des modules.
6. **v1/v2** : flag = création seule ; traitement v1+v2 **permanent** ; **version minimale de rollback = premier release avec lecteur v2** ; jamais revenir en-deçà tant que des v2 existent.

**Conséquences.** 1 facture lisible/cycle (plan+modules+proratas datés, 1 solde) ; **3 changements de comportement assumés** (consolidation + prorata + report, vs plein/immédiat actuel) impactant le CA reconnu sous provisional ; double modèle encadré ; migration M0→M10 réversible.

**Préserve** : ADR-328 (historique intact), ADR-575 (anti-rétro), ADR-593 (blocage provisional), snapshots, numéros, factures émises, règles fiscales.

**Invariants** (tests d'acceptation) : pas de double facturation (event+run idempotents) ; une prestation courante jamais refacturée par un event historique ; document finalisé immuable ; Σ lignes = Σ taxes = total au **centime près** ; prorata reproductible depuis snapshots ; concurrence/crash/rejeu sûrs ; historique v1 intègre ; facture v2 payable même flag OFF.
