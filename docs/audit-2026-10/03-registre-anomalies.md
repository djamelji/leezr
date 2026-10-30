# Livrable 3 — Registre des anomalies (Master Audit 360°, octobre 2026)

> Chaque anomalie : ID · gravité · environnement · module · fichier:ligne · attendu vs constaté · preuve · cause · correctif · risque de régression · effort · confiance. Les hypothèses sont explicitement distinguées des faits confirmés.

## P0 — CRITIQUES

### SEC-01 — Super-admin avec mot de passe `password`, re-seedé à chaque déploiement
- **Env** : prod + dev · **Module** : PlatformSeeder / déploiement
- **Fichier** : `database/seeders/PlatformSeeder.php:52,56,71,85` ; `deploy.sh:103` (SystemSeeder `--force`) ; `PlatformUser.php:42` (cast hashed) ; 2FA optionnel `PlatformAuthController.php:54`
- **Attendu** : pas de credential par défaut en prod ; 2FA obligatoire pour super-admin.
- **Constaté** : `admin@leezr.com` (re)créé avec `'password' => 'password'` à chaque déploiement.
- **Preuve** : grep direct confirmé ; fichier suivi par git.
- **Cause** : seeder de bootstrap exécuté en prod sans garde-fou.
- **Correctif** : retirer la création d'admin du seeder prod (ou mot de passe aléatoire une fois + reset obligatoire), **imposer 2FA** super_admin.
- **Régression** : faible · **Effort** : S · **Confiance** : CONFIRMÉ.

### SEC-02 — Credentials email/serveur de production committés
- **Env** : prod · **Module** : PlatformSeeder (email hub)
- **Fichier** : `database/seeders/PlatformSeeder.php:122,127` → `@REDACTED_COMPROMISED_PASSWORD_ROTATE_ADR663` (SMTP+IMAP, `admin@leezr.com`, `213.32.20.37`)
- **Attendu** : secrets via `env()`, jamais dans le VCS.
- **Constaté** : mot de passe prod en clair, suivi par git ; même famille que l'accès SSH/ISPConfig.
- **Correctif** : **rotation immédiate** du mot de passe, purge de l'historique git, bascule `env()`.
- **Régression** : moyenne (reconfigurer les secrets) · **Effort** : S · **Confiance** : CONFIRMÉ.

