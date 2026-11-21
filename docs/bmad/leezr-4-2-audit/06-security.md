# Leezr 4.2 — 06 · Sécurité

> Méthode : lecture des routes et middlewares (962 routes), lecture ciblée du code, **sondes d'isolation non destructives** (lecture seule, base de recette marquée `leezr_audit842`, comptes fictifs — `data/isolation.json`), navigation de 20 profils (`data/crawl`). Aucun test contre un serveur public, aucun secret affiché, aucun mot de passe public utilisé.
>
> Échelle : **Critique** (compromission d'un environnement ou de données de plusieurs sociétés) · **Élevé** · **Moyen** · **Faible** · **Info**.

## 1. Synthèse

| Gravité | Nombre | Constats |
|---|---|---|
| Critique (connu, non clôturé) | 3 | SEC-01, SEC-02, SEC-03 |
| Élevé | 0 | — |
| Moyen | 5 | SEC-04 → SEC-08 |
| Faible | 5 | SEC-09 → SEC-13 |
| Info / bon point | 8 | SEC-14 → SEC-21 |

**Aucune nouvelle vulnérabilité critique n'a été trouvée par cet audit.** Les trois critiques sont l'incident déjà connu (ADR-663 / ADR-833) : il reste **ouvert tant que sa résolution n'est pas prouvée**.

## 2. Risques critiques connus — non clôturés

| ID | Constat | Impact | Preuve disponible | État | Correction / preuve attendue |
|---|---|---|---|---|---|
| **SEC-01** | Mot de passe SSH commun DEV / PROD / ISPConfig présent dans l'historique d'un **dépôt public** (ADR-663, rappelé ADR-833). | Prise de contrôle des serveurs et des bases (toutes les sociétés). | ADR-833 « incident ADR-663 toujours ouvert : rotation requise » ; OP-2 préparée, rotation SSH PROD **revenue en arrière** lors de l'opération précédente. | **Ouvert** | Rotation effective des 3 secrets + accès par clé uniquement (`PasswordAuthentication no`) ; preuve = tentative d'authentification par l'ancien secret refusée, consignée sans afficher le secret. |
| **SEC-02** | Comptes de démonstration aux mots de passe publics (seeders `password`) présents en production ; verrouillage OP-2bis **préparé, non appliqué**. | Connexion à des comptes réels de production avec un mot de passe connu de tous. | Script OP-2bis prêt ; décision du propriétaire en attente. | **Ouvert** | Appliquer OP-2bis (hors compte administrateur principal) ; preuve = requête en lecture listant les comptes de démonstration avec mot de passe non connu / désactivés. |
| **SEC-03** | Mot de passe du super-administrateur Platform 1 à changer par le propriétaire (décision de l'incident). | Accès complet à Platform. | Aucune preuve de changement dans ce dépôt. | **Non prouvé** | Changement par le propriétaire + 2FA active ; preuve = date de changement / statut 2FA lus en base (sans afficher le hash). |

## 3. Constats

| ID | Gravité | Composant | Constat | Preuve / reproduction | Impact | Correction recommandée | Effort |
|---|---|---|---|---|---|---|---|
| SEC-04 | Moyen | Données de rôles (société 1, DEV) | Le rôle **Chauffeur** de la société 1 porte `members.view` et `settings.view` (surplus de droits connu ADR-823, remédiation « simulée DEV, appliquée clone seulement »). | Sonde : `bob@leezr.test` (chauffeur) → `GET /api/company/members` = **200** (liste des membres et e-mails). Toutes les autres API de gestion refusées (403). | Un chauffeur voit l'annuaire et les coordonnées de tous les membres. | Appliquer la remédiation ADR-823 sur les rôles existants (DEV puis PROD), via le moteur de presets (pas de SQL manuel). | S |
| SEC-05 | Moyen | 2FA Platform | `DELETE /api/platform/2fa` désactive la 2FA sur **mot de passe seul** (pas de code OTP). | `PlatformTwoFactorController@disable`. | Une session volée + mot de passe suffisent à retirer la 2FA. | Exiger un code OTP (ou code de secours) en plus du mot de passe ; journaliser. | S |
| SEC-06 | Moyen | 2FA Platform | `POST /api/platform/2fa/backup-codes` régénère les codes de secours **sans reconfirmation** (mot de passe ou OTP). | `PlatformTwoFactorController@regenerateBackupCodes`. | Une session volée obtient des codes de secours → persistance. | Exiger OTP/mot de passe ; notifier l'utilisateur par e-mail. | S |
| SEC-07 | Moyen | Members (Company) | Définir / réinitialiser le mot de passe d'un membre (`MemberCredentialController`) **n'écrit aucune entrée d'audit**. | Aucun appel `AuditLogger` dans le contrôleur (les rôles, membres et modules sont audités). | Opération sensible invisible dans le journal de la société. | Ajouter `member.password_set` / `member.password_reset_sent` (acteur, cible, sans valeur). | XS |
| SEC-08 | Moyen | RBAC Platform (UX/sécurité) | Des écrans appellent des API que le profil n'a pas le droit d'utiliser (403 « évitables ») : tableau de bord (activité, funnel), onglets Facturation (coupons, plans, modules), revue tarifaire. | Crawl `p-finance` : 13 réponses 403 + 10 erreurs console. | Pas de fuite (le serveur refuse) mais erreurs visibles et écrans à moitié vides ; masque les vrais refus dans la supervision. | Les widgets/onglets déclarent leur permission et ne s'affichent pas sans elle (même règle que la nav). | M |
| SEC-09 | Faible | Export métriques | `GET /api/platform/billing/metrics-export` : jeton comparé avec `!==` (non constant) et **aucune limitation de débit**. | `BillingMetricsExportController`, route sans `throttle`. | Attaque par force brute / temporelle théorique sur le jeton. | `hash_equals` + `throttle:10,1` ; jeton long (≥ 32 octets). | XS |
| SEC-10 | Faible | Typographie Platform | Téléversement de police : seule l'**extension déclarée** (`.woff2`) est vérifiée ; stockage sur le disque **public** avec l'extension devinée du contenu. | `TypographyController@uploadFont`. | Un administrateur interne pourrait déposer un fichier non-police servi publiquement (XSS stocké sur le domaine). | Vérifier le type réel (`mimetypes:font/woff2`) et forcer l'extension `.woff2`. | XS |
| SEC-11 | Faible | Diagnostic | `GET /api/whoami` marqué « Temporary identity diagnostic » toujours actif, sans appel front ni test. | `routes/api.php:131-133`. | Surface inutile (n'expose que la session courante). | Retirer (voir TEC-05). | XS |
| SEC-12 | Faible | Inbound e-mail | `POST /api/platform/email/inbound` accepte le secret **aussi dans le corps** (`secret=`), pas seulement en en-tête. | `EmailInboundController@store`. | Secret plus facilement journalisé (logs d'accès, outils). | En-tête uniquement. | XS |
| SEC-13 | Faible | Données de rôles Platform | 13 rôles `e2e_limited_*` / `e2e_*` résiduels et un compte `prod@leezr.com` administrateur dans la base locale (vérifier DEV/PROD). | `data/catalog.json` (platform_roles, platform_users). | Comptes/rôles orphelins = cible d'élévation et bruit d'administration. | Inventaire DEV/PROD en lecture, puis retrait contrôlé (TEC-10). | S |

## 4. Bons points vérifiés (preuves)

| ID | Contrôle | Résultat |
|---|---|---|
| SEC-14 | Isolation multi-tenant (sondes) | **19/20 refus** : en-tête `X-Company-Id` d'une autre société (403), identifiants d'un autre tenant (salarié, membre, expédition, facture, ticket, documents : 403/404), session Company sur API Platform (401). La seule acceptation (SEC-04) concerne la **propre** société du chauffeur. |
| SEC-15 | Routes sans authentification | 53 routes publiques, toutes attendues (connexion, inscription, centre d'aide, signature par jeton, webhooks signés, onboarding public par session dédiée) ; `metrics-export` et `email/inbound` protégés dans le contrôleur. |
| SEC-16 | Routes Company | 501 routes sous contexte société ; 83 sans permission en middleware = libre-service (`me`, profil, notifications, 2FA) ou contrôle dans le contrôleur (`WorkforceAccess::canViewEmployeeData`) ; aucune route de gestion sans contrôle trouvée. |
| SEC-17 | Routes Platform | Toutes les routes métier portent `platform.permission` ; les 20 sans permission sont du libre-service (compte, notifications, 2FA, nav). Le mot de passe seul n'ouvre **aucune session** quand la 2FA est active (session « pending »). |
| SEC-18 | Secrets dans le dépôt | Aucun secret suivi par git (recherche de motifs de clés ; `.env.production.example` ne contient que des valeurs vides commentées). |
| SEC-19 | Ancien déploiement | `public/webhook.php` inerte (410), `deploy.sh` neutralisé, garde de release héritée (ADR-833). La sonde `/_recipe/identity` répond 404 en production. |
| SEC-20 | Téléversements de documents | Type contrôlé sur le **contenu** (MIME réel), stockage disque **privé**, quotas (ADR-764), quarantaine antivirus fail-closed pour l'onboarding (ADR-796.1). |
| SEC-21 | Sessions | Production : `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `same_site=lax`, durée 120 min ; gouvernance de session (`SessionGovernance`) sur toutes les routes authentifiées. |

## 5. Non couvert par cet audit (à faire)

- Vérification **sur DEV/PROD** (lecture seule) des rôles résiduels, des comptes de démonstration et de la configuration réelle (`APP_DEBUG`, cookies) — interdit de les tester de façon intrusive ; à faire par une requête en lecture autorisée.
- Événements temps réel (SSE) : isolation déjà prouvée par P0-D / ADR-829 ; non rejouée ici (driver `null` sur le clone).
- Jobs asynchrones : file synchrone sur le clone ; isolation hors HTTP prouvée par P0-D (8f4ecdf), non rejouée.
- Signature électronique réelle (prestataire) et paiements Stripe réels : hors périmètre (sandbox absente).

## 6. Ordre de remédiation proposé

1. **SEC-01, SEC-02, SEC-03** — clôturer l'incident (actions du propriétaire, une à la fois, preuve à chaque étape).
2. **SEC-04** — appliquer la remédiation des rôles existants (même lot que UXC-02 / FUN-04).
3. **SEC-05, SEC-06, SEC-07, SEC-09, SEC-10, SEC-12** — un lot « durcissement » unique (≈ 1 jour).
4. **SEC-08** — avec le lot « permissions visibles » (UXP-03) : une seule règle widget/onglet = permission.
5. **SEC-11, SEC-13** — avec l'assainissement (05).
