# Leezr 4.2 — M10 : entonnoir d'inscription, recettes reproductibles, état de release

ADR-849 (entonnoir) et ADR-850 (recettes, release). Travail local sur `dev`. Aucun push, aucun déploiement, aucune donnée historique modifiée.

## 1. Entonnoir d'inscription publique (ADR-849)

| Mesure | Source réelle | Définition |
|---|---|---|
| Inscription commencée | écran `/register` → `POST /api/register/funnel-track` `started` | une par **parcours** |
| Étapes franchies | même endpoint, à chaque passage vers l'avant | `industry`, `plan`, `addons`, `company`, `account` : les étapes réelles de l'écran |
| Inscription terminée | **serveur** (`RegisterCompanyUseCase`) | écrit à la création de la société, rattaché au parcours |
| Activation | état existant | société inscrite dont l'abonnement courant est `active` ou `trialing`, donc pas en attente de paiement |
| Première connexion | journal existant `auth.login` | connexion du propriétaire via `/login` **après** l'inscription (l'inscription elle-même ne journalise pas de connexion) |
| En cours | événements | non terminé, activité il y a moins de **24 h** |
| Abandon | événements | **non terminé ET aucune activité depuis 24 h** (`REGISTRATION_FUNNEL_ABANDON_AFTER_HOURS`) ; jamais déduit d'un clic, d'une fermeture d'onglet ou d'une donnée manquante |

- **Période** : cohorte des parcours *commencés* entre `from` et `to` (affichés). Paramètre `days` borné entre 1 et 90.
- **Parcours** : identifiant aléatoire généré par le navigateur (`sessionStorage`, durée de l'onglet). Il remplace l'identifiant de session Laravel, qui est régénéré pendant le parcours. Les anciennes lignes restent lisibles par leur session.
- **Doublons** : un index unique (parcours, étape) ; une étape renvoyée répond `duplicate` et n'est comptée qu'une fois.
- **Données collectées** :
  - l'identifiant de parcours, l'étape et l'**hôte** du site d'origine, sans chemin ni paramètres ;
  - jamais de nom, d'e-mail, de mot de passe, de jeton ni de champ libre (test : les champs envoyés en plus sont ignorés et rien n'est stocké).
