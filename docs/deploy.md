# Deployment — Leezr (ADR-076)

## Architecture

```
GitHub push (main ou dev)
        │
        ▼
GitHub Actions (CI)
  ┌─────────────────┐
  │  Build job       │
  │  - composer      │
  │  - pnpm install  │
  │  - pnpm build    │
  │  - tar artifact  │
  └────────┬────────┘
           │ artifact (tar.gz)
           ▼
  ┌─────────────────┐
  │  Deploy job      │
  │  - SCP artifact  │
  │  - SSH deploy    │
  │  - Health check  │
  └────────┬────────┘
           │
           ▼
VPS (deploy_release.sh)
  unpack → link shared → migrate → optimize → switch symlink
```

**Principe** : zéro build sur le VPS. L'artifact contient tout (vendor/, public/build/).

---

## Environnements

| Branche | Environnement | Chemin VPS | URL |
|---------|---------------|------------|-----|
| `dev` | staging | `/var/www/clients/client1/web3` | https://dev.leezr.com |
| `main` | production | `/var/www/clients/client1/web2` | https://leezr.com |

---

## Structure VPS

```
/var/www/clients/client1/web2/     (ou web3)
├── releases/
│   ├── 20260218120000_abc1234/    ← release précédente
│   └── 20260218130000_def5678/    ← release courante
├── shared/
│   ├── .env                        ← configuration persistante
│   ├── storage/                    ← logs, cache, uploads
│   │   ├── app/public/
│   │   ├── framework/
│   │   │   ├── cache/
│   │   │   ├── sessions/
│   │   │   └── views/
│   │   └── logs/
│   │       └── deploy.log          ← journal de déploiement
│   └── .deploy.lock                ← flock anti-concurrence
├── current → releases/20260218130000_def5678
└── web → current/public             ← DocumentRoot Apache
```

---

## Secrets GitHub Actions

Configurer dans **Settings → Secrets and variables → Actions**.

### Secrets (repository-level)

| Secret | Description |
|--------|-------------|
| `VPS_HOST` | Adresse IP ou hostname du VPS |
| `VPS_SSH_KEY` | Clé SSH privée (format PEM, commence par `-----BEGIN`) |
| `VPS_USER` | Utilisateur SSH (ex: `root` ou `web2`) |

### Variables d'environnement (par Environment)

Créer 2 environments dans **Settings → Environments** : `staging` et `production`.

| Variable | staging | production |
|----------|---------|------------|
| `APP_PATH` | `/var/www/clients/client1/web3` | `/var/www/clients/client1/web2` |
| `DEPLOY_URL` | `https://dev.leezr.com` | `https://leezr.com` |
| `VPS_PORT` | `22` (ou autre) | `22` (ou autre) |

---

## Préparation VPS (une seule fois)

### 1. Créer la structure de répertoires

```bash
APP_PATH="/var/www/clients/client1/web2"  # ou web3

mkdir -p "$APP_PATH/releases"
mkdir -p "$APP_PATH/shared/storage/app/public"
mkdir -p "$APP_PATH/shared/storage/framework/cache"
mkdir -p "$APP_PATH/shared/storage/framework/sessions"
mkdir -p "$APP_PATH/shared/storage/framework/views"
mkdir -p "$APP_PATH/shared/storage/logs"
```

### 2. Copier le .env

```bash
# Copier le .env.production.example comme base, remplir les valeurs
cp .env.production.example "$APP_PATH/shared/.env"
nano "$APP_PATH/shared/.env"
```

Variables **obligatoires** dans `shared/.env` :
- `APP_KEY` — générer avec `php artisan key:generate --show`
- `APP_URL` — `https://leezr.com` ou `https://dev.leezr.com`
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `MAIL_*` (SMTP credentials)
- `SANCTUM_STATEFUL_DOMAINS`
- `SESSION_DOMAIN`

### 3. Permissions

```bash
# Le user PHP-FPM (web2 ou www-data) doit pouvoir écrire dans releases/ et shared/
chown -R web2:client1 "$APP_PATH/releases" "$APP_PATH/shared"
chmod -R ug+w "$APP_PATH/shared/storage"
```

### 4. SSH key pour GitHub Actions

```bash
# Sur votre machine locale
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/leezr_deploy

# Copier la clé publique sur le VPS
ssh-copy-id -i ~/.ssh/leezr_deploy.pub root@YOUR_VPS_IP

# Copier la clé privée dans GitHub Secrets > VPS_SSH_KEY
cat ~/.ssh/leezr_deploy
```

