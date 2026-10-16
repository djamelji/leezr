# Runbook — Certificats SSL Let's Encrypt (dev & prod)

> Référence d'infrastructure standard. Décision : **ADR-568**. Objectif : renouvellement **automatique et fiable, sans intervention manuelle**, en dev comme en prod.

## 0. ✅ RÉSOLU 2026-10-16 — renouvellement durable rétabli (via CI root)

**Problème** : `dev.leezr.com` expiré depuis le 12/05/2026 (`ERR_CERT_DATE_INVALID`). Diagnostic root (via CI, qui a `sudo` sans mot de passe) :
- Les certificats **des sites** sont émis par **`acme.sh`** (pas certbot — certbot ne sert que `panel`/`webmail`).
- **Aucun alias ACME global** en `conf-enabled` → le challenge HTTP-01 n'était servi en statique nulle part (le docroot route vers Laravel) → acme.sh échouait à renouveler.

**Fix appliqué** (`scripts/ssl-ops-dev.sh` via workflow `SSL Ops (dev)`) :
1. Alias ACME **global** `/etc/apache2/conf-available/000-acme-global.conf` : sert `/.well-known/acme-challenge/` en statique depuis `/var/www/acme-global/` pour **tous** les vhosts (`a2enconf` + reload). *(bénéficie aussi à prod/leezr.com.)*
2. Ré-émission `acme.sh --issue -d dev.leezr.com -w /var/www/acme-global --server letsencrypt`.
3. Installation au chemin ISPConfig (`.../web3/ssl/dev.leezr.com-le.{crt,key}`) + `reloadcmd systemctl reload apache2`.

**Résultat vérifié (externe)** : cert Let's Encrypt valide **16/10/2026 → 14/01/2027**, `ssl_verify=0`.
**Durabilité vérifiée** : cron `acme.sh --cron` quotidien (01:48) actif ; dev enregistré avec `Le_Webroot=/var/www/acme-global` (webroot qui marche) → **renouvellement auto le 16/12/2026**. L'alias global persiste → plus d'expiration.

