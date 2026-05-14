# DSN Gateway — Runbook Production

> Phase 7–8 — Sprint 7.5 (ADR-533), Sprint 8.3 (ADR-536)
> Dernière mise à jour : 2026-05-08

## Table des matières

1. [Architecture gateway](#architecture-gateway)
2. [Validation sandbox (Sprint 8.3)](#validation-sandbox)
3. [Checklist activation production](#checklist-activation-production)
4. [Procédures opérationnelles](#procédures-opérationnelles)
5. [Sécurité](#sécurité)
6. [Fallback et rollback](#fallback-et-rollback)
7. [Observabilité](#observabilité)
8. [Commandes ops](#commandes-ops)

---

## Architecture gateway

```
DsnGatewayManager
├── NullDsnGateway        (driver: null — dry-run, auto-accept)
├── FileDsnGateway        (driver: file — write to disk, no transmission)
└── NetEntreprisesDsnGateway (driver: net-entreprises — réel)
    ├── NetEntreprisesClientInterface (injectable)
    │   ├── FakeNetEntreprisesClient (tests, 7 scénarios)
    │   └── NetEntreprisesHttpClient (Phase 8 — réel, HTTP+XML)
    └── DsnCredentialService (PlatformSetting.dsn chiffrés)
```

**Kill-switch** : `config('workforce.dsn.submit_enabled')` = false → NullDsnGateway actif quelle que soit la config driver.

**State machine** :

| Gateway response | gateway_status | DsnDeclaration.status | Action |
|:---|:---|:---|:---|
| AEE (submit success) | aee_received | submitted | Schedule poll |
| ARE (submit rejection) | — | exported (inchangé) | DomainException |
| CCO (poll accepted) | cco_accepted | accepted | Store report |
| BAN (poll rejected) | ban_rejected | rejected | Store error |
| EN_COURS (poll pending) | pending | submitted (inchangé) | Schedule next poll |
| Timeout 72h | poll_timeout | rejected | markRejected |

---

## Validation sandbox

> Sprint 8.3 — Procédure complète de validation avant passage en production.

### Prérequis sandbox

1. Credentials sandbox configurés dans PlatformSettings.dsn :
   - `ne_siret` = SIRET de test (fourni par NE portail bac à sable)
   - `ne_nom`, `ne_prenom` = identifiant déclarant test
   - `ne_password` = mot de passe sandbox (chiffré en DB)
   - `ne_environment` = `sandbox`
2. `config('workforce.dsn.gateway_driver')` = `net-entreprises`
3. URLs pointent vers `test-services.net-entreprises.fr` / `test-dsnrg.net-entreprises.fr`

### Procédure de validation sandbox

```bash
# Étape 1 : Vérifier la santé gateway
php artisan dsn:gateway-health --metrics
# → environment=sandbox, endpoints=consistent, credentials=green

# Étape 2 : Dry-run sur une déclaration exportée
php artisan dsn:sandbox-submit <ID> --dry-run
# → Affiche payload hash, file path, endpoints, timeout, retry config
# → Aucun appel HTTP

# Étape 3 : Submit réel vers sandbox NE
php artisan dsn:sandbox-submit <ID>
# → Authentification NE sandbox
# → Dépôt gzippé → AEE reçu (idFlux)
# → Status: SUBMITTED

# Étape 4 : Polling sandbox
php artisan dsn:poll-pending --dry-run
# → Vérifie que la déclaration est éligible au poll

php artisan dsn:poll-pending
# → Poll NE sandbox → CCO (accepted) ou BAN (rejected)

# Étape 5 : Vérification des retours
php artisan dsn:inspect <ID>
# → Vérifier technical_receipt_path (AEE archivé)
# → Vérifier business_report_path (CCO/BAN archivé)
# → Vérifier gateway_status, gateway_metadata
```

### Critères de succès sandbox (GO)

- [ ] `dsn:gateway-health` = GREEN avec environment=sandbox
- [ ] `dsn:sandbox-submit --dry-run` affiche les bonnes infos sans erreur
- [ ] `dsn:sandbox-submit` réussit avec AEE reçu (SUBMITTED)
- [ ] Polling transforme en ACCEPTED (CCO) ou REJECTED (BAN) selon le scénario
- [ ] Retours XML archivés dans `workforce/dsn/returns/`
- [ ] Aucun secret dans les logs, audit trail, ou CLI output
- [ ] `dsn:gateway-health --metrics` montre les compteurs cohérents

### Critères de blocage sandbox (NO-GO)

- Credentials invalides (format ou auth échouée)
- Endpoints mismatch (sandbox env avec URLs prod)
- Transport errors récurrents (réseau, timeout)
- Secrets dans les rawResponse ou metadata audit

---

## Checklist activation production

### Phase 1 : Prérequis techniques

- [ ] **Sandbox validée** — tous les critères GO ci-dessus sont passés
- [ ] **HTTP client opérationnel** — NetEntreprisesHttpClient testé en sandbox ✓
- [ ] **XML returns archivés** — AEE/CCO/BAN stockés sur disque ✓
- [ ] **Tests automatisés** — 2800+ tests verts ✓
- [ ] **Runbook lu et compris** par l'opérateur

### Phase 2 : Configuration production

- [ ] **Credentials NE production** configurés dans PlatformSettings.dsn
  - `ne_siret` (14 digits, Luhn valid, SIRET réel entreprise)
  - `ne_nom`, `ne_prenom` (déclarant réel inscrit sur net-entreprises.fr)
  - `ne_password` (mot de passe production, chiffré en DB)
  - `ne_environment` = `production`
- [ ] **URLs production** dans config/workforce.php :
  - `ne_auth_url` = `https://services.net-entreprises.fr/authentifier/1.0/`
  - `ne_deposit_url` = `https://dsnrg.net-entreprises.fr/deposer-dsn/1.0/`
  - `ne_status_url` = `https://dsnrg.net-entreprises.fr/consulter-retour/1.0/`
- [ ] **Service code** = `25` (production, pas 97)
- [ ] **Gateway driver** = `net-entreprises`
- [ ] **dsn:gateway-health** retourne GREEN avec environment=production, endpoints=consistent
- [ ] **dsn:check-credentials** valide les formats

### Phase 3 : Activation progressive

```bash
# 1. Activer submit (config ou PlatformSetting)
# config/workforce.php → submit_enabled = true

# 2. Vérifier health check
php artisan dsn:gateway-health --metrics

# 3. Premier submit avec supervision manuelle
# → Surveiller les logs, audit trail, gateway_status
# → Vérifier AEE reçu → poll → CCO/BAN

# 4. Activer le cron polling
# → dsn:poll-pending dans routes/console.php (déjà en place)
```

### Phase 4 : Monitoring post-activation

- [ ] **Cron scheduler** actif : `dsn:poll-pending` tourne toutes les 5 min
- [ ] **dsn:gateway-health --metrics** vérifié quotidiennement la première semaine
- [ ] **Alertes configurées** pour gateway_errors ≥ 5 en 24h
- [ ] **Rollback plan** compris (voir section ci-dessous)

---

## Procédures opérationnelles

### Déclaration rejetée (BAN)

```bash
# 1. Identifier la déclaration
php artisan dsn:inspect <ID>

# 2. Analyser l'erreur
# gateway_error_code + gateway_error_message dans l'output

# 3. Corriger la cause (données payroll, format DSN, etc.)

# 4. Reset pour re-soumission
php artisan dsn:retry-rejected <ID> --force

# 5. Régénérer le fichier DSN
php artisan dsn:regenerate <ID>

# 6. Re-soumettre via le flux normal (UI ou CLI)
```

### Polling timeout (72h sans réponse)

```bash
# 1. Vérifier la déclaration
php artisan dsn:inspect <ID>
# gateway_status = poll_timeout, status = rejected

# 2. Vérifier le portail Net-Entreprises manuellement
# (la déclaration peut être acceptée côté NE mais le polling a échoué)

# 3. Si acceptée côté NE, accepter manuellement via tinker ou future commande

# 4. Si toujours en cours côté NE, retry
php artisan dsn:retry-rejected <ID> --force
php artisan dsn:regenerate <ID>
```

### Credentials invalides

```bash
# 1. Diagnostiquer
php artisan dsn:check-credentials
php artisan dsn:gateway-health

# 2. Mettre à jour via PlatformSettings (UI admin ou tinker)
# Les credentials sont chiffrés automatiquement (Crypt::encryptString)

# 3. Re-vérifier
php artisan dsn:check-credentials
```

### Gateway en erreur (errors_total élevé)

```bash
# 1. Health check
php artisan dsn:gateway-health --metrics

# 2. Vérifier les audit logs
php artisan dsn:inspect <LAST_FAILED_ID>

# 3. Si erreur réseau/transport → les déclarations seront retryées automatiquement
# (backoff exponentiel : 15min → 30min → 60min)

# 4. Si erreur persistante → désactiver temporairement
# config/workforce.php → submit_enabled = false
# (NullDsnGateway prend le relais, auto-accept)
```

---

## Sécurité

### Garanties anti-fuite de secrets

1. **Audit logs** : aucun credential dans les metadata audit (vérifié par tests)
2. **Gateway rawResponse** : filtré par `NetEntreprisesDsnGateway::filterSecrets()` — supprime `password`, `token`, `jeton`, `motdepasse`, `ne_password`, `secret`, `credential`
3. **CLI output** : les commandes masquent les passwords (`str_repeat('*', 8)`)
4. **Exceptions** : les RuntimeException/DomainException ne contiennent jamais de credentials
5. **DB** : `PlatformSetting.dsn.ne_password` est chiffré via `Crypt::encryptString`

### Champs sensibles (NEVER in logs/output)

- `ne_password`
- `password`
- `token` / `jeton`
- `motdepasse`
- `secret`
- `credential`

---

## Fallback et rollback

### Rollback vers NullGateway (urgence)

```bash
# Option 1 : désactiver le submit (immédiat, pas de redéploiement)
# Modifier PlatformSetting ou config :
config('workforce.dsn.submit_enabled', false)
# → Toute soumission utilise NullDsnGateway (auto-accept, pas de transmission)

# Option 2 : changer le driver
# config/workforce.php → dsn.gateway_driver = 'null'
# Nécessite redéploiement
```

### Rollback vers FileGateway (traçabilité sans transmission)

```bash
# config/workforce.php → dsn.gateway_driver = 'file'
# → Les fichiers DSN sont écrits sur disque mais pas transmis
# → Utile pour vérifier le contenu avant activation NE
```

### Déclarations bloquées en submitted

```bash
# Si des déclarations restent en submitted après rollback :
# Le polling continuera à tourner mais NullGateway auto-accept

# Pour forcer l'acceptation :
# 1. Le kill-switch submit_enabled=false fait que poll() via NullGateway auto-accept
# 2. Ou attendre le timeout 72h → rejected → retry-rejected
```

---

## Observabilité

### Métriques disponibles (DsnMetricsService)

| Métrique | Description |
|:---|:---|
| `dsn_declarations_submitted_total` | Nombre total de déclarations soumises |
| `dsn_declarations_accepted_total` | Nombre total de déclarations acceptées |
| `dsn_declarations_rejected_total` | Nombre total de déclarations rejetées |
| `dsn_poll_attempts_total` | Somme des tentatives de polling |
| `dsn_gateway_errors_total` | Déclarations avec erreur gateway |
| `dsn_average_acceptance_delay_minutes` | Délai moyen soumission → acceptation |

### Health check (DsnGatewayHealthCheck)

| Check | Green | Yellow | Red |
|:---|:---|:---|:---|
| environment | production | sandbox | — |
| endpoints | URLs consistent with env | — | Mismatch env/URLs |
| submit_enabled | Activé | Désactivé | — |
| credentials | Présents + valides | — | Absents ou invalides |
| gateway_driver | net-entreprises | null/file | — |
| last_submit | Submit récent | Aucun submit | — |
| recent_errors | 0 erreurs 24h | 1-4 erreurs | ≥5 erreurs |
| pending_polls | 0 overdue | 1-9 overdue | ≥10 overdue |

---

## Commandes ops

| Commande | Description |
|:---|:---|
| `dsn:gateway-health [--metrics]` | Status santé gateway + métriques optionnelles |
| `dsn:check-credentials` | Valide les credentials NE (format, présence) |
| `dsn:poll-pending [--limit=50] [--dry-run]` | Poll les déclarations dues |
| `dsn:retry-rejected <ID> [--force]` | Reset rejected → exported pour re-soumission |
| `dsn:sandbox-submit <ID> [--dry-run]` | Submit vers sandbox NE uniquement (Sprint 8.3) |
| `dsn:regenerate <ID> [--dry-run]` | Régénère le fichier DSN depuis le payroll run |
| `dsn:inspect <ID>` | Affiche le détail d'une déclaration + audit trail |
| `dsn:validate <ID>` | Valide la structure DSN (NEODES) |
