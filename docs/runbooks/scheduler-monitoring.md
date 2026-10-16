# Runbook — Surveillance externe du scheduler Laravel

> Un scheduler **complètement arrêté** ne peut pas lever sa propre alerte (l'évaluateur d'alertes est lui-même planifié). Ce runbook décrit la surveillance **indépendante**, sans dépendance payante ni nouveau service lourd.

## 1. Signal de vie (déjà livré, en code)

- Commande `scheduler:heartbeat` (chaque minute, via le `schedule:run` existant) → table `scheduler_heartbeats` (1 ligne, `beat_at`).
- `ScheduledTaskRegistry::globalHealth()` : `ok` (≤180 s) / `silent` (180–300 s) / `dead` (>300 s) / `unknown` (aucun heartbeat — jamais promu `ok`).

## 2. Endpoint de santé externe (sécurisé, grâce au démarrage)

`GET /health/scheduler`. **Contrat HTTP (pour le moniteur) :**

| HTTP | `status` | `healthy` | Signification | Action moniteur |
|------|----------|-----------|---------------|-----------------|
| 200 | `ok` / `silent` | `true` | Heartbeat récent → cron actif | — |
| 200 | `starting` | `false` | Aucun heartbeat **encore**, mais dans la fenêtre de grâce post-déploiement (par défaut 600 s) | **ne pas alerter** (transitoire) |
| 503 | `dead` | `false` | Heartbeat périmé (>300 s) **ou** aucun heartbeat après la grâce (scheduler jamais démarré) | **ALERTER** |
| 404 | — | — | Endpoint désactivé (pas de token) ou token invalide | configuration |

Un état `unknown` (aucun heartbeat) **n'est jamais présenté comme sain** : il est `starting` (200, `healthy:false`) pendant la grâce, puis `dead` (503) ensuite.

**Sécurité** : token obligatoire, comparé en temps constant (`hash_equals`), jamais journalisé côté application. **Désactivé (404) tant qu'aucun token n'est configuré** → jamais exposé en clair.
```
# .env serveur (staging + prod) :
SCHEDULER_MONITOR_TOKEN=<valeur_aléatoire_longue>   # openssl rand -hex 32
# (optionnel) SCHEDULER_HEARTBEAT_GRACE_SECONDS=600
```
Appel — **préférer l'en-tête** (le query-string `?token=` peut apparaître dans les logs d'accès Apache) :
```
curl -s -o /dev/null -w "%{http_code}" -H "X-Monitor-Token: $TOKEN" "https://leezr.com/health/scheduler"
# repli (si le moniteur n'accepte que l'URL) : ...?token=$TOKEN
```

## 3. Option A — moniteur d'uptime externe (recommandé)

Configurer un moniteur **hors VPS** (offre gratuite suffisante — ex. UptimeRobot/Healthchecks/BetterStack free, ou un moniteur déjà en place) :
- URL : `https://leezr.com/health/scheduler?token=<TOKEN>` (prod), `https://dev.leezr.com/health/scheduler?token=<TOKEN>` (staging).
- Intervalle : 5 min.
- **Condition d'alerte** : code HTTP ≠ 200 **sur 2 vérifications consécutives** (~10 min) → absorbe un blip de déploiement (fenêtre `unknown`/redémarrage).
- Succès : 200 attendu en régime normal ; une alerte signifie heartbeat > 5 min = cron arrêté.

## 4. Option B — watchdog on-VPS (indépendant du scheduler Laravel)

Si aucun moniteur externe n'est disponible, un **cron séparé** (indépendant de `schedule:run`) qui alerte si le heartbeat est périmé. Survit à un `schedule:run` cassé (mais pas à un arrêt du démon cron lui-même → l'option A reste préférable).

Script `/usr/local/bin/leezr-scheduler-watchdog.sh` :
```bash
#!/usr/bin/env bash
set -euo pipefail
TOKEN="<TOKEN>"
URL="https://leezr.com/health/scheduler?token=${TOKEN}"
CODE=$(curl -sk -o /dev/null -w "%{http_code}" "$URL" || echo 000)
if [ "$CODE" = "503" ]; then
  echo "$(date -Is) scheduler DEAD (HTTP 503)" | mail -s "[Leezr] Scheduler arrêté" ops@leezr.com
fi
```
Crontab (utilisateur applicatif ou root), **toutes les 5 min**, décalé pour éviter la minute de déploiement :
```
*/5 * * * * /usr/local/bin/leezr-scheduler-watchdog.sh >/dev/null 2>&1
```

## 5. Faux positifs de déploiement

- Staging : `migrate:fresh` vide `scheduler_heartbeats` → `starting` (grâce, par défaut 600 s) → endpoint **200** (pas d'alerte). Le heartbeat revient en < 1 min. Si le scheduler ne démarrait jamais, le statut passerait `dead` (503) après la grâce → alerte.
- Prod : `migrate --force` **préserve** la table → reste `ok`.
- Les deux options exigent 2 échecs consécutifs avant d'alerter → une fenêtre de redéploiement (< 5 min) n'alerte jamais.

## 6. Opérations restantes (à faire sur le serveur)

1. Générer un token et l'ajouter à `shared/.env` (staging + prod) : `SCHEDULER_MONITOR_TOKEN=…` puis `php artisan config:cache`.
2. **Déployer** ce lot (le code de l'endpoint doit être en ligne — actuellement commits locaux non déployés).
3. Configurer l'option A (externe) ou B (watchdog) ci-dessus.
4. Vérifier : `curl .../health/scheduler?token=…` → 200 ; simuler un arrêt (désactiver temporairement la ligne cron `schedule:run` sur un env de test) → 503 après 5 min → alerte reçue → réactiver.

**Condition de réussite** : une absence d'exécution du cron au-delà du délai configurable (défaut 5 min) est détectée par un canal **indépendant** du scheduler, sans endpoint non sécurisé ni faux positif de déploiement.
