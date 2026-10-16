# Audit Finance & Facturation — Leezr (octobre 2026)

> Audit lecture seule du domaine Billing SaaS (`app/Core/Billing/**` ~90 classes + scheduler + cockpit automations). Facturation de l'**abonnement SaaS** (plans/wallet/dunning/essais) — la facturation *client* n'existe pas (cf. ADR-563). Déclencheur : incohérences constatées + **périodes d'essai bloquées**. Chaque constat est adossé à une preuve (fichier:ligne ou mesure runtime). Confirmé vs hypothèse distingués.

## 0. Réponse directe : pourquoi les essais sont « bloqués »

**Chaîne causale confirmée :**
1. **Le scheduler n'a jamais tourné** dans l'environnement observé : `ScheduledTaskRun` = **0 exécution**, `ScheduledTaskRegistry::globalHealth()` = **`dead`** (mesuré en local). Le cockpit `/platform/operations/automations` afficherait « scheduler mort ».
2. → `billing:expire-trials` (planifié `routes/console.php:78`, logique **correcte** — dry-run : « Found 1 expired trial #2 ») **n'a jamais été exécuté** → l'essai « Trial Logistics » est gelé en `status='trialing'` avec `trial_ends_at=2026-05-23` (dépassé de ~5 mois), `is_current=1`.
3. **Mais même expiré, ça ne changerait rien** : le **statut d'abonnement ne gate aucun accès** (seul `company.status='suspended'` bloque). L'essai « bloqué » garde en réalité **tout l'accès**.

**Donc « bloqué » = essai gelé par absence de scheduler, pas un verrou d'accès.** Le vrai problème produit est double : (a) le scheduler ne tournait pas (ops), (b) le concept d'essai n'a aucune application réelle (ni blocage, ni conversion forcée).

## 1. Cockpit Automations — ne centralise PAS tout (ton intuition confirmée)

`/platform/operations/automations` repose sur `ScheduledTaskRegistry` (liste **statique** maintenue à la main) + `ScheduledTaskRun` (historique) + `globalHealth()`. **Il ne lit PAS la vraie planification** (`routes/console.php`, source de vérité réelle via le cron `schedule:run`). Deux sources désynchronisées :

- `routes/console.php` : **20** `Schedule::command(...)`.
- `ScheduledTaskRegistry::TASKS` : **18** entrées (commentaire périmé « 14 »).
- **Absentes du cockpit (invisibles + non pilotables)** : `alerts:escalate`, `registration:detect-abandoned`.
- « Run now » fonctionne (`RunScheduledTaskJob` → `Artisan::call`).

**Incohérence P1** : le cockpit se présente comme central mais 2 tâches planifiées y échappent, et il ne garantit pas que ce qu'il affiche tourne réellement (c'est le cron OS qui exécute). Un ajout dans `console.php` sans ajout dans le registry = tâche fantôme non supervisée.

## 2. Cycle de vie Essai / Abonnement — incohérences

`Subscription` : 9 statuts (`pending_payment,pending,trialing,active,past_due,suspended,cancelled,expired,rejected`), transitions gardées (`Subscription.php:24-99`), invariant `is_current` (l.37).

| ID | Gravité | Constat | Preuve |
|----|---------|---------|--------|
| T1 | **P0** | **Le statut d'abonnement ne gate aucun accès.** Seul `company.status='suspended'` bloque (403). Essai expiré/gelé, `past_due`, `pending`, `expired`, `cancelled` → accès complet maintenu. | `SetCompanyContext.php:33` ; `EntitlementResolver.php:42` (gate sur `plan_key`, jamais sur le statut d'abo) |
| T2 | **P0 (ops)** | **Essai gelé en `trialing`** car scheduler non exécuté (0 run, health=dead). Le `WHERE` d'expire-trials est correct. | `ScheduledTaskRun`=0 (mesuré) ; `BillingExpireTrialsCommand.php:26-31` ; dry-run OK |
| T3 | **P1** | **Le dunning saute `trialing`** (`where('status','active')`) en contradiction avec l'en-tête `DunningEngine` qui annonce « active/trialing → past_due ». Un essai payant impayé n'est jamais relancé ni suspendu. | `DunningTransitioner.php:42-46` vs `DunningEngine.php:24,38` |
| T4 | **P1** | **Deux notions divergentes d'« essai en cours »** : `scopeTrialing`/`scopeCurrent` ignorent la date ; `isTrialing()` vérifie `trial_ends_at->isFuture()`. | `Subscription.php:202,216` vs `:223-228` |
| T5 | **P2** | **Facture de renouvellement orpheline** : `renew` (planifié avant `expire-trials`) peut créer une facture `open` sur un essai que `expire-trials` passe ensuite à `expired`. | `routes/console.php:22` vs `:78` ; `BillingRenewCommand.php:312-315` |
| T6 | **P2** | **Méthodes `markActive/markPastDue/...` jamais appelées** (code mort) ; les chemins réels font des `update(['status'=>...])` directs qui contournent le point d'entrée canonique. | `DunningTransitioner.php:72,85` |

**Conversion essai→payant (trou)** : `trial_requires_payment_method=false` → en fin d'essai sans carte, l'auto-charge échoue → facture `open`, l'abonnement **reste `trialing`** (`BillingRenewCommand.php:312-315`), le dunning l'ignore (T3) → seul `expire-trials` peut le sortir, et il ne tournait pas (T2). **Gel permanent.**

