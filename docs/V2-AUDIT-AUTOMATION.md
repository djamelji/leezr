# V2-AUDIT-AUTOMATION — Automation Center & Workflow Engine

> Mode : Platform Architect | Audit du scheduler existant et plan pour le workflow engine user-defined

## 1. Contexte actuel

Leezr dispose d'un Automation Center platform-admin (ADR-425, ADR-430, ADR-431) qui fonctionne comme un cockpit de monitoring pour les 14 tâches scheduler Laravel. C'est un outil de **gouvernance et observabilité**, pas un workflow engine. Les automations sont codées en dur dans les commandes Artisan — aucune n'est configurable par les companies.

**Le problème central** : le système actuel est un scheduler monitor, pas un automation engine. Les companies ne peuvent pas créer de workflows custom (trigger → condition → action). C'est un gap produit majeur pour le positionnement SaaS de Leezr.

## 2. État existant

### Backend — Modèles

**AutomationRule** (`app/Core/Automation/AutomationRule.php`, 57 lignes) :
- Table `automation_rules` — champs : `key` (unique), `label`, `description`, `category`, `enabled`, `schedule` (cron), `config` (JSON), `last_run_at`, `next_run_at`, `last_status`, `last_error`, `last_run_duration_ms`, `last_run_actions`
- Relations : `hasMany(AutomationRunLog)`
- Scopes : `active()`, `byCategory(string)`
- **ATTENTION** : Aucun champ `company_id` — les règles sont **platform-wide** uniquement

**AutomationRunLog** (`app/Core/Automation/AutomationRunLog.php`, 34 lignes) :
- Table `automation_run_logs` — champs : `automation_rule_id` (FK), `status` (ok/error/skipped), `actions_count`, `duration_ms`, `error`, `metadata` (JSON)

**ScheduledTaskRun** (`app/Core/Automation/ScheduledTaskRun.php`, 54 lignes) :
- Table `scheduled_task_runs` — champs : `task` (100 chars), `status` (running/success/failed), `started_at`, `finished_at`, `duration_ms`, `output`, `error`, `environment`
- Index : `(task, created_at)`
- Scopes : `task(string)`, `last24h()`, `successful()`, `failed()`

### Backend — AutomationRunner

**AutomationRunner** (`app/Core/Automation/AutomationRunner.php`, 202 lignes) :
Orchestre les règles → commandes Artisan via COMMAND_MAP (14 entrées) :

| Category | Rule Key | Artisan Command |
|----------|----------|-----------------|
| Documents | documents.auto_remind | documents:auto-remind |
| Documents | documents.auto_renew | documents:auto-renew |
| Documents | documents.check_expiration | documents:check-expiration |
| Billing | billing.retry_payment | billing:process-dunning |
| Billing | billing.renew_subscriptions | billing:renew |
| Billing | billing.recover_webhooks | billing:recover-webhooks |
| Billing | billing.recover_checkouts | billing:recover-checkouts |
| Billing | billing.check_expiring_cards | billing:check-expiring-cards |
| Billing | billing.check_trial_expiring | billing:check-trial-expiring |
| Billing | billing.expire_trials | billing:expire-trials |
| Billing | billing.collect_scheduled | billing:collect-scheduled |
| Billing | billing.check_dlq | billing:check-dlq |
| Billing | billing.reconcile | billing:reconcile |
| FX | fx.refresh_rates | fx:refresh |

Méthodes : `runAll()`, `runSingle(rule)`, `isDue(rule)`, `calculateNextRun(schedule)`, `recordRun(...)`.

### Backend — ScheduledTaskRegistry

**ScheduledTaskRegistry** (`app/Core/Automation/ScheduledTaskRegistry.php`, 314 lignes) :
Registre statique des 14 tâches — source de vérité pour métadonnées.

| Task | Frequency | Cron | Expected Interval |
|------|-----------|------|-------------------|
| billing:expire-trials | daily | 0 0 * * * | 1440 min |
| billing:renew | daily | 0 0 * * * | 1440 min |
| billing:process-dunning | daily | 0 0 * * * | 1440 min |
| billing:reconcile | weekly | 0 0 * * 0 | 10080 min |
| billing:recover-webhooks | every10min | */10 * * * * | 10 min |
| billing:recover-checkouts | every10min | */10 * * * * | 10 min |
| billing:check-dlq | hourly | 0 * * * * | 60 min |
| billing:check-expiring-cards | daily | 0 0 * * * | 1440 min |
| billing:check-trial-expiring | daily | 0 0 * * * | 1440 min |
| billing:collect-scheduled | dailyAt06 | 0 6 * * * | 1440 min |
| documents:check-expiration | daily | 0 0 * * * | 1440 min |
| documents:auto-renew | dailyAt08 | 0 8 * * * | 1440 min |
| documents:auto-remind | dailyAt09 | 0 9 * * * | 1440 min |
| fx:rates-sync | every6h | 0 */6 * * * | 360 min |

