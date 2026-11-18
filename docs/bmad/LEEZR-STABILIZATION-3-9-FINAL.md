# Leezr 3.9 — Stabilisation SSE, correction E2E, sécurité des environnements, readiness (ADR-829)

> Branche `dev` locale. Aucun push, aucun déploiement, aucun fetch/pull, aucun `git reset`. Aucune écriture de recette sur le DEV, aucune restauration, aucune remédiation RBAC, aucune migration, aucun worker billing. Statuts : **Implémenté et recetté** · **Implémenté, non recetté** · **Audité uniquement** · **Bloqué** · **Non réalisé**.

## 1. Commits locaux

| Commit | Contenu |
|---|---|
| `d858e0bf` | Temps réel : reprise SSE après repli, 401 JSON sur `/api/*`, 204 en mode dégradé, plafond neutralisé dans PHPUnit, tests unitaires du client |
| `12f62d72` | E2E : 13 specs réalignées sur les parcours actuels (+ helper), auto-préparées et isolées ; 2 options ajoutées à des commandes protégées |
| `2d98bcf7` | Facturation : conditions de relance figées par tests ; défaut des jobs différés démontré, correctif préparé non appliqué |
| (ce commit) | ADR-829, runbook 3.9, rapport, preuves (`docs/bmad/leezr-3-9/`) |

## 2. Configuration SSE du DEV — D-19 — **Implémenté et recetté (banc miroir)**

- Pool Valet relevé (lecture) : `pm = dynamic`, `pm.max_children = 5`, `max_execution_time = 30` (php.ini, non verrouillé). Rien de système modifié ; `pm.max_children` n'a pas été augmenté.
- Appliqué : `REALTIME_MAX_CONCURRENT_STREAMS=1` dans le `.env` local (ignoré par git, propre au poste ; aucune configuration partagée avec la production). Copie du `.env` d'avant modification dans le scratchpad de session (chmod 600).
- Valeurs résolues après modification : driver `sse`, transport `polling`, heartbeat 30 s, durée de flux 300 s (bornée par `StreamLifetime` sous la limite PHP), `max_concurrent_streams = 1`, par société 100, global 500.
- Tests PHPUnit : `phpunit.xml` force `REALTIME_MAX_CONCURRENT_STREAMS=0` — sans cela, le plafond local faisait échouer `Adr826RealtimeConnectionTrackerTest` (constaté puis corrigé).
- La recette navigateur a été faite sur un **banc isolé reproduisant Valet** (nginx + php-fpm `pm.max_children=5`, plafond 1, clone `leezr_audit821`, préfixe Redis `leezr829-`), pas sur `leezr.test` : y ouvrir des sessions écrirait sur le DEV. Sur le DEV réel, seul un appel sans session a été fait (401 JSON, aucune écriture).

## 3. Retour polling → SSE — D-20 — **Implémenté et recetté**

`resources/js/core/realtime/RealtimeClient.js` :
- 3 échecs (2 s, 4 s) → polling (inchangé), puis **une** tentative SSE à la fois : 30 s, 60 s, 120 s, 240 s, puis toutes les 300 s, ±20 % de jitter ;
- jamais pendant qu'un onglet est caché (reprise au retour de visibilité), jamais si la session ou la société ont changé (`canRecover` fourni par le runtime) ;
- retour au SSE sans rechargement au premier flux accepté ; un flux perdu relance le polling (`onDisconnected`) ;
- `connect()` refuse d'ouvrir un second flux ; `disconnect()` libère flux, timers et écouteur de visibilité.

Tests : `tests/js/realtime-client.test.mjs` (`pnpm test:js`, 5/5) — backoff borné, une seule tentative, pas de flux concurrent, onglet caché, `canRecover`, nettoyage complet.

## 4. Correction HTTP 401 — D-20 — **Implémenté et recetté**

