# Leezr 3.4 — Sécurisation de la validation de paie, finitions Company, audit RBAC chauffeur (ADR-824)

> Branche `dev` locale, **aucun push**, aucun déploiement, aucun `git reset`.
>
> - **Écritures de recette** : uniquement sur le clone `leezr_audit821`, dans la société fictive 109.
> - **Société 1** : lue sur le clone pour les captures, jamais écrite.
> - **Base DEV** : seulement lue, par l'audit RBAC.
> - **Preuves** : `docs/bmad/company-3-4/`.

## Synthèse

**Implémenté et recetté** (37/37 étapes navigateur) :
- incohérence anomalies / validation de paie corrigée ;
- Planning finalisé sur les quatre vues ;
- utilisation d'un poste (personnes et embauches) ;
- Présences, Types de congés, DSN et ligne de paie repris ;
- onglets mobiles libellés ;
- audit RBAC historique du chauffeur (lecture seule, aperçu de remédiation).

**Défauts réels trouvés en recette et corrigés** :
- le panneau d'une ligne de paie ne s'ouvrait jamais ;
- totaux de paie écrasés selon l'ordre des requêtes ;
- page Congés vide pour un onglet inconnu ;
- débordement des onglets mobiles.

**Reste à faire** : §15.

## 1. Commits locaux

| Commit | Objet |
|---|---|
| `9bfc3fb0` | Lot 1 — diagnostic des anomalies de paie aligné sur la règle serveur |
| `af5a2fa7` | Lot 2 — Planning : un niveau d'en-tête, vues Jour, Postes et Mois |
| `46483fc0` | Lot 3 — fiche poste : utilisation (personnes, embauches) |
| `7776aa78` | Lot 4 — ligne de paie, totaux, Présences, Types de congés, DSN |
| `1f24e200` | Lot 5 — onglets mobiles libellés |
| `985ac547` | Lot 6 — audit en lecture seule des rôles chauffeur historiques |
| `502b3bff` | Types de congés système : raison affichée |
| `9abc58c8` | Onglets mobiles sans débordement ; specs alignées sur les actions déplacées |
| (ce commit) | ADR-824, rapport, preuves |

## 2. Diagnostic des anomalies de paie

**Règle serveur** (`ValidatePayrollUseCase`, inchangée sur le fond) : deux niveaux distincts.

