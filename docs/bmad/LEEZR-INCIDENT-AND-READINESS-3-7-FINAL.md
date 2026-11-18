# Leezr 3.7 — Incident Company 1, restauration contrôlée, sécurité des tests, pré-déploiement

ADR-827 · 2026-11-18 · branche `dev` locale, aucun push, aucun déploiement, aucune restauration sur le DEV.

**Statuts utilisés.**
- **CLONE** : réalisé sur un clone isolé.
- **PRÉP** : préparé uniquement.
- **LS-DEV** : vérifié en lecture seule sur le DEV.
- **NON** : non exécuté.
- **BLOQ** : bloqué en attente d'une décision.

## 1. Commits locaux

| Commit | Objet |
|---|---|
| `aff04844` | Les commandes de recette ne s'exécutent que sur un clone marqué (`RecipeCommand`, `recipe:status`, `make-clone.sh`, tests) |
| `c696494d` | Restauration de la paie d'avril 2026 vérifiée par le journal binaire : outil, données, preuves, recette sur clone (non exécutée sur le DEV) |
| `b6ec4235` | Capacité SSE mesurée sous un vrai pool PHP-FPM : course de comptage, vrai 429, limite verrouillée, plafond de flux |
| `b8ac861f` | Durée de vie du flux sortie du contrôleur, qui revient sous la limite des 250 lignes |
| *(commit docs)* | ADR-827, ce rapport, dossier de pré-déploiement, empreintes |

## 2. Chronologie de l'incident

Toutes les heures sont en UTC, le 2026-11-18.

| Heure | Fait | Source |
|---|---|---|
| 2026-10-19 09:22 | Création de la période 11 (avril 2026) par la même spec E2E, alors exécutée sur le DEV par conception (`--company=1`). Note de validation « E2E: reviewed, anomalies acknowledged », export JSON 09:22:30 | Journal d'audit (5 événements intacts), image binlog |
| 08:04:01 | Pendant la recette 3.6, `workforce-payroll-workflow` est lancée sans forcer la base, et son `beforeAll` exécute deux commandes en local, donc sur le DEV :<br>- `e2e:reset-payroll` supprime l'année-à-date 9, le calcul 9, la ligne 9 et la période 11 ;<br>- `e2e:prepare-payroll` supprime la feuille 17 et insère la feuille 143. | `binlog.000007`, positions 282802279 → 282809571 |
| 08:06 | Script de restauration 3.6 extrait du clone `leezr_audit821` | Fichier daté de 08:06 |
| 08:08 | Nouvelle exécution de la spec sur le clone (période 32, feuille 165) : le clone **ne contient plus** la période 11 | Lecture seule du clone |
| 3.7, 10:0x | Reconstitution, preuve, outil, recette (ce rapport). Aucune écriture sur le DEV | — |

## 3. Données touchées (LS-DEV)

Le journal binaire est au format ligne complet (`binlog_row_image=FULL`). Le drapeau `USE_SQL_FOREIGN_KEY_F` indique que les cascades de clés étrangères y sont journalisées. Sur la base `leezr`, pendant la fenêtre de l'incident, il ne contient **exactement que 5 suppressions et 1 insertion**.

| Table | Ligne | Contenu clé |
|---|---|---|
| `workforce_payroll_runs` | 11 | Société 1, du 2026-04-01 au 2026-04-30, mensuelle, **exported**, brut 220 000, 1 salarié ; calculée, validée et exportée le 19/10 ; clé d'idempotence `payroll:1:2026-04-01:2026-04-30` |
| `workforce_payroll_lines` | 9 | Période 11, salarié 3, feuille 17, 9 240 min, brut 220 000 |
| `workforce_payroll_calculations` | 9 | `payroll-calc-v2`, statut validated |
| `workforce_payroll_ytd_snapshots` | 9 | Salarié 3, 2026, mois 4 |
| `workforce_timesheet_periods` | 17 → **143** | Avril 2026, verrouillée, 9 240 min ; **la feuille 17 n'avait aucune ligne** (aucune suppression de `timesheet_lines`) |

Les dépendances ont été vérifiées sur le DEV, en lecture seule :
- aucune déclaration DSN, aucun bulletin publié pour avril (les bulletins publiés concernent 2026-08 et 2026-09), aucun document ni lot d'import ne référence 11, 9, 17 ou 143 ;
- la feuille 143 n'a aucune dépendance ;
- le journal d'audit de la période 11 est intact.