`bootstrap/app.php` : `shouldRenderJsonWhen` → toute route `/api/*` répond en JSON quel que soit `Accept`. Sanctum, CSRF et les règles multi-tenant sont inchangés.

| Cas | Avant | Après |
|---|---|---|
| SSE sans session (company / platform) | 302 → page de connexion HTML | **401** `{"message":"Unauthenticated."}` |
| Authentifié, autre société | 403 | 403 |
| Plafond atteint | 429 | 429 |
| SSE désactivé (`REALTIME_DRIVER` ≠ `sse`) | flux ouvert inutilement (worker tenu) | **204** immédiat |

Preuves : `Adr829RealtimeAuthStatusTest` (échoue en 302 sans le correctif, passe avec) ; banc (S0) ; DEV Valet réel (`curl`, 401 JSON).

## 5. Mesures PHP-FPM — banc miroir Valet (5 workers, plafond 1)

Rapport complet : `docs/bmad/leezr-3-9/sse/browser-recette.json` ; spec : `browser-recette.spec.js.txt`.

| Scénario | Résultat |
|---|---|
| S0 sans session | 401 `application/json` |
| S1 un onglet | 200 ; 1 flux ; workers actifs 2/5 |
| S2 cinq onglets | A1 200 ; A2–A5 : 429 ×3 puis polling ; 1 flux ; workers actifs 2/5, `max children reached` 0 |
| Disponibilité pendant la saturation | 12 appels `/api/nav` : tous 200, **50 ms en moyenne, 98 ms au pire** ; page complète 200 |
| S3 pas de boucle | 0 tentative SSE dans les 20 s suivant le repli, sur chacun des 4 onglets |
| S4 fermeture de l'onglet porteur | un onglet capé repasse en SSE **sans rechargement** en 4,0 s ; les autres font une seule tentative (429) et restent en polling ; toujours 1 flux |
| S5 autre société | 403 |
| S6 perte réseau 8 s | flux rétabli 11,1 s après le retour du réseau |
| S7 changement de société (cookie `currentCompanyId` + rechargement, ce que fait `switchCompany`) | nouveau flux sur la société 2 : 429, 429 puis 200 (l'ancien flux libère sa place au battement suivant) |
| S8 session terminée (logout) | reconnexions refusées en **401** (×2 puis une sonde à ~26 s) ; aucune boucle ; aucun flux 200 |
| S9 tout fermé | flux 0, utilisateurs 0 ; clés Redis restantes = compteurs à 0 ; workers actifs 1/5 |

## 6. État des E2E — D-21

| Campagne | Base | Résultat |
|---|---|---|
| Référence 3.9 (avant correction) | clone marqué `leezr_recipe829` | **8 specs / 20 réussies** (les 12 autres en échec) |
| **Finale** | **nouveau** clone marqué `leezr_recipe829b`, un seul passage, ordre de campagne | **21 specs / 21, 32 tests / 32 réussis** |

Aucun refus du garde ; aucune spec désactivée ; aucune assertion métier affaiblie ; aucun `tinker`. **Hors périmètre** : les autres specs du dépôt (non rattachées à `e2e:*` / ex-tinker) n'ont pas été rejouées dans cette mission.

## 7. Specs corrigées

| Spec | Cause réelle | Nature | Correction |
|---|---|---|---|
| `adr797` | Bouton « brouillon depuis l'historique » absent : 0 rôle sans équivalent depuis `logistique_rbac` v4 ; identité enregistrée par `jd-save-general` ; association et versions gérées depuis le JobDomain (ADR-820/821) | Test obsolète + données | Assertion rendue exacte (bouton ⇔ compteur > 0) ; administration catalogue faite tant que la famille est « bibliothèque », puis association par « Utiliser un modèle partagé » ; épinglage v1 / v2 signalée / aucune application conservés |
| `adr798` | Une famille recommandée par un domaine n'a plus « Nouvelle version » au catalogue (ADR-820) | Test obsolète | Vérifie le renvoi vers le JobDomain et l'absence du bouton ; l'éditeur mobile est vérifié sur un nouveau preset |
| `adr799` | JobDomain en 3 sections (ADR-821) ; familles sous « Configuration initiale › avancé » | Test obsolète | Navigation réalignée ; assertions métier inchangées |
| `workforce-signature` | (1) deux tiroirs montés ; (2) document de préparation de 12 octets → scellement PAdES exigé impossible → « Échec » (le produit refuse correctement un faux succès) | Préparation | Tiroir actif ciblé ; vrai PDF (DomPDF) comme les specs wsig |
| `company-module-toggle`, `module-dependency` | `logistics_tracking` est `preview` (jamais vendable) depuis ADR-732 ; onglets devenus filtres | Test obsolète | Cible `workforce_contract` (vendable, sans dépendant ; parent `workforce_documents`), filtres `modules-filter-*`, état d'origine restauré par l'endpoint réel |
| `workforce-clock-workflow`, `workforce-break-countdown` | Une journée du 2026-10-20 restée ouverte (reste d'une ancienne recette) bloque le pointage par conception (bandeau ADR-779) | Préparation | `e2e:reset-clock-day --clear-open-days` ; cible de pause remise à 30 min en fin de spec |
| `workforce-leave-ledger`, `workforce-leave-workflow`, `hrux4` (E1) | `POST /me/leaves/deduction-preview` (ajouté depuis) capté à la place de la création | Test imprécis | Correspondance sur le chemin exact (`clickAndExpectApi({ urlPath })`) |
| `hrux4` (E1/E2) | Recrue du jour : 0,9 jour acquis, demande d'1 jour refusée (règle correcte) | Préparation | Solde crédité par `e2e:prepare-leave-fixture` (clone uniquement) ; type « Congés payés » choisi explicitement |
| `ux4` (responsable) | Hauteur du calendrier bornée (ADR-824) : la fin du glisser sortait de l'écran ; abscisse calculée sur une boîte débordante | Test fragile | Plage entière amenée à l'écran ; glisser dans la colonne du jour (fonction vérifiée : 09:00 → 12:00 prérempli) |
| `ux4` (anciennes routes) | `/time?tab=entries` redirige vers `/time/entries` (onglets dans le chemin) | Test obsolète | Page d'arrivée attendue explicitement |
| `ux4` (Bob) | Solde de Bob consommé par les specs de congés précédentes ; préparation idempotente par année | Isolation | `e2e:prepare-leave-fixture --key=<exécution>` |
| `workforce-two-company-isolation` | Ne lançait pas sa préparation et lisait l'état laissé par une autre spec (45 min) | Isolation | `e2e:setup-two-companies` en `beforeAll` |

Aucun défaut produit n'a été trouvé par ces specs ; deux constats produit sont consignés au §14 (sélecteur de société orphelin, session expirée non redirigée).

## 8. Sécurité des recettes

- Toutes les écritures de recette sur `leezr_recipe829` / `leezr_recipe829b` (marqués, créés par `make-clone.sh`, dumps conservés avec SHA-256) ; serveur applicatif du clone sur 8821 avec `DB_DATABASE` et préfixe Redis dédiés.
- **DEV** : empreinte `CHECKSUM TABLE` avant/après la campagne et journal binaire depuis `binlog.000007:345960714` : seules `sessions` (238 mises à jour, 1 insertion, 1 suppression) et `company_audit_logs` (1 insertion `auth.login`, utilisateur 1, agent `Chrome/157` **non headless**) ont changé — une session navigateur interactive sur `leezr.test`. **Zéro écriture de recette sur le DEV.**
- Les deux options ajoutées passent par `RecipeCommand` (garde + transaction) ; `Adr829ResetClockDayTest` prouve le refus par le vrai garde, puis la portée exacte (journées antérieures **ouvertes** de **ce** salarié seulement).
- Tests de refus 3.7/3.8 (`Adr827RecipeGuardTest`, `Adr828RecipeHardeningTest`) : verts dans la suite complète.

## 9. Billing async — D-17 — **Audité, tests ajoutés ; correctif préparé, non appliqué**

- `--async` reste verrouillé ; aucun worker activé ; aucun job envoyé (`Queue::assertNothingPushed`) ; ledger et règles inchangés.
- Tests de non-régression (`Adr829DunningRetryConditionsTest`, 6) : pas de relance avant `next_retry_at` ; pas de seconde relance le même jour ; le job ignore une facture payée, annulée (`void`) ou irrécouvrable ; pas de second débit du portefeuille ; abonnement suspendu = règle existante (pas de réactivation sans paiement) ; `--async` refusé sans envoi.
- **Défaut démontré** : un `ProcessDunningBatchJob` consommé en retard puis relivré ne revérifie que le statut : `retry_count` passe de 1 à 3 en un jour (politique 1, 3, 7 jours) → irrécouvrable et suspension de la société jusqu'à 9 jours trop tôt.
- Correctif **préparé** : `docs/bmad/leezr-3-9/billing/deferred-dunning-guard.patch` (réclamation de la facture sous verrou + contrôle de `next_retry_at`) et son test `Adr829DeferredDunningDefectTest.php.txt` — validés ensemble (38 tests billing verts), puis retirés du code. Limite connue : deux jobs **simultanés** peuvent encore passer entre la réclamation et la tentative (un marqueur de réclamation serait nécessaire). Décision D-17 inchangée.

## 10. Incident Company 1 — D-13 — **Audité uniquement (restauration non exécutée)**

- Outil `restore-company1-april2026.php` : SHA-256 `2aa78470…` (inchangé) ; lignes `rows-binlog-verified.json` `487f1982…` (inchangé) ; sauvegarde `storage/app/incident-3-7/dev-state-2026-11-18.sql` `6551b86a…` (vérifiée) + 3 sauvegardes de tests de clone.
- Simulation en lecture seule sur le DEV : `would_restore`, **0 conflit** (`docs/bmad/leezr-3-9/incident/restore-simulation-dev-3-9.json`).
- Procédure et retour arrière (`--rollback=<backup.json>`) documentés depuis la 3.7 ; script SQL 3.6 toujours marqué obsolète. Période 11, feuilles 17 et 143 non touchées.

## 11. RBAC chauffeur — D-8 — **Audité uniquement**

Simulation en lecture seule sur le DEV : **8 rôles**, inchangé (`docs/bmad/leezr-3-9/rbac/…-3-9.json`). Rôles : société 1 (rôle 7, 12 membres), 2, 3, 31, 32, 33, 34 (0 membre chacune), 101 (rôle 171, 1 membre). Vague 1 proposée : 2, 3, 31–34 ; 1 et 101 exclues. Aucune permission modifiée.

## 12. Pré-déploiement — **Préparé ; production non vérifiée**

- Runbook : `docs/bmad/leezr-3-9/PRE-DEPLOYMENT-RUNBOOK.md` — révision déployée, migrations, sauvegarde, variables, presets, Redis, planificateur, workers et isolation des files, PHP-FPM, Apache/proxy et buffering SSE, mode dégradé, retour arrière (code, base, temps réel, workers), surveillance post-déploiement.
- `docs/bmad/leezr-3-8/PRODUCTION-READONLY-CHECKS.md` conservé tel quel.
- Mode dégradé de production : `REALTIME_DRIVER=null` → 204, polling, aucun worker tenu (réutilise la configuration existante ; ni Reverb ni WebSocket).
- **Aucune vérification de production** (pas d'accès lecture seule autorisé, pas de SSH partagé, pas de push). Aucune valeur de plafond SSE de production proposée.

## 13. Tests et build

| Contrôle | Résultat |
|---|---|
| PHPUnit complet | **4 743 réussis, 12 sautés, 3 échecs** — les 3 `ControllerSizeInvariant` historiques ; liste des 24 contrôleurs fautifs **identique** à la 3.8 (le contrôleur SSE, 249 lignes, n'y figure pas) |
| Tests ajoutés | `Adr829RealtimeAuthStatusTest` (4), `Adr829DunningRetryConditionsTest` (6), `Adr829ResetClockDayTest` (1), `tests/js` (5) |
| Temps réel / multi-tenant / RBAC / Workforce / billing | inclus dans la suite complète, verts |
| JS (`pnpm test:js`) | 5/5 |
| Build (`pnpm build`) | propre |
| E2E recette | 21/21 specs, 32/32 tests (clone neuf) |
| Banc SSE navigateur | 1/1 (S0–S9) |
| Migrations | aucune migration ajoutée ; aucune exécutée sur le DEV |
| Git | `dev` local, 4 commits, rien poussé ; fichiers protégés (`CLAUDE.md`, `typed-router.d.ts`, `components.d.ts`, `DMS-ARCHITECTURE.md`) intacts ; captures réécrites par les specs restaurées ; 14 captures ux2-777 initiales laissées telles quelles |

## 14. Risques résiduels

1. **Production inconnue** : révision, pool PHP-FPM, Apache (buffering, compression, timeouts), workers, planificateur, Redis — rien n'est constaté (D-11).
2. **DEV, plafond 1** : après un rechargement, l'ancien flux tient la place jusqu'au battement (30 s) ; l'onglet rechargé peut rester en polling jusqu'à la reprise suivante (≤ ~36 s). Fonctionnel, mais le temps réel du DEV est dégradé par choix.
3. **Session expirée** : la page n'est pas redirigée vers la connexion par le polling (comportement antérieur, constaté S8) ; les sondes SSE restent bornées (401).
4. **Sélecteur de société orphelin** : `CompanySwitcher.vue` n'est monté nulle part ; changer de société passe par le cookie et le rechargement.
5. **Relances différées** : défaut démontré, correctif préparé incomplet pour deux jobs simultanés ; `--async` verrouillé.
6. **Preuves de l'incident** : sauvegardes dans `storage/` (ignoré par git) ; le journal binaire source expire vers le 18/12 (lignes vérifiées versionnées).
7. **E2E** : seules les 21 specs du périmètre ont été rejouées ; elles supposent les comptes de démo du DEV (owner, bob) présents dans le clone.
8. Accès PDO direct à une autre base toujours hors de portée du garde (ADR-828).

## 15. Matrice GO / NO-GO

| Sujet | Statut | Verdict |
|---|---|---|
| DEV non saturable par les onglets SSE (D-19) | Implémenté et recetté (banc miroir Valet) | **GO DEV** |
| Reprise SSE après repli, nettoyage (D-20) | Implémenté et recetté | **GO DEV** |
| 401 / 403 / 429 / 204 sur le SSE (D-20) | Implémenté et recetté (banc + DEV réel sans session) | **GO DEV** |
| E2E de recette (D-21) | Implémenté et recetté (21/21, clone neuf) | **GO DEV** |
| Sécurité des recettes | Recetté (zéro écriture DEV) | **GO DEV** |
| Billing async (D-17) | Audité ; correctif préparé | **NO-GO activation** (verrou maintenu) |
| Incident Company 1 (D-13) | Audité ; simulation OK | **En attente d'autorisation** |
| RBAC chauffeur (D-8) | Audité ; simulation OK | **En attente d'autorisation** |
| Pré-déploiement (runbook) | Préparé | Prêt à dérouler |
| Vérifications de production (D-11) | **Bloqué** (accès non autorisé) | **NO-GO** |
| SSE en production (D-10) | Non réalisé (pool inconnu) ; repli `REALTIME_DRIVER=null` prêt | **NO-GO SSE**, repli polling possible |
| **Déploiement en production** | — | **NO-GO** : vérifications réelles de production absentes |