| Niveau | Exemples | Effet |
|---|---|---|
| **Anomalies de période** (niveau `error`) | `missing_timesheet`, `missing_compensation`, `zero_base_salary`, `invalid_weekly_hours` | Le salarié est **exclu** de la période : seuls les salariés avec une feuille de temps verrouillée et une rémunération sont payés. **Ce n'est pas un blocage** : la validation exige une **justification écrite** (`validation_note`), conservée et auditée. |
| **Avertissements** (niveau `warning`) | heures nulles, écarts | Ne bloquent jamais. |
| **Anomalies de calcul** (`blocking_anomalies` d'un calcul) | règles de cotisation absentes, plafond SS manquant, règles RGDU manquantes | **Bloquent toujours**, même avec une justification. |

**Incohérences constatées** :
- L'interface qualifiait toute anomalie de période de niveau erreur d'« **Anomalies bloquantes** — empêchent la validation », alors que le serveur validait avec une justification.
- La fenêtre de validation ne disait pas que la justification était obligatoire.
- Le serveur acceptait une justification faite d'espaces (`empty('   ')` est faux).
- Les vraies anomalies de calcul pouvaient disparaître de l'affichage : voir la course des totaux, §9.

**Contrats sans feuille de temps** : le moteur n'a **aucune règle** dispensant un type de contrat (forfait jours, etc.) de feuille verrouillée. Tout salarié actif sans feuille est exclu. C'est la règle effective : je ne l'ai **pas** modifiée (calcul de paie interdit), je la signale (§16).

## 3. Corrections fonctionnelles

- **Serveur** : justification normalisée (espaces retirés), refusée si vide ; stockée sans espaces superflus.
- **Interface**, trois états fidèles à la règle :
  1. anomalies de calcul → « Anomalies bloquantes », bouton Valider **désactivé** ;
  2. exclusions → « Validation avec justification », avec le nombre de salariés exclus ;
  3. sinon → « Prête à valider ».
- **Groupes d'anomalies** marqués « exclu de la période » ou « à vérifier ».
- **Fenêtre de validation** : elle annonce les exclusions, et la justification y est obligatoire.
- **Tests** `Adr824PayrollValidationRulesTest` (6) : période propre, avertissement, salarié exclu (null / vide / espaces refusés, justification stockée nettoyée), anomalie de calcul bloquante même justifiée, période déjà validée, export seulement après validation.
- **Recette réelle** :

| Cas | Résultat |
|---|---|
| Exclusions | Message, confirmation désactivée sans texte, API refuse sans justification et avec des espaces, validation par l'interface avec justification, période déjà validée refusée, export après validation |
| Blocage réel produit **par le moteur** (période 2025 : règles de cotisation absentes) | Bouton désactivé, API refuse même avec justification |

## 4. Planning finalisé

- **Un seul niveau d'en-tête** : Nouveau créneau (principal), Préparer ma semaine et « Plus » (création en lot, Modèles) à côté du titre, dans **les quatre vues**. Les vues Jour, Postes et Mois n'avaient pas le bouton de création. La ligne inférieure ne garde que le choix de vue et la navigation dans le temps.
- **Jour** :
  - locale FullCalendar française (« all-day » → « Journée ») et styles du preset Vuexy (fini le fond jaune) ;
  - hauteur bornée : la journée s'ouvre à 6 h au lieu d'afficher 24 h d'un bloc ; la nuit reste accessible par défilement.
- **Postes** :
  - filtre « Tous les besoins / À pourvoir uniquement » ;
  - jours repliables avec un résumé (« 5 à pourvoir sur 6 ») ;
  - jour courant ouvert par défaut. Avant, une liste interminable.
  - État vide utile quand aucun besoin n'est configuré (cas de la société fictive).
- **Mois** : plus de débordement sur téléphone (colonnes `minmax(0, 1fr)`).
- **Semaine** : barre 3.3 conservée.
- **Inchangés** : créneaux, publication, premier jour, permissions, règles de repos et légales.

## 5. Fiche poste — utilisation

- **Section « Utilisation du poste »** :
  - collaborateurs affectés (lien vers l'onglet Personnes) ;
  - **embauches en cours sur ce poste**, avec leur avancement (informations et documents repris des exigences) et un lien vers chaque dossier ;
  - lien vers l'espace Embauches.
- **Lecture existante** `GET /workforce/onboarding-files` filtrée par `job_role_id` : mêmes lignes, aucun second moteur.
- **Accès** : réservé à `workforce.manage`, comme l'espace Embauches. Test : un `workforce.view` reçoit 403.
- **Recette** : dossier créé sur le poste, visible dans la section, le lien ouvre `/company/workforce/hiring/{id}`.

## 6. Présences

- Les anomalies s'affichent en puces libellées (« Pointage sans départ »…) au lieu d'une icône seule.
- Liste, filtres, corrections et suppressions inchangés. Consultation recettée.

## 7. Types de congés

- **Défaut corrigé** : l'adresse `/leave/types` (comme tout onglet inconnu) affichait une **page vide**. Alias `types` / `leave-types`, puis repli sur Demandes.
- **Présentation** : libellé en premier, code technique en sous-titre, « — » au lieu de « 0 jour » pour un congé sans acquisition.
- **Types système** : puce « Système » avec explication. La modification est refusée par le serveur (règle existante, recettée) ; les boutons étaient déjà désactivés mais sans raison.
- **Recette** : création et modification d'un type personnalisé ; refus pour le chauffeur.

## 8. DSN — périmètre réel

L'état réel de la fonctionnalité, vérifié en recette :

| Étape | Comportement |
|---|---|
| Génération | Depuis une période validée |
| Identité de l'employeur incomplète (SIRET, NAF, adresse) | **Refus explicite, aucun fichier** |
| Profil de la société fictive complété | Le contrôle des **données salariés** obligatoires (NIR, sexe, naissance…) enregistre un **brouillon avec les erreurs explicites** |
| Transmission | **Désactivée** (bandeau « kill-switch », statuts transmis / acceptés simulés) |

**Aucune déclaration n'est présentée comme prête à transmettre.**

**Interface** :
- libellés français corrigés (« Acceptees », « Rejetees », « Periode », « declaration » → accentués) ;
- bande d'indicateurs compacte ;
- état vide qui décrit le périmètre réel.

## 9. Détail d'une ligne de paie

- **Défaut majeur — le panneau ne s'ouvrait jamais** : l'ouverture écrit `?line=` dans l'adresse (lien profond), et Vuetify referme par défaut un panneau temporaire à chaque changement de route. Corrigé avec `disable-route-watcher`.
- **Défaut — course des totaux** : le résumé de la période (`fetchRun`) écrasait les totaux des calculs (`fetchCalculations`), de forme différente, selon l'ordre d'arrivée. Net et Coût affichaient « — », et le nombre d'anomalies bloquantes retombait à 0. Corrigé en séparant les deux états.
- **Tableau des lignes** : Net et Coût lus dans les calculs déjà chargés (ils affichaient « — »).
- **Contenu du panneau** (existant, vérifié) : temps, absences, rémunération, provenance des éléments, points à traiter, estimation indicative.
- **Navigation** : le lien vers la fiche va directement à la fiche unifiée.
- **Aucun montant modifié.**

## 10. Espace salarié mobile

- **Navigation de section commune** (Mon espace, fiche, Temps, fiche salarié) : sur téléphone, chaque onglet affiche **icône + libellé** (points de suspension si long), avec une cible tactile de 56 px.
  - Même composant, même règle de débordement (« Plus », désormais traduit).
  - `role="tab"`, `aria-selected` et `aria-label` conservés ; état actif visible.
- **Défaut détecté et corrigé** : un libellé élargissait l'onglet (2 px de débordement sur la fiche à 390 px).
- **Recette** : salarié chauffeur, onglets libellés ; il n'a aucune permission d'administration.

## 11. Audit RBAC des rôles Chauffeur-livreur historiques

**Commande** `rbac:driver-role-audit`, en lecture seule. Elle ne modifie et n'applique rien ; elle ne donne que des identifiants de société et des nombres de membres, aucune donnée personnelle. Pour chaque rôle chauffeur :
- droits examinés au-delà de la livraison, et conséquence de leur maintien ;
- origine probable : application de preset RBAC enregistrée, sinon rôle par défaut du registre à la création ;
- personnalisations explicites (journal d'audit des rôles) ;
- impact sur les livraisons en cas de retrait (aucun) ;
- **aperçu de remédiation** : retirer / conserver / qui décide.

**Résultat sur DEV** (`company-3-4/driver-role-audit-dev.json`, noms de société retirés) :
- 8 rôles chauffeur, tous concernés, **13 membres** au total ;
- `members.view` : 8 rôles ; `settings.view` : 8 ; `documents.view` : 1 ;
- origine : rôle par défaut du registre à la création (avant ADR-823) ;
- personnalisations : 2 retraits de permission tracés le 15/11 par rôle des sociétés historiques (lot ADR-788), sans ajout manuel des droits examinés.

**Stratégie proposée (non appliquée)** :
1. Partager l'aperçu avec chaque société concernée.
2. Retirer `members.view`, `settings.view` et `documents.view` par l'éditeur de rôles, ou par une commande d'application dédiée, à écrire **après autorisation spécifique**, idempotente et tracée.
3. Contrôler les livraisons : `shipments.view_own` et `manage_status` sont conservés.

## 12. Captures (`docs/bmad/company-3-4/shots/`)

- **`before/`** : vues Jour, Postes et Mois, mobile, Présences, Types, DSN, détail et ligne de paie.
- **`after/`** :
  - `r-*` : étapes de recette ;
  - `plan-*` : quatre vues et mobile ;
  - `v-*` : variantes de 7 écrans en FR clair, FR sombre, mobile et EN.
- **`variants-a824.json`** : 28 captures, aucun débordement, aucune clé brute, `lang` bien à `en` / `fr`.

## 13. Tests et E2E

- **Nouveaux tests** : `Adr824PayrollValidationRulesTest` (6), dossiers d'embauche filtrés par poste et réservés à la RH (`Adr818HiringTest`), audit chauffeur en lecture seule (`Adr823DriverRoleTest`).
- **Recette 3.4** (`acceptance-a824.json`) : **37/37**, rejouable.
- **E2E du dépôt rejoués sur le clone** (Planning, onglets, Équipe) :
  - **Specs mises à jour** :
    - `workforce-m6d-views` et `lot2-drawer-reserve` : Modèles via le menu « Plus » (desktop) ou le menu mobile de l'en-tête ;
    - « Besoins de couverture » via le menu « Plus » de la grille. Ce déplacement date de la 3.3 et la spec n'avait pas été rejouée alors : je corrige ici cet oubli.
  - **Échecs restants**, comparés au code de fin de 3.3 (`39bdf8ab`, worktree temporaire sur un second port) : **identiques ligne pour ligne**, donc historiques.
    - `e3r-r3` (3) : attente du texte « Djamel » sur une page de fiche, et page « laboratoire » ;
    - `ux2-team` (3) : ligne attendue, bouton d'ajout ;
    - `workforce-grid-refonte` desktop et `undo` A : historiques déjà relevés en 3.3.
  - **Aucune régression 3.4.**
- **Suite complète** : 4 711 réussis, 12 ignorés, 3 échecs historiques `ControllerSizeInvariant` (aucun contrôleur modifié en 3.4 n'y figure). **Aucun nouvel échec.**
- **Build** : propre.

## 14. Multi-tenant

- `onboarding-files?job_role_id`, `dsn` et une période de paie demandés pour une autre société : refusés (403 / 404).
- Le chauffeur se voit refuser la paie, les embauches, la DSN, la validation de paie et la modification des types de congés.

## 15. Éléments non terminés

- **Paie** : règle « contrat dispensé de feuille de temps » absente du moteur ; non ajoutée (calcul de paie interdit).
- **Planning** : vue Mois mobile reprise, mais pas de mode « liste » dédié sur téléphone ; la vue jour mobile 3.3 est conservée.
- **Présences** : aucune période par défaut dans les filtres (comportement existant conservé).
- **Rôles chauffeur historiques** : remédiation **préparée, non appliquée** (autorisation requise).
- **E2E historiques** `e3r-r3`, `ux2-team`, `grid-refonte`, `undo` A : non corrigés (antérieurs, hors périmètre).

## 16. Risques résiduels

- **Exclusion sans feuille de temps** : un salarié actif sans feuille de temps verrouillée est exclu de la paie, quel que soit son contrat (forfait jours inclus). La validation l'impose désormais par écrit, mais la règle métier reste à confirmer.
- **Autres panneaux temporaires** : d'autres panneaux qui écrivent dans l'adresse à leur ouverture pourraient avoir le même défaut de fermeture. Non audité au-delà de la paie.
- **Activité DEV hors de mes actions** : une entrée DEV `onboarding.on_site_opened` (société 1, 04:08) a été créée par un utilisateur via l'application. Mes recettes 3.4 visaient toutes le clone et ne touchaient pas aux embauches.
- **Rôles chauffeur historiques** : ils conservent l'annuaire et, pour un rôle, le coffre documentaire, jusqu'à décision.
