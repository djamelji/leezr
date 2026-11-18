# Leezr 3.8 — Verrouillage des environnements, sécurisation E2E, SSE, Billing, readiness

ADR-828 · 2026-11-18 · branche `dev` locale, aucun push, aucun déploiement, aucune restauration, aucune migration réelle sur le DEV ou la production.

Statuts :
- **CLONE** : réalisé sur clone ou banc isolé ;
- **LS-DEV** : vérifié en lecture seule sur le DEV ;
- **PRÉP** : préparé uniquement ;
- **NON** : non exécuté ;
- **BLOQ** : en attente d'une décision ou d'un accès.

## 1. Commits locaux

| Commit | Objet |
|---|---|
| `3b2b3852` | Plus aucune voie d'écriture E2E non protégée : `e2e:php`, `recipe.js`, 9 specs sans `tinker`, connexions secondaires, maintenance confirmée |
| `747502e7` | `--async` refusé tant qu'aucun worker ne consomme les files de facturation ; gabarit de worker préparé ; preuves SSE |
| *(commit docs)* | ADR-828, ce rapport, vérifications de production prêtes, preuves de migration et RBAC |

## 2. Sécurisation des neuf specs Tinker

| Spec | Opérations | Remplacement |
|---|---|---|
| `adr796-onboarding` | Lectures (id salarié, id dépôt) | `recipeEval` |
| `adr797-jobdomain-modernization` | Lectures et nettoyage (suppression des liens, presets et JobDomain créés par la spec) | `recipeEval` |
| `adr798-preset-editors` | Lectures et nettoyage (presets, exigences) | `recipeEval` |
| `adr799-jobdomain-tabs-working-time` | Nettoyage et lecture d'empreinte (`company_policies`, société 1) | `recipeEval` |
| `external-pades-two-browsers` | Création d'une demande de signature, de 2 jetons ; relecture | `recipePhp` |
| `workforce-signature` | Création d'une demande et d'un jeton | `recipePhp` |
| `wsig7-visual` | Création d'une demande et d'un jeton | `recipePhp` |
| `wsig11-company-journey` | Création d'un document, d'un jeton ; relecture | `recipePhp` |
| `wsig12-internal-journey` | Création d'utilisateurs, d'adhésions, d'un salarié, d'un document, d'une demande ; relecture | `recipePhp` |

- **Une seule commande commune**, `e2e:php <fichier>`, hérite de `RecipeCommand`. Elle applique le garde sur l'identité réelle de la connexion et exécute le script dans une transaction.
- **Le helper `tests/e2e/recipe.js`** écrit le script dans un répertoire temporaire, exécute la commande, puis supprime le répertoire. Les specs écrivaient auparavant dans le scratchpad, codé en dur, d'une ancienne session.
- Il ne reste plus un seul appel `tinker` dans `tests/e2e`.
- Les seules commandes artisan lancées par les specs sont désormais les 13 `e2e:*` protégés, plus `onboarding:scan-uploads --simulate` et `onboarding:cleanup-fixtures --apply`, protégés eux aussi (§3).
- Les assertions métier sont inchangées.

## 3. Protection des commandes destructives

- **Garde des commandes de recette** (ADR-827, étendu ici) : il s'appuie sur l'identité réelle de la connexion (`SELECT DATABASE()` ou le fichier SQLite), sans jamais dépendre de `APP_ENV`, de `DB_DATABASE`, de l'adresse du navigateur ou d'une option.
- **Connexions secondaires** : toute connexion déjà ouverte au démarrage, ou ouverte en cours d'exécution, doit être un clone marqué. L'événement `ConnectionEstablished` se déclenche avant la première requête ; une connexion refusée est purgée, et la commande est annulée.
- **Maintenance**, d'après l'audit D-16 :

| Commande | Mode | Règle |
|---|---|---|
| `workforce:planning-loadtest list` | Lecture | Libre |
| `workforce:planning-loadtest seed` / `purge` | Création ou suppression du tenant de charge | Clone marqué, ou `--confirm-database=<base réellement connectée>` ; jamais en production |
| `onboarding:cleanup-fixtures` (sans `--apply`) | Simulation | Libre |
| `onboarding:cleanup-fixtures --apply` | Nettoyage destructif | Clone marqué, ou `--confirm-database=<base réellement connectée>` ; jamais en production |
| `onboarding:scan-uploads` | Maintenance planifiée (toutes les 10 min) | Inchangée |
| `onboarding:scan-uploads --simulate` | Écriture d'un faux résultat d'analyse : recette | Clone marqué uniquement |

