# BILLING 3.0 — FACTURATION CONSOLIDÉE : AUDIT & CONCEPTION

> **Audit + conception uniquement — aucune implémentation.** Lecture code + données PROD en lecture seule. Aucune facture émise, aucun prix provisional confirmé, aucune facture historique touchée, ADR-575/593 préservées. Date : 2026-10-27.

---

## 1. Diagnostic architectural de la fragmentation

### Trois chemins de création de documents
| Chemin | Fichier | Comportement |
|---|---|---|
| **Primitive** | `InvoiceIssuer` | Neutre : `createDraft` + `addLine(type)` + `finalize`. Accepte **N lignes typées** (plan/addon/module/discount) sur un seul document. Ne fragmente pas. |
| **Renouvellement** | `BillingRenewCommand::renewSubscription` (152-398) | **CONSOLIDÉ** : 1 facture = ligne plan + boucle addons + coupon, puis `finalize`. Prouve que la consolidation fonctionne déjà. |
| **Activation addon mi-cycle** | `AddonBillingListener::process` (51-202) | **FRAGMENTE** : crée une **annexe** (si facture de période finalisée existe) ou une **facture autonome** (sinon), avec **solde propre**. |

### Cause racine : l'immutabilité des factures
- `InvoiceIssuer::addLine` **lève** « Cannot add lines to a finalized invoice » (120-122) ; `Invoice::isMutable() = !isFinalized()` (115-118).
- La facture de période est **finalisée au début de la période** (au renouvellement). Un addon activé **en milieu de cycle** ne peut donc **pas** être ajouté à cette facture gelée.
- ADR-328 (2026-03-12) a choisi l'**annexe** comme moindre mal : un document enfant `parent_invoice_id` + `annexe_suffix`, `number=NULL` (ne consomme pas la séquence officielle), mais **avec son propre `amount_due`** → donc **payable, relançable, réconcilié indépendamment**.

➡️ **La fragmentation est un artefact de cycle de vie (timing + immutabilité), PAS une limite du modèle de données.** Le schéma `Invoice hasMany InvoiceLine(type)` supporte déjà la consolidation (prouvé par `BillingRenewCommand` et `PricingEngine::forCurrentPeriod` qui construit déjà un `PriceBreakdown` plan + addons + coupon ; `PriceBreakdown::toInvoiceLines()` est conçu pour alimenter **une** facture).

### Preuve du préjudice déjà observé
ADR-614 : fausse alerte PROD « N factures impayées / problème de paiement » car les **annexes étaient comptées comme des factures distinctes** (1 facture + 2 annexes). Correctif read model `whereNull('parent_invoice_id')`. ⇒ la fragmentation **fuite une fausse vérité financière** dans les read models.

---

## 2. Cause exacte de l'absence de facture « Abonnement Pro » (Leezr Logistics, PROD lecture seule)

| Fait reconstitué | Donnée |
|---|---|
| Company #1 créée | 2026-05-02 |
| sub#1 Pro | créé 2026-05-02, **prix 2900 `provisional`**, période **2026-05-01→2026-05-31** (jamais avancée) |
| Paiements | **0** |
| Addons | leave 1900 / planning 2900 / documents 900 — **tous activés 2026-10-21** |
| Factures | inv#2 (parent, ligne **addon**) + annexes A/B — **toutes créées 2026-10-21**, période 10-21→11-21, lignes **addon** uniquement |

**Enchaînement :**
1. **Aucune facture initiale de plan** : souscription à prix **provisional** → facturation du plan **différée** (ADR-593). Aucune facture mai.
2. **Renouvellement bloqué** depuis mai (prix non fiable) → période jamais avancée → aucun plan facturé.
3. Le **2026-10-21**, activation des addons → `AddonBillingListener` facture **les addons**. Comme **aucune facture de période (plan) n'existait**, le 1ᵉʳ addon a créé une **facture autonome** (inv#2, devenue « parent »), et les 2 suivants s'y sont rattachés en **annexes A/B**. D'où un « parent » qui ne contient **qu'un addon**, et **jamais de ligne plan**.

**Classification** : ❌ pas une migration · ❌ pas un plan gratuit · ❌ pas un bug isolé de données · ✅ **absence de facture initiale (différé provisional) + blocage renouvellement + chemin addon découplé qui facture malgré le blocage du plan.**

