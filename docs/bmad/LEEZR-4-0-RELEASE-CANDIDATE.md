# Leezr 4.0 — Release Candidate (ADR-830)

> Branche `dev` locale. Aucun push, aucun déploiement, aucun fetch/pull, aucun `git reset`, aucun sous-agent.
> Aucune écriture de recette sur le DEV (prouvé, §14), aucune restauration, aucune remédiation RBAC, aucun paiement
> réel, aucun changement de prix, de calcul de paie ou de règle de relance, aucun worker billing, `@core` intact.
> Statuts : **Implémenté et recetté** · **Implémenté, non recetté** · **Audité** · **Bloqué** · **Non réalisé**.

## 1. Commits locaux

| Commit | Contenu |
|---|---|
| `496784aa` | Billing : une seule tentative de relance par échéance, quelle que soit la livraison (verrou, revérification, comptage unique) |
| `fe83888c` | Sécurité / disponibilité : un limiteur sans nom ne limite plus que **sa** route |
| `47e94c36` | Temps réel et session : notifications en mode polling, retour à l'écran après reconnexion, accessibilité de deux listes |
| `1c8ad5bd` | E2E : campagnes interdites sur le DEV par défaut ; specs historiques réalignées et auto-préparées |
| (ce commit) | ADR-830, procédure de mise en production, preuves, ce rapport |

## 2. Synthèse

La 4.0 ne refond rien. Elle corrige quatre défauts démontrés :
1. **Relances** : un job en retard, relivré ou concurrent multipliait les tentatives.
2. **Limiteurs** : un compteur unique pour toutes les routes produisait des 429 sur les pages de facturation.
3. **Polling** : le badge de notifications restait figé sans SSE.
4. **Session** : après reconnexion, l'utilisateur ne revenait pas à son écran.

Elle ferme aussi une voie d'écriture accidentelle sur le DEV : une campagne E2E lancée avec la configuration par défaut. Le code est **candidat**. Le déploiement reste **NO-GO**, faute de preuves de production (§19).

## 3. Correctif Billing — **Implémenté et recetté**

Audit (`DunningEngine`, `DunningRetryStrategy`, `DunningTransitioner`, `ProcessDunningBatchJob`, adaptateurs) :
- Le job appelait la relance **admin**, qui ignore l'échéancier. Il ne revérifiait que le statut, jamais `next_retry_at`.
- Le balayage planifié et le job n'avaient **aucune exclusion mutuelle**. Deux workers pouvaient appeler le prestataire et compter deux tentatives.
- Une charge acceptée par le prestataire (`provider_attempted`) laissait la facture exigible jusqu'au webhook. Le balayage suivant recommençait.
- Points déjà sains, conservés :
  - **Stripe** : clé d'idempotence par facture et par moyen de paiement (fenêtre de 24 h du prestataire) ;
  - **portefeuille** : clé indexée par numéro de tentative ;
  - **passage en perte** : comptabilisé une seule fois (`alreadyPosted`) ;
  - les montants, intervalles, politiques et suspensions sont inchangés.