- **Aucun contournement** : `--force` n'existe pas et est rejeté. Une confirmation qui nomme une autre base que la base connectée est refusée. Les refus comme les confirmations explicites sont journalisés dans `storage/logs/recipe-guard.log`.
- **Tests** : `Adr828RecipeHardeningTest`, 4 tests et 43 assertions, en vrais processus enfants :
  - 7 bases interdites, toutes avec zéro écriture : DEV fictive, production fictive marquée, base inconnue marquée, base sans marqueur, marqueur étranger, `APP_ENV=production`, `APP_ENV=testing` sans marqueur ;
  - le clone marqué est accepté, et un script en échec est annulé ;
  - une connexion secondaire non marquée est refusée, sans écriture ni sur elle ni sur la base principale, tandis qu'une connexion secondaire marquée est acceptée ;
  - la matrice de maintenance ;
  - les refus de `seed`, `purge` et `--simulate`.

  `Adr7961` est adapté : il vérifie d'abord le refus sans écriture, puis il exerce la logique de la commande.

## 4. Résultats des refus sur le DEV (LS-DEV, contrôlés par le journal binaire)

| Cas réel | Résultat |
|---|---|
| `e2e:php` avec un script supprimant la paie de la société 1 | Refusé, code 1 |
| Configuration trompeuse (`DB_DATABASE` = clone, `DB_URL` → DEV) | Refusé : « the live database (leezr) is not the configured one » |
| Depuis le clone marqué, script ouvrant une connexion secondaire vers le DEV | Refusé : « secondary connection side refused : leezr is a DEV / production database » |
| `planning-loadtest seed` sans confirmation | Refusé |
| `planning-loadtest seed --confirm-database=leezr_recipe827` alors que la connexion est sur le DEV | Refusé : la confirmation ne nomme pas la base réellement connectée |
| `scan-uploads --simulate` | Refusé |
| `planning-loadtest list` | Autorisé (lecture) |

**Le journal binaire ne montre aucune écriture sur `leezr` pendant cette série.**

## 5. Mesures SSE (CLONE : nginx et PHP-FPM isolés, 4 workers, base `leezr_audit821`, préfixe Redis dédié)

Les mesures de la 3.7 restent valables :
- saturation : 17,1 s pour une requête ordinaire avec 4 flux sur 4 workers ;
- avec le plafond, le 4ᵉ flux reçoit un 429 et la requête répond en 0,05 s ;
- fuite des compteurs supprimée ;
- fin propre avant une limite verrouillée.

**Recette navigateur 3.8** (`leezr-3-8/sse/browser-recette.json`, plafond de 2 flux, flux de 20 s, battement de 2 s) :

| Scénario | Mesure |
|---|---|
| Un utilisateur, deux onglets | Deux flux en 200 ; compteurs : 2 flux, 1 utilisateur |
| Second utilisateur, plafond atteint | 429 à 9,0 s, 11,0 s et 15,1 s (reprises à 2 s puis 4 s), puis **polling, sans nouvelle tentative SSE** |
| Autre société (`company_id=1`) | **403** |
| Fermeture d'un onglet | Place libérée en **4,1 s** (≈ battement + pause du transport) |
| Rechargement de l'utilisateur plafonné | Flux en 200 |
| Fin naturelle du flux (20 s) | Reconnexion automatique en 200, 2 s plus tard |
| Déconnexion pendant le flux | Le flux ouvert vit jusqu'à son terme, puis les reconnexions reçoivent un **302** (redirection vers la connexion, pas un 401) : 2 tentatives, repli ensuite |
| Tout fermé | Compteurs à 0 en 3,2 s |