**Violation d'invariant mise en évidence** : `AddonBillingListener` **facture les modules alors que le plan est contractuellement bloqué** (provisional). Un module ne doit pas contourner le blocage du plan.

---

## 3. Modèle actuel vs. modèle consolidé

| Dimension | Actuel (fragmenté) | Cible (consolidé) |
|---|---|---|
| Document / cycle | 1 facture plan **+ 1 annexe/facture par addon mi-cycle** | **1 facture** / company / devise / cycle / période |
| 134 modules | jusqu'à 135 documents, 135 soldes, 135 relances | **1 document, 135 lignes**, 1 solde, 1 relance |
| Ligne plan | peut manquer (cas Leezr) | **toujours présente** (ou cycle entièrement différé) |
| Paiement | sélection document par document | **1 règlement** de la facture consolidée |
| Dunning/ledger/réconciliation | par document (bruit) | par facture consolidée (+ détail interne par ligne) |
| Vérité read models | fuites (ADR-614) | 1 facture = 1 entrée |
| Mi-cycle | nouveau document immédiat | **ligne proratisée** reportée sur la prochaine facture consolidée (ou ajustement tracé) |
| Blocage plan | contourné par les addons | **cycle bloqué globalement** (plan + modules ensemble) |

**Ce n'est PAS un regroupement graphique** : c'est **un seul document comptable** (un header, N lignes typées, un total HT/TVA/TTC, un solde), avec détail interne conservé par ligne.

---

## 4. Architecture proposée (réutilise BillingCore — pas de nouveau sous-système)

### Entités (réutilisation maximale)
| Concept | Réutilise | Rôle |
|---|---|---|
| **BillingCycle** | `Subscription.interval` + `billing_anchor_day` (existants) | définit période/ancre/devise du cycle |
| **BillingRun** *(nouveau, mince)* | orchestsre `PricingEngine` + `InvoiceIssuer` | **1 passe de consolidation** par (company, devise, cycle, période) → **1 facture** |
| **PriceBreakdown** | **existant** (`PricingEngine::forCurrentPeriod`) | plan + modules + addons + coupon déjà consolidés |
| **Invoice / InvoiceLine** | **existants** | header + N lignes typées (`plan`/`module`/`addon`/`adjustment`/`discount`) |
| **BillableEvent / accrual** *(nouveau, mince)* | table d'événements facturables en attente | mi-cycle : activation/désactivation/prorata **mis en file**, consommés au prochain BillingRun |
| **Snapshots contractuels** | `Subscription.plan_price_cents/source`, `CompanyAddonSubscription.amount_cents` (existants) | prix figés par prestation |
| **Ledger / allocation paiement** | `LedgerService`, `WalletLedger`, `InvoiceBatchPayService` (existants) | 1 écriture par facture + **détail par ligne** possible ; allocation sur la facture consolidée |
| **Idempotence** | clés existantes + `BillingRun` key | `run:{company}:{currency}:{cycle}:{periodStart}` empêche la double facturation |

### Principe de consolidation
> **BillingRun** calcule le `PriceBreakdown` complet (plan + tous les modules/addons du cycle + ajustements en file + remises) et produit **une** facture via `InvoiceIssuer` (déjà capable de N lignes). Le document reste en **draft mutable** pendant la préparation, **finalisé** une seule fois à la frontière de période.

Lignes regroupables **par famille de module** pour la lecture ; **écritures internes détaillées par prestation** (une `InvoiceLine` par module → ledger/analytique par ligne). **135 lignes sur 1 document commercial.**

---

## 5. Invariants financiers (à garantir par tests)

