# Runbook — Certificats SSL Let's Encrypt (dev & prod)

> Référence d'infrastructure standard. Décision : **ADR-568**. Objectif : renouvellement **automatique et fiable, sans intervention manuelle**, en dev comme en prod.

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

## 3. Fix durable (déjà appliqué dans le code — ADR-568)

`public/.htaccess`, en tête du bloc `mod_rewrite` :

```apache
# Let's Encrypt / ACME http-01 challenge — TOUJOURS servir en statique
RewriteRule ^\.well-known/acme-challenge/ - [L]
```

→ Déployé à chaque release, l'application n'intercepte plus jamais le challenge. **Aucune régression de renouvellement possible côté applicatif.**

## 4. Remise en état d'un certificat expiré (nécessite root ou panneau)

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