Health computation : `computeHealth(task, lastRun)` → ok/delayed/broken/unknown. Global scheduler health : `globalHealth(silenceThreshold)` → ok/silent/dead. Queue stats : `queueStats()`.

### Backend — Instrumentation

**SchedulerInstrumentation** (`app/Core/Automation/SchedulerInstrumentation.php`, 194 lignes) :
Hooks before/onSuccess/onFailure pour chaque tâche scheduler. Crée ScheduledTaskRun records, capture output, publie event realtime `automation.run.completed`, dispatch notification alert aux PlatformUsers en cas de failure.

**RunScheduledTaskJob** (`app/Core/Automation/Jobs/RunScheduledTaskJob.php`, 91 lignes) :
Job queue async pour "Run Now" manuel — timeout 300s, tries 1, onQueue('default'). Dispatch depuis AutomationController::run().

### Backend — Scheduled Tasks (routes/console.php)

14 tâches enregistrées, chacune avec :
- `Schedule::command('task:name')->cron('expression')`
- `->withoutOverlapping()`
- `->appendOutputTo(storage_path('logs/scheduler/task-name.log'))`
- `->before(SI::before('task'))->onSuccess(SI::onSuccess('task'))->onFailure(SI::onFailure('task'))`

### Backend — API

| Endpoint | Method | Description |
|----------|--------|-------------|
| GET /platform/automations | GET | Index : summary KPIs + scheduler health + 14 tasks avec health/stats |
| GET /platform/automations/runs | GET | Run history paginé (20/page) par task |
| POST /platform/automations/run | POST | Dispatch task manuellement |

Routes protégées : `module.active:platform.automations` + `platform.permission:manage_automations`

### Frontend — Store

**platformAutomations.store.js** (134 lignes) : State `_summary`, `_schedulerHealth`, `_tasks[]`, `_runs[]`, `_runsPagination`, `_loading`, `_runningTask`. Actions `fetchTasks({ silent })`, `_mergeTasks(incoming)` (smart merge JSON.stringify), `fetchRuns(task, page)`, `runTask(task)`, `_startRunningPoll()` (5s interval), `_stopRunningPoll()`.

### Frontend — Page

**platform/automations/index.vue** (637 lignes) :
1. Header avec chip santé scheduler global
2. KPI Cards 24h (success, failed, avg duration, queue pending) — card-grid-xs
3. Queue Monitor (default/AI pending + failed) — card-grid-xs
4. Tasks VDataTable (14 rows, health/frequency/status chips, play/info actions)
5. Detail Drawer (VNavigationDrawer 520px, stats 24h, last output/error, run history paginé)
6. Realtime : `useRealtimeSubscription('automation.run.completed')` → refresh tasks + drawer

## 3. Problèmes identifiés

### P0 — CRITIQUE

*Aucun P0* — Le scheduler monitor fonctionne correctement.

### P1 — URGENT

**P1-1 : Aucune automation user-defined**
Les companies ne peuvent pas créer d'automations. Le system est 100% platform-admin. C'est un gap produit majeur — les concurrents SaaS offrent des "workflows" configurables.

**P1-2 : AutomationRule sans company_id**
Le modèle AutomationRule n'a pas de champ `company_id`. Il est impossible d'ajouter des règles per-company sans refactorer le modèle.

**P1-3 : Pas de trigger events**
Le system n'a pas de concept de "trigger" (quand un événement se produit, déclencher une action). Tout est basé sur le cron — time-based, pas event-based.

### P2 — AMÉLIORATIONS

**P2-1 : COMMAND_MAP diverge du ScheduledTaskRegistry**
Les clés dans AutomationRunner.COMMAND_MAP ne correspondent pas exactement aux noms dans ScheduledTaskRegistry (ex: `fx:refresh` vs `fx:rates-sync`). Anomalie mineure mais source de confusion.

**P2-2 : Pas de webhook trigger**
Pas de possibilité de déclencher une automation via webhook externe.

**P2-3 : Pas de chaînage**
Pas de possibilité de chaîner des tâches (si A réussit, exécuter B).

## 4. Risques

### Risques techniques
- **Complexité workflow engine** : Construire un vrai workflow engine (trigger → condition → action) est un projet conséquent (estimé 15-20j)
- **Isolation** : Les automations company-scoped doivent être isolées (une company ne peut pas affecter une autre)
- **Performance** : Les automations event-based doivent être asynchrones pour ne pas bloquer le request lifecycle