- **Volume** : limiteur de 30 requêtes par minute, au plus 6 lignes par parcours, `source` de 100 caractères au plus.
- **Séparation des sources** : seules l'inscription publique et l'endpoint écrivent l'entonnoir (test structurel). Les sociétés créées par Platform (ADR-834) n'y figurent jamais.
- **Consultation** : `view_usage_monitoring` (Platform) ; un opérateur sans ce droit reçoit 403.
- **Alerte** : `registration:detect-abandoned` applique la **même** définition. Les deux définitions concurrentes (sans grâce, et 2 h depuis le début) sont supprimées.
- **Consentement et confidentialité** : mesure de premier niveau, sans cookie (`sessionStorage`), sans donnée personnelle, à finalité unique (conversion de l'inscription), non partagée. **À valider juridiquement** (D-M10-1) : exemption de consentement pour mesure d'audience strictement nécessaire.

## 2. Recettes E2E reproductibles (ADR-850)

Problème constaté en M9 : les recettes ne passaient qu'une par une, dans un ordre précis et après des fixtures lancées à la main. En cause :
- un mot de passe et la 2FA modifiés sur des comptes partagés ;
- des états de société hérités d'une recette précédente.

Correctifs :
- **Chaque recette se prépare elle-même** (`test.beforeAll` → `tests/e2e/recipe-fixtures.js`), avec les fixtures existantes (`scripts/audit842-*-fixture.php`), qui refusent toute base autre que le clone marqué.
- **Comptes indépendants** : M2 (mot de passe et 2FA) utilise ses propres comptes (`pwd842`, `twofa842` Company et Platform), recréés à chaque exécution. Le propriétaire, `member842` et `viewer842` ne sont plus jamais modifiés. Le journal est lu par une session du propriétaire.
- **Plus d'identifiant codé en dur** : l'identifiant `184` de M2 est remplacé par la sortie JSON de la fixture.
- **États explicites** :
  - M5 prépare la société en Pro, puis prévisualise un changement vers Business ;
  - M4 calcule ses montants attendus (SQL indépendant) **après** sa préparation ; elle n'est plus ignorée ;
  - M8, M8.1 et M8.2 se rétablissent toujours en `afterAll` : la société n'est jamais laissée suspendue ;
  - M9 prépare les collaborateurs M7 et leur contrat.
- **Navigation** : chaque test ouvre un contexte navigateur neuf (comportement Playwright conservé).
- **File de traitement** : le serveur de recette tourne en `QUEUE_CONNECTION=sync`. Il traite uniquement les tâches créées par la recette, jamais la file historique locale.

Résultats des campagnes : §5.

## 3. Non-régression

- **Contrat des routes déplacées en M9** : 973 routes avant M9 et 972 après. Une seule route retirée, volontairement (`api/whoami`) ; aucune ajoutée. Les noms et middlewares sont **identiques**, et 25 routes pointent vers un nouveau contrôleur à la même adresse.
- **Migrations sur copie MySQL représentative** : clone marqué `leezr_audit850` de la base locale `leezr` (MySQL 9.6, 201 tables, 12 275 lignes ; la source n'a été que lue).
  - les 4 migrations en attente localement (M8 → M10) s'appliquent ;
  - **aucune ligne des tables existantes n'est modifiée** ; une nouvelle table `company_data_exports` ;
  - `platform_audit_logs.actor_type` identique à l'octet près ; recopie `actor_role` sans écart ;
  - le **retour arrière** (`rollback --step=4`) restitue l'état initial (tables et lignes), et la réapplication réussit.
- Parcours transversaux : §5.

## 4. Correctif découvert pendant la vérification

- **Export complet resté « en préparation »** (M8) : le worker DEV `default` tourne avec `--timeout=60` et la file avec `retry_after=90`. Un export de plus de 90 secondes est repris puis échoue en « nombre maximal de tentatives », sans passer par le code d'échec : l'export restait « running » et **bloquait toute nouvelle demande** de la société.
  - Correctif : `BuildCompanyDataExportJob::failed()` marque l'export en échec et le journalise ; une préparation plus ancienne que la durée maximale de la tâche ne bloque plus une nouvelle demande.
  - Test ajouté dans `Adr846CompanyDataRecoveryTest`.
  - Reste une précondition d'exploitation (P-6) : des exports volumineux nécessitent une file dont `retry_after` dépasse 1 800 secondes.
- **Constat signalé, non corrigé (Billing)** : l'écran d'inscription transmet `coupon_code`, mais le store `auth.register` ne l'envoie pas au serveur. **Les codes promo saisis à l'inscription sont ignorés.** Décision D-M10-2 : toucher à ce point modifie un comportement commercial.

## 5. Tests

| Type | Résultat |
|---|---|
| PHPUnit, suite complète lancée seule (fin de mission) | **4 887 tests verts, 0 échec**, 13 ignorés |
| Tests M10 | `Adr849RegistrationFunnelTest` (4), `Adr846` + nouveau test « tâche perdue » (15 tests d'export verts) |
| Tests JS | 42 sur 42 (`registration-funnel.test.mjs` ajouté) |
| Build | propre |
| Recettes, **campagne A** (ordre alphabétique, première passe) | 5 recettes en échec, toutes **dues à la préparation** et corrigées : imports oubliés (M1, M2), M5 dépendant de l'offre laissée par une autre recette, argument mal lu par la fixture M9, un blocage de 1,2 h en M8 non reproduit (M8 seule : 21 s ; campagnes B et C : 21 s) |
| **Campagne B** (ordre inverse) | **41 sur 41** |
| **Campagne C** (ordre mélangé + M10) | **43 sur 43** |
| Parcours transversaux de l'audit (`audit842-journeys`, `audit842-isolation`) | isolation : **19 sondes sur 19 refusées** ; parcours : 18 étapes sur 21. Les 3 échecs (J3/J3b, invitation) **échouaient déjà à l'audit initial** et visent l'écran d'avant M6 ; même intention rejouée sur l'écran actuel dans la recette M10 : invitation envoyée, **e-mail reçu**. Résultats dans `transversal/` |
| Contrat des routes déplacées (M9) | conforme (§3) |
| Migrations sur copie MySQL | conformes, retour arrière vérifié (§3) |

**Non exécuté** :
- migrations sur **MariaDB** (DEV est sous MariaDB ; test fait sur MySQL 9.6) ;
- migrations ADR-834/835 (`2026_12_14`, `2026_12_15`) sur des données de type DEV : elles sont déjà appliquées sur la base locale, donc non rejouées sur `leezr_audit850` ;
- tout test sur DEV ou PROD (interdit) ;
- Stripe réel ;
- charge des exports volumineux.

## 6. État de release

### 6.1 Commits locaux à déployer (47 depuis `82e35416`, déployé sur DEV le 19/11)

| Ordre | Commits | Contenu | Dépendance |
|---|---|---|---|
| 1 | `c9a05518`, `66141aef`, `9585d1ac`, `9bee60c3`, `5d0b9604`, `06524897` | ADR-833 : pipeline unique GitHub Actions, `deploy.sh` et `webhook.php` inertes, DevSeeder refuse une base peuplée, runbook d'incident et scripts de verrouillage des comptes de démonstration | **Doit être déployé en premier** : sans lui, l'ancien `deploy.sh` relance DevSeeder sur DEV (incident du 19/11) |
| 2 | `e9d1664f` … `9d9fb4b5` | ADR-834/835 : création de société par Platform, champs de création | migrations `2026_12_14`, `2026_12_15` |
| 3 | `ac779c65` | audit 4.2 (documentation) | — |
| 4 | `b6b38959` … `662be384` | M1 → M8.2 (ADR-837 → 847) : facturation et MRR, sécurité 2FA (M2), permissions, ergonomie, exports, fin de relation, connexion Platform, mode suspendu | migrations `2026_12_16` → `2026_12_18` ; M2 (ADR-838) est un correctif de sécurité |
| 5 | `adee4bf7` … `dd07a2ea` | M9 (ADR-848) : assainissement technique | — |
| 6 | `14ea3b43`, `9a75d961`, documentation M10 | M10 (ADR-849/850) | migration `2026_12_19` |

### 6.2 Migrations en attente sur DEV (6, toutes additives)

`2026_12_14_000001_create_platform_company_creations_table`, `2026_12_15_000001_create_jobdomain_company_fields_table`, `2026_12_16_000001_create_company_data_exports_table`, `2026_12_17_000001_add_exceptional_procedure_to_company_data_exports`, `2026_12_18_000001_add_actor_role_to_platform_audit_logs` (avec recopie de l'historique, sans réécriture), `2026_12_19_000001_add_funnel_id_to_registration_funnel_events`.

Le pipeline exécute `migrate --force` puis `system:sync --force`. La synchronisation ajoute la permission `exceptional_company_export` et son groupe dédié ; aucune attribution automatique n'est faite.

### 6.3 Fonctionnalités terminées (DEV local, non déployées)

- Facturation : historique, changement d'offre depuis la fiche, MRR unique.
- Sécurité des comptes : 2FA à facteur frais, fermeture des sessions.
- Pages « module indisponible » et 403.
- Regroupement Facturation Platform.
- « Ajouter une personne » unifié.
- Exports temps, planning et absences.
- Fin de relation : export complet, export partiel ou échoué, export pendant suspension, procédure exceptionnelle (**exécution désactivée**).
- Connexion Platform avec clé de rôle longue.
- Mode Company suspendue.
- Assainissement M9.
- Entonnoir d'inscription mesuré.
- Recettes reproductibles.

### 6.4 Risques résiduels

| # | Risque | Mesure |
|---|---|---|
| R-1 | Migrations non testées sur MariaDB | appliquer d'abord sur un clone MariaDB de DEV, ou sur DEV avec sauvegarde vérifiée |
| R-2 | Exports volumineux et `retry_after=90` en DEV | précondition P-6 ; l'export ne peut plus rester bloqué (correctif §4) |
| R-3 | Workers et planificateur : constats précédents (workers systemd non séparés par environnement, planificateur PROD absent) | vérifier le worker `default` et `schedule:run` sur web3 (exports, purge, alerte d'abandon) |
| R-4 | Codes promo ignorés à l'inscription | décision D-M10-2 |
| R-5 | Données fictives de recette qui s'accumulent dans le clone (personnes invitées) | clone jetable, jamais copié vers DEV ou PROD |

### 6.5 Décisions indispensables

- **D-M10-1** : validation juridique de la mesure d'audience de l'inscription sans consentement (premier niveau, sans donnée personnelle), et choix de la fenêtre d'abandon (24 h par défaut).
- **D-M10-2** : transmettre `coupon_code` à l'inscription (correctif commercial).
- **Ouvertes depuis M8.1** : D-M81-1 (preuves d'autorité pour activer l'export exceptionnel), D-M8-1 (conservation par marché).
- **Ouvertes depuis ADR-833** : restauration des sociétés 1 et 2 de DEV (script testé, non exécuté).

### 6.6 Préconditions du déploiement DEV

- **P-1** : incident ADR-833, **OP-2** : rotation des identifiants et durcissement SSH autorisés et exécutés (en attente d'autorisation).
- **P-2** : vérifier qu'aucun webhook GitHub n'est réactivé (OP-1 exécuté : 0 webhook).
- **P-3** : décision sur la restauration DEV des sociétés 1 et 2, à prendre **avant** le déploiement, puisque `system:sync` et les migrations s'appliquent ensuite.
- **P-4** : sauvegarde DEV vérifiée (dump avec SHA-256), retour arrière possible : les 6 migrations sont additives et réversibles (vérifié pour M8 → M10).
- **P-5** : le déploiement passe **uniquement** par GitHub Actions (branche `dev`), jamais par l'ancien `deploy.sh`.
- **P-6** : file d'export avec `retry_after` supérieur à 1 800 secondes, ou exports volumineux acceptés comme pouvant échouer proprement.
- **P-7** : `COMPANY_EXCEPTIONAL_EXPORT_ENABLED` absent ou à `false` sur DEV.
- **P-8** : l'incident des comptes de démonstration en **PROD** (OP-2bis) reste une urgence indépendante ; ce déploiement DEV ne le traite pas et ne doit pas être présenté comme le traitant.