Correction (`DunningEngine::retryIfDue`, seul chemin automatique) :
1. Une **réservation atomique par facture** (verrou du cache de l'environnement). Un autre worker repart sans rien faire (`claimed_elsewhere`), sans appel au prestataire.
2. La facture est **relue sous réservation**. Il ne se passe rien si elle n'est plus `overdue` ou si `next_retry_at` n'est pas atteint (`not_due`).
3. Le `retry_count` lu à la réservation est **revérifié sous verrou de ligne** au moment de comptabiliser. Une tentative déjà comptée par un autre worker n'est jamais recomptée, même si le verrou a expiré.
4. Une charge acceptée attend la **date suivante de la politique** (intervalles inchangés), et non plus le prochain balayage.
5. La relance admin reste hors échéancier (acte humain explicite), mais elle est **sérialisée** avec les autres.

Le verrou SQL n'est pas présenté comme une idempotence externe. Celle-ci reste assurée par la clé du prestataire.

## 4. Tests de concurrence Billing — **Recetté**

`tests/Feature/Adr830DunningConcurrencyTest.php` (12 tests). Le prestataire est simulé : aucun réseau, aucun paiement réel.

| Cas | Résultat |
|---|---|
| Relance à échéance | 1 tentative ; prochaine date selon la politique (J+3) |
| Relance prématurée | aucune action, aucun appel au prestataire |
| Job en retard, puis relivré | aucune tentative supplémentaire |
| Deux workers concurrents (réservation détenue) | le second ne fait rien ; après libération, une seule tentative |
| Tentative déjà comptée par un autre worker | non recomptée |
| Facture payée, annulée ou irrécouvrable | jamais relancée |
| Doublons de jobs sur une facture payée | portefeuille débité une seule fois |
| Erreur du prestataire | 1 tentative échouée, aucune écriture comptable |
| Charge acceptée, webhook en retard | aucune seconde charge avant la date suivante |
| Réexécution après interruption | exactement 1 tentative |
| Doublons sur 5 jours | irrécouvrable et suspension seulement à la 3ᵉ échéance réelle ; une seule écriture de passage en perte |
| Société suspendue | règle existante, sans réactivation sans paiement |

**Course réelle sur clone** (`leezr_recipe830`, factures fictives, deux processus PHP, prestataire simulé lent) :

| | Appels au prestataire | `retry_count` |
|---|---|---|
| Avant correctif | **2** | **2** |
| Après correctif | **1** | **1** |

Preuves : `docs/bmad/leezr-4-0/billing/`. Non-régression : 49 tests Billing verts.

## 5. Mode asynchrone — **Inchangé (verrouillé)**

- `BILLING_ASYNC_QUEUES_ENABLED=false` ; `--async` refusé avant tout envoi.
- Aucun worker billing démarré.
- Le correctif rend l'asynchrone **techniquement sûr pour les relances**. Son activation reste une décision de déploiement distincte (D-17).

## 6. Stratégie SSE / polling — **Implémenté et recetté**

- **Profil A — Polling** : recommandé par défaut tant que le profil B n'est pas validé en production.
- **Profil B — SSE** : seulement après relevé du pool PHP-FPM et du proxy.

Le détail des deux profils est dans `docs/bmad/leezr-4-0/DEPLOYMENT-PROCEDURE.md` §1.

Défaut corrigé : sans SSE, le badge de notifications société n'était relu qu'au démarrage. Il est désormais relu toutes les 30 s, et seulement quand aucun flux n'est actif.

Recette du profil A : banc nginx et PHP-FPM à 5 workers, `REALTIME_DRIVER=null`, clone marqué. Preuves : `docs/bmad/leezr-4-0/realtime/`.

| Contrôle | Résultat |
|---|---|
| 5 onglets | uniquement des 204 (3 sondes par onglet), **1 worker actif sur 5**, jamais `max children reached`, API ≤ 113 ms |
| Rafraîchissement | navigation et notifications relues toutes les 30 s environ ; au plus 1 sonde SSE par minute |
| Notification | visible **sans rechargement** en 9,5 s |
| Changement d'état (module désactivé) | répercuté à la navigation par le polling ; état d'origine rétabli |
| Changement de société | aucun flux (204), navigation 200 |
| Fuite de connexions | aucun flux ni compteur |

## 7. Tests Platform — **Recetté (suite complète), avec réserves**

| Domaine | Résultat |
|---|---|
| Connexion, permissions, CRUD sociétés (suspension et réactivation) | verts ; `platform-crud` réaligné sur le libellé « Confirmer » |
| JobDomains, presets, publication (`adr797`, `adr798`, `adr799`) | verts |
| Modules, dépendances, catalogue (`module-dependency`, `company-module-toggle`, `commercial-catalog`) | verts (catalogue réaligné sur ADR-818) |
| Messagerie (XSS, réponse à tous, transfert) | verts. **XSS vérifié de bout en bout** : `<script>` et `onerror` neutralisés, texte sûr conservé |
| RBAC Platform (`platform-rbac-workflow`) | vert |
| Accessibilité Platform (`platform-a11y`) | **en échec, mineur** : contraste de la classe Vuexy `text-disabled` dans 26 widgets (« Aucune donnée »), barre de chargement sans libellé |

## 8. Tests Company — **Recetté sur les parcours actuels, avec réserves**

Parcours actuels verts : les 21 specs de recette de la 3.9, plus UX1 (accueil), UX3 (documents), UX4 (planning et temps), M7, la facturation (9/10, puis 10/10 après réalignement), les modules, la signature, le planning M6.A à M6.E, la grille et les lots 4b. Le détail des échecs restants est au §15.

## 9. RBAC — **Recetté**

- PHPUnit (permissions société et Platform, délégation, droits dormants, chauffeur) : vert.
- `m7-lot4` : un salarié reçoit 403 sur les salariés, les bulletins, la signature, les rôles, et la modification ou suspension d'un membre. L'escalade (créer un membre avec un rôle administratif) est **refusée** : 403, car il n'a pas le droit d'inviter. Les identifiants des sondes sont lus sur le clone, car ils ne sont plus exposés à un salarié depuis ADR-816.
- Rôles chauffeur : simulation inchangée (8 rôles) ; aucune remédiation.

## 10. Multi-tenant — **Recetté**

- `security-cross-tenant` : une société dont le compte n'est **pas** membre renvoie 403 sur les salariés, les membres et les expéditions, sans données dans la réponse. L'échec initial n'était pas une fuite : `owner@leezr.test` est réellement propriétaire de la société 2.
- PHPUnit : isolation hors HTTP (jobs, commandes), SSE (`RealtimeSseIsolationP0DTest`, 403 inter-société), relances (`CompanyContext::runFor`) : vert.
- Données du DEV (lecture seule) : 10 contrôles de références croisées (contrats, feuilles, pointages, congés, factures et abonnements, adhésions, salariés, modules, permissions, signatures), **0 incohérence**.

## 11. Workforce — **Recetté sur les parcours actuels**

Verts :
- pointage (cycle complet, pause, compteur persistant) ;
- congés (demande, approbation, registre) ;
- feuilles de temps ; paie (préparation) ; intégration (`adr796`) ;
- planning moderne (M6.A, M6.B, M6.D avec 11 tests, M6.E, grille, lots 4b, phase A) ;
- modèles M2 à M5.

Les échecs restants portent sur des générations antérieures du planning et des écrans RH (§15).

## 12. Contrôles de session — **Implémenté et recetté**

Le mécanisme existant (Sanctum SPA, ADR-357 et ADR-358b) est conservé.

| Cas | Résultat |
|---|---|
| Expiration côté serveur, onglets inactifs | requêtes de fond silencieuses (`_authCheck`) : 7 réponses 401 en 70 s sur 5 onglets, **pas de boucle** |
| Action utilisateur après expiration | renvoi vers la connexion |
| Reconnexion | **retour à l'écran d'origine** (`/company/workforce/planning`) ; polling relancé, 0 réponse 401 ensuite |
| Déconnexion dans un onglet | les autres onglets suivent (diffusion) ; **chacun revient à son écran** après reconnexion (corrigé : avant, retour à l'accueil) |
| Données non soumises | non conservées (aucun mécanisme de brouillon existant) ; non ajoutées |
| Platform | pas de mécanisme de retour à l'écran (évolution, non réalisée) |

## 13. Compatibilité historique — **Audité**

DEV, en lecture seule :
- 22 sociétés, toutes actives, dont la société 101 ;
- presets : 4 publiés, 4 brouillons, 3 archivés ;
- 126 modules actifs, 18 souscriptions de modules, 10 abonnements ;
- 49 rôles, dont **9 personnalisés** ;
- 34 documents générés, 223 contrats, 11 périodes de feuilles de temps, 1 paie ;
- 33 factures, 80 écritures comptables.

Migrations :
- 347/347 appliquées, aucune en attente ;
- **aucune migration ajoutée ni modifiée** depuis la 3.8 ;
- aucune migration destructive.

Incident Company 1 :
- outil, lignes et sauvegarde intacts (empreintes de la 3.9) ;
- **pas de restauration**.

Rôles chauffeur : non touchés ; les rôles personnalisés restent exclus de la remédiation.

## 14. Tests PHPUnit et E2E

| Contrôle | Résultat |
|---|---|
| PHPUnit complet | **4 757 réussis, 12 ignorés, 3 échecs** : les 3 `ControllerSizeInvariant` historiques, liste de 24 contrôleurs **identique** à la 3.8 et à la 3.9 |
| Tests ajoutés | `Adr830DunningConcurrencyTest` (12), `Adr830PublicThrottleTest` (2) |
| JS (`pnpm test:js`) | 5/5 |
| Build | propre |
| E2E, suite complète sur clone neuf marqué `leezr_recipe830` (profil polling) | 465 tests : **366 réussis, 90 échecs, 9 ignorés** au premier passage |
| E2E après corrections et relances | **31** échecs résolus et revérifiés ; état consolidé : **396 réussis, 58 échecs, 11 ignorés** |
| Recette du profil polling et de la session (banc isolé) | verte |
| Course de concurrence Billing (2 processus, clone) | conforme |
| Écritures sur le DEV | empreintes et journal binaire : seulement `sessions`, `company_audit_logs`, `platform_audit_logs`, `platform_ai_modules`, toutes dues à une **session Chrome interactive** (connexion, puis contrôle de santé de l'IA). **Zéro écriture de recette** |

Régression introduite puis corrigée pendant la mission : la première version du limiteur par route (alias) échappait à `withoutMiddleware()` dans 38 fichiers de tests et faisait échouer `one time code hardening`. Elle a été remplacée par une déclaration dans le conteneur ; la suite est verte.

## 15. Échecs restants (58 tests E2E)

Aucun de ces échecs n'a révélé de défaut produit **dans les cas analysés**. Ils n'ont pas tous été requalifiés un par un : ils ne sont **pas** déclarés verts.

| Groupe | Specs | Cause constatée ou probable | Statut |
|---|---|---|---|
| Anciens écrans Membres et Salariés | `company-crud`, `company-member-lifecycle`, `hrux-after`, `hrux2/3/5-dev*`, `person-p3a-dev`, `workforce-employee-file`, `workforce-wg2-employees` (4), `workforce-wg1-forms` (2), `adr786-rbac-recette` | Écrans fusionnés dans `/company/people` (M7) ; boutons supprimés (« Ajouter un membre »). L'export, l'import guidé et les actions groupées **existent toujours** (onglet RH) | Dérive, à requalifier |
| Ancienne navigation RH | `workforce-nav-e2-recette` (7), `workforce-hr-nav-group`, `home-p3b-dev`, `m7-lot3-self-service`, `e3r-r2` | Groupe « Ressources humaines » et lien « Mon espace » remplacés (ADR-818, accueil unique `/home`) | Dérive, à requalifier |
| Anciennes générations du planning | `workforce-wg4a-planning` (5), `workforce-wg4b-operations` (3), `lot1`, `lot3`, `lot4a`, `lot4b1`, `lotA`, `p1a`, `sp1a`, `sp1a1`, `step1`, `org-preset`, `m1b` (1), `m2` (1), `m6c` (mobile) | Barre d'outils FullCalendar interne supprimée (M6.D), vue par défaut « Semaine », hauteur bornée (ADR-824). La couverture moderne (M6.A à M6.E, grille, lots 4b, phase A) est **verte** | Remplacées en grande partie ; à requalifier |
| Données ou réglages | `workforce-eb2-cockpit` (salarié de démonstration absent), `workforce-eb4-lifecycle`, `workforce-employee-lifecycle` (contrat créé sans activation, probable), `workforce-config-cockpit`, `workforce-hr-settings`, `ux5-settings` | Préparation dépendante de l'état du DEV ou réglages déplacés | À requalifier |
| Outils visuels | `ui-lab-a11y`, `ui-lab-capture`, `workforce-visual-audit`, `presentation-resize` | Page de référence UI et captures, hors parcours métier | Hors périmètre RC |
| Accessibilité Platform | `platform-a11y` | Contraste `text-disabled` (Vuexy, 26 widgets) et barre de chargement sans libellé | **Mineur**, documenté |

## 16. Préparation du déploiement

- Procédure ordonnée (sauvegardes, vérifications préalables, migrations, configuration, déploiement, smoke tests, vérifications métier, surveillance, critères de retour arrière) : `docs/bmad/leezr-4-0/DEPLOYMENT-PROCEDURE.md`. Elle est **documentaire, sans autorisation d'exécution**.
- Démontré localement :
  - PHP : `^8.2` ; build CI en 8.3 ; les 96 paquets verrouillés acceptent 8.4 ;
  - migrations convergentes ;
  - garde-fous billing ;
  - profils temps réel ;
  - sauvegarde vérifiée avant migration (`deploy_release.sh` [4.9]) ;
  - retour arrière par bascule de lien.
- **Preuves de production manquantes** :
  - révision déployée ;
  - binaire et extensions PHP ;
  - `migrate:status` ;
  - variables (présence) ;
  - cron et unités de workers ;
  - files `billing*` vides ;
  - préfixes Redis ;
  - sessions et cookies ;
  - stockage et espace disque ;
  - pool PHP-FPM ;
  - Apache (`flushpackets`, compression, timeouts) ;
  - une restauration d'essai d'un dump ;
  - le dispositif de surveillance.

## 17. Matrice de risques

| Fonctionnalité | État | Tests | Risque | Décision requise | Impact déploiement |
|---|---|---|---|---|---|
| Relances (synchrone) | Corrigé | 12 tests + course réelle | Faible | — | Aucun |
| Relances asynchrones | Sûr techniquement, verrouillé | idem | Moyen (jamais exploité) | D-17 | Laisser désactivé |
| Limiteurs de débit | Corrigé | 2 tests + E2E facturation | Faible | — | **Bloquant si absent** (disponibilité) : inclus |
| Isolation multi-tenant | Vérifiée | E2E + PHPUnit + données | Faible | — | Aucun |
| RBAC et anti-escalade | Vérifiés | E2E + PHPUnit | Faible | — | Aucun |
| XSS messagerie | Vérifié (client, DOMPurify) | E2E | Faible (pas d'assainissement serveur) | — | Mineur |
| Temps réel, profil A | Recetté | banc | Faible | — | Recommandé |
| Temps réel, profil B (SSE) | Non validé en production | banc seulement | **Élevé** (saturation du pool) | D-10 | **Bloquant** s'il est activé sans relevé |
| Session et retour à l'écran | Corrigé | banc | Faible | — | Aucun |
| Campagnes E2E sur le DEV | Fermé (config) | — | — | — | Aucun |
| Specs E2E historiques (58) | Non requalifiées | — | Moyen (couverture de régression réduite) | D-21 bis | **Majeur** pour la confiance, non bloquant pour le code |
| Accessibilité Platform (contraste) | Connue | axe | Faible | — | Mineur |
| Incident Company 1 (DEV) | Non restauré | simulation | Données DEV seulement | D-13 | Aucun (DEV) |
| Rôles chauffeur historiques | Simulés | simulation | Moindre privilège partiel | D-8 | Aucun |
| Production (révision, migrations, pool, proxy, workers, cron, sauvegardes) | **Inconnue** | — | **Élevé** | D-11 | **Bloquant** |

## 18. Verdict A — Release Candidate (code)

**PRÊTE COMME CANDIDATE, avec réserve documentée.**
- Les défauts financiers, de disponibilité, de session et de polling démontrés sont corrigés et prouvés.
- PHPUnit est vert, hors 3 échecs historiques isolés et inchangés.
- Le build est propre et la sécurité (multi-tenant, RBAC, XSS) est vérifiée.
- Les parcours actuels sont verts.

Réserve : **58 tests E2E historiques restent en échec, non requalifiés un par un.** Les cas analysés relèvent tous de la dérive des specs ; aucun défaut produit n'a été trouvé parmi eux. La suite E2E n'est donc **pas** verte.

## 19. Verdict B — Déploiement (environnement)

**NO-GO — non vérifié.** Aucune preuve de l'état réel de la production n'a pu être recueillie (D-11) : révision, migrations, pool, proxy, workers, cron, Redis, sauvegardes. Le profil B (SSE) est à proscrire sans relevé du pool ; le profil A est prêt.

## 20. Décisions en attente et opérations soumises à votre autorisation

1. **D-11** — Accès en lecture seule à la production pour les vérifications (`docs/bmad/leezr-3-8/PRODUCTION-READONLY-CHECKS.md`) : qui, et par quel accès.
2. **Restauration d'essai** d'un dump de production sur une base jetable, hors production.
3. **Validation des migrations sur un clone de la production** (pas du DEV).
4. **Configuration de production** : profil A (`REALTIME_DRIVER=null`), `BILLING_ASYNC_QUEUES_ENABLED=false`, préfixes Redis, activation des nouvelles tâches planifiées.
5. **Déploiement** (push et workflow), après les points 1 à 4.
6. **D-10** — Profil B (SSE) en production : plafond à fixer après relevé du pool et du proxy.
7. **D-17** — Activer un jour l'asynchrone billing (worker `billing`, `billing-slow`), désormais sûr pour les relances.
8. **D-21 bis** — Requalifier ou retirer les 58 specs E2E historiques (liste au §15).
9. **D-13** — Restauration de la paie d'avril 2026 de la société 1 (DEV).
10. **D-8** — Remédiation des rôles chauffeur : vague 1 (sociétés 2, 3, 31 à 34), puis décisions séparées pour 1 et 101.
11. **Nettoyage des clones de recette** (`leezr_recipe827`, `829`, `829b`, `830`, `830s`, `leezr_audit820`, `821`, `827`, `leezr_incident_a/b/c`) : suppression de bases, irréversible. Les clones de l'incident servent de preuves.
12. Option : **contraste des états vides Platform** (classe Vuexy) : retouche visuelle hors périmètre RC.