### 5. Sudoers pour PHP-FPM reload (si SSH user != root)

```bash
# /etc/sudoers.d/deploy
web2 ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm
```

---

## Commandes

### Vérifier la release courante

```bash
readlink -f /var/www/clients/client1/web2/current
# → /var/www/clients/client1/web2/releases/20260218130000_def5678
```

### Lister les releases

```bash
bash /var/www/clients/client1/web2/current/deploy/rollback.sh \
  /var/www/clients/client1/web2 --list
```

### Rollback (release précédente)

```bash
bash /var/www/clients/client1/web2/current/deploy/rollback.sh \
  /var/www/clients/client1/web2
```

### Rollback (release spécifique)

```bash
bash /var/www/clients/client1/web2/current/deploy/rollback.sh \
  /var/www/clients/client1/web2 20260218120000_abc1234
```

### Consulter les logs de déploiement

```bash
tail -100 /var/www/clients/client1/web2/shared/storage/logs/deploy.log
```

### Déclencher un re-deploy manuellement

Depuis GitHub : **Actions → Build & Deploy → Run workflow** (ou re-push sur la branche).

---

## Troubleshooting

### Le deploy ne se déclenche pas

1. Vérifier que le push est sur `main` ou `dev`
2. Vérifier **Actions → Build & Deploy** pour le statut du workflow
3. Vérifier les secrets : `VPS_HOST`, `VPS_SSH_KEY`, `VPS_USER` sont configurés
4. Vérifier les environment variables : `APP_PATH`, `DEPLOY_URL` pour chaque environment

### Le build échoue

- `composer install` : vérifier `composer.lock` est commité
- `pnpm install` : vérifier `pnpm-lock.yaml` est commité
- `pnpm build` : erreur Vite → reproduire localement avec `pnpm build`

### Le deploy échoue (SSH)

```
Permission denied (publickey)
```
→ Vérifier que `VPS_SSH_KEY` contient bien la clé privée complète (y compris les lignes BEGIN/END).
→ Vérifier que la clé publique est dans `~/.ssh/authorized_keys` du `VPS_USER` sur le VPS.

### Le deploy échoue (script)

- Consulter `shared/storage/logs/deploy.log`
- Le script s'arrête au premier échec (`set -euo pipefail`)
- Le symlink current n'est PAS modifié → ancien code toujours live

### Migrations échouent

```bash
# Se connecter en SSH, aller dans la release problématique
cd /var/www/clients/client1/web2/releases/XXXXXXXXXX
php artisan migrate:status
php artisan migrate --force
```

### Chunks 404 après deploy

Impossible avec ce système : l'artifact contient `public/build/` cohérent avec le code backend. Le switch symlink est atomique — les deux changent en même temps.

### PHP-FPM cache stale

```bash
sudo systemctl reload php8.4-fpm
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### OPcache stale (page blanche)

```bash
sudo systemctl reload php8.4-fpm
# OU
sudo systemctl restart php8.4-fpm
```

### Permissions

```bash
# Si erreur "Permission denied" sur storage ou bootstrap/cache
chown -R web2:client1 /var/www/clients/client1/web2/shared/storage
chmod -R ug+w /var/www/clients/client1/web2/shared/storage
```

---

## Ancien système (webhook)

L'ancien système (`public/webhook.php` + `deploy.sh`) est **déprécié**. Il reste dans le repo pour référence mais n'est plus utilisé. Le nouveau système (GitHub Actions) le remplace complètement.

Pour désactiver l'ancien webhook :
1. Supprimer le webhook dans GitHub Settings → Webhooks
2. (Optionnel) Supprimer `public/webhook.php` du repo

---

## Garanties

| Garantie | Comment |
|----------|---------|
| Zéro build sur VPS | pnpm build uniquement dans GitHub Actions |
| Atomique | Symlink switch via `mv -Tf` (syscall rename) |
| Idempotent | Relancer N fois = même résultat |
| Rollback instantané | `bash rollback.sh APP_PATH` (symlink switch) |
| Pas de partial deploy | Si une étape échoue avant switch → ancien code reste live |
| Chunks cohérents | public/build/ dans le même artifact que le backend |
| Anti-concurrence | flock empêche 2 deploys simultanés |
| Traçabilité | deploy.log + GitHub Actions logs |
