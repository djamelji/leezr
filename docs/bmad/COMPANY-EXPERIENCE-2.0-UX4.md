# Company Experience 2.0 — UX4 : Planning & temps (ADR-779)

> Base `9bdc3b05` · DEV uniquement · aucun nouveau moteur, aucune migration, aucune permission ajoutée ou élargie.
> Preuves : `docs/bmad/ux4-779/` (captures, mesures JSON, démontages) · recette `tests/e2e/ux4-planning-time.spec.js` · sonde avant/après `tests/e2e/ux4-probe-before.spec.js`.

## 0. Décisions d'entrée appliquées

- UX3 validé ; les 8 documents générés de recette (ids 26 à 33) restent en base, non touchés.
- Libellés UX3 validés, défauts résiduels UX3 conservés au backlog (non rouverts).

## 1. Audit ciblé (avant)

| Écran | Constat mesuré (`before-*.json`, captures `before-*.jpg`) |
|---|---|
| Planning (mobile 390 px) | **Page de 485 px de large** : défilement horizontal, bouton « Nouv… » coupé, titre écrasé, besoins non couverts (chips) qui débordent et repoussent les créneaux du jour sous l'écran (F09). |
| Planning (desktop) | 2 boutons d'en-tête + 4 modes + « Modèles » + « Préparer ma semaine » + 10 icônes + 5 champs de filtre visibles en permanence avant la grille. |
| Création de créneau | 7 champs visibles (collaborateur, date, modèle, début, fin, lieu, note). La vue Jour ne permettait ni de choisir une plage horaire, ni de modifier un créneau depuis sa fiche ; après enregistrement, la vue Jour ne se rafraîchissait pas. |
| Navigation | 3 pages séparées (Planning, « Pointage », « Congés & absences ») sans lien entre elles hors du menu ; « Mon activité » à part. |
| Absence (responsable) | Champ « Durée (centièmes de jour) » (« 100 = 1 jour ») ; solde non visible pendant la saisie (F11). |
| Absence (salarié) | Nombre de jours **saisi à la main** (1 par défaut), aucun calcul, solde non visible. |
| Types d'absence | « Solde maximum » et « Report » saisis en **centièmes sans le dire** : taper 25 enregistrait 0,25 jour. |
| Durées | « 32.8h », « 7.5h », « 45 min » selon les écrans ; « Taux horaire (centimes) » alors que le champ se saisit déjà en euros. |
| Pointage | Pointage d'un jour précédent resté ouvert (cas réel : Bob, 20/10, en pause) : l'écran propose « Commencer », le serveur refuse (« Clock out first »). |

## 2. Architecture avant / après

