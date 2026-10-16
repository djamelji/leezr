# Intégration Scheduler ↔ Cockpit Automations — Livrable (octobre 2026)

> Objet : finaliser l'intégration réelle du scheduler Laravel avec `/platform/operations/automations`. Ce document présente l'état vérifié + les durcissements livrés. Référence décisions : ADR-430/431/438, ADR-572 (ce lot).

## 1. Fonctionnement exact du scheduler (vérifié)

Chaîne : **cron OS `schedule:run` (chaque minute)** → `routes/console.php` (20 `Schedule::command`, chacun instrumenté `->when(enabled) ->before/onSuccess/onFailure`) → `SchedulerInstrumentation` crée/clôture un `ScheduledTaskRun` (status running→success/failed, durée, output, erreur) + publie un événement realtime + alerte en cas d'échec → le cockpit lit `ScheduledTaskRun` + `ScheduledTaskRegistry` (santé, prochaine exécution, historique).

Le cockpit **n'invente pas** la liste : le registre `ScheduledTaskRegistry` est le **miroir 1:1** de `routes/console.php` (test anti-dérive `ScheduledTaskRegistryConsistencyTest`), et le frontend ne contient **aucune liste statique** (tout vient de l'API).

## 2. État réel du cron VPS (vérifié par SSH — lecture seule)

**Le cron n'est PAS manquant : il est installé, unique et actif sur staging ET prod.** Le « dead » constaté précédemment était l'environnement **local** (aucun cron en dev — normal).

| | Staging (web3, dev.leezr.com) | Prod (web2, leezr.com) |
|---|---|---|
| `schedule:run` (crontab user, 1×/min) | ✅ présent (unique) | ✅ présent (unique) |
| `schedule:list` | 20 tâches | 20 tâches |
| `ScheduledTaskRun` (total) | 174 (vivant, +13 en 20 min) | **159 186** |
| Dernier run observé | `email:fetch-inbox` success, <1 min | `email:fetch-inbox` success, ~1 min |
| `globalHealth()` | `ok`/`silent` (normal, cf. §6) | `ok` |
| Queue `default` | cron self-healing `pgrep` | cron self-healing `pgrep` |
| Queue `ai` | cron self-healing `pgrep` | **systemd `leezr-queue-ai.service` = active** |

Commande exacte installée (identique staging/prod, chemin réel) :
```
* * * * * /usr/bin/php8.4 /var/www/clients/client1/web{2,3}/current/artisan schedule:run >> .../current/storage/logs/scheduler.log 2>&1
```
PHP CLI : `/usr/bin/php8.4`. Release courante servie via `releases/<ts>_<sha>/` (symlink atomique) → le cron pointe sur `current/` donc **persiste après déploiement**. **Aucun doublon créé** (conformément à la consigne).

## 3. Cockpit centralisé — champs & états

Chaque tâche expose : nom, description (i18n), catégorie, fréquence, cron, **sensible**, **activée**, santé, prochaine exécution, dernière exécution/durée/statut/sortie/erreur, compteurs 24h (succès/échec/total/durée moy.), historique paginé (`/automations/runs`).

