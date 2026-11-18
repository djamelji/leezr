# Leezr 3.3 — Finalisation des interfaces Company, sécurité du rôle chauffeur (ADR-823)

> Branche `dev` locale, **aucun push**, aucun déploiement, aucun `git reset`.
>
> - **Écritures de recette** : uniquement sur le clone `leezr_audit821`, dans la société fictive 109 et dans une société fictive créée par la recette.
> - **Société 1** : lue sur le clone, jamais écrite.
> - **Preuves** : `docs/bmad/company-3-3/`.

## Synthèse — livraison PARTIELLE (proche du terme)

**Les sept domaines demandés ont été repris et recettés en navigateur** (60/60 étapes) : Planning, Postes, fiche collaborateur, Pointage, Congés, Paie et espace salarié.

**Corrections de fond** :
- rôle chauffeur ramené au moindre privilège pour les nouvelles sociétés ;
- dates suivies dans la langue de l'interface ;
- soldes de l'accueil salarié faux (0 j au lieu de 0,97 j) ;
- deux erreurs 500 sur des identifiants non numériques.

**Restent incomplets** (§3) :
- l'en-tête à deux niveaux du Planning ;
- l'usage d'un poste par les processus (embauches) ;
- les onglets Présences, Types de congés, DSN et le détail d'une ligne de paie ;
- les onglets icônes seules de « Mon espace » sur mobile (choix conservé).

**Je ne déclare donc pas la mission intégralement terminée.**

## 1. Commits locaux (`cb90d2d0`…)

| Commit | Objet |
|---|---|
| `0fcc48e1` | Lot 6 — rôle chauffeur limité à son travail (registre + preset publié v4 + rapport) |
| `cb90d2d0` | Lot 7 — les dates suivent la langue de l'interface |
| `2e6e31a5` | Lots 1/2 — barre du Planning refaite ; liste des postes avec recherche et filtres |
| `fcef31a7` | Lot 3 — fiche : un seul éditeur par champ ; Temps & absences lisibles |
| `421475f4` | Lot 3 — permissions lisibles ; listes de documents raccourcies |
| `e0a94fee` | Lots 4/5 — soldes d'équipe, calendrier Vuexy, feuilles de temps, paie, espace salarié |
| `983d525c` | Déclarations auto-import (`displayLocale`) |
| `39bdf8ab` | Routes de paie : identifiant numérique (500 → 404) |
| (ce commit) | ADR-823, rapport, preuves |

## 2. Pages terminées (refondues et recettées)

- Planning, vue Semaine, desktop :
  - en-tête de période portant la synthèse ;
  - une seule barre ;
  - menu « Plus » libellé ;
  - bandeau de publication conditionnel.
- Liste des postes, fiche collaborateur (5 rubriques), feuilles de temps, soldes de congés, calendrier des congés.
- Détail d'une période de paie et liste de paie.
- Accueil salarié et « Mon activité ».

Le détail est en §4–8 et la matrice en §17.

## 3. Pages encore inachevées

- **Planning** :
  - l'en-tête de l'espace (titre « Planning & temps », sélecteur de modes, Modèles, « Préparer ma semaine ») reste sur deux niveaux ;
  - les vues Jour, Postes et Mois sont inchangées ;
  - la vue mobile est conservée : elle était déjà adaptée (vue jour, actions tactiles) et a été vérifiée.
- **Fiche poste** : section « utilisation par les processus métier » (embauches ouvertes sur le poste) non faite.
- **Pointage** : onglet Présences non repris.
- **Congés** : onglet Types de congés non repris.
- **Paie** : DSN et détail d'une ligne non repris.
- **Mon espace** (mobile) : onglets à icônes seules conservés. C'est un choix du composant commun `AppSectionNavigation`, avec libellés accessibles ; l'aperçu, juste en dessous, liste chaque rubrique avec son libellé.

## 4. Refonte Planning (lot 1)

- **Avant** :
  - statistiques sur une ligne séparée ;
  - barre d'une dizaine d'icônes sans libellé, plus un anneau d'icône ;
  - bouton « Filtres » isolé ;
  - sélection et publication mélangées à la barre ;
  - bouton « Publier la sélection » en double dans l'en-tête.
- **Après** :
  - **Période** : la synthèse (créneaux, publiés, brouillons, heures) est sous la semaine.
  - **Une ligne d'outils** : sélecteur « Par salariés / Par postes » (même modèle que les modes), annuler / rétablir, menu **« Plus »** libellé : Affichage (regrouper par équipe, liste simple, compact / aéré) et Actions de la semaine (copier la semaine précédente, copier vers, besoins de couverture).
  - **Deuxième ligne** : recherche pleine largeur et Filtres.
  - **Bandeau de publication** affiché seulement s'il y a des brouillons, avec le nombre de créneaux non visibles des salariés.
