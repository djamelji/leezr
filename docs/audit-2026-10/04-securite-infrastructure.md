# Livrable 4 — Audit Sécurité & Infrastructure (Master Audit 360°, octobre 2026)

> Lecture seule, aucun test intrusif. Findings P0/P1 confirmés par citation de code ou inspection directe. Hypothèses signalées comme telles.

## A. Sécurité applicative

### P0 — CRITIQUES (confirmés par inspection directe)

**SEC-01 — Super-admin avec mot de passe `password`, re-seedé à chaque déploiement.**
- Preuve : `database/seeders/PlatformSeeder.php:52-85` → `admin@leezr.com` créé via `updateOrCreate` avec `'password' => 'password'` (cast `hashed`). Fichier **suivi par git**. `deploy.sh:103` exécute `db:seed --class=SystemSeeder --force` à chaque déploiement prod → le hash est réécrit à chaque fois. 2FA non obligatoire (`PlatformAuthController.php:54`).
- Impact : prise de contrôle totale de la plateforme (toutes companies, toutes données) par login mot de passe seul.
- Confiance : **CONFIRMÉ**.

**SEC-02 — Credentials email de production en dur dans le dépôt git.**
- Preuve : `database/seeders/PlatformSeeder.php:122,127` → `smtp_password`/`imap_password` = `@REDACTED_COMPROMISED_PASSWORD_ROTATE_ADR663` pour `admin@leezr.com` sur `213.32.20.37`. Fichier suivi par git.
- Aggravation : cette valeur appartient à la **même famille de credentials** que l'accès SSH/ISPConfig du serveur (cf. mémoire ops) → la fuite dépasse l'email (potentiellement serveur + panneau).
- Impact : lecture de la boîte admin, envoi de mails usurpant Leezr, pivot infra possible.
- Confiance : **CONFIRMÉ**. Action : rotation immédiate + purge historique git + bascule `env()`.

### P1 — MAJEURS

**SEC-03 — `migrate:fresh --seed --force` dans le script de déploiement dev.**
- Preuve : `deploy/deploy_release.sh:185-187` (commenté « dev mode — no real clients ») puis `:191` `db:seed --class=DevSeeder`. Le script **prod** `deploy.sh` ne fait PAS de `migrate:fresh` (il fait SystemSeeder `:103` + DevSeeder `:108`).
- Impact : chaque déploiement **dev** efface la base ; catastrophique si jamais pointé sur prod. `deploy.sh:108` (DevSeeder sur prod) injecte des données de démo en prod — à vérifier.
- Confiance : présence **CONFIRMÉE** ; usage prod = hypothèse (le script dev est labellisé dev).

**SEC-04 — `CompanyScope` no-op hors contexte HTTP (risque cross-tenant).**
- Preuve : `app/Core/Scopes/CompanyScope.php:19` `if (app()->bound('company.context'))` ; binding posé uniquement par `SetCompanyContext.php:67` (HTTP company). Jobs, commandes artisan, listeners asynchrones s'exécutent **sans** scope.
- Impact : un job `Invoice::where('status','pending')->get()` traite les factures de **toutes** les companies. Fuite silencieuse si une requête oublie `where('company_id')`.
- Confiance : comportement **CONFIRMÉ** ; exploitation = hypothèse (à auditer job par job billing/workforce).

**SEC-05 — ~16 modèles avec `company_id` sans trait `BelongsToCompany`.**
- Preuve : 54 modèles tracés / 76 tables `company_id`. À vérifier au cas par cas : `EmailThread`, `EmailLog`, `UsageSnapshot`, `WorkflowExecutionLog`, `AiRequestLog`, `SecurityAlert`, `RegistrationFunnelEvent`, `CompanyDashboardWidgetSuggestion`. Certains sont scopés manuellement (`NotificationEvent`, `FieldActivation`) mais la protection dépend d'un `where` manuel par requête.
- Confiance : hypothèse par modèle (scan trait vs colonnes).

