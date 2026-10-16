# Anomalie « Prochaine facture estimée : 31 mai 2026 » — Investigation complète

> Déclencheur : `owner@leezr.test`, page `/company/billing/plan`, le 16/10/2026 → « Prochaine facture estimée : 31 mai 2026 » (≈4,5 mois dans le passé). Investigation remontant toute la chaîne, correction à la source, **sans réécriture d'écriture comptable**.

## 1. Donnée réelle (PROD, lecture seule)

Company #1 « Leezr Logistics » — `status=active`, `market=FR`, `plan=pro`. **1 abonnement** :

| champ | valeur |
|---|---|
| status | `active` |
| **is_current** | **NULL** ⚠️ |
| current_period_start → end | **2026-05-01 → 2026-05-31 23:59:59** (passé) |
| trial_ends_at | — |
| factures | **0** |

NOW = 2026-10-16. Le `current_period_end` est figé à mai 2026.

## 2. Chaîne technique (cartographie)

`subscriptions.current_period_end` → `CompanyBillingReadService::nextInvoicePreview()` **l.394** (`'next_billing_date' => $subscription->current_period_end?->toDateString()`, émis **verbatim**, sans clamp ni calcul) → route `GET /billing/next-invoice-preview` (`CompanyBillingController@nextInvoicePreview`) → store `billing.store.js::fetchNextInvoicePreview` → `_BillingPlan.vue:287` `nextBillingDate: p.next_billing_date` → `_BillingPlan.vue:811` `formatDate(...)` (aucun clamp front).

La page affiche donc **la valeur stockée telle quelle**.

## 3. Cause racine (démontrée)

**Double cause, même origine :**

1. **État incohérent de l'abonnement** : `status=active` **avec `is_current=NULL`**. L'invariant « actif ⇒ is_current=1 » n'est **pas** enforced (`Subscription::guardInvariants` ne vérifie que l'inverse : is_current=1 ⇒ statut autorisé).
2. **Renouvellement jamais exécuté sur cet abo** : `BillingRenewCommand` sélectionne `whereIn(status,[active,trialing])->where('is_current',1)->where('current_period_end','<=',now())` (l.58-61). **`is_current=NULL` ⇒ l'abo est exclu à jamais** → `current_period_end` n'est jamais avancé → reste figé à `endOfMonth()` du jour du seed (mai 2026).

**Origine de l'état incohérent = SEEDER** : `DevSeeder.php:176-185` (et `BillingDemoSeeder.php:72-81`) créaient l'abonnement `active` avec `current_period_end = now()->endOfMonth()` **sans définir `is_current`** → défaut NULL. La donnée PROD « Leezr Logistics » provient de ce seed (exécuté en mai 2026).

**Incohérence secondaire (chemins divergents)** : le chemin **lecture** (`nextInvoicePreview`) sélectionne l'abo **sans** filtrer `is_current` (il le voit), alors que le chemin **renouvellement** exige `is_current=1` (il l'ignore). Lecture et renouvellement ne s'accordent pas sur la notion d'« abonnement courant ».

Parmi les 6 hypothèses initiales : **(2) état incohérent + (4) renouvellement jamais exécuté + (6) mauvaise interprétation de « prochaine facture »** (un `current_period_end` passé présenté comme estimation future). Ce n'est **pas** un simple problème d'affichage ou de fuseau.

## 4. Les 4 notions à distinguer (exigence produit)

| Notion | Source de vérité |
|---|---|
| Facture déjà émise | ligne `invoices` (statut `paid/open/overdue`) |
| Échéance passée | `invoice.due_at` < now **ou** renouvellement en retard |
| **Prochaine facture estimée** | `current_period_end` **si futur** ; sinon = renouvellement **en attente** |
| Action de paiement attendue | facture `open/overdue` à régler |

## 5. Corrections (sans réécriture comptable)

1. **Source (seeders)** : `is_current => 1` ajouté aux abonnements actifs seedés (`DevSeeder.php`, `BillingDemoSeeder.php`) → l'abo devient **courant et renouvelable**. Les futurs seeds ne reproduisent plus le gel.
2. **Logique (read service)** : `nextInvoicePreview` expose désormais `renewal_overdue` (= `current_period_end->isPast()`) + `subscription_status`. La **source de vérité (date) est conservée** telle quelle — **aucune date future fabriquée** (consigne respectée : on n'ajoute pas un mois pour masquer).
3. **UI** (`_BillingPlan.vue`) : si `renewal_overdue`, affiche **« Renouvellement en attente »** (i18n `companyPlan.renewalPending`) au lieu d'une date passée étiquetée « prochaine facture estimée ». Sinon, la date (future) est affichée normalement.

**Aucune facture ni écriture historique modifiée.** La donnée PROD de l'abonnement reste intacte ; sa régularisation est une **action ops traçable** documentée ci-dessous (non exécutée automatiquement sur l'environnement partagé).

## 6. Tests de non-régression

- `BillingNextInvoicePreviewTest::test_future_period_end_is_not_renewal_overdue` + `::test_past_period_end_is_flagged_renewal_overdue` (la date passée reste la source de vérité **et** est flaggée `renewal_overdue`, jamais présentée comme estimation future).
- `SubscriptionRenewalEligibilityTest` : prouve le mécanisme racine — un abo actif `is_current=NULL` est **sauté** par `billing:renew` (période figée) ; `is_current=1` est **avancé**.

## 7. Régularisation PROD restante (ops — non exécutée)

L'abonnement PROD « Leezr Logistics » (#1) est en état incohérent (`active` + `is_current=NULL`). **Régularisation traçable** à exécuter sur autorisation (pas d'opération massive ni réécriture comptable) :
```php
// 1 abo démo, correction d'état (PAS une écriture comptable) :
$s = \App\Core\Billing\Subscription::where('company_id',1)->where('status','active')->whereNull('is_current')->first();
$s?->update(['is_current' => 1]);   // le rend éligible au renouvellement
// puis, sous supervision : php artisan billing:renew  (rattrape la période ; crée la facture due si plan payant)
```
**Résultat attendu** : `is_current=1` ; au prochain `billing:renew`, période avancée (plan gratuit) ou facture de renouvellement émise (plan payant) → « prochaine facture » redevient cohérente. Tant que non régularisé, l'UI affiche désormais honnêtement **« Renouvellement en attente »** (plus de date passée trompeuse).

## 8. Gravité

**P1** — information de facturation trompeuse (date passée présentée comme future) sur une page client, + état d'abonnement incohérent empêchant le renouvellement. Pas de perte d'argent silencieuse (aucune facture erronée émise), mais parcours facturation incohérent. Corrigé à la source + logique + UI + tests.
