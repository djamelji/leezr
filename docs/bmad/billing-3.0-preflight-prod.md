# BILLING 3.0 — PRÉFLIGHT PRODUCTION (lecture seule)

> Date : 2026-10-26 · PROD servi `1097d8a` · candidat `a6a767d` (dev). **Inspection PROD en LECTURE SEULE — aucune donnée modifiée, aucune opération de facturation, aucune valeur de secret affichée.**

---

## 1. Préflight PROD (lecture seule)

| Contrôle | Résultat PROD | Verdict |
|---|---|---|
| SHA servi | `releases/20261024191921_1097d8a` = `main` | ✅ conforme |
| Release précédente (rollback) | `…_8573ba8` (+ antérieures) disponibles | ✅ rollback possible |
| Migrations en attente | **0** | ✅ |
| Changement de schéma | **aucun** (candidat = 0 migration) | ✅ |
| `BILLING_DRIVER` / provider | **`null`** (NullPaymentGateway) | ⚠️ paiement en ligne non actif |
| Secrets Stripe (`STRIPE_*`) | runtime `stripe_secret_set=0` | ⚠️ non configurés |
| `BILLING_METRICS_TOKEN` | `metrics_token_set=0` | ⚠️ export Prometheus 403 (heartbeats en DB/UI OK) |
| Webhooks Stripe | n/a (driver null) | ⚠️ à configurer si Stripe activé |
| Scheduler | cron `* * * * * artisan schedule:run` **actif** | ✅ |
| Queue worker | cron self-heal `queue:work --queue=default` **actif** (DB queue) | ✅ |
| Redis | `PONG` disponible (queue/cache = `database`) | ✅ (billing n'exige pas redis) |
| Markets / TVA / devises | 2 markets, FR `vat_rate_bps=2000` (20 %), EUR | ✅ |
| Abonnements | 3 total, 2 courants | ✅ |
| **Prix provisional/null (courants actifs)** | **2** | ⚠️ entreront en revue tarifaire (ADR-593) — **par conception** |
| Factures | 4 finalisées, **0 void** — immuables (`billing_snapshot`) | ✅ |
| Dead-letters pending | 0 | ✅ |
| Heartbeats | 6 jobs, **tous `status=ok`** (recover-* 3 min, check-dlq 23 min, renew/dunning 21 h, reconcile 1 j) | ✅ automations saines |
| Compatibilité données existantes | code forward-only, fallback `plan_price_cents` legacy | ✅ |

**Synthèse** : PROD sain. Infra (scheduler/worker/redis) opérationnelle. **Aucune migration, aucun schéma à changer.** Le billing tourne sur le provider `null` (pas de paiement en ligne) ; Stripe n'est **pas** configuré.

---

## 2. Vérification Stripe

- **Stripe n'est PAS actif en PROD** : `billing.driver=null`, `STRIPE_*` absents. Aucun circuit paiement/webhook réel en production aujourd'hui.
- **Déployer Billing 3.0 avec `driver=null` est SÛR** : le gating de capacité (ADR-623) masque correctement tout paiement en ligne pour `internal`/`null`. Aucune régression.
- **Aucun test de paiement réel joué** (pas de clés, et interdit). Si/quand Stripe est activé : configurer `STRIPE_KEY/SECRET/WEBHOOK_SECRET`, enregistrer l'endpoint webhook, puis valider en **mode test Stripe sur un environnement isolé** — hors périmètre de ce déploiement. **Clés/endpoints PROD non modifiés.**

---

## 3. Sécurité du déploiement

### Diff `1097d8a → a6a767d`
- **63 fichiers, +3103/−202, 0 migration.** Répartition : 42 billing (app), 4 Console/Commands (heartbeats LOT 5), 2 i18n, 22 tests, 5 docs.
- Fichiers hors nom « billing » mais **tous du cycle billing** : `ModuleCatalogReadModel` (addon pricing), `CompanySubscriptionAdminController` (cancelPreview), `resources/fonts/poppins/*` (PDF), `useMarketFormatting.js`, `company/modules/index.vue`. **Aucun changement d'un domaine non lié.**

### Effets de bord jobs/événements
- Les 4 commandes nouvellement instrumentées (collect-scheduled, expire-trials, check-trial-expiring, check-expiring-cards) **enregistrent désormais un heartbeat** (bénin, lecture/écriture sur table heartbeat uniquement). Aucun nouveau job mutant, aucun événement financier nouveau.
- `cancelPreview` ne fait plus d'erreur fatale (lisait une classe inexistante) → appel read-only de la policy.

### Rollback
- **0 migration ⇒ rollback = repointage du symlink `current` sur `…_8573ba8`** (release précédente, présente). Aucune réversion de schéma/donnée nécessaire.

### Contrôles de santé post-déploiement
1. SHA servi PROD = `a6a767d` (lecture symlink `current`).
2. `migrate:status` → **toujours 0 pending**.
3. Cockpit Platform charge ; KPI « renouvellements bloqués » = **2** (les 2 subs provisional).
4. Les 4 nouveaux heartbeats commencent à enregistrer (collect-scheduled 06:00, les 3 autres quotidiens) sous 24 h, `status=ok`.
5. Aucune nouvelle facture `void` / aucun avoir / aucune régularisation apparus spontanément.
6. Log `billing` : aucune erreur fatale (notamment `cancelPreview`).

### Indicateurs / logs à surveiller
- Heartbeats (onglet recovery) : tous `ok`, `last_finished_at` récents.
- Dead-letters pending (doit rester 0).
- Réconciliation : gaps = 0.
- Log channel `billing` (storage/logs).

### Procédure ANTI-rétroactif (impératif)
- **Ne PAS** lancer `billing:renew` manuellement après déploiement — laisser le scheduler (ADR-575 empêche la facturation rétroactive).
- **Ne PAS** confirmer les prix provisional automatiquement — la revue tarifaire est **manuelle avec preuve** (ADR-593). Les 2 subs PROD restent non facturées tant que non confirmées.
- **Ne PAS** régulariser les factures historiques (immuables).

### Garde-fou recette (triple sécurité — vérifié)
1. Code : `if (App::environment('production')) return;` dans `BillingRecetteSeeder::run()`.
2. `BillingRecetteSeeder` **non référencé** dans `DatabaseSeeder` → jamais lancé par un `db:seed` global.
3. PROD `APP_ENV=production` → le garde se déclenche même si invoqué manuellement.
⇒ **Le seeder de recette ne peut JAMAIS modifier les données financières PROD.**

---

## 4. Décision finale GO / NO-GO

| Prérequis | Preuve | Risque | GO ? |
|---|---|---|---|
| 0 migration / 0 schéma | `git diff` + `migrate:status` PROD = 0 pending | nul | ✅ |
| Rollback trivial | release `8573ba8` présente, pas de migration | nul | ✅ |
| Diff = cycle billing only | catégorisation 63 fichiers | nul | ✅ |
| Infra PROD (scheduler/worker/redis) | crontab + ps + PONG | nul | ✅ |
| Heartbeats sains | 6 jobs `ok`, cadences OK | nul | ✅ |
| Factures historiques immuables | `billing_snapshot`, 0 void | nul | ✅ |
| Prix provisional non confirmés auto | ADR-593 + 2 subs resteront bloquées | nul (conception) | ✅ |
| Seeder inoffensif en PROD | triple garde vérifiée | nul | ✅ |
| Pas de traitement rétroactif | ADR-575/593 + procédure | nul | ✅ |
| Suite tests | 3224 ✅ / 1 ❌ non-billing / 12 skip env | faible (dette hors billing) | ✅ |
| Paiement en ligne (Stripe) | driver=null, non configuré | **inchangé** par ce deploy | ⚠️ hors périmètre |
| Export Prometheus | `BILLING_METRICS_TOKEN` absent | faible (heartbeats UI OK) | ⚠️ optionnel |

### VERDICT : **GO (déploiement du code Billing 3.0)** — risque financier **FAIBLE**

Justification : aucune migration, aucun changement de schéma, rollback trivial, aucun traitement rétroactif (ADR-575/593), provider `null` (aucun prélèvement possible), seeder incapable de toucher PROD, factures historiques immuables. Le déploiement est **sûr et réversible**.

**Notes post-déploiement (NON bloquantes)** :
1. Le **paiement en ligne reste inactif** (driver `null`) — inchangé par ce déploiement ; l'activation de Stripe est une **décision/config séparée** (clés + webhook + validation mode test sur env isolé).
2. **2 abonnements provisional** apparaîtront en « renouvellements bloqués » / revue tarifaire — comportement **attendu** ; à confirmer **manuellement avec preuve** quand l'équipe le décidera (jamais auto).
3. Optionnel : définir `BILLING_METRICS_TOKEN` pour l'export Prometheus.

---

## Plan de promotion (PRÉPARÉ — NON EXÉCUTÉ, en attente d'autorisation explicite)

```bash
# 1. Promotion main (fast-forward only — garantit l'absence de divergence)
git checkout main
git merge --ff-only a6a767d          # échoue si divergence → sécurité
git push origin main                  # déclenche le workflow de déploiement PROD (web2/leezr.com)

# 2. Contrôles post-déploiement (lecture seule)
#    - SHA servi PROD == a6a767d
#    - php artisan migrate:status  → 0 pending
#    - cockpit : KPI renouvellements bloqués == 2
#    - heartbeats == ok

# 3. Rollback si besoin (aucune migration à réverser)
#    repointer le symlink current sur releases/..._8573ba8 (procédure déploiement atomique)
```

**Aucune commande ci-dessus n'est exécutée sans autorisation explicite de l'utilisateur.**