Autres constats :
- **Concurrence aux places** : sous saturation, chaque fin de flux (toutes les `stream_timeout` secondes) oblige l'onglet à se reconnecter. Un autre onglet peut prendre la place, et un onglet évincé reste en polling jusqu'au rechargement. Le SSE se dégrade donc vers le polling sans revenir de lui-même (D-20).
- **Mémoire** : 44 Mo de RSS moyen par worker sur le banc.
- **DEV local** : Valet fixe `pm.max_children = 5`, `max_execution_time = 30` et `memory_limit = 512M`, et ne déclare ni `fastcgi_read_timeout` (donc 60 s par défaut) ni `fastcgi_buffering` (couvert par l'en-tête `X-Accel-Buffering: no`). Cinq onglets suffisent à saturer le DEV.

## 6. Paramétrage recommandé (fondé sur les mesures, à confirmer avec la production)

Notations : `W` = `pm.max_children` du pool ; `R` = réserve pour les requêtes ordinaires ; `P` = pic de requêtes ordinaires simultanées, mesuré dans les journaux de production.

| Paramètre | Règle | Pourquoi |
|---|---|---|
| `REALTIME_MAX_CONCURRENT_STREAMS` | `W − R`, avec `R ≥ max(4, ⌈W/2⌉, P)` | Un flux occupe un worker. Sans réserve, la mesure donne 17 s d'attente |
| `pm.max_children` (W) | ≤ mémoire disponible pour PHP / (RSS mesuré en production × 1,2) | 44 Mo mesurés sur le banc ; non transposable tel quel |
| `REALTIME_STREAM_TIMEOUT` | 300 s par défaut, acceptable. Le flux s'arrête de lui-même 5 s avant une limite verrouillée | Chaque fin de flux coûte une reconnexion et remet la place en jeu |
| `REALTIME_HEARTBEAT_INTERVAL` | 30 s, à garder **inférieur** aux délais de lecture du proxy (nginx 60 s, Apache `ProxyTimeout`) et à `request_terminate_timeout` | Détection d'un onglet fermé ≈ un battement ; garde la connexion vivante côté proxy |
| `request_terminate_timeout` | ≥ `REALTIME_STREAM_TIMEOUT` + 15 s, ou 0 | Sinon le pool tue le flux et le désenregistrement repasse par la fonction d'arrêt |
| Polling de secours | 30 s (`NAV_POLL_MS`), inchangé | Déjà en place |

Exemples :
- **DEV local**, W = 5 : plafond de **1 ou 2** flux, ou `REALTIME_TRANSPORT=polling`. Non modifié : le `.env` est le vôtre.
- **Production**, W inconnu : **pas de valeur proposée** sans le relevé D-10.

Sans relevé, le plafond doit rester actif à une valeur prudente, ou le SSE désactivé (`REALTIME_DRIVER=null` donne le polling). Ne jamais le laisser à 0 (illimité) en production.

## 7. Audit de la facturation asynchrone

| Point | Constat |
|---|---|
| Producteurs | `billing:renew --async` → `RenewSubscriptionBatchJob` (file `billing`) ; `billing:process-dunning --async` → `ProcessDunningBatchJob` (`billing`) ; `billing:reconcile --async` → `ReconcileCompanyJob` (`billing-slow`). Aucun autre chemin : ni le planificateur, ni l'interface, ni l'API |
| Consommateurs | Aucun : les workers déployés n'écoutent que `ai` et `default` (ADR-738) |
| Configuration | `QUEUE_CONNECTION=database` ; aucun job `billing*` en attente sur le DEV (`ai` 20, `default` 57, historiques, non lancés) |
| Idempotence du renouvellement | Forte : `current_period_end <= now()` et `lockForUpdate` par abonnement ; un job en retard ne refacture pas une période déjà renouvelée |
| Idempotence des relances | **Faible en différé** : le job ne vérifie que `status = overdue`, pas `next_retry_at`. Consommé après un passage planifié, il refait une tentative de prélèvement sur une facture déjà relancée, ce qui épuise trop tôt le nombre de relances (risque de passage en irrécouvrable) |
| Rapprochement | Lecture et réparations « sûres » ; risque faible |
| Double facturation | Non démontrée pour le renouvellement ; possible en **double tentative de prélèvement** pour les relances différées |

**Décision technique appliquée** : `--async` est refusé (code 1, message, journal `billing`) **avant tout enregistrement** tant que `BILLING_ASYNC_QUEUES_ENABLED` est faux. Rien n'est envoyé, et le battement de cœur de la tâche n'est pas touché. Le mode synchrone planifié est inchangé, comme les prix, les factures, le registre comptable (ledger) et les abonnements.

Les tests de facturation ciblés passent (144) ; le refus est vérifié sur le clone, sans job créé.

**Préparé** : `deploy/templates/leezr-queue-billing.service.example`, non branché, avec sa procédure d'activation. `.env.example` reçoit `BILLING_ASYNC_QUEUES_ENABLED=false`.

## 8. Vérifications de production (NON réalisées)

Aucun accès en lecture seule à la production n'est autorisé. Les accès connus passent par SSH, avec une identité partagée entre DEV et production, ou par un workflow GitHub, qui impose un push. **Rien n'a donc été vérifié en production.** Les commandes prêtes, en lecture seule et sans afficher de secret, sont dans `docs/bmad/leezr-3-8/PRODUCTION-READONLY-CHECKS.md`. Elles couvrent la révision déployée, les migrations et les index, le cron, les workers et les files, les préfixes Redis, PHP-FPM, Apache/nginx (dont `flushpackets` pour le SSE), la présence des variables et les presets publiés.

## 9. État réel des migrations (CLONE ; production non vérifiée)

| Élément | Constat |
|---|---|
| Version initiale `109d95cd` (dans `main`) | Rejouée sur un clone : **échec `1059 Identifier name … too long`** après la création de la table et de ses deux clés étrangères ; migration **non enregistrée** |
| Version actuelle `9d1bf431` | Noms d'index courts ; rejouable (`hasColumn`, `hasTable`) |
| Réparation `2026_11_23_000002` | Additive ; détecte les index par leurs colonnes |
| État partiel (après l'échec d'origine) + migrations actuelles | Converge : `wf_sig_enroll_signer_created_idx` ajouté ; `company_id` couvert par l'index de sa clé étrangère |
| Table absente + migrations actuelles | Converge : table créée avec `wf_sig_enroll_company_idx` et `wf_sig_enroll_signer_created_idx` |
| Différence entre ces deux voies | Seulement le nom de l'index sur `company_id`, fonctionnellement équivalent |
| DEV | Enregistrée ; index présents |
| Production | **Non vérifiée.** Si la version initiale y a été appliquée manuellement, en contournant l'échec, il faut le constater avant le déploiement (§2 des vérifications) |

Aucune migration appliquée n'a été modifiée. Aucune migration corrective n'est nécessaire dans les états reproduits.

## 10. Incident Company 1 (LS-DEV ; aucune restauration)

- **Outil, données et preuves** : inchangés depuis `c696494d`. Empreintes : `rows-binlog-verified.json` `487f1982…`, outil `2aa78470…`. L'ancien script SQL 3.6 reste marqué `SUPERSEDED`.
- **Sauvegardes** : le dump du DEV d'avant toute opération (`storage/app/incident-3-7/`) est revérifié par SHA-256. Les 3 sauvegardes de feuilles 143 issues des clones sont présentes.
- **Lignes sources** : le journal binaire contient toujours exactement 5 suppressions et 1 insertion à 08:04:01. Sa rétention est de **30 jours** : il **expire vers le 2026-12-18**, mais les lignes vérifiées sont conservées dans le dépôt.
- **Simulation sur le DEV** (lecture seule) : `would_restore`, aucun conflit, feuille 143 « as_created_by_incident » sans dépendance, **zéro écriture** d'après le journal binaire.
- **Depuis l'incident** : aucune écriture sur les tables de paie, de feuilles de temps ou de DSN du DEV.
- **Procédure** : inchangée (rapport 3.7, §9 : simulation, empreinte, `--apply --confirm-database=leezr --backup-dir`, relance idempotente, retour arrière `--rollback`).
- **Reporter** : rien ne se dégrade tant que personne ne crée de paie d'avril 2026 pour la société 1 ni ne modifie la feuille 143 ; l'outil refuserait alors, et une décision manuelle deviendrait nécessaire.
- **Abandonner** : le DEV garde une période d'avril sans paie et une feuille 143 artificielle. Ce sont des données de recette, sans enjeu métier réel.

## 11. Plan RBAC chauffeur (PRÉP ; rien d'appliqué)

Simulation relancée en lecture seule sur le DEV : 8 rôles, identiques à la 3.6, zéro écriture (`leezr-3-8/rbac/driver-remediation-simulation-dev-2026-11-18.json`).

| Ordre | Société | Rôle | Membres | Retrait | Conservé |
|---|---|---|---|---|---|
| Vague 1 | 2, 3, 31, 32, 33, 34 | 2, 12, 29, 34, 39, 44 | **0** | `members.view`, `settings.view` | `shipments.view_own`, `shipments.manage_status`, `theme.*` |
| Exclu | **1** | 7 | 12 | `members.view`, `settings.view` | — **non modifié** (consigne 3.8) |
| Exclu | **101** | 171 | 1 | + `documents.view` | — **non modifié** (consigne 3.8) |

Le mécanisme est conservé sans réimplémentation.
- **Personnalisations** : un rôle modifié explicitement par sa société est ignoré, sauf avec `--role` ; aucun n'est concerné ici.
- **Contrôle et traçabilité** : `--confirm-database` est obligatoire, avec une transaction et un audit `role.remediation_applied` par rôle, et une restauration par lot (`--restore`).
- **Non-régression Livraison** : relancer la suite RBAC (82 tests verts aujourd'hui) et les tests Livraison du rôle chauffeur, puis vérifier par l'API qu'un chauffeur fictif lit encore ses livraisons et en change le statut, et ne voit plus la liste des membres ni les paramètres.
- **Communication** : prévenir les sociétés concernées (aucun membre en vague 1).
- **Sociétés 1 et 101** : chacune demande une décision séparée.

## 12. Tests et E2E

- **PHPUnit complet** : **4 732 réussis**, 12 sautés, **3 échecs `ControllerSizeInvariant` historiques**. La liste des contrôleurs en infraction est strictement identique à celle de la 3.7 : aucune nouvelle régression masquée.
- **Tests ciblés** : garde 3.8 4/4, garde 3.7 5/5, Onboarding 49/49, facturation 144, RBAC 82, Workforce et paie couverts par la suite complète.
- **Build** : propre.
- **E2E sur le clone marqué `leezr_recipe827`** :

| Lot | Résultat | Classement |
|---|---|---|
| 9 specs ex-`tinker` | **6 réussies**, 4 échouées | Les 4 échecs sont préexistants et sans lien avec le helper. **A/B réalisé** : `adr797` et `workforce-signature` échouent à l'identique avec l'ancienne version `tinker`. `adr799` est antérieure à la refonte du JobDomain en 3 sections (`49dd8019`). `adr798` échoue sur un bouton d'éditeur introuvable, sans A/B. Toutes les fixtures se sont exécutées (aucun refus du garde) |
| Specs utilisant les `e2e:*` | 3 réussies (paie, feuilles de temps, compteur de pointage persistant), 8 échouées | **A/B réalisé** sur 3 de ces specs avec le code d'avant la 3.7 (`e3a655fd`, sans garde ni transaction) : échecs identiques. Causes : état des données copiées du DEV (module déjà actif, solde de congés, journée de pointage) et dérive d'interface. **Aucun refus du garde** sur le clone |
| Recette SSE dans le navigateur (banc) | 1/1 | §5 |

Ces échecs E2E préexistants restent à requalifier (D-21).

## 13. Absence d'écriture sur le DEV

- **Empreinte** (`CHECKSUM TABLE`, 197 tables) avant et après la campagne : 194 tables identiques. Ont changé `sessions`, `company_audit_logs` et `workforce_employees`.
- **Journal binaire depuis le début de la campagne** (11:51:44 UTC) : 272 mises à jour et 2 insertions dans `sessions`, 2 insertions dans `company_audit_logs` (connexion et déconnexion de l'utilisateur 1), 1 mise à jour dans `workforce_employees` (salarié 2 rattaché au département 46 « DPD » et au rôle 3, à 11:54:03).
- **Origine** : toutes les sessions DEV écrites pendant la campagne sont celles d'un **Chrome interactif** (`Chrome/157`), aucune de `HeadlessChrome`. Les sessions de la recette, sur le clone, s'annoncent `HeadlessChrome/157`, et l'utilisateur 1 était connecté au DEV depuis 11:49:57, avant le début de la campagne. Ces écritures sont des **actions manuelles sur `leezr.test`**, et non des effets de la recette.
- **Écritures de la recette sur le DEV : zéro.**

## 14. Matrice GO / NO-GO

| Domaine | État | Preuve | Risque | Action |
|---|---|---|---|---|
| Sécurité E2E | **GO vérifié** (code et DEV local) | §3–4 ; zéro écriture sur les bases interdites ; journal binaire | Accès PDO direct hors garde ; specs hors repo | Aucune |
| Données historiques (incident) | **Décision métier requise** | §10 ; simulation propre | Expiration du journal binaire vers le 2026-12-18 (lignes conservées dans le dépôt) | D-13 |
| Migrations | **GO sous condition** | §9 ; 3 états rejoués | État de la production inconnu | D-11 (§2 des vérifications) |
| Planificateur | **Non vérifié** (production) | Code : 30 tâches `withoutOverlapping`, source unique ADR-739 | Cron absent ou doublé en production | D-11 (§3) |
| Workers | **Non vérifié** (production) | Code : unités par environnement (ADR-738) | Unités, files, `QUEUE_CONNECTION` de production | D-11 (§4) |
| Billing | **GO sous condition** | §7 ; `--async` verrouillé ; synchrone inchangé | Relances différées si un worker est activé sans contrôle | D-17 |
| SSE | **NO-GO** tant que le pool n'est pas relevé | §5–6 | Saturation du pool (17 s) ; proxy Apache qui tamponne le flux ; plafond à 0 | D-10 ; plafond ou SSE coupé |
| RBAC | **Décision métier requise** | §11 ; simulation identique | Accès étendus des chauffeurs historiques | D-8 (vague 1 ; 1 et 101 séparés) |
| Workforce | **GO vérifié** (code) | Règles 3.6 intactes ; suite complète verte hors historiques | Modèles de temps non pris en charge (exclus avec motif) | D-1 à D-6 |
| Presets | **Non vérifié** (production) | DEV : 4 publiés, 4 brouillons, 3 archivés | Écart DEV/production inconnu | D-11 (§9) |
| Multi-tenant | **GO vérifié** (code) | SSE 403 entre sociétés ; isolation des commandes ; tests d'isolation | — | Aucune |

**Verdict global : NO-GO de déploiement.** Le code est prêt sur la sécurité des recettes, Workforce et l'isolation entre sociétés. L'environnement de production n'est pas vérifié, et le SSE n'y est pas dimensionné. **Aucun déploiement n'est autorisé par ce rapport.**

## 15. Décisions encore nécessaires

- **D-11** : autoriser l'exécution des vérifications de production en lecture seule (`PRODUCTION-READONLY-CHECKS.md`), et désigner qui les exécute et par quel accès.
- **D-10** : après le relevé, fixer `REALTIME_MAX_CONCURRENT_STREAMS`, ou couper le SSE en production. Vérifier le tamponnage du flux par Apache (`flushpackets`, `mod_deflate`).
- **D-13** : restauration de la paie d'avril 2026 sur le DEV. L'outil est prêt, et la simulation est propre aujourd'hui.
- **D-17** : activer le worker de facturation (gabarit prêt) ou garder `--async` verrouillé. En cas d'activation, ajouter d'abord au job de relance un contrôle de `next_retry_at` (règle financière : votre décision).
- **D-8** : vague 1 du RBAC chauffeur (sociétés 2, 3, 31 à 34, sans membre). Les sociétés 1 et 101 demandent chacune une décision séparée.
- **D-19** : `REALTIME_MAX_CONCURRENT_STREAMS` sur le DEV local (pool de 5) : 1 ou 2, ou polling.
- **D-20** : faire retenter le SSE au client après un repli (par exemple toutes les 5 minutes) et répondre 401 au lieu de 302 pour un flux non authentifié. C'est un changement d'interface et de comportement : non fait.
- **D-21** : requalifier les specs E2E en échec préexistant (dérive d'interface, dépendance à l'état du DEV).
- **D-1 à D-6, D-9** : inchangées depuis la 3.6.

Clones conservés : `leezr_recipe827` (marqué, recette), `leezr_audit827` (incident restauré), `leezr_audit821` (banc SSE) et `leezr_incident_a`, `leezr_incident_b`, `leezr_incident_c`. Supprimés : les deux clones jetables de migration, `leezr_recipe828_mig_a` et `leezr_recipe828_mig_b`.