**Limite (edge case)** : si ISPConfig force une ré-émission avec le webroot du site (routé vers l'app), elle échouerait ; le renouvellement de routine (cron acme.sh) utilise le webroot global et fonctionne. Robustesse maximale = aussi via le toggle panneau (§4).

## 1. Architecture réelle

| Élément | Valeur |
|---|---|
| Serveur web | **Apache** (pas Nginx) piloté par **ISPConfig** |
| Émission / renouvellement | **acme.sh exécuté en root par ISPConfig** (tâche quotidienne). Recharge Apache via le hook ISPConfig standard. |
| Comptes SSH de déploiement | `jliouidevleezr` (web3 → dev), `jliouiprodleezr` (web2 → prod) — **sans sudo** (ne peuvent PAS renouveler ni recharger Apache) |
| Panneau | `https://panel.novamoov.com` (admin) — gère le SSL/Let's Encrypt par site |
| Emplacement certs | `/var/www/clients/client1/web<N>/ssl/<domaine>-le.crt` / `-le.key` (owned root) |
| Vhosts | `/etc/apache2/sites-available/<domaine>.vhost` (exclut déjà `.well-known` de la réécriture ISPConfig) |

## 2. Cause racine de l'incident 2026-05/07 (dev.leezr.com)

Le challenge **ACME http-01** (`http://<domaine>/.well-known/acme-challenge/<token>`) doit être servi **en fichier statique** depuis le docroot. Le `.htaccess` Laravel déployé (`public/.htaccess`) routait tout vers `index.php` → le token renvoyait un **404 applicatif** → validation Let's Encrypt en échec → **non-renouvellement → expiration** (12 mai 2026).

## 3. Fix durable (dans le code — ADR-568 + renforcement oct 2026)

Deux niveaux de protection, déployés à chaque release :

**(a)** `public/.htaccess`, en tête du bloc `mod_rewrite` :
```apache
# Let's Encrypt / ACME http-01 challenge — TOUJOURS servir en statique
RewriteRule ^\.well-known/acme-challenge/ - [L]
```

**(b) Renforcement (indispensable)** — un `.htaccess` dédié dans **`public/.well-known/.htaccess`** + le répertoire physique `public/.well-known/acme-challenge/.gitkeep` :
```apache
RewriteEngine Off            # coupe net la réécriture Laravel pour ce sous-arbre
Options -MultiViews -Indexes
<IfModule mod_mime.c>
    ForceType text/plain
</IfModule>
```

> **Pourquoi (b) est nécessaire** : la règle (a) seule ne suffisait pas sur ce vhost ISPConfig — le challenge tombait quand même sur le front-controller Laravel (→ 404 applicatif → certbot en échec → cert dev expiré). Le `.htaccess` le plus profond l'emporte : `RewriteEngine Off` dans `public/.well-known/` garantit que les jetons certbot sont servis tels quels. Le répertoire physique (`.gitkeep`) assure que le chemin existe après déploiement.

→ Une fois **déployé** (push → CI), l'application n'intercepte plus jamais le challenge. Le cron certbot d'ISPConfig (root, quotidien) peut alors valider et renouveler automatiquement.

**⚠️ Activation** : ce fix prend effet **au prochain déploiement** (push `dev`). Pour un certificat **déjà expiré**, soit on attend le prochain passage du cron certbot (qui réussira une fois le challenge servi), soit on force une ré-émission immédiate via le panneau ISPConfig (§4).

## 3bis. ⚠️ CONSTAT 2026-10-16 — le fix `.htaccess` est INSUFFISANT sur ce vhost ISPConfig

Après déploiement du fix `.htaccess` (§3) sur dev et **vérification en conditions réelles** :
un fichier **existant** sous `/.well-known/acme-challenge/` (testé avec `.gitkeep`) est **toujours
routé vers Laravel** (réponse 404 applicative avec cookies `leezr-session`), au lieu d'être servi
en statique. Donc le challenge HTTP-01 échoue encore → **certbot ne peut pas renouveler**.

**Cause racine (diagnostiquée, lecture seule)** : le vhost ISPConfig route les requêtes via un
layout `DocumentRoot → symlink public/` + `RewriteRule ^(.*)$ public/$1`. Le `RewriteCond
!^/.well-known/` présent dans le vhost **n'aboutit pas** à un service statique sur ce layout ;
les fichiers du challenge n'atteignent jamais le disque avant le front-controller. Les `.htaccess`
applicatifs (niveau docroot **et** niveau `.well-known/`) ne peuvent pas court-circuiter ce routage.

**Conséquence** : le renouvellement SSL de dev **n'est PAS réparable au niveau applicatif/dépôt**.
Il nécessite une action **root / panneau ISPConfig** (§4). Le fix `.htaccess` (§3) reste committé —
il est correct et utile sur un Apache/nginx standard, mais **ne suffit pas** sur ce vhost ISPConfig.

**Fix autoritatif = mécanisme natif ISPConfig** (ISPConfig gère alors lui-même le challenge au
niveau vhost, indépendamment de l'app) : voir §4 option A (toggle Let's Encrypt via le panneau).

## 4. Remise en état d'un certificat expiré + réparation du renouvellement (nécessite root ou panneau)

### Option A — Panneau ISPConfig (recommandé, sans ligne de commande)
1. `https://panel.novamoov.com` → **Sites** → `dev.leezr.com`.
2. Onglet **Domaine** : **décocher** `SSL` et `Let's Encrypt` → **Enregistrer**. Attendre ~1 min (job ISPConfig).
3. **Re-cocher** `SSL` + `Let's Encrypt` → **Enregistrer**. ISPConfig relance acme.sh, ré-émet le certificat et recharge Apache.
4. Vérifier (§6).

### Option B — En root (SSH root ou clé de déploiement)
```bash
# ISPConfig / acme.sh
/root/.acme.sh/acme.sh --renew -d dev.leezr.com --force
# puis recharge (ISPConfig le fait normalement seul ; sinon)
systemctl reload apache2
```
> Ne PAS lancer un `certbot` indépendant : ISPConfig gère déjà acme.sh — deux clients ACME concurrents cassent la validation.

## 5. Renouvellement automatique — vérification

Le renouvellement est déjà automatisé par ISPConfig (acme.sh root, quotidien, reload Apache inclus). Pour **confirmer qu'il fonctionnera avant expiration** :

```bash
# En root sur le VPS — test à blanc (n'émet rien) :
/root/.acme.sh/acme.sh --renew -d dev.leezr.com --dry-run
/root/.acme.sh/acme.sh --renew -d leezr.com   --dry-run
# Lister l'état / dates de renouvellement acme.sh :
/root/.acme.sh/acme.sh --list
# Vérifier que le hook de reload Apache est bien configuré :
crontab -l | grep acme        # tâche quotidienne acme.sh
```

Un `--dry-run` qui réussit garantit que la prochaine émission réelle aboutira.

## 6. Vérification / supervision (depuis n'importe quel poste, sans SSH)

```bash
# Date d'expiration d'un domaine :
echo | openssl s_client -servername dev.leezr.com -connect 213.32.20.37:443 2>/dev/null \
  | openssl x509 -noout -enddate

# HTTPS OK ?
curl -sS -o /dev/null -w "%{http_code} ssl_verify=%{ssl_verify_result}\n" https://dev.leezr.com

# Challenge ACME servi en statique (doit renvoyer Apache 404, PAS de cookie Laravel) :
curl -sI http://dev.leezr.com/.well-known/acme-challenge/test | grep -iE "server|set-cookie"
```

**Seuil d'alerte** : `notAfter` à moins de **20 jours** → investiguer (le renouvellement automatique agit normalement à J-30).

## 7. Checklist « zéro intervention »

- [x] `public/.htaccess` exclut `.well-known/acme-challenge` (code, durable).
- [ ] Cert `dev.leezr.com` ré-émis une fois via panneau ISPConfig (§4) après déploiement du fix.
- [ ] `acme.sh --dry-run` OK pour dev **et** prod (§5).
- [ ] Reload Apache post-renouvellement confirmé (hook ISPConfig).
- [ ] Supervision mensuelle `openssl … -enddate` (§6).