Aucune séquence ni projection n'a été affectée : les identifiants restaurés sont inférieurs aux compteurs `AUTO_INCREMENT`. Depuis l'incident, le DEV n'a reçu que des écritures de `sessions` et 1 `auth.logout` (utilisateur 1, 10:01).

## 4. Provenance des données de restauration

- **Source authentique** : les images avant suppression du journal binaire du DEV (serveur `3e824880-…`). Je les ai extraites avec un analyseur indépendant (`incident/binlog2sql.php`).
- **Contre-preuve** : le script 3.6, chargé dans une base de travail. Ses **5 lignes sont identiques, octet par octet** à celles du journal binaire (SHA-256 par ligne, toutes colonnes, voir `clone-tests/hash-comparison-3-6-script-vs-binlog.txt`).
- La feuille 143 actuelle du DEV est identique à son image d'insertion de l'incident. Elle n'a donc pas été modifiée depuis.
- Le clone `leezr_audit821` **n'est plus une source** : il a été réécrit à 08:08.
- Aucune fixture ni aucun calcul reconstitué n'est utilisé.

## 5. Sauvegardes vérifiées

| Élément | Emplacement | Contrôle |
|---|---|---|
| Dump complet du DEV avant toute opération (`--single-transaction`) | `storage/app/incident-3-7/dev-state-2026-11-18.sql` (hors git) | SHA-256 `6551b86a…98fa0`, revérifié |
| Empreinte du DEV (`CHECKSUM TABLE`, 197 tables) | `incident/clone-tests/dev-fingerprint-before.tsv` | Clone créé à partir du dump : **identique sur les 197 tables** |
| Images du journal binaire | `incident/binlog-dev-row-images-2026-11-18.txt`, `binlog-positions.txt` | Rétention binlog : 30 jours |
| Sauvegarde de la feuille 143 avant suppression | Produite par l'outil (`backup-<db>-<horodatage>.json`) | Utilisée par le retour arrière |

## 6. Tests de restauration (CLONE)

Outil `docs/bmad/leezr-3-7/incident/restore-company1-april2026.php`. C'est un script autonome : ni Laravel, ni `.env`, la cible est donnée explicitement puis vérifiée sur la connexion réelle.

| Cas | Résultat |
|---|---|
| Simulation | `would_restore`, 0 écriture |
| `--apply` avec un `--confirm-database` différent | Refusé (code 2) |
| Restauration | `restored` : feuille 143 sauvegardée puis retirée, 5 lignes insérées, empreinte de chaque ligne vérifiée, une seule transaction |
| Relance (idempotence) | `already_restored`, 0 écriture |
| Retour arrière | `rollback_done` : clone **identique à l'état initial sur les 197 tables** (feuille 143 comprise) |
| Second retour arrière | Refusé (lignes absentes) |
| Re-restauration | Identique à la première (empreintes) |
| Relecture applicative (Laravel, clone) | Période 11 exportée le 2026-10-19 10:22:30, 1 ligne, feuille 17 verrouillée, calcul validé, 143 absente |

**Conflits** : 9 scénarios, chacun sur un clone jetable. Tous sont **refusés sans aucune écriture** (empreinte identique) :

| Scénario | Réaction |
|---|---|
| Une période couvrant avril existe déjà | Refus « a payroll run of company 1 already covers April 2026 » |
| Identifiant de ligne 9 occupé | Refus « id 9 is occupied by a different row » |
| Identifiant 17 occupé | Refus |
| Feuille 143 modifiée | Refus « modified after the incident » |
| Ligne dépendant de la feuille 143 | Refus « dependent rows » |
| État partiel (1 ligne sur 5) | Refus « partial state » |
| Dérive du schéma (colonne ajoutée) | Refus « schema drift » |
| Contrainte en échec en cours de transaction (déclencheur forcé) | Annulation complète (code 3), feuille 143 conservée |
| Salarié 3 hors société 1 | Refus |

## 7. Résultats avant/après sur clone

| État | Tables modifiées par rapport au DEV actuel |
|---|---|
| Après incident (clone initial) | 0, identique au DEV |
| Après restauration | 5 tables ; différence ligne à ligne : +5 lignes (identiques au binlog), −1 (feuille 143). Aucune autre ligne, aucune autre société |
| Après retour arrière | 0 |

## 8. Risques d'une restauration sur le DEV