### Risques produit
- **Différenciation** : Sans automations user-defined, Leezr est "juste un backoffice" — pas un "platform intelligent"
- **Churn** : Les power users demandent des automations custom. Sans elles, ils cherchent des alternatives
- **Upsell** : Les automations sont un levier de monétisation naturel (plan Pro = 5 automations, plan Enterprise = illimité)

## 5. Gaps architecturels

| Gap | Gravité | Existant | Cible |
|-----|---------|----------|-------|
| Automations company-scoped | ÉLEVÉE | 0 | Modèle avec company_id |
| Trigger events | ÉLEVÉE | Aucun | Event listener system |
| Condition evaluator | ÉLEVÉE | Aucun | Rule engine |
| Action executor | ÉLEVÉE | COMMAND_MAP seulement | Action registry extensible |
| Workflow chaînage | MOYENNE | Aucun | DAG simple |
| Webhook triggers | BASSE | Aucun | Endpoint dédié |

## 6. Contrats manquants

### Backend
- **WorkflowRule** (model, company-scoped) : `company_id`, `name`, `trigger_type`, `trigger_config` (JSON), `conditions` (JSON), `actions` (JSON), `enabled`, `max_executions`, `cooldown_minutes`
- **TriggerRegistry** : Map d'événements disponibles par module (document.uploaded, member.joined, invoice.created, etc.)
- **ConditionEvaluator** : Évalue des conditions JSON (field = value, field > value, field in [values])
- **ActionExecutor** : Exécute des actions (send_notification, create_task, update_field, webhook)
- **WorkflowExecutionLog** : Trace chaque exécution (trigger, conditions évaluées, actions exécutées, durée)

### Frontend
- **Workflow Builder** : UI drag-and-drop pour créer des automations (trigger → condition → action)
- **Workflow Templates** : Bibliothèque de workflows pré-configurés par jobdomain
- **Execution History** : Vue des exécutions par company (succès, échecs, logs)

## 7. UX Impact

### Workflow Builder (vision)

```
┌─────────────────────────────────────────┐
│ Quand...                [Trigger]        │
│ ┌─────────────────────────────────────┐  │
│ │ Un document est uploadé             │  │
│ │ Type: Carte d'identité              │  │
│ └─────────────────────────────────────┘  │
│                                          │
│ Si...                   [Conditions]     │
│ ┌─────────────────────────────────────┐  │
│ │ AI confidence > 80%                 │  │
│ │ ET document non expiré              │  │
│ └─────────────────────────────────────┘  │
│                                          │
│ Alors...                [Actions]        │
│ ┌─────────────────────────────────────┐  │
│ │ Approuver automatiquement           │  │
│ │ Notifier le membre                  │  │
│ └─────────────────────────────────────┘  │
└─────────────────────────────────────────┘
```

### Triggers disponibles (par module)

| Module | Trigger | Description |
|--------|---------|-------------|
| Documents | document.uploaded | Un document est uploadé |
| Documents | document.ai_completed | L'analyse AI est terminée |
| Documents | document.expiring | Un document expire dans N jours |
| Members | member.joined | Un nouveau membre rejoint |
| Members | member.profile_updated | Le profil d'un membre est modifié |
| Billing | invoice.created | Une facture est créée |
| Billing | payment.failed | Un paiement a échoué |
| Billing | subscription.expiring | Un abonnement expire dans N jours |
| Shipments | shipment.status_changed | Le statut d'une expédition change |

### Actions disponibles

| Action | Description |
|--------|-------------|
| send_notification | Envoyer une notification in-app |
| send_email | Envoyer un email |
| update_field | Modifier un champ sur l'entité |
| create_task | Créer une tâche/reminder |
| webhook | Appeler un webhook externe |
| approve_document | Approuver un document automatiquement |

## 8. Proposition V2 — Architecture cible

### Phase 1 : Foundation (V2-3)

```php
// WorkflowRule model (company-scoped)
class WorkflowRule extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'trigger_type', 'trigger_config',
        'conditions', 'actions', 'enabled', 'max_executions_per_day',
        'cooldown_minutes', 'last_triggered_at',
    ];

    protected $casts = [
        'trigger_config' => 'array',
        'conditions' => 'array',
        'actions' => 'array',
    ];
}
```

### Phase 2 : Trigger System