### SSL-01 — HTTPS dev.leezr.com indisponible (cert expiré depuis ~5 mois)
- **Env** : dev · **Module** : infra/Apache-ISPConfig
- **Preuve** : cert `notAfter=May 12 2026` ; `curl https://dev.leezr.com` = `certificate has expired` ; fichier `dev.leezr.com-le.crt` daté 11 fév.
- **Cause** : renouvellement jamais abouti (le fix durable `.htaccess` ADR-568 est déployé, mais le cert expiré n'a jamais été ré-émis — nécessite panneau ISPConfig/root, pas de sudo sur comptes deploy).
- **Correctif** : ré-émettre via panneau ISPConfig (décocher/recocher Let's Encrypt) — procédure `docs/runbooks/ssl-letsencrypt.md`.
- **Régression** : nulle · **Effort** : S (manuel, root/panel) · **Confiance** : CONFIRMÉ.

## P1 — MAJEURS

### SEC-03 — `migrate:fresh --seed --force` dans le script de déploiement dev
- **Fichier** : `deploy/deploy_release.sh:185-187` (labellisé « dev mode ») ; prod `deploy.sh` ne le fait pas.
- **Constaté** : chaque déploiement dev drop+recrée la base ; catastrophique si pointé sur prod. `deploy.sh:108` seed DevSeeder sur prod (données démo à vérifier).
- **Correctif** : garde-fou environnement explicite ; retirer DevSeeder du chemin prod.
- **Confiance** : présence CONFIRMÉE ; usage prod = hypothèse.

### SEC-04 — `CompanyScope` no-op hors contexte HTTP (fuite cross-tenant potentielle)
- **Fichier** : `app/Core/Scopes/CompanyScope.php:19` ; binding `SetCompanyContext.php:67`.
- **Constaté** : jobs/commandes/listeners sans binding → requêtes non scopées.
- **Correctif** : scoping explicite dans les jobs, ou binding de contexte systématique ; audit billing/workforce.
- **Confiance** : comportement CONFIRMÉ ; exploitation = hypothèse.

### SEC-05 — Modèles `company_id` sans trait `BelongsToCompany` (~16)
- **Preuve** : 54 modèles tracés / 76 tables `company_id`. À traiter : `EmailThread`, `EmailLog`, `UsageSnapshot`, `WorkflowExecutionLog`, `AiRequestLog`, `SecurityAlert`, `RegistrationFunnelEvent`, `CompanyDashboardWidgetSuggestion`.
- **Correctif** : ajouter le trait ou prouver le scoping manuel par requête.
- **Confiance** : hypothèse par modèle.

### TEST-01 — Suite de tests complète inexécutable (gate CI cassé)
- **Env** : CI/dev · **Preuve** : Unit 293 vert (5.7s) ; classes Feature vertes en isolation (WorkforcePayrollTest 15/15) ; mais `--filter` cross-classes → échecs de contamination (`UniqueConstraintViolationException jobdomains.key='logistique'`) ; **suite complète HANG** (>50 min observé en juillet, non terminée).
- **Cause** : isolation de tests — plusieurs `setUp()` recréent le même jobdomain sur SQLite `:memory:` partagé (bug de **tests**, pas applicatif) + un test bloquant.
- **Correctif** : `firstOrCreate`/factory partagée pour le jobdomain, isoler le test bloquant, envisager paratest.
- **Confiance** : CONFIRMÉ (bug de tests, code applicatif sain).

### DEP-01 — ~49 vulnérabilités de dépendances PHP (13 HIGH)
- **Preuve** : `composer audit` → 13 high / 28 medium / 8 low (ex. `symfony/yaml` CVE-2026-45133).
- **Cause** : dépendances non mises à jour pendant l'inactivité.
- **Correctif** : `composer update` ciblé sur les CVE high, re-test. (Idem `pnpm audit` côté JS — à exécuter.)
- **Confiance** : CONFIRMÉ.

### WF-01→06 — 6 P0 produit Workforce intacts (0/6 corrigés)
- (a) salaire en **centimes** exposé `_EmployeeContract.vue:348-374` + `fr.json:5924-5927` ; (b) **9 clés i18n `workforceMe.*`** absentes des 3 locales `me.vue:596-661` ; (c) bouton « Modifier » = `currentTab=0` `employees/[id].vue:126` ; (d) congé employé → page admin `me.vue:572` ; (e) bulletins par ID tapé `_PayslipActionCards.vue:13-97` ; (f) **aucune `.validate()`** dans les formulaires workforce.
- **Correctif** : euros (×100 au store), validation bloquante, self-service congé scoped, sélecteurs de run, 9 clés i18n.
- **Confiance** : CONFIRMÉ (tous).

## P2 — SIGNIFICATIFS
- **SEC-06** : `/media/{path}` public sans auth/tenant (`StorageFileController.php:18`, `web.php:13`). Confirmé (pas de path traversal).
- **I18N-01** : AR ~96 % non traduit + RTL Vuetify non configuré (`themeConfig.js:32`, `plugins/vuetify/`) ; **ES inexistant** mais proposé dans `UserInfoEditDialog.vue:176` (option morte). Confirmé.
- **UX-01** : bug `watch(..., { debounce: 300 })` ignoré (option inexistante sur `watch` natif) → requête API par frappe, sur **6 pages** workforce. Confirmé.
- **FUNC-01** : `complianceWarnings = ref([])` jamais peuplé → bandeau conformité jamais affiché (`company/workforce/index.vue:225,252`). Confirmé.
- **FUNC-02** : 3 endpoints fantômes jobdomains (`jobdomains.store.js:71,78,82`) → 404/405. Confirmé (legacy).
- **CAT-01** : `logistics_fleet/tracking/analytics` `is_sellable=true` + pricing, 0 page (facturer du vide). Confirmé.
- **INFRA-01** : logs dev 196 Mo non purgés (rétention à configurer). Confirmé.
- **WF-07** : Workforce 100 % synchrone (0 job `ShouldQueue`) → paie/DSN/documents en requête HTTP (scalabilité/PP1). Confirmé.
- **EN-01** : 5 clés `companyBilling.overview.scheduledChange*` absentes de `en.json`. Confirmé.

## P3 — MINEURS
- **UX-02** : couleurs hex en dur `planning/index.vue:490-526` (non theme-aware). Confirmé.
- **FUNC-03** : noms de plans en dur en fallback `_PlanBadgeWidget.vue:19-22`. Confirmé.
- **SEC-07** : comparaison token non timing-safe `BillingMetricsExportController.php:27`. Confirmé.
- **SEC-08** : `verifyWebhookSignature` no-op pour adapter `internal` (`InternalPaymentAdapter.php:129`). **Hypothèse** (dépend de la résolution providerKey).
- **CODE-01** : `MembershipController.php` 258 lignes (>250, invariant). Confirmé.
- **ARCH-01** : Core pollué par métier FR (`app/Core/Workforce/`, `if('FR')` `PayrollCalculationEngine.php:324`). Confirmé — violation ADR-562 (classée dette architecturale, pas bug runtime).