- La période 11 était **elle-même une donnée de recette E2E** (créée le 19/10 par la même spec). La restaurer rend au DEV son état d'avant l'incident ; cela ne restaure pas une paie réelle.
- Les deux exécutions de la spec ont remis la clé d'idempotence en usage : la restauration la reprend à l'identique, sans doublon possible (contrôle explicite).
- La période restaurée est **exportée** : la refaire passer par une spec la supprimerait à nouveau. Le garde 3.7 l'interdit désormais sur le DEV.
- Si quelqu'un modifie la feuille 143 ou crée une paie d'avril avant l'exécution, l'outil refuse : il faut alors une décision manuelle.
- Le retour arrière n'est possible que tant que les lignes restaurées n'ont pas changé.

## 9. Procédure D-13 proposée (NON exécutée)

1. Contrôle du DEV, en lecture seule : `php restore-company1-april2026.php --defaults-file=<option-file-0600> --database=leezr` doit répondre `would_restore` et `sheet_143: as_created_by_incident`.
2. Empreinte avant : `fingerprint.sh <option-file> leezr > before.tsv`.
3. Exécution : la même commande avec `--apply --confirm-database=leezr --backup-dir=storage/app/incident-3-7`.
4. Contrôle : relance (doit répondre `already_restored`), empreinte après (seules les 5 tables de paie et de feuilles de temps changent), relecture de la période 11 dans l'application.
5. Retour arrière si nécessaire : `--rollback=<backup.json> --apply --confirm-database=leezr`.

## 10. Protections E2E installées (CLONE et LS-DEV)

- `App\Console\Recipe\RecipeCommand` : base de **tous** les `e2e:*` (12 commandes, invariant testé). Le contrôle s'exécute dans `execute()`, donc au point d'exécution réel, quel que soit le chemin d'appel : `php artisan`, `Artisan::call`, `$this->call()` ou processus enfant.
- `App\Console\Recipe\RecipeDatabaseGuard` lit l'identité sur la **connexion réelle** (`SELECT DATABASE()`, ou le fichier SQLite) et applique cinq règles :
  1. `APP_ENV=production` est refusé ;
  2. une base non identifiable (vide ou `:memory:`) est refusée ;
  3. la base connectée doit être celle configurée, ce qui détecte un `DB_URL` ou un socket trompeur ;
  4. le nom doit suivre la convention `leezr_(audit|clone|recipe)…` et ne pas figurer parmi `leezr`, `c1leezr`, `c1leezrdev` (listes en constantes : aucune variable ne peut les élargir) ;
  5. la base doit porter un marqueur `_recipe_clone_identity` à une seule ligne qui la nomme : un dump de clone restauré ailleurs perd sa preuve.
- `APP_ENV=testing` ne vaut jamais preuve. Aucune option ne contourne le garde : `--force` n'existe pas et est rejeté.
- Chaque refus est journalisé dans `storage/logs/recipe-guard.log`, et aucune écriture n'a lieu.
- Le corps de la commande s'exécute dans **une transaction** : une exception ou un code d'échec annule tout, et un processus tué ne laisse aucune écriture partielle.
- Outils : `recipe:status` (lecture seule) et `scripts/recipe/make-clone.sh`, qui refuse les noms protégés et les clones existants, conserve le dump et son SHA-256, puis marque le clone.
- Commandes auditées hors `e2e:*` :
  - `leezr:dev-reset` : `migrate:fresh` du DEV par conception, protégé par `--force --i-have-a-backup` ;
  - `workforce:planning-loadtest` : société fictive dédiée ;
  - `onboarding:cleanup-fixtures` : nettoyage des fixtures.

  Elles sont conçues pour le DEV et non modifiées (D-16).
- **Risque résiduel** : 9 specs écrivent via `artisan tinker --execute` (dont 3 suppressions de presets créés par la spec), et le garde ne peut pas couvrir `tinker` (D-15).

## 11. Tests de refus DEV / production

| Test | Où | Résultat |
|---|---|---|
| `e2e:reset-payroll` contre le DEV | Processus réel (LS-DEV) | Refusé, code 1 ; binlog : 0 écriture sur `leezr` |
| Configuration trompeuse (`DB_DATABASE` = clone, `DB_URL` → DEV) | Processus réel | Refusé : « live database (leezr) is not the configured one » |
| Clone non marqué | Processus réel | Refusé |
| `APP_ENV=production` sur un clone marqué | Processus réel | Refusé |
| Configuration vide | `recipe:status` | Refusé |
| Clone marqué | Processus réel | Autorisé (écritures uniquement sur le clone, binlog) |
| **Spec de l'incident relancée comme en 3.6** (sans forcer la base) | Playwright → DEV | Échoue sur le refus du garde, **0 écriture sur le DEV** (binlog) |
| Matrice de décision (16 cas), invariant `e2e:*`, base de test refusée sans écriture, transaction annulée, **6 refus et 1 acceptation en vrais processus enfants** (fichiers SQLite) | PHPUnit `Adr827RecipeGuardTest` | 5/5 |