| | Avant | Après |
|---|---|---|
| Entrée | Menu « Planning & temps » → 3 pages | Inchangé (mêmes routes), + **en-tête commun** `TimeSpaceHeader` sur les 4 pages : titre « Planning & temps », vues **Planning · Temps & pointages · Absences · Mon activité**, action principale de la vue |
| Visibilité d'une vue | Menu seul | Permission **réelle** de la route (`planning_view` ; `timesheet_view` ; `leave_request` + `leave_view_all`/`leave_approve`) + module actif ; « Mon activité » selon la capacité personnelle serveur `time_clock` (`/profile/space`) |
| Sous-navigation | Propre à chaque page | Conservée (modes du planning ; Aujourd'hui/Présences/Feuilles ; Demandes/Calendrier/Soldes/Types) |
| Moteurs | — | **Inchangés** : `LeaveDeductionCalculator`, `RequestLeaveUseCase`, `ClockIn/Out`, `Start/EndBreak`, planning board, cockpit prévu/réel (ADR-698) |

Aucune page fusionnée, aucune route supprimée, aucun lien historique cassé (vérifié : `/company/workforce/time?tab=entries`, `/leave`, `/planning`).

## 3. Parcours simplifiés et implémentés

**Planning**
- **Créer par plage horaire** (vue Jour) : glisser sur le calendrier → tiroir prérempli (date, début, fin, durée calculée « 3 h 00 ») → choisir le collaborateur → Créer. Lieu appliqué d'office s'il n'y en a qu'un. Rien n'est enregistré sans « Créer » ; conflits, absences et règles restent contrôlés par le serveur.
- **Tiroir créneau** : collaborateur, date, début/fin, durée lisible (« se termine le lendemain » si nuit), lieu (seulement si l'entreprise a des lieux) ; modèle et note sous « Plus d'options » (ouverts d'office s'ils sont renseignés).
- **Modifier** depuis la fiche d'un créneau brouillon de la vue Jour (« Modifier », relecture du créneau exact) ; **Dupliquer** depuis l'édition (mêmes heures/lieu/modèle, lendemain par défaut, contrôlé à la création).
- La vue Jour se rafraîchit après création/modification.
- **Desktop** : filtres de la semaine repliés derrière « Filtres » (compteur ; ouverts d'office si un filtre ou un modèle armé est actif).

**Mobile (F09)**
- En-tête : titre + menu « ⋯ » (Préparer ma semaine, Création en lot, Modèles) ; aucun bouton coupé.
- Vues de l'espace en libellés courts répartis sur la largeur (Planning · Temps · Absences · Moi).
- Vue jour : **aujourd'hui** par défaut ; besoins non couverts **résumés** (« N besoin(s) non couvert(s) ce jour », dépliable, libellés tronqués proprement) au lieu de chips débordantes ; lieu affiché sur chaque créneau ; « Nouveau créneau » prérempli sur le jour affiché.
- Tous les tiroirs du planning, des absences et de « Mon activité » limités à la largeur de l'écran.

**Absences**
- Responsable : Collaborateur → Type d'absence → Du / Au → **durée calculée en jours** (modifiable par demi-journée) avec « Calculée selon le contrat : 2 jours (14 h) » → **solde disponible** du type (rouge si insuffisant) → commentaire facultatif → « Envoyer la demande ».
- Salarié : Type → Du / Au (la fin reprend le début) → **durée calculée par le même moteur** (nouvel endpoint lecture seule, voir §6) → solde → commentaire facultatif → envoi.
- Période contenant un samedi/dimanche : avertissement explicite (le moteur compte les jours calendaires, voir §11).
- Approbation : la confirmation résume la demande (type, dates, durée) ; actions icônes étiquetées (accessibilité).
- Seule la **dernière** période saisie fait foi (réponses de calcul tardives ignorées).

**Pointage**
- Commencer / Pause / Reprendre / Terminer : machines d'état et règles de pause inchangées.
- Sans fiche salarié (manager, RH…), « Mon activité » n'affiche plus « Commencer la journée » (voué au refus).
- Pointage resté ouvert un jour précédent : message clair (« Votre responsable doit le corriger… ») au lieu d'un « Commencer » refusé. Le fermer automatiquement compterait tout l'intervalle comme travaillé : la correction reste au responsable (cockpit, motif obligatoire).

**Prévu / réel** : le cockpit existant (écarts, retards, pointages manquants, correction avec motif) devient la vue « Temps & pointages » de l'espace ; aucun calcul ajouté.

## 4. États et unités métier

| Avant | Après |
|---|---|
| Durée (centièmes de jour), « 100 = 1 jour » | Durée en **jours** (pas 0,5) ; l'API reçoit des centièmes exacts (`Math.round(j × 100)`) |
| « 2.00 j », « 0.50 j » | « 2 jours », « 0,5 jour », « 12,08 jours » |
| Solde maximum / Report (centièmes implicites) | En jours, conversion exacte |
| « 32.8h », « 7.5h », « 45 min », « 7h30 » | « 32 h 45 », « 7 h 30 », « 45 min » (`utils/workUnits.js`, partout dans l'espace) |
| Taux horaire / journalier / salaire « (centimes) » | Libellés sans unité technique ; la saisie était déjà en euros (`AppMoneyField`) |
| « shift » | « créneau » (libellés français du planning) |
| « Pointage », « Congés & absences » | « Temps & pointages », « Absences » |

Durée de travail (minutes), quantité de congés (centièmes de jour) et montants (centimes) restent trois unités distinctes ; seule la présentation change.

## 5. Permissions (mesurées, `result-profiles.json`)

| Profil | Vues de l'espace | Accès direct aux autres pages |
|---|---|---|
| Propriétaire (aussi salarié) | Planning · Temps & pointages · Absences · Mon activité | — |
| Manager | Planning · Temps & pointages · Absences | Mon activité : écran « pas de fiche » (aucune fiche liée) |
| RH sans paie | Planning · Temps & pointages · Absences | idem |
| Salarié (recette) | Temps & pointages · Mon activité | Planning → 403 ; Absences → accueil |
| Dispatcher également salariée (Alice) | Mon activité seule (pas de barre de vues) | Planning, Temps → 403 |
| Driver (Bob) | Mon activité seule | idem |
| Consultation, Paie | Mon activité seule | idem |
| Entreprise sans Workforce | Aucun espace « Planning & temps » ; l'ancien lien Planning ne rouvre pas l'écran | — |

Toute vue proposée est réellement accessible (assertion). 0 erreur JS, 0 réponse 403/5xx sur les 8 profils. Collaborateur **sans compte** : créneaux et absence créés, modifiés, approuvés pour lui (parcours responsable). Entreprise multi-sites (2 lieux) : sélecteur de lieu présent ; lieu unique appliqué d'office.

## 6. Backend (aucun moteur nouveau)

- `POST /workforce/me/leaves/deduction-preview` : appelle `LeaveDeductionCalculator::suggest` pour la fiche **active de la personne connectée** (jamais un paramètre), lecture seule.
- `GET /workforce/me` : ajoute `open_entry` (pointage actif d'un jour antérieur, à soi uniquement).
- `e2e:teardown-run` : nouveau type `time_entry` (identifiant exact lu dans la réponse de l'API, créé après le début de l'exécution, dans l'entreprise de l'exécution ; les pauses suivent par cascade).

## 7. Mesures avant / après

Après = mesuré par la recette (clics, champs remplis, pages, défilement horizontal). Avant = audit ADR-774 (ouverture sans soumission) ou analyse du formulaire à `9bdc3b05` quand le parcours n'avait pas été mesuré ; signalé comme tel.

| Parcours | Avant | Après (mesuré) |
|---|---|---|
| Consulter son planning (salarié) | 1 page | 1 page, 0 clic, pas de défilement horizontal (390 px) |
| Créer un créneau | 2 clics pour ouvrir + 7 champs visibles, saisie date et heures (audit #14) ; mobile : bouton coupé | 4 actions (glisser, collaborateur, créer), **1 champ** rempli, date/heures/durée préremplies |
| Modifier un créneau | Impossible depuis la vue Jour (réaffecter seulement) | 3 clics, 1 champ |
| Dupliquer un créneau | Menu de la grille (même jour) | 4 clics, 0 champ, lendemain prérempli |
| Consulter les présences | 1 clic (menu) | 1 clic (vue de l'espace), 1 page |
| Commencer, pause, reprise, fin | 4 clics | 4 clics (inchangé) ; cas bloqué expliqué |
| Demander une absence (responsable) | 7 champs dont durée en centièmes à vérifier | 7 clics, **4 champs** (collaborateur, type, deux dates), durée et solde affichés |
| Demander une absence (salarié) | 5 champs, nombre de jours saisi à la main | 2 clics, **3 champs** (deux dates, commentaire), type présélectionné, durée calculée |
| Approuver une absence | 2 pages, 2 clics (audit #16, non exécuté) | 2 clics, demande résumée dans la confirmation |
| Consulter un solde | Onglet Soldes (1 clic) | 1 clic ; et 0 clic dans les formulaires |
| Identifier une anomalie | Page Temps de travail (1 clic) | 1 clic (cockpit prévu/réel) |
| Planning mobile | Page 485 px pour 390 px | 390 px ; tablette 820 px et téléphone 390 px sans défilement sur les 3 vues |
| Erreurs réseau/JS | — | 0 sur tous les parcours |

Pas de gain annoncé au-delà de ces mesures. La densité de la grille desktop (cellules « + ») reste équivalente (527 boutons contre 521) : la grille n'a pas été refondue.

## 8. Tests

| Niveau | Contenu | Résultat |
|---|---|---|
| HTTP | `Ux4SelfLeavePreviewTest` (6) : calcul par le moteur, identifiant ignoré, période invalide, sans contrat, autre entreprise, fiche inactive, aucune écriture, pointage ouvert visible à soi seul et clock-in toujours refusé | 6/6 |
| Navigateur | `ux4-planning-time.spec.js` (6) : responsable 8 parcours avec écritures ; salariée mobile (pointage complet) ; salarié mobile (pointage ouvert + absence) ; 8 profils ; entreprise sans Workforce ; captures tablette/mobile/sombre + anciennes routes | 6/6 |
| Suite complète | `php artisan test` (une fois, après toutes les modifications backend) | 4286 réussis, 12 ignorés, **3 échecs préexistants** (`ControllerSizeInvariantTest`, même liste de contrôleurs qu'avant UX4) |
| Build | `pnpm build` final | propre |
| Régression navigateur (lecture seule) | UX0 navigation (9), M6.D vues (11), M6.E accessibilité (1), phase A planning (3), M6.A navigation (4) | 27/28 ; échec M6.A n°1 : test antérieur à UX0 qui cherche le lien « Planning » sans ouvrir le groupe replié « Planning & temps » (menu non modifié par UX4) |

## 9. Données de recette

- Fiches créées par la recette : supprimées avec leurs créneaux, absences, soldes (cascade) — 6 exécutions, 6 fiches supprimées, 0 ignorée.
- Pointages d'Alice : supprimés par identifiant exact (3 exécutions : 1339, 1340, 1341). 0 pointage restant.
- **Demandes d'absence de Bob** : envoyées puis **annulées par l'API métier** ; elles restent en base à l'état « annulée » (ids 68, 69 et 71), car le journal des soldes est en ajout seul et n'est pas réécrit.
- Les 8 documents UX3 (ids 26 à 33) n'ont pas été touchés.

## 10. Captures

`docs/bmad/ux4-779/` : `before-*` / `after-*` (desktop, mobile × planning, temps, absences, mon activité), `j-desktop-01-shift-range`, `j-desktop-02-time`, `j-desktop-03-leave-form`, `j-mobile-01-me` à `j-mobile-05-leave`, `j-tablet-*`, `j-mobile-*`, `j-desktop-*`, `j-dark-*`, `hub-<profil>`, `no-workforce-planning-link`.

## 11. Défauts résiduels

1. **Décompte en jours calendaires** : `LeaveDeductionCalculator` compte chaque jour (week-ends compris) ; il n'existe ni rythme de travail par contrat ni calendrier des jours fériés par Market. L'écran avertit et la durée reste modifiable ; corriger exige une règle métier (Markets/contrat), hors UX4.
2. « Solde maximum » et « Report » sont stockés mais appliqués par aucun moteur.
3. `GET /workforce/employees/{id}/leave-balances` ne vérifie pas le périmètre manager (préexistant, non élargi par UX4, désormais appelé par le formulaire).
4. Pointage ouvert d'un jour précédent : le salarié est informé, mais le cockpit du responsable ne le signale pas spécifiquement.
5. Grille Semaine desktop toujours dense (palette de modèles, couverture, cellules) ; non refondue.
6. Pour un profil sans droit de gestion, le titre « Planning & temps » coiffe « Mon activité » seule.
7. Redirections hétérogènes pour une page non autorisée (`/company/403`, accueil, tableau de bord) — préexistant.
8. Données de démonstration DEV bruitées (besoins « Besoin 87086 », modèles « Bulk-… ») visibles dans les captures.
9. `EmployeeSelfController` dépassait déjà la taille maximale (322 lignes) ; UX4 le porte à 366 (deux points d'entrée en lecture). Extraction en read-model à prévoir.
10. Test E2E `workforce-m6a-navigation` n°1 obsolète depuis UX0 (groupe de menu replié).
11. Résiduels UX0 à UX3 inchangés.

## 12. Conditions pour UX5

- Valider les libellés : « Planning & temps », « Temps & pointages », « Absences », « Mon activité » / « Moi », « créneau ».
- Arbitrer le décompte des absences (rythme de travail, jours fériés par Market) : chantier métier distinct.
- Décider du sort des demandes annulées de recette sur la fiche de Bob (ids 68, 69, 71).