1. **Pas de double facturation** : une prestation facturable n'apparaît qu'une fois par période (clé d'idempotence `BillingRun` + garde « déjà facturé pour la période »).
2. **Document finalisé = immuable** : on ne modifie jamais un `finalized_at` ; les corrections passent par **avoir** ou **ajustement reporté**.
3. **Snapshots historiques** préservés (prix figés par ligne).
4. **Taxes selon le contexte** : `TaxContextResolver` (domestique / reverse-charge intra-UE / export) pilote le **taux par ligne** ; `TaxResolver` applique le mode. Jamais `tax_mode` comme instruction de non-taxation (BILL-011).
5. **Blocage plan respecté** : si le plan est bloqué (prix provisional, ADR-593), **le cycle entier est différé** — **aucun module facturé** (corrige le contournement actuel).
6. **Pas de confirmation implicite** d'un prix provisional.
7. **Aucun renouvellement rétroactif** non autorisé (ADR-575).
8. **Aucun montant calculé par le frontend** (backend fournit HT/TVA/TTC/soldes).
9. **Pas de double paiement** (idempotence par facture + par ligne wallet).
10. **Ajustements traçables** (chaque ajustement = ligne typée `adjustment` + référence événement).
11. **Paiements partiels imputés correctement** (allocation sur la facture consolidée, solde unique).

---

## 6. Événements hors cycle — règles de décision

| Événement | Décision cible |
|---|---|
| Module activé **mi-cycle** | **Ligne proratisée reportée** sur la prochaine facture consolidée (accrual) — **pas** de document immédiat. Exception : si règlement immédiat exigé par contrat → document séparé justifié. |
| Module désactivé mi-cycle | Avoir/prorata **reporté** sur la prochaine facture (crédit ligne) ; jamais de modification du document finalisé. |
| Changement de plan | Prorata via `PlanChangeExecutor` (existant) → ajustement reporté ou document dédié selon timing (immediate vs end_of_period, policy existante). |
| Évolution prix contractuel | Nouveau snapshot ; s'applique **en avant** (next run) — jamais rétroactif. |
| Période suspendue | Cycle non exécuté → pas de facture (cohérent blocage). |
| Addon proratisé | Ligne prorata (périodes de service **explicites** par ligne si différentes). |
| Coupon / remise 1 ligne | Ligne `discount` sur la facture consolidée (portée ligne ou document). |
| Facture déjà finalisée | **Immuable** → correction par avoir ou report ; **aucune modification silencieuse**. |
| Facture partiellement réglée | Solde unique maintenu ; nouveaux ajustements reportés. |
| Avoir émis | `CreditNote` (existant) rattaché à la facture consolidée. |
| Mensuel / annuel | Cycle distinct → **factures distinctes par cycle** (un annuel + un mensuel = 2 factures, légitime). |
| Régimes fiscaux différents par ligne | Autorisé **au sein d'une facture** si même contexte client ; sinon **documents séparés** (obligation fiscale). |
| Multi-devises | **1 facture par devise** (jamais mélangées). |

**Règle générale** : ajustement → **reporté sur la prochaine facture consolidée** par défaut ; **document séparé** uniquement si contrat/devise/fiscalité/client facturé/événement hors cycle l'exige réellement.

---

## 7. Compatibilité des factures historiques (ADR-328)

- **Interdits absolus** : fusionner/supprimer les annexes, effacer des soldes, renuméroter, réémettre, modifier snapshots/paiements/piste d'audit.
- **Coexistence** : introduire un **versionnement du modèle de facturation** — champ `billing_model_version` (ou flag `consolidated`) sur `Invoice`. `v1` = ADR-328 (parent+annexes historiques, lus/payés/réconciliés tels quels). `v2` = consolidé (1 doc, N lignes).
- Les read models, PDF, dunning, paiement **continuent de gérer v1** pour les documents existants (aucune régression) et **produisent v2** pour les nouveaux cycles.
- Aucune migration de données destructive : les 3 documents Leezr Logistics restent intacts.

---

## 8. Plan de migration par lots (conception — non implémenté)

| Lot | Portée | Risque |
|---|---|---|
| **M0** | Versionnement `Invoice.billing_model_version` (migration additive, défaut v1) ; aucun changement de comportement | faible |
| **M1** | `BillingRun` + table `BillableEvent` (accrual) ; **shadow mode** (calcule, ne finalise pas) | faible |
| **M2** | `AddonBillingListener` : mi-cycle → **accrual** au lieu d'annexe (nouveaux cycles v2 seulement) ; **respect du blocage plan** | **élevé** (fiscal/paiement) |
| **M3** | Génération consolidée au renouvellement (déjà proche : `BillingRenewCommand`) → marquée v2 | moyen |
| **M4** | Paiement : `InvoiceBatchPayService` règle la facture consolidée (1 solde) ; v1 inchangé | moyen |
| **M5** | Ledger : écriture par facture + **détail analytique par ligne** | moyen |
| **M6** | Read models Company/Platform : 1 facture consolidée = 1 entrée (v2) ; v1 préservé | moyen |
| **M7** | PDF consolidé multipage (Poppins local conservé) ; v1 garde son rendu | moyen |
| **M8** | Écrans Company/Platform (liste/détail/paiement) adaptés v2 | moyen |
| **M9** | Recette DEV exhaustive (matrice §9) + personas | — |
| **M10** | Déploiement PROD progressif (v2 pour **nouveaux** cycles ; historique v1 intact) + **rollback** (feature flag `billing.consolidated` off → retombe en v1, aucune donnée réécrite) | **élevé** |

**Stratégie de bascule** : flag `billing.consolidated` ; v2 pour les cycles **à partir d'une date d'effet** ; rollback = désactiver le flag (les nouveaux cycles repassent v1), **aucune réécriture rétroactive**.

---

## 9. Matrice de tests (à construire)

Plan seul · plan + 1 module · **plan + 134 modules (1 facture, 135 lignes, 1 solde)** · plan gratuit + addons payants · prix provisional (cycle différé, **0 facture**) · plan bloqué (modules **non facturés**) · addon mi-période (prorata reporté) · remises/coupons (ligne) · plusieurs taux fiscaux · reverse charge · paiement partiel (imputation) · wallet (solde suffisant + action explicite) · échec paiement · dunning (1 relance/facture) · avoir · réconciliation (gap 0) · idempotence (`BillingRun` rejoué → 0 doublon) · **historique ADR-328 v1 intact** · renouvellement mensuel ET annuel (2 factures distinctes).

---

## 10. Risques & arbitrages métier

| Risque / arbitrage | Décision proposée |
|---|---|
| **Avance vs arriéré** : mi-cycle reporté = le client paie l'addon le cycle suivant | Accepter le report (prorata sur prochaine facture) ; document immédiat seulement si contrat l'exige |
| **Respect du blocage plan** change le comportement actuel (addons facturés aujourd'hui malgré blocage) | **Corriger** : plan bloqué → cycle différé. Impacte le CA reconnu tant que les prix provisional ne sont pas confirmés (revue tarifaire). |
| Double modèle v1/v2 (complexité) | Nécessaire pour l'immutabilité historique ; encadré par `billing_model_version` + flag |
| Immutabilité vs consolidation mi-cycle | Résolu par **draft mutable jusqu'à clôture de période** (finalize unique) + accrual |
| 134 lignes = PDF lourd / perf | PDF multipage + regroupement par famille à la lecture ; subset Poppins local conservé |
| Migration PROD | v2 **uniquement pour nouveaux cycles** ; rollback par flag sans réécriture |
| **Cas Leezr Logistics** | Avec v2 + respect du blocage, les 3 factures addon **n'auraient pas dû être créées** (plan bloqué). Historique **laissé intact** ; à régulariser **manuellement** via revue tarifaire (confirmation prix) **sous votre autorisation**, jamais automatiquement. |

---

## Synthèse des 10 livrables
1. **Diagnostic** : fragmentation = immutabilité + événements mi-cycle (AddonBillingListener), pas une limite du modèle. §1
2. **Cause facture Pro absente** : provisional → facturation initiale différée + renouvellement bloqué + chemin addon découplé. §2
3. **Comparaison** actuel/consolidé. §3
4. **Architecture** réutilisant BillingCore (BillingRun + accrual, PricingEngine/InvoiceIssuer existants). §4
5. **Invariants** financiers. §5
6. **Événements hors cycle**. §6
7. **Compatibilité historique** (versionnement v1/v2, ADR-328 intact). §7
8. **Migration** M0→M10 + rollback par flag. §8
9. **Tests**. §9
10. **Risques & arbitrages**. §10

**Rien n'est implémenté.** Prochaine étape, sous votre autorisation : rédiger l'ADR de décision (modèle consolidé v2 + versionnement) puis lancer le lot **M0** (versionnement additif, sans changement de comportement).
