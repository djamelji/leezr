# Contrat de contexte tenant (P0-D / ADR-733)

> Isolation multi-tenant HORS HTTP. `dev @ 90cc59d`. Mécanisme : `app/Core/Tenancy/*`.
> Convention : **[FAIT]** vérifié · **[RISQUE]** identifié non prouvé · **[PRÉVENTIF]** amélioration.

## 1. Cartographie des contextes d'exécution

| Contexte | Binding tenant | Autorité | Statut |
|---|---|---|---|
| **A. HTTP Company-scoped** | `SetCompanyContext:78` (header `X-Company-Id`) → `company.context` bindé, nettoyé en `terminate()` | scope actif | FAIT, sûr |
| **A'. Job/Listener tenant** | `InteractsWithTenant::runInTenant` → `CompanyContext::runFor($companyId)` | scope actif pendant le job | PRÉVENTIF (nouveau socle) |
| **B. Platform global autorisé** | aucun contexte ; permission Platform (`EnsurePlatformPermission`) ; `withoutGlobalScopes()` | filtrage explicite | FAIT, sûr |
| **C. Maintenance cross-tenant** | `CompanyContext::runGlobal()` ou balayage `chunkById` re-filtré par-ligne | explicite | FAIT (commandes billing/leave/docs) |
| **D. Système sans tenant** | migrations, FX, scheduler heartbeat, modèles Platform (`Market`, `FxRate`…) | N/A (non tenant) | FAIT, sûr |

**Hygiène de queue (`QueueTenancyHygiene`)** : avant chaque job → sauvegarde + nettoie le contexte ambiant ; après → restaure. Garantit : aucun contexte résiduel entre jobs, retries sans ancien tenant, requête HTTP qui dispatche en sync récupère son contexte.

## 2. Modèles tenant-scoped

- **69 modèles** avec le trait `BelongsToCompany` (scope auto + auto-fill `company_id`) : Billing (`Invoice`, `Payment`, `Subscription`, `LedgerEntry`, `CompanyWallet`, `CompanyAddonSubscription`…), Workforce (`Employee`, `Shift`, `TimeEntry`, `LeaveRequest`, `PayrollRun`, `EmploymentContract`, `DsnDeclaration`…), Documents, Signature (6), RBAC (`CompanyRole`, `Membership`), Automation (`WorkflowRule`), Audit, Support, Shipment.
- **~39 tables `company_id` SANS le trait** [RISQUE, hors P0-D] : `NotificationEvent`, `EmailLog`, `BillableEvent`, `UsageSnapshot`, `AiRequestLog`, `SecurityAlert`, `WorkforceFavorite`, `DocumentTemplate`, `BillingCoveredInterval`, `BillingRunRecord`… → **aucun filet automatique, même en HTTP** : toute requête DOIT filtrer `company_id` manuellement. À auditer en P0 distinct (lectures sensibles : `NotificationEvent`, `EmailLog`, `BillableEvent`).

## 3. Opérations globales légitimes à PRÉSERVER (balayage multi-tenant, re-filtré par-ligne)

`billing:renew` (`chunkById` + `$sub->company`), `billing:process-dunning`, `billing:expire-trials`, `billing:reconcile` (`detectDrifts($customer->company_id)`), `leave:accrue` (`$cid` explicite), `leave:consume-due`, `documents:*`, `fx:rates-sync`, `alerts:evaluate/escalate`, `usage:collect-snapshots`, `workflow:reset-daily-counters`. **Tous SÛRS par discipline** (filtre explicite / traitement par-ligne). Jobs billing (`ProcessDunningBatchJob` par PK `Invoice`, `ReconcileCompanyJob(companyId:)`, `RenewSubscriptionBatchJob` par IDs) : SÛRS.

## 4. Matrice des risques

| # | Risque | Gravité | Preuve | Correctif P0-D |
|---|---|---|---|---|
| R1 | **Faille inter-tenant réelle** hors HTTP | — | **AUCUNE démontrée** (tous re-filtrent) | — |
| R2 | Asymétrie sync/async : job s'appuyant sur le scope passe les tests, fuite en prod | **ÉLEVÉ (latent)** | `test_scope_is_noop_without_context` | **Hygiène de queue** (job = ardoise vierge, comme async) + **socle `InteractsWithTenant`** + **mode strict** (filet CI) |
| R3 | Contamination entre jobs (futur job bindant le contexte sans nettoyer) | ÉLEVÉ (latent) | `test_no_contamination_between_successive_jobs` | **`QueueTenancyHygiene`** (nettoyage avant/après chaque job) |
| R4 | Chargement non borné tous-tenants en mémoire | ÉLEVÉ (perf, pas fuite) | `DocumentCheckExpirationCommand:63` (`->get()` sans chunk) | **Consigné** (by-line sûr ; chunk à ajouter hors P0-D) |
| R5 | `withoutGlobalScopes()->find($pk)` sans re-filtre | MOYEN | `PayrollCalculationEngine:44`, `YtdCalculator:133` | **Consigné** (sûr si PK vient d'un contexte scoped ; durcissement préventif hors §15) |
| R6 | Tables `company_id` sans trait | MOYEN/ÉLEVÉ | ~39 tables (cf. §2) | **Consigné — P0 distinct** |

## 5. Couverture de tests

- **Testé dynamiquement** (`TenantIsolationP0DTest`, 12 verts) : scope no-op sans contexte · `runFor` isole/nettoie/ré-entrant · `runGlobal` · mode strict (lève / exempt sous runFor / ignore withoutGlobalScopes) · job tenant-aware isole + fail-closed · hygiène nettoie+restaure · aucune contamination.
- **Audité statiquement** : tous les jobs/commands/listeners/notifications (verdict SÛR par re-filtre explicite).
- **Non couvert (consigné)** : worker async réel (Horizon) ; Reverb/broadcasting channels ; les ~39 tables sans trait ; durcissement paie/documents.

## 6. Activation du mode strict (recommandation)

`CompanyContext::$strict = true` interdit toute requête tenant sans contexte (hors `runGlobal`). **Non activable en production** tant que les jobs legacy qui s'appuient sur le no-op (`ProcessDunningBatchJob:45` `Invoice::find` scopé) ne sont pas migrés vers `CompanyContext::runFor` / `runGlobal` / `withoutGlobalScopes`. Chantier de **migration graduelle** recommandé : (1) migrer les jobs tenant vers `InteractsWithTenant`, (2) envelopper les commandes globales dans `runGlobal`, (3) activer le strict en staging puis prod. D'ici là, le strict sert de **filet CI** dans les tests dédiés.