### P2 — SIGNIFICATIFS
- **SEC-06** : `/media/{path}` (`StorageFileController.php:18`, `web.php:13`) sert tout fichier du disque `public` **sans auth ni contrôle tenant** (avatars/logos). Pas de path traversal (sandbox `Storage::disk`). Accès cross-company de ces assets.
- **SEC-07** : comparaison de token non timing-safe `!==` (`BillingMetricsExportController.php:27`). Fail-closed si vide (OK).
- **SEC-08** : `verifyWebhookSignature` no-op pour l'adapter `internal` (`InternalPaymentAdapter.php:129-132`). Injection d'événements de paiement forgés **si** `POST /webhooks/payments/internal` résout cet adapter — **hypothèse** (dépend de la résolution `providerKey`).

### Points sains (confirmés)
- `.env` / `.env.production` **non committés** (seuls les `.example` suivis, sans secrets).
- Isolation HTTP company **solide** : `SetCompanyContext` vérifie `isMemberOf` + invariant RBAC (pas de confiance au header `X-Company-Id` seul).
- **Fix IDOR ADR-465 toujours en place** : `EmployeeSelfController::resolveEmployee` dérive l'employé de `$request->user()`, jamais d'un `employee_id` client.
- Downloads de documents sensibles scopés tenant + disque privé `local`.
- **RBAC appliqué côté serveur** (pas qu'un `v-can` front) : company via `company.access:use-module`/`use-permission` ; platform via `auth:platform` + `platform.permission` + `platform.2fa`. Aucune route sensible sans middleware d'autorisation trouvée. Routes publiques = légitimes et throttlées.
- Aucune clé `sk_live`/`AKIA`/clé privée en dur.

## B. Infrastructure & DevOps

### DEV (`dev.leezr.com`, VPS 213.32.20.37, web3, Apache/ISPConfig)
- **HTTPS CASSÉ (P1)** : certificat Let's Encrypt **expiré le 12 mai 2026, jamais ré-émis** (`dev.leezr.com-le.crt` daté 11 fév). Le fix durable `.htaccess` (ADR-568) est déployé (release `f7ae49a`), mais la **ré-émission du cert expiré n'a pas été faite** (nécessite panneau ISPConfig / root — pas de sudo sur les comptes de déploiement). `curl https://dev.leezr.com` = `certificate has expired`.
- **App vivante** : HTTP local répond 200 ; workers queue `default` + `ai` tournent (uptime ~52 min via cron self-healing), scheduler actif (`schedule:run` chaque minute, « No scheduled commands ready » = fonctionne), déploiement opérationnel (release du 11 oct). Disque 19 %.
- **Logs 196 Mo non purgés** (P2) : fichiers quotidiens `billing-2026-10-*` accumulés → rétention/rotation à configurer.

### PROD (`leezr.com`, web2)
- **HTTPS OK** : cert valide jusqu'au **16 déc 2026** → renouvellement automatique **fonctionne** (était 8 août en juillet, renouvelé depuis). Prod déploiement via `deploy.sh` (pas de `migrate:fresh`).

### NON VÉRIFIÉ (accès root/ISPConfig requis)
- **Sauvegardes DB/fichiers** : aucun cron de backup côté utilisateur ; gérées (ou non) au niveau root/ISPConfig → **non vérifiable sans sudo**.
- **Monitoring/alerting externe** : l'app a un moteur d'alertes interne (`platform.alerts`) mais la supervision infra (uptime, disque, cert expiry) n'est pas vérifiable sans root.
- **Renouvellement cert — test `--dry-run`** : nécessite root (acme.sh/certbot). Le mécanisme ISPConfig root gère acme.sh quotidiennement (prod le prouve), mais le dry-run dev n'a pas pu être exécuté.
- Détail CORS/rate-limit par route, dépendances vulnérables (`composer audit`/`pnpm audit` non exécutés ici) → à compléter.

## C. Top 5 risques sécurité P0/P1
1. **SEC-01** super-admin `password` re-seedé à chaque déploiement (+ 2FA optionnel).
2. **SEC-02** credentials email/serveur prod committés (`@REDACTED_COMPROMISED_PASSWORD_ROTATE_ADR663`).
3. **SEC-03** `migrate:fresh --force` dans un script de déploiement.
4. **SEC-04** scope tenant no-op hors HTTP (fuite cross-tenant via jobs).
5. **HTTPS dev expiré** depuis 5 mois (indispo + mauvais signal).