- **Inchangés** : grille, règles légales, états (un créneau publié n'est pas modifiable, vérifié), permissions, premier jour de semaine (dimanche vérifié), vue mobile.
- **E2E** : 3 specs mises à jour pour ouvrir le menu « Plus ».
  - Les specs du planning ont été rejouées sur le code de référence (`cb90d2d0`, worktree temporaire servi sur un second port) puis sur le code final.
  - Résultat **identique** : 5 réussies et les 2 mêmes échecs historiques (`grid-refonte` desktop : motif « 16h · 2 j » sans espace ; `undo` A : modèle absent de la palette). **Aucune régression.**

## 5. Refonte Postes (lot 2)

- **Liste** :
  - recherche, filtre par service, puces Tous / Occupés / Sans personne, résumé (« 4 poste(s) · 7 personne(s) ») ;
  - ligne cliquable vers la fiche ; les trois cartes chiffrées sont supprimées ;
  - bandeau « Organisation proposée » replié dès que l'organisation existe (rouvrable).
- **Fiche** (3.2 + 3.3) : création, modification, exigences effectives lues dans le registre, indépendantes du RBAC (recetté).

## 6. Refonte fiche collaborateur (lot 3)

- **Informations & emploi** : un seul éditeur par champ.
  - Les champs déjà modifiables dans « Informations complémentaires » (fiche RH, écrivain canonique `PersonFieldWriter`, confirmation contractuelle) ne sont plus répétés dans le formulaire du compte.
  - Seuls les codes réellement affichés par l'éditeur RH sont retirés : NIR, IBAN, sexe… restent modifiables là où ils l'étaient. Un premier essai qui retirait toute la catégorie RH a été **détecté à la capture et corrigé**.
  - Valeurs en lecture seule sur une grille libellé / valeur ; groupes traduits (plus de « Identity » / « Hr ») ; titre de la carte Emploi corrigé.
- **Temps & absences** :
  - créneaux **à venir** avec horaires et statut traduit (la liste montrait février en premier, statuts bruts) ;
  - pointages des 30 derniers jours (le serveur ne renvoyait que la semaine : « aucun pointage récent » alors qu'il en existait) ;
  - soldes lisibles.
- **Documents** : 8 éléments puis « Tout afficher (n) ».
- **Accès** : permissions groupées par domaine avec les libellés du catalogue, la clé en infobulle.
- **Données confidentielles** : aucune nouvelle donnée chargée côté client ; la rémunération reste fournie seulement avec `compensation_read`.

## 7. Pointage, Congés et Paie (lot 4)

- **Pointage** :
  - Feuilles de temps : bande d'indicateurs cliquable.
  - Parcours arrivée → pause → reprise → fin recetté, et historique sur la fiche.
- **Congés** :
  - **Soldes** : vue d'équipe immédiate, nouvelle lecture `GET /workforce/leaves/balances` (soldes du registre, périmètre du lecteur, petit contrôleur dédié).
  - **Calendrier** : styles du preset Vuexy et vraie locale française de FullCalendar.
  - **Parcours** : demande du salarié, validation depuis l'interface, soldes.
- **Paie — recette réelle sur la société fictive autorisée** :
  - activation du module (achat sur le clone) ;
  - salarié fictif sous contrat rémunéré ;
  - feuille de temps générée → soumise → approuvée → verrouillée (seule source de la paie) ;
  - période créée → collecte → estimations → détail (statut traduit, totaux en bande, anomalies groupées, « 1 salarié ») → validation → export.

  Aucune règle financière ni aucun calcul modifié.

## 8. Espace salarié (lot 5)

| Défaut | Correction |
|---|---|
| L'accueil affichait **0 j** de congés (clés `balance_hundredths` inexistantes) alors que « Mon activité » affichait 0,97 j | Même source `available` |
| La page personnelle s'intitulait « Planning & temps » | « Mon activité » |
| « Actualiser » occupait la place de l'action principale | Icône secondaire ; l'action de pointage reste principale |

- **Recette mobile (390 px)** : accueil, Mon activité, Mon espace, Mes livraisons.
- **Aucun débordement, aucune fonction d'administration exposée** ; routes et API administratives refusées.

## 9. Audit du rôle Chauffeur-livreur (lot 6)

**Provenance** :
- Registre Logistique : bundles `members.team_view` (→ `members.view`) et `settings.company_info` (→ `settings.view`), choix ADR-777.
- **Preset RBAC publié v2** (utilisé à la création des sociétés) : il contenait en plus `documents.access` (→ `documents.view`), alors qu'ADR-788 l'avait retiré du registre.

**Accès réels** :

| Permission | Ouvre |
|---|---|
| `members.view` | `/company/members/{id}` et `/fields` (profil et champs personnels des collègues) ; facette compte de `/company/people/{ref}` |
| `documents.view` | Coffre de la société, documents des collègues |
| `settings.view` | Lecture des réglages |

Aucune de ces permissions n'est nécessaire pour livrer ni pour l'espace personnel (qui ne demande aucune permission).

**Correction** :
- Registre : chauffeur = thème + `shipments.delivery`.
- Nouvelle version **v4 publiée** de `logistique_rbac` (migration, configuration Platform uniquement ; autres rôles inchangés ; brouillon v3 intact).
- **Sociétés existantes** :
  - aucune révocation silencieuse ;
  - `rbac:least-privilege-report` liste désormais chaque rôle chauffeur concerné (14 sur le clone, dont la société 1) comme proposition explicite.

**Recette** :
- Une nouvelle société fictive inscrite reçoit un chauffeur sans `members.view`, `settings.view` ni `documents.view`, avec `shipments.view_own`.
- Le rôle existant de la société 109 est conservé.
- Le chauffeur se voit refuser les pages Contrats, Paie, Embauches, Postes et Rôles, ainsi que les API correspondantes.
- Les tests ADR-777 / 788 sont mis à jour vers la nouvelle doctrine (décision de la mission).

## 10. Harmonisation Vuexy (lot 7)

- **Bandes d'indicateurs** : même modèle `VCard` pour Pointage (Aujourd'hui et Feuilles), Congés, Paie (liste et détail).
- **Composants** : sélecteurs `VBtn` cohérents avec les modes du Planning, `VList` pour les listes de la fiche, styles du preset `apps/calendar`.
- **`AppPageHeader`** (10 pages) **conservé** :
  - Vuexy ne fournit pas de composant d'en-tête de page (ses modèles écrivent un `h4` à la main) ;
  - il apporte la disposition responsive des actions sur mobile ;
  - il est utilisé de façon cohérente. Le remplacer n'aurait rien apporté.
- Aucune modification de `@core`.

## 11. Localisation des dates

- `formatDate` / `formatDateTime` utilisaient uniquement la locale du marché (fr-FR).
- **`displayLocale()`** garde la région du marché et prend la langue de l'interface (même source vue-i18n).
- Formats `fr-FR` codés en dur remplacés : fiche, accès, accueil personnel, invitations. Les factures gardent la locale du marché (document légal).
- **Vérifié en anglais** : « Nov 19, 2026 », « Day to day ». Retour au français vérifié.

## 12. Captures (`docs/bmad/company-3-3/shots/`)

- **`before/`** : rubriques de la fiche, Feuilles de temps, Soldes, Calendrier, Types, DSN, Planning, Postes.
- **`after/`** :
  - `r-*` : étapes de recette ;
  - `v-*` : 12 pages × FR clair, FR sombre, mobile 390 et EN.
- **Fichier `variants-a823.json`** : 48 captures, aucun débordement, aucune clé brute, `lang` bien à `en` / `fr`.

## 13. Tests et E2E

- **Nouveaux tests** :
  - `Adr823DriverRoleTest` ;
  - soldes d'équipe (périmètre, autre société, pas de recalcul) dans `Ux41LeaveBalanceScopeTest`.
- **Tests adaptés** (changement de doctrine demandé) : `Ux2TeamTest`, `Adr788FieldAccessTest`.
- **Recette 3.3** (`acceptance-a823.json`) : **60/60**, rejouable.
- **E2E Planning** : voir §4.
- **Suite complète** : 4 703 réussis, 12 ignorés, 3 échecs historiques `ControllerSizeInvariant` (aucun contrôleur créé ou modifié en 3.3 n'y figure). **Aucun nouvel échec.**
- **Build** : propre.

## 14. RBAC et multi-tenant

- Routes et API administratives refusées au chauffeur.
- `/workforce/leaves/balances`, `/workforce/contracts` et `/workforce/payroll` : refusés pour une autre société.
- Soldes d'équipe : aucun salarié d'une autre société (recette et test).

## 15. Régressions

**Défauts trouvés et corrigés pendant la 3.3** :
- Planning : sélecteur de vue illisible (`VBtnToggle` compact) → corrigé avant commit.
- Fiche : NIR / IBAN rendus non modifiables par un premier filtrage trop large → corrigé avant commit.
- Documents : chaîne `v-if` / `v-else` cassée par l'ajout du bouton → corrigé avant commit.
- Paie : identifiant non numérique → erreur 500 → 404.

**Aucune régression connue restante.**

## 16. Risques résiduels

- **Rôles chauffeur existants** : ils conservent l'annuaire et, pour certains, le coffre documentaire, jusqu'à décision explicite de chaque société (rapport fourni).
- **Rejeu du preset** : la v4 ne change que les créations ; une société peut toujours le réappliquer ou personnaliser son rôle.
- **Paie validée malgré des anomalies** : sur la société fictive, la validation a réussi alors que des salariés sans feuille de temps étaient signalés. C'est le comportement du moteur existant (non modifié), à confirmer métier.
- **Parrainage** : comportement 3.2 conservé (« inclus avec sa dépendance »), aucun prix modifié.

## 17. Matrice des pages Company depuis 3.0

| Page | État initial | Modifications | Parcours testés | Visuel | Permissions | Résultat |
|---|---|---|---|---|---|---|
| Catalogue des modules | Cartes sans dépendances | 3.1 : catalogue filtré, dépendances, aperçu | Activation / désactivation (3.1) | 3.1 | Vérifiées | Refondue et recettée |
| Liste Équipe | 7 colonnes | 3.1 : 4 colonnes | Navigation (3.1-3.3) | 3.1 | — | Refondue et recettée |
| Fiche collaborateur — vue d'ensemble | Texte brut | 3.2 | 3.2, 3.3 | 3.2 / 3.3 (EN) | Sections serveur | Refondue et recettée |
| Fiche — Informations & emploi | 3 formulaires concurrents | 3.3 | Profil, sans compte, admin également salarié (société 1, lecture) | 3.3 | Écrivain canonique | Refondue et recettée |
| Fiche — Documents | Listes infinies | 3.3 | Consultation | 3.3 | Inchangées | Refondue et recettée |
| Fiche — Temps & absences | Statuts bruts, passé d'abord | 3.3 | Créneau créé depuis la fiche, historique | 3.3 | Périmètre serveur | Refondue et recettée |
| Fiche — Accès | Clés brutes | 3.3 | Consultation | 3.3 | `roles.view` | Refondue et recettée |
| Liste des postes | Cartes + tableau | 3.3 | Recherche, filtres, création, modification | 3.3 | `workforce.manage` | Refondue et recettée |
| Fiche poste | Texte brut | 3.2 | Exigences (3.2, 3.3) | 3.2 / 3.3 | `settings.manage` / `documents.configure` | Refondue et recettée (sans « usage par les processus ») |
| Planning (Semaine, desktop) | Barre d'icônes, stats séparées | 3.3 | Plusieurs semaines, Mois, dimanche, création, modification, publication | 3.3 | Inchangées | Refondue et recettée |
| Planning — en-tête / Jour / Postes / Mois / mobile | — | Aucune | Mois + mobile vérifiés | 3.3 | — | Existante et vérifiée |
| Pointage — Aujourd'hui | 7 cartes | 3.2 | 3.2, 3.3 | 3.2 | — | Refondue et recettée |
| Pointage — Feuilles de temps | 4 cartes | 3.3 | Génération → verrouillage | 3.3 | Inchangées | Refondue et recettée |
| Pointage — Présences | — | Aucune | — | — | — | Non refondue |
| Congés — Demandes | Compteur faux | 3.2 | Demande, validation | 3.2 / 3.3 | — | Refondue et recettée |
| Congés — Soldes | Sélection obligatoire | 3.3 | Vue d'équipe | 3.3 | Périmètre serveur | Refondue et recettée |
| Congés — Calendrier | Boutons noirs, « +1 more » | 3.3 | Consultation | 3.3 | — | Refondue et recettée |
| Congés — Types | — | Aucune | — | — | — | Non refondue |
| Paie — liste et période | Cartes, statut brut | 3.2 + 3.3 | **Parcours complet** (3.3) | 3.3 | Montants sous `compensation_read` | Refondue et recettée |
| Paie — DSN, détail d'une ligne | — | Aucune | — | — | — | Non refondue |
| Contrats (registre) | Inexistant (404) | 3.2 | 3.2 | 3.2 | Périmètre | Refondue et recettée |
| Documents RH | Deux registres empilés | 3.2 | 3.2 | 3.2 | — | Refondue et recettée |
| Expédition (détail), Mes livraisons | Brut | 3.2 | 3.2 | 3.2 | Identifiant chauffeur | Refondue et recettée |
| Accueil salarié | Soldes faux | 3.3 | Mobile | 3.3 | — | Refondue et recettée (correctifs) |
| Mon activité | Titre collectif | 3.3 | Mobile, pointage | 3.3 | — | Refondue et recettée (correctifs) |
| Mon espace | — | 3.2 (`VCard`) | Mobile | 3.3 | — | Existante et vérifiée |
| Réglages (hub) | — | 3.0 | — | 3.0 | — | Existante et vérifiée |