## 12. État des règles Workforce

Les protections 3.6 sont inchangées et testées (211 tests paie et Workforce verts) :
- modèle inconnu ;
- forfait jours payé à l'heure ou avec des heures supplémentaires ;
- forfait heures en dépassement ;
- temps partiel au-delà de son contrat ;
- exclusion avec justification obligatoire ;
- blocage des anomalies de calcul.

Les spécificités de chaque modèle restent **non prises en charge** (D-1 à D-6) : heures complémentaires et leurs taux, relevé de jours, seuil propre au forfait heures. Aucun taux universel, aucun recalcul d'une période passée.

## 13. Planificateur (PRÉP)

Correction du rapport 3.6 : le constat « planificateur absent » était un faux négatif, déjà corrigé par ADR-739. L'unique déclencheur est le cron ISPConfig de chaque site, et le timer systemd redondant est retiré au déploiement. Les 30 tâches ont toutes `withoutOverlapping()` (vérifié par analyse du fichier). Les verrous passent par la base de chaque environnement (`CACHE_STORE=database` dans le gabarit), avec journaux par tâche, battement de cœur et instrumentation. Reste à vérifier en lecture seule en production : le cron et le `.env` (D-11). Détail : `leezr-3-7/PRE-DEPLOYMENT-RUNBOOK.md`.

## 14. Workers (PRÉP)

Les workers sont déjà séparés par environnement (ADR-738 : `leezr-queue-{ai,default}-{web2,web3}`), et `QUEUE_CONNECTION=database` isole les files par base. Ils redémarrent avec `--max-jobs`/`--max-time` et `queue:restart`, et gèrent les échecs avec `--tries=3`, `failed_jobs` et `billing:check-dlq`.

**Constat nouveau** : les files `billing` et `billing-slow` n'ont aucun consommateur. Sans effet aujourd'hui, car le planificateur lance la facturation en ligne ; mais un `--async` manuel bloquerait ses jobs (D-17). Les 77 jobs historiques du DEV ne sont pas lancés.

## 15. Audit de la migration (PRÉP, LS-DEV)

Il s'agit de `2026_10_30_150000`. Sa version initiale `109d95cd` (dans `main`) a des noms d'index de 68 caractères, refusés par MySQL et MariaDB. Elle échoue **après** la création de la table et de ses clés étrangères, sans être enregistrée. La version actuelle `9d1bf431` a des noms courts.

La migration est rejouable (`hasColumn`/`hasTable`), et la réparation additive `2026_11_23_000002` existe déjà. Côté DEV, elle est enregistrée et ses index sont présents. Tous les états possibles en production convergent sans nouvelle migration. Reste à vérifier en lecture seule : `migrations` et `SHOW INDEX` en production (D-11).

## 16. Audit SSE / PHP-FPM (CLONE : nginx et PHP-FPM isolés, ports 8827/9827, base `leezr_audit821`, préfixe Redis dédié)