États distingués (critère #3) :
| État | Source |
|---|---|
| Scheduler actif | `globalHealth.status = ok` |
| Scheduler silencieux/arrêté | `globalHealth.status = silent` / `dead` |
| Tâche en échec | `health = broken` (dernier run failed) |
| Tâche jamais exécutée | `health = unknown` (aucun run) |
| Tâche désactivée volontairement | `enabled = false` → `health = disabled` |
| Tâche en cours | `last_status = running` |
| Tâche en retard | `health = delayed` (silence > intervalle attendu) |

La santé n'est **pas** déduite de la seule présence du cron : elle vient de signaux persistants (`ScheduledTaskRun`).

## 4. Pilotage sécurisé (livré)

- **Exécution manuelle** (`POST /automations/run`) : dispatch asynchrone (queue), run tracé.
- **Protection des tâches sensibles** (mass-financial : `billing:renew`, `process-dunning`, `expire-trials`, `collect-scheduled`) : exige la permission dédiée **`run_sensitive_automations`** + un flag **`confirm=true`** (dialogue de confirmation côté UI). Jamais déclenchables librement.
- **Anti-double-exécution** : verrou atomique (Cache lock) + refus si un run est déjà en cours (`409 already_running`) ; les tâches planifiées gardent `withoutOverlapping`.
- **Activation/désactivation réelle** (`POST /automations/toggle`) : persistée (`ScheduledTaskState`), le scheduler **saute réellement** la tâche (`->when(SI::enabled)`), le run manuel la refuse (`422 task_disabled`), et le job la saute même si déjà dispatché. Réactiver une tâche **sensible** exige `run_sensitive_automations`.
- **Relance contrôlée** d'une tâche en échec = run-now (re-exécution tracée), soumise aux mêmes gardes.
- **Audit** : chaque run manuel + chaque toggle → `PlatformAuditLog` (acteur, IP, sévérité, raison). Aucun secret exposé.

## 5. Source de vérité unique

`ScheduledTaskRegistry` ⟷ `routes/console.php` en bijection stricte (test anti-dérive conservé). Toute nouvelle `Schedule::command` sans entrée registry (ou l'inverse) **casse le test**. Le frontend ne duplique aucune liste.

## 6. Supervision & alertes (livré via l'Alert Center existant, ADR-438)

`AutomationSupervisor` (appelé par `alerts:evaluate`) lève des `PlatformAlert` dédupliquées, auto-résolues :
- `scheduler_silent` (critique) — `globalHealth = dead`.
- `queue_backlog` (warning) — jobs en attente > seuil (worker down/bloqué).
- `automation_task_failing` (critique) — dernier run échoué + échecs répétés 24h.

Les échecs individuels sont déjà notifiés aux admins plateforme (`SchedulerInstrumentation::onFailure`).

**Nuance honnête** : `globalHealth` oscille `ok`↔`silent` en fonctionnement normal car la tâche la plus fréquente est à 5 min (silence ≤ ~5 min = normal ; `dead` = > 10 min). Un **scheduler totalement mort ne peut pas s'auto-signaler** via `alerts:evaluate` (qui est lui-même planifié) → la détection fiable d'un arrêt total requiert un **moniteur externe** (uptime) frappant un endpoint de santé. Recommandation ops ci-dessous.

## 7. Sécurité & multi-tenancy

Cockpit = fonctionnalité Platform, gardée par `module.active:platform.automations` + `platform.permission:manage_automations` ; actions sensibles par `run_sensitive_automations`. Toutes les actions manuelles sont tracées et protégées contre la concurrence. Aucune donnée Company exposée, aucun secret/credential dans les logs ou réponses.

## 8. Tests effectués

- **Local (suite)** : `AutomationCockpitSecurityTest` (6), `AutomationToggleTest` (8), `AutomationSupervisorTest` (3), `ScheduledTaskRegistryConsistencyTest` (4) — + suite complète verte, build clean.
- **VPS (réel, non-destructif)** : `schedule:list` = registre (20) ; cron unique installé (staging+prod) ; **nouveaux `ScheduledTaskRun` apparaissant en continu** (staging 161→174 observé) ; santé qui évolue (`ok`/`silent`) ; queue AI systemd `active` ; aucune exécution de rattrapage massif déclenchée.

## 9. Opérations VPS restantes

- **Rien d'obligatoire pour le scheduler** : il est installé et vérifié. ✅
- **Recommandé (hors scope code)** : (a) un moniteur **externe** d'uptime (cron OS vivant) car un arrêt total du cron ne peut être auto-détecté ; (b) déployer ce lot (branche `dev`/`main`) pour activer en prod les nouveaux endpoints `toggle` + gardes sensibles (actuellement **code prêt, non déployé** — pas de déploiement sans autorisation explicite).

**Conclusion** : la page `/platform/operations/automations` reflète fidèlement le scheduler réel (historique, santé, pilotage sécurisé). Le cron système est **effectivement installé et vérifié** sur staging et prod.