## 3. Intégrité Finance — incohérences

| ID | Gravité | Domaine | Preuve | Impact |
|----|---------|---------|--------|--------|
| F1 | **P1** | Grand livre | `recordPaymentReceived` appelé **uniquement** par `StripeEventProcessor.php:165` (grep confirmé) | Paiements hors Stripe (internal, wallet-dunning, auto-charge SEPA, pay-now admin) : AR posé à l'émission, **jamais soldé par CASH** → AR s'accumule, trial balance faux |
| F3 | **P1** | Grand livre / TVA | `LedgerService.php:33,55-65,231` | REVENUE = TTC ; **aucun compte de TVA** → CA surévalué du montant de TVA, TVA collectée jamais isolée |
| F4 | **P1** | Idempotence ledger | `LedgerService.php:74` ; `BillingRecoverWebhooksCommand.php:111-118` | Pas de garde d'unicité sur le posting ; la recovery appelle `process()` hors dédup `WebhookEvent` → **double-posting** possible (scénario, non observé) |
| F2/F10 | **P1 (latent)** | Devise / pricing | `Plan.php`, migration `plans:17-18`, `BillingMetricsCalculationService.php:70` | Table `plans` **sans colonne devise** (cents EUR implicites) + libellé multi-devise → **prix identique quelle que soit la devise**. Latent tant que seul FR/EUR actif. MRR EUR codé en dur |
| F8 | **P2** | « Forensics » trompeur | `FinancialForensicsService.php:22-155` | Ne fait qu'agréger une timeline ; **aucune détection d'anomalie ni réconciliation** → F1/F3/F4 jamais détectés |
| F9 | **P2** | Réconciliation partielle | `ReconciliationEngine.php:39,119-121` | Réconcilie **uniquement Stripe** ; paiements `internal`, solde wallet, équilibre ledger non vérifiés |
| F5/F6/F7 | **P2** | Seeders incohérents | `BillingDemoSeeder.php:186-258` vs `FinanceDemoSeeder.php:148-193` | Deux conventions ledger (HT/TTC) divergentes + factures `paid` avec `amount_due≠0` → dashboards financiers de démo faux |
| F12 | **P2** | Numérotation | `InvoiceNumbering.php:36-41` | Compteur global jamais remis à 1 à l'année alors que le format embarque l'année (pas de trou/collision, juste non conforme) |

## 4. Ce qui est solide
- **Wallet ledger** (`company_wallet_transactions`) : immuable (append-only, pas d'`updated_at`), recalcul du solde sous `lockForUpdate` avec auto-heal du `cached_balance`, idempotence par contrainte unique DB, atomicité. **La partie la mieux faite.**
- **Dédup webhook HTTP** : contrainte unique `provider_key+event_id`, dead-letter, retour 200 systématique (`PaymentWebhookController.php:64-114`).
- **Numérotation factures** sans trou (verrou + consommation intra-transaction) ni collision.
- **Pricing centralisé** (`PricingEngine`, ADR-324), proration déterministe « company-favorable », appels provider hors transaction (pas de charge sans commit).
- **Cents partout** (entiers), conversions centralisées.
- **Dunning** : retry sous verrou, intervalles configurables, write-off au max, réactivation bornée.

## 5. Priorités de remédiation (quand tu valideras — hors audit)
1. **OPS d'abord (débloque les essais)** : garantir l'exécution du cron `schedule:run` sur dev/prod (le cockpit doit afficher `health=ok`, pas `dead`). Puis lancer une fois `billing:expire-trials` + `billing:renew`. **C'est le correctif immédiat du symptôme.**
2. **Décider le modèle d'essai (T1)** : soit l'essai expiré **bloque** réellement (gate sur statut d'abo dans `SetCompanyContext`/entitlement), soit on assume le non-blocage — mais la cohérence produit l'exige.
3. **Centraliser les schedulers (cockpit)** : source unique (générer le registry depuis `console.php` ou l'inverse) + ajouter `alerts:escalate` et `registration:detect-abandoned`.
4. **Dunning sur `trialing`** (T3) : aligner code et doc (relancer/suspendre un essai payant impayé, ou documenter l'exclusion).
5. **Grand livre (F1/F3/F4)** : poster CASH pour tous les paiements (pas seulement Stripe), isoler la TVA, garde d'idempotence sur le posting — **avant** d'utiliser le ledger comme référence comptable.
6. **Devise (F2/F10)** : ajouter une devise au plan (ou acter mono-devise) **avant** tout 2ᵉ marché non-EUR.
7. **Seeders (F5/F6/F7)** : aligner sur la convention de production.

## Verdict
Le **cœur transactionnel** (wallet, webhooks, numérotation, pricing, dunning) est solide. Les incohérences se concentrent sur : (a) **l'exécution/supervision du scheduler** (cause directe des essais bloqués + cockpit non central), (b) **le concept d'essai non appliqué** (statut sans effet, dunning qui saute l'essai), (c) **le grand livre double-entrée non fiable** (AR non soldé hors Stripe, TVA non isolée), (d) **le mono-devise latent**. Aucune perte d'argent silencieuse côté wallet ; le risque principal est **comptable/reporting** (ledger) et **produit** (essai sans effet).