| Mesure | Avant | Après |
|---|---|---|
| Saturation : 3 flux / 4 workers | `/api/me` 0,04 s | — |
| Saturation : 4 flux / 4 workers | `/api/me` **17,1 s** (toute l'application attend) | Avec `REALTIME_MAX_CONCURRENT_STREAMS=3` : le 4ᵉ flux reçoit un 429, `/api/me` 0,05 s |
| Rafales de 2, 4 et 3 onglets du même utilisateur | Fuite de **n−1 par rafale** (6 au total ; c'est la cause des compteurs gonflés) | 0 fuite |
| Refus de connexion (limite atteinte) | **500** (TypeError : `JsonResponse` au lieu de `StreamedResponse`) | Vrai 429, l'onglet passe au polling |
| Flux de 40 s, `max_execution_time=30` verrouillé par le pool | Tué à 30,1 s, **erreur fatale** (le correctif 3.6 est inopérant sous `php_admin_value`) | Fin propre à 25 s, 0 fatale, compteurs à 0 |
| Fermeture brutale du client (battement de 2 s) | Worker libéré en 3 s | Inchangé (≈ un battement : jusqu'à 30 s avec les réglages par défaut) |
| Nettoyage Redis après les flux | 0 | 0 |

Ces résultats corrigent ce que le rapport 3.6 annonçait : la phrase « chaque flux vit sa durée configurée » n'est vraie que si le pool laisse la limite d'exécution modifiable.

**Dimensionnement.** Un onglet ouvert occupe un worker en permanence et se reconnecte à chaque fin de flux. Les connexions longues sous PHP-FPM sont donc dimensionnables tant que `pm.max_children` dépasse largement le nombre d'onglets ouverts simultanément, plafond configuré compris. Au-delà, il faut passer au polling, qui existe déjà en repli. Les mesures ne justifient pas une migration WebSocket à ce stade.

Sous Linux en production, `max_execution_time` compte le temps CPU, pas le temps écoulé (documentation PHP). Les arrêts y sont donc probablement plus rares qu'en DEV macOS, ce qui n'a pas été mesuré, mais la saturation du pool est d'autant plus probable. `request_terminate_timeout` et le proxy Apache d'ISPConfig ne sont pas mesurés (D-10).

Mes premières rafales ont touché, avant l'isolation de Redis, le compteur global partagé par le DEV (+14, clé Redis à expiration de 10 minutes, sans lien avec la base). Je l'ai laissé expirer sans y toucher.

## 17. RBAC historique (NON appliqué)

`rbac:driver-role-remediate` est inchangé : simulation, journal d'audit, restauration par lot, exclusion des rôles personnalisés, permissions de livraison conservées. La procédure progressive est décrite dans le dossier de pré-déploiement, §4 (D-8).

## 18. Tests et build

- **PHPUnit complet** : **4 726 réussis**, 12 sautés, **3 échecs `ControllerSizeInvariant` historiques**.
  - Ces 3 tests masquaient une **régression réelle** : le contrôleur SSE était passé à 268 lignes en 3.6, puis à 278 en 3.7, au-delà de la limite de 250. Elle est corrigée (246 lignes, `StreamLifetime`).
  - Les 19 contrôleurs encore en infraction n'ont pas été modifiés depuis la 3.5.
- **Tests ciblés après correction** : garde 5/5, capacité SSE 4/4, suivi des connexions, isolation SSE, teardown E2E 4/4, paie et Workforce 211/211.
- **Build** : propre.
- **E2E sur le clone marqué `leezr_recipe827`** : paie (créer, calculer, valider, exporter), feuilles de temps et contrats : **5/5**, commandes de recette autorisées sur le clone.
- **DEV avant/après la campagne** : 196 tables sur 197 identiques. `sessions` a changé : 25 mises à jour depuis 10:11 UTC, du trafic applicatif dont une session invitée sur Chrome non headless. Aucune autre écriture, d'après le binlog.

## 19. Blocages résiduels

| Point | Statut |
|---|---|
| Restauration sur le DEV | BLOQ (D-13) — outil prêt et recetté |
| Écritures `tinker` dans 9 specs | Risque résiduel (D-15) |
| Pool PHP-FPM de production inconnu, plafond de flux à fixer | BLOQ avant déploiement (D-10) |
| Vérifications en lecture seule de la production (cron, `.env`, migration, index) | BLOQ, accès à autoriser (D-11) |
| Files `billing*` sans worker | Latent (D-17) |
| RBAC chauffeur | BLOQ (D-8) |
| SHA de production réellement servi | Non revérifié à distance (notes : `7d49e78`) |

## 20. Décisions nécessitant votre autorisation

- **D-13** : exécuter la restauration sur le DEV selon §9 (avec sauvegarde, empreintes et retour arrière possible).
- **D-10** : autoriser le relevé, en lecture seule, du pool PHP-FPM de production, puis fixer `REALTIME_MAX_CONCURRENT_STREAMS` et `REALTIME_STREAM_TIMEOUT`.
- **D-11** : autoriser les vérifications en lecture seule de la production listées dans le dossier de pré-déploiement.
- **D-15** : remplacer les écritures `tinker` des specs par des commandes `e2e:*` protégées, ou les réserver aux clones.
- **D-16** : appliquer ou non le garde à `workforce:planning-loadtest` et `onboarding:cleanup-fixtures`, aujourd'hui utilisées sur le DEV par conception.
- **D-17** : consommer les files `billing` et `billing-slow`, ou interdire `--async`.
- **D-8** : migration progressive du rôle chauffeur.
- **D-1 à D-6, D-9** : règles propres aux modèles de temps, et contrat de la société 21. Inchangés depuis la 3.6.

Clones conservés pour vérification :
- `leezr_audit827` : DEV d'après l'incident, restauré ;
- `leezr_recipe827` : clone marqué de la recette ;
- `leezr_incident_a`, `leezr_incident_b`, `leezr_incident_c` : bases de comparaison des empreintes.