```php
// TriggerRegistry — declares available triggers per module
class TriggerRegistry
{
    private static array $triggers = [];

    public static function register(string $eventKey, TriggerDefinition $definition): void
    {
        static::$triggers[$eventKey] = $definition;
    }

    // Called by EventEnvelope after publishing domain event
    public static function evaluate(string $eventKey, array $payload, int $companyId): void
    {
        $rules = WorkflowRule::where('company_id', $companyId)
            ->where('trigger_type', $eventKey)
            ->where('enabled', true)
            ->get();

        foreach ($rules as $rule) {
            ProcessWorkflowJob::dispatch($rule, $payload);
        }
    }
}
```

### Phase 3 : Condition + Action Executors

```php
class ConditionEvaluator
{
    public static function evaluate(array $conditions, array $context): bool
    {
        foreach ($conditions as $condition) {
            $field = data_get($context, $condition['field']);
            $operator = $condition['operator']; // eq, neq, gt, lt, in, contains
            $value = $condition['value'];

            if (! self::check($field, $operator, $value)) return false;
        }
        return true;
    }
}

class ActionExecutor
{
    private static array $handlers = [
        'send_notification' => SendNotificationAction::class,
        'send_email' => SendEmailAction::class,
        'update_field' => UpdateFieldAction::class,
        'webhook' => WebhookAction::class,
    ];
}
```

## 9. Règles non négociables

1. **Le scheduler existant NE CHANGE PAS** — les 14 tâches platform restent identiques. Le workflow engine est un ajout, pas un remplacement
2. **Les workflows company sont ISOLÉS** — BelongsToCompany obligatoire, aucun workflow ne peut affecter une autre company
3. **Les actions sont ASYNCHRONES** — exécutées dans des jobs queue, jamais dans le request lifecycle
4. **Les triggers sont DÉCLARATIFS** — chaque module déclare ses triggers dans son manifest, pas de trigger "magic"
5. **Le quota est PAR PLAN** — Free = 0, Starter = 3 workflows, Pro = 10, Enterprise = illimité
6. **L'exécution est LOGGÉE** — chaque trigger/condition/action est tracé pour debug et audit

## 10. Plan d'implémentation

| Phase | Scope | Effort | Dépendance |
|-------|-------|--------|------------|
| Phase 1 | WorkflowRule model + migration | 1j | V2-AUDIT-TENANCY (BelongsToCompany) |
| Phase 2 | TriggerRegistry + EventEnvelope hook | 2j | Phase 1 |
| Phase 3 | ConditionEvaluator + ActionExecutor | 2j | Phase 2 |
| Phase 4 | API CRUD workflows + quota enforcement | 1.5j | Phase 3 |
| Phase 5 | Frontend Workflow Builder (basic) | 3j | Phase 4 + presets Vuexy |
| Phase 6 | Workflow Templates par jobdomain | 1j | Phase 5 |
| Phase 7 | Execution History + monitoring | 1.5j | Phase 4 |
| **Total** | | **12j** | |

## 11. Impacts sur autres modules

- **Documents** : Déclare triggers `document.uploaded`, `document.ai_completed`, `document.expiring`. Expose action `approve_document`
- **Members** : Déclare triggers `member.joined`, `member.profile_updated`. Expose action `send_welcome_email`
- **Billing** : Déclare triggers `invoice.created`, `payment.failed`, `subscription.expiring`. Expose action `send_dunning_email`
- **Shipments** : Déclare triggers `shipment.created`, `shipment.status_changed`. Expose action `notify_recipient`
- **Support** : Déclare triggers `ticket.created`, `ticket.updated`. Expose action `auto_assign`
- **Scheduler existant** : Inchangé. Les 14 tâches platform coexistent avec les workflows company

## 12. Dépendances avec autres audits

- **V2-AUDIT-TENANCY** : Les WorkflowRule doivent utiliser BelongsToCompany. Dépendance directe sur le trait
- **V2-AUDIT-RBAC** : Nouvelle permission `automations.manage` par company. Le RBAC frontend doit gater l'accès au workflow builder
- **V2-AUDIT-REALTIME** : Les triggers s'appuient sur les mêmes événements que le SSE (EventEnvelope). Le hook TriggerRegistry.evaluate() est appelé dans EventEnvelope::publish()
- **V2-AUDIT-AI-ENGINE** : L'AI peut être une action dans un workflow (ex: "Quand un document est uploadé, lancer l'analyse AI"). Le ProcessDocumentAiJob est déjà en place
- **V2-AUDIT-MULTI-MARKET** : Les workflows sont company-scoped, donc market-aware par héritage. Les templates de workflows peuvent varier par market

---

> **Verdict** : L'Automation Center existant est **solide comme cockpit de monitoring**. Le gap est le workflow engine user-defined — un projet de 12 jours qui transforme Leezr d'un "backoffice" en une "plateforme intelligente". C'est un levier de monétisation et de différenciation majeur, à planifier pour V2-4.
