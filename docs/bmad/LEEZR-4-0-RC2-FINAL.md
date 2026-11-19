# Leezr 4.0 RC2 — Qualification des échecs E2E, sécurisation des recettes, verdict final (ADR-831)

> Branche `dev` locale. Aucun push, aucun déploiement, aucun accès production, aucune écriture de recette sur le DEV,
> aucune écriture sur Company 1 réelle, aucun paiement, Billing asynchrone non activé, `@core` / `@layouts` intacts.
> Toutes les campagnes ont visé des clones marqués (`leezr_recipe831`, `leezr_recipe831b`, `leezr_recipe831c`).

## 1. Commits locaux

| Commit | Objet |
|---|---|
| `f3c64b3d` | fix(security) — une campagne E2E prouve que sa cible est un clone marqué ; `E2E_ALLOW_DEV` retiré |
| `ae7d50e5` | fix(planning) — le tiroir « Nouveau créneau » confirme un sureffectif comme la grille |
| `ac3fc58a` | fix(nav) — une entrée de menu Company n'hérite plus de l'onglet de la page courante |
| `dd8fbd9b` | fix(a11y) — états vides lisibles (26 widgets), barre du ratio de remboursement libellée, règle de pause traduite |
| `f0335b54` | fix(a11y) — « Personnaliser » lisible en sombre, navigation des jours mobile nommée |
| `7abdd360` | test(e2e) — les 58 échecs historiques qualifiés un par un et réalignés sans affaiblissement |
| `4328fe01` | test(e2e) — `lot4c1` atteint son créneau quel que soit le jour de la semaine |
| (ce commit) | docs — ADR-831, ce rapport, preuves `docs/bmad/leezr-4-0/rc2/` |

## 2. Tableau des échecs initiaux

La campagne 4.0 consolidée annonçait **58 échecs** ; sa liste « restante » comptait **59 entrées** : la 59ᵉ
(`platform-email-reply-forward › forward`) avait été *ignorée* (mode série) au dernier passage 4.0, après la
correction de sa fixture. Les 59 entrées sont qualifiées individuellement ci-dessous (section 3).

| # | Spec — scénario | Cl. | Cause prouvée | Décision | Correction | Campagne RC2 |
|---|---|---|---|---|---|---|
| 1 | `adr786-rbac-recette` — livreur : la fiche d'un collègue ne liste pas ses permissions, libellé | **A** | Onglet « Accès & autorisations » attendu ; le serveur n'envoie plus la section access à un livreur (rubriques serveur) | ADR-777 | Section absente prouvée par l'API + assertions d'origine sur chaque rubrique visible | réussi |
| 2 | `company-crud` — create a member → persists and appears after reload | **A** | Bouton « Ajouter un membre » de /company/members supprimé | ADR-802 | Tiroir unique « Ajouter une personne » (accès oui) ; POST réel + liste après rechargement ; registre d'exécution | réussi |
| 3 | `company-member-lifecycle` — owner: create member → change role → delete (persisted, with owner pro | **A** | Liste Members absorbée ; rôle et retrait dans la fiche | ADR-802 / ADR-777 | Création tiroir, rôle Manager persisté, protection propriétaire (ni retrait ni rôle), suppression persistée | réussi |
| 4 | `e3r-r2` — drawer mobile : ouverture, fermeture après navigation, Escape + focus | **A** | Lien Équipe dans le groupe repliable « Équipe » | ADR-822 | Ouverture du groupe si replié ; assertions inchangées | réussi |
| 5 | `home-p3b-dev` — P3-B DEV — single canonical home + redirect + one nav entry | **A** | /dashboard attendu ; accueil unique = /home, /dashboard alias | ADR-818 | /home attendu ; comptage « une seule entrée Accueil » devenu une vraie assertion | réussi |
| 6 | `hrux-after` — after: employee fiche shows onboarding checklist | **B** | Dépend de « Bob Dupont » du DEV et de l'ancienne liste | ADR-802 | Collaborateur fictif + contrat préparés (registre) ; checklist dans la fiche Collaborateur | réussi |
| 7 | `hrux2-dev-pass2` — DEV pass2: create fictitious employee via UI → fiche + checklist | **A** | Visait le DEV réel et l'ancien tiroir « Ajouter un employé » | ADR-802 | Tiroir Équipe, clone marqué, registre | réussi |
| 8 | `hrux3-dev-access` — DEV: grant portal access from fiche checklist | **A** | Même ancienne entrée ; même dialogue d'accès réutilisé | ADR-802 / ADR-777 | Checklist de la fiche → « Faire » → Donner accès ; compte rattaché relu | réussi |
| 9 | `hrux5-dev-rh` — W-HR-UX-5 — parcours RH sur DEV réel (création → poste/manager → contr | **A** | Onglets Profil/Contrat/Planning de l'ancienne fiche | ADR-777 / ADR-820 | Rubriques de la fiche ; registre ; clone | réussi |
| 10 | `m7-lot3-self-service` — entreprise SANS Workforce : self-service Core complet, aucune requête  | **A** | « Mon espace » cherché dans la barre latérale ; pour un profil gestionnaire il est dans le menu du compte | ADR-775 | Recherche dans le menu du compte | réussi |
| 11 | `person-p3a-dev` — P3-A DEV — fiche access card + members salarié badges | **A** | Carte « Accès & compte » de l'ancienne fiche | ADR-777 | Entrée « Accès & autorisations » de la vue d'ensemble | réussi |
| 12 | `platform-a11y` — platform P2 a11y — 0 serious (light+dark) | **C** | Barre de progression sans libellé (ratio de remboursement) + « Aucune donnée disponible » en text-disabled (2,29:1 clair, 2,83:1 sombre) | — | aria-label ; text-medium-emphasis sur les 26 états vides (Platform + Company) | réussi |
| 13 | `platform-email-reply-forward` — forward opens the composer with a Fwd subject and empty recipient | **B** | Fixture d'e-mail entrant (corrigée en 4.0) ; ignoré au dernier passage 4.0 | ADR-830 | Aucune ; vert sur clone neuf | réussi |
| 14 | `presentation-resize` — ADR-748b — resize dynamique : le graphe ne disparaît jamais | **E** | Laboratoire de présentation réservé au serveur Vite | ADR-818 (827626b1) | Ignoré avec motif quand la route redirige (produit construit) | ignoré (motivé) |
| 15 | `ui-lab-a11y` — ui-lab — a11y light & dark | **E** | Page de référence UI réservée au serveur Vite | ADR-818 | Ignoré avec motif quand la route redirige ; le test « dashboard réel » du même fichier reste exécuté | ignoré (motivé) |
| 16 | `ui-lab-capture` — ui-lab reference page — all viewports & themes | **E** | Idem | ADR-818 | Ignoré avec motif quand la route redirige | ignoré (motivé) |
| 17 | `ux5-settings` — UX5 parcours propriétaire (lecture) : hub, rôle, absence, factures, in | **A** | Onglet des rôles attendu en ?tab=roles ; onglets dans le chemin | ADR-820 | /company/people/roles attendu | réussi |
| 18 | `workforce-config-cockpit` — manager views the cockpit and edits a configurable break rule (persist | **A** | Cockpit de configuration absorbé par le hub Paramètres RH ; cible pause = assistant | ADR-639 / ADR-797-799 | Panneau « Règles avancées » : minimum légal verrouillé, maximum configurable persisté | réussi |
| 19 | `workforce-eb2-cockpit` — cockpit pointages : synthèse + correction tracée persistée | **B** | Dépend du seeder de démonstration lancé à la main sur le DEV | — | Pointage du jour préparé par e2e:reset-clock-day / e2e:prepare-clock (gardés), collaboratrice résolue par l'API | réussi |
| 20 | `workforce-eb4-lifecycle` — feuille : cycle de vie complet persisté jusqu'au verrouillage | **B** | Feuille brouillon du seeder consommée à chaque exécution (aucune sur le clone neuf) | — | Feuille brouillon générée par le vrai cas d'usage via e2e:php (timesheet-fixtures.js) | réussi |
| 21 | `workforce-employee-file` — unified employee file shows header, overview and conditional lazy sect | **A** | Ancienne fiche W-C et onglets | ADR-802 / ADR-777 | Redirections vérifiées + rubriques + section paresseuse | réussi |
| 22 | `workforce-employee-lifecycle` — manager: create employee → add contract → schedule shift (persisted) | **A** | Onglet Contrat de l'ancienne fiche | ADR-777 | Rubrique Documents & contrats ; registre | réussi |
| 23 | `workforce-hr-nav-group` — Workforce activity nav renders a single group and routes correctly | **A** | Lien Équipe dans un groupe replié | ADR-822 | Ouverture du groupe | réussi |
| 24 | `workforce-hr-settings` — HR settings hub aggregates sections, shows provenance and edits a rule | **A** | Onglet « Règles & conventions » supprimé ; règles dans « Règles avancées » | ADR-797-799 | Provenance, règle configurable persistée, minimum verrouillé dans le panneau avancé | réussi |
| 25 | `workforce-lot1-drawers-publish` — drawers : scroll fonctionnel jusqu'aux actions, multi-résolution | **A** | « Besoins de personnel » déplacé dans le menu « Plus » de la grille | ADR-823 | grid-more → coverage-manage | réussi |
| 26 | `workforce-lot3-complement` — A — absence partielle : décompte proportionnel + champs heures rendus | **A** | Libellé « Absence partielle » devenu « Quelques heures seulement » | ADR-717 | Sélection par libellé de champ | réussi |
| 27 | `workforce-lot4a-dnd` — A — modèle glissé vers une cellule : création serveur + persistance ap | **A** | Palette repliée par défaut | ADR-779 | Ouverture de la palette (aria-expanded) | réussi |
| 28 | `workforce-lot4b1-overstaffing` — sureffectif : dialog, refus sans confirmation, puis confirmation → écr | **A** | Idem | ADR-779 | Idem | réussi |
| 29 | `workforce-lotA-qualified-coverage` — édition directe d'un besoin depuis la grille (inline) | **A** | Bandeau de couverture replié par défaut (préférence mémorisée) | ADR-712 | Dépliage du bandeau | réussi |
| 30 | `workforce-m1b-editor` — M1.b — éditeur visuel : création composée, reload, application, modif  | **A** | Helper ADR-830 incomplet : le mode de vue est mémorisé (cookie), la page rouvre sur Modèles où « Plus » est masqué | ADR-735 / ADR-824 | Helper openPlanningTemplates : déjà sur Modèles → rien à ouvrir | réussi |
| 31 | `workforce-m2-presets` — M2 — tâche type : créer, insérer dans un modèle, appliquer, modifier s | **A** | Palette repliée prise pour « indisponible » → étape d'application ignorée | ADR-779 | Palette ouverte ; le modèle créé DOIT y figurer (saut supprimé) | réussi |
| 32 | `workforce-m6c-construct` — M6.C G — mobile : « Préparer ma semaine » accessible et utilisable à 3 | **A** | Sur mobile, « Préparer ma semaine » est dans le menu de l'en-tête | ADR-824 | planning-more → prepare-week-open | réussi |
| 33 | `workforce-nav-e2-recette` — nav reflète les capacités de rh-nopay@leezr.test | **A** | Groupe « Ressources humaines » et liens historiques | ADR-691 / ADR-802 / ADR-818 | Matrice ADR-691 + liens actuels ; absences conservées | réussi |
| 34 | `workforce-nav-e2-recette` — nav reflète les capacités de ops@leezr.test | **A** | Matrice ADR-690 (exploitation sans Collaborateurs/Organisation/Paramètres) remplacée par ADR-691 ; données côté serveur limitées au périmètre (listes vides prouvées) | ADR-691 / ADR-817 | Matrice ADR-691 | réussi |
| 35 | `workforce-nav-e2-recette` — nav reflète les capacités de pay@leezr.test | **A** | Groupe « Ressources humaines » | ADR-818 | Liens actuels | réussi |
| 36 | `workforce-nav-e2-recette` — nav reflète les capacités de salarie@leezr.test | **A** | Idem | ADR-818 | Idem | réussi |
| 37 | `workforce-nav-e2-recette` — owner : responsive + dark + états actifs | **A** | Lien /company/workforce/employees | ADR-802 | Lien /company/people | réussi |
| 38 | `workforce-nav-e2-recette` — deep-links historiques préservés (anciennes URLs toujours routables) | **A** | Dépendait de l'ancien groupe de navigation | ADR-818 | Inchangé (vert après correction du fichier) | réussi |
| 39 | `workforce-nav-e2-recette` — mobile 390 : drawer de navigation ouvrable et navigable | **A** | Lien dans un groupe replié | ADR-822 | Ouverture du groupe + navigation vérifiée | réussi |
| 40 | `workforce-org-preset` — org preset card renders and preview is non-destructive | **A** | Carte « Organisation proposée » devenue un bouton d'en-tête | ADR-823 | org-preset-open puis aperçu non destructif | réussi |
| 41 | `workforce-p1a-compliance` — contrôles réglementaires : détection sur le constaté, sans blocage | **B** | Feuille brouillon (journée de 12 h) du seeder consommée | — | Feuille + journée de 12 h préparées (timesheet-fixtures.js) | réussi |
| 42 | `workforce-sp1a-smart-planning` — coverage : création persistée d'un besoin de personnel | **A** | « Besoins de personnel » dans le menu de la grille | ADR-823 | grid-more → coverage-manage | réussi |
| 43 | `workforce-sp1a1-grid-timeline` — copie de la semaine précédente (brouillons) | **A** | « Copier la semaine précédente » dans le menu de la grille | ADR-823 | grid-more → copy-prev-week | réussi |
| 44 | `workforce-step1-coverage-drawer` — drawer besoins : ponctuel + récurrent + catégorie + édition + suppress | **A** | « Besoins de personnel » dans le menu de la grille | ADR-823 | grid-more → coverage-manage | réussi |
| 45 | `workforce-visual-audit` — capture responsive (mobile 390 / tablette 768) sur écrans corrigés | **A** | Liste RH en cartes sous md | ADR-802 | Clic sur la première ligne emp-row ; captures dans le dossier de sortie | réussi |
| 46 | `workforce-wg1-forms` — document generation uses business selectors, not technical IDs | **A** | Bouton de génération dans « Documents générés » de l'espace Documents | ADR-802 | Bascule documents-people-generated-switch | réussi |
| 47 | `workforce-wg1-forms` — contract money field keeps precision and shows currency | **A** | Onglet Contrats de l'ancienne fiche | ADR-777 | Rubrique Documents & contrats | réussi |
| 48 | `workforce-wg2-employees` — advanced filters update the list and sync the URL | **A** | Filtres avancés derrière « Filtres » | ADR-817 | fp-toggle | réussi |
| 49 | `workforce-wg2-employees` — guided import: file → mapping → preview (dry-run) → confirm → created | **A** | Import dans le menu « ⋯ » | ADR-802 | emp-more → emp-import ; registre | réussi |
| 50 | `workforce-wg2-employees` — export triggers a CSV download | **A** | Export dans le menu « ⋯ » | ADR-802 | emp-more → emp-export | réussi |
| 51 | `workforce-wg2-employees` — bulk change department: select → confirm → persisted (reserve W-G2 §3) | **A** | Filtre département derrière « Filtres » | ADR-817 | fp-toggle | réussi |
| 52 | `workforce-wg4a-planning` — planning calendar renders week view, switches to day, navigates | **A** | Barre FullCalendar interne supprimée ; vue Jour contrôlée | ADR-735 | plan-view-calendar + navigateur partagé | réussi |
| 53 | `workforce-wg4a-planning` — filters are present (department + employee + status) | **A** | Calendrier = mode Jour | ADR-735 | Idem | réussi |
| 54 | `workforce-wg4a-planning` — clicking a shift opens read-only detail without modifying it | **A** | Idem | ADR-735 | Idem | réussi |
| 55 | `workforce-wg4a-planning` — existing grid tab (create/publish) still available — not degraded | **A** | Onglets remplacés par les modes | ADR-735 | Mode Semaine : shift-add + publish-week (assertion renforcée) | réussi |
| 56 | `workforce-wg4a-planning` — planning is responsive (390px) and dark-mode clean | **A** | Calendrier = mode Jour | ADR-735 | Idem | réussi |
| 57 | `workforce-wg4b-operations` — week start preference persists and drives the planning calendar (real  | **A** | Première colonne lue dans FullCalendar ; la grille Semaine porte la préférence | ADR-735 / ADR-698 | En-tête de la grille Semaine | réussi |
| 58 | `workforce-wg4b-operations` — reassign a draft shift via availability suggestions (real write + pers | **B** | Brouillon « de la semaine courante » supposé sur le DEV | — | Brouillon du jour créé par la spec puis supprimé | réussi |
| 59 | `workforce-wg4b-operations` — planning operations are responsive and dark-mode clean | **A** | Calendrier = mode Jour | ADR-735 | Idem | réussi |

Colonne « Campagne RC2 » : statut dans la seconde campagne intégrale sur clone neuf (section 10). Sur les 59 entrées : **56 réussies, 3 ignorées avec motif (E), 0 échec**.

## 3. Classification individuelle

| Classe | Nombre | Signification |
|---|---|---|
| **A** — test obsolète | 49 | l'interface ou le parcours a changé par une décision identifiée (ADR citée par ligne) |
| **B** — fixture incorrecte | 6 | dépendance à des données du DEV (seeders de démonstration lancés à la main, brouillons consommés, « Bob Dupont ») |
| **C** — défaut applicatif | 1 | `platform-a11y` : contraste et barre sans libellé |
| **D** — environnement | 0 | — |
| **E** — fonctionnalité retirée | 3 | laboratoires `devOnly` (ADR-818) absents du produit construit |
| **F** — indéterminé | **0** | — |

Chaque classement repose sur une preuve rejouée : erreur exacte sur clone neuf, capture et instantané de page,
sonde en lecture seule de l'écran actuel, historique git (`git log -S`) et ADR correspondante.

## 4. Tests obsolètes corrigés (A)

Principes appliqués : parcours actuel, mêmes assertions métier, aucune suppression de spec, aucune assertion
remplacée par une simple présence. Exemples significatifs :
- **Membres → Équipe** (ADR-802/777) : création par le tiroir unique, rôle et retrait dans la fiche, protection du propriétaire (ni retrait ni changement de rôle), persistance relue par l'API.
- **Navigation RBAC** (`workforce-nav-e2-recette`) : réalignée sur la matrice **ADR-691** (qui a remplacé celle d'ADR-690 le jour même) ; les assertions d'**absence** sont toutes conservées. Le profil « Responsable exploitation » voit Collaborateurs et Contrats : le serveur ne lui renvoie que son périmètre (listes vides prouvées par l'API, ADR-817), ce n'est pas une fuite.
- **Livreur sur la fiche d'un collègue** (ADR-786) : l'onglet « Accès & autorisations » n'existe plus pour lui — le serveur n'envoie pas la section `access` (prouvé par l'API) ; les contrôles « pas de permissions listées, pas de clés brutes » portent sur chaque rubrique visible.
- **Planning** (ADR-735/779/823/824) : mode Jour au lieu du calendrier W-G4A, menus « Plus » de la grille et de l'en-tête mobile, palette et bandeau de couverture repliés par défaut ; assertion « grille opérationnelle » renforcée (création et publication présentes).
- **Réglages RH** (ADR-639, L7 ADR-797/799) : règles avancées dans le hub ; le minimum légal reste verrouillé, une règle configurable est modifiée et relue après rechargement.
- **Sauts silencieux supprimés** : `m1b`, `m2` (« palette indisponible » alors qu'elle était repliée), `lot4c1` (« shift non atteint » : la spec cliquait le premier chevron de la page — un groupe de menu — au lieu du navigateur de jours). Ces étapes s'exécutent désormais.
- **Défilement mobile** (`lot4c1`) : l'assertion vérifie l'absence de verrou de défilement et, si la page déborde, que le défilement bouge réellement (l'ancienne formule acceptait un simple débordement).

## 5. Fixtures corrigées (B)

| Spec | Avant | Après |
|---|---|---|
| `workforce-eb4-lifecycle`, `workforce-p1a-compliance` | brouillon du `WorkforceTimesheetDemoSeeder` (consommé à chaque exécution : aucun sur un clone neuf) | collaborateur fictif (registre) + semaine passée de pointages (journée de 12 h) + feuille générée par `GenerateTimesheetUseCase` via `e2e:php` ; nettoyage par identifiants exacts (`tests/e2e/timesheet-fixtures.js`) |
| `workforce-eb2-cockpit` | `WorkforceCockpitDemoSeeder` lancé à la main | `e2e:reset-clock-day` + `e2e:prepare-clock` (gardés), collaboratrice résolue par l'API, sortie cohérente avec l'entrée |
| `hrux-after` | « Bob Dupont » tel qu'il existait sur le DEV | collaborateur + contrat préparés, registre |
| `workforce-wg4b-operations` (réaffectation) | brouillon « de la semaine courante » supposé | brouillon du jour créé puis supprimé |
| `platform-email-reply-forward` | fixture sans `<?php` (corrigée en 4.0) | vert sur clone neuf, aucune modification |

Aucune utilisation de `tinker` ; toutes les écritures de préparation passent par des commandes de recette gardées
(clone marqué uniquement) ou par l'API de l'application servie sur le clone.

## 6. Défauts produit identifiés

| # | Défaut | Origine de la détection | Impact | Gravité |
|---|---|---|---|---|
| 1 | Le tiroir « Nouveau créneau » ne proposait pas la confirmation de sureffectif : le refus serveur (422) n'était qu'un toast | `workforce-employee-lifecycle` (date déjà couverte dans les données clonées) ; reproduit par l'API | un responsable ne pouvait pas créer ou modifier un créneau sur un jour déjà couvert depuis le formulaire principal (contournement : grille) | moyenne |
| 2 | Les liens du menu héritaient de l'onglet courant (`/leave/config` → « Paie » vers `/payroll/config`) | instantané de page `p1a` ; reproduit par sonde et par test Node | mauvaise page d'arrivée ; aucun impact de sécurité (gardes inchangées) | moyenne |
| 3 | « Aucune donnée disponible » en `text-disabled` (2,29:1 / 2,83:1) et barre du ratio de remboursement sans libellé | `platform-a11y` (axe) | lisibilité, lecteurs d'écran | mineure (sérieuse au sens axe) |
| 4 | Libellé brut `splittable` dans les règles de pause | sonde du hub Réglages RH | affichage | mineure |
| 5 | Bouton « Personnaliser » de l'accueil illisible en sombre (2,57:1) | campagne intégrale (`ui-lab-a11y › dashboard réel`) | lisibilité | mineure (sérieuse au sens axe) |
| 6 | Boutons jour précédent/suivant du planning mobile sans nom accessible | analyse du saut `lot4c1` | lecteurs d'écran | mineure |

## 7. Défauts produit corrigés

Les six, sans modification serveur des moteurs Billing, Workforce, RBAC ou DMS :
1. `planning/index.vue` : le tiroir utilise le dialogue et le rejeu signés existants (ADR-719) — parcours prouvé en navigateur par `workforce-employee-lifecycle` (créneau créé après confirmation, relu par l'API).
2. `useCompanyNav.js` : `tab` remis à vide pour les cibles nommées qui en ont un — `tests/js/company-nav-tab-isolation.test.mjs` reproduit le défaut avec le vue-router du projet puis prouve la correction.
3. `text-medium-emphasis` sur les 26 états vides ; `aria-label` sur la barre — `platform-a11y` vert (0 sérieux, clair et sombre).
4. `hrSettings.rules.splittable` (fr, en).
5. Jeton applicatif `.lzr-text-action` (sombre `#a9a2ff`, même valeur que `.lzr-link`, ADR-743) — `ui-lab-a11y › dashboard réel` vert.
6. `aria-label` « Précédent / Suivant » + `data-test` — `lot4c1` exécuté sans saut.

Permissions et isolation : aucune garde modifiée ; les familles RBAC et multi-tenant sont vertes (section 14).

## 8. Vérification `E2E_ALLOW_DEV`

Audit : les commandes `e2e:*` et `e2e:php` restaient protégées par `RecipeDatabaseGuard` (base réelle, marqueur,
connexions secondaires) indépendamment du drapeau. **Mais** deux contournements existaient :
1. avec `E2E_ALLOW_DEV=1`, les specs écrivaient sur le DEV **par l'API** de l'application (créations, rôles, réglages) ;
2. le contrôle portait sur le **nom d'hôte** : un serveur `127.0.0.1` branché sur la base DEV passait sans drapeau.

Correction : sonde `GET /api/_recipe/identity` (non enregistrée en production, 404 hors clone marqué, aucune
écriture ni journal) exigée par `tests/e2e/global-setup.js` avant le premier test, avec `recipe:status` sur la même
base ; drapeau retiré. Matrice de refus rejouée (`docs/bmad/leezr-4-0/rc2/e2e-guard-matrix.txt`) : DEV par défaut,
drapeau, drapeau + clone, serveur local sur la base DEV, base déclarée différente, variable absente, serveur en
`APP_ENV=production`, commandes de recette en production → **refusés avant toute requête** ; clone légitime → accepté.
PHPUnit `Adr831RecipeIdentityProbeTest` (10 tests). Journal binaire : section 15.

## 9. Accessibilité résiduelle

- Corrigé : états vides, barre du ratio, bouton « Personnaliser », boutons de jour mobiles.
- **Résiduel documenté** : le primaire partagé entre les thèmes (ADR-332) reste faible **en texte sur fond sombre** pour les autres boutons texte primaires non mesurés par la recette ; le jeton `.lzr-text-action` est prêt à être appliqué au cas par cas. Une correction globale (tous les boutons texte primaires en sombre) changerait l'apparence de la marque : décision produit à prendre.
- Le groupe de menu « Équipe » ne se déplie pas automatiquement sur ses sous-pages (`/company/people/hr`) : comportement de `@layouts` Vuexy (correspondance exacte de route), non modifiable ici ; mineur.
- Les contrôles axe Vuexy de la barre supérieure et du menu restent exclus des scans (chrome `@core`), comme avant.

## 10. Nouvelle campagne E2E

Deux campagnes **intégrales** (465 tests, inventaire inchangé, aucune spec retirée), chacune sur un **clone neuf
et marqué** créé depuis le DEV par `scripts/recipe/make-clone.sh` (lecture seule de la source), identité vérifiée
(`recipe:status` + sonde `/api/_recipe/identity`) avant le premier test, profil polling, serveur dédié.

| Campagne | Clone | Réussis | Échecs | Ignorés | Durée |
|---|---|---|---|---|---|
| 1 | `leezr_recipe831b` | 457 | 2 | 6 | 1 h 28 |
| 2 (après corrections) | `leezr_recipe831c` | **459** | **0** | 6 | 1 h 27 |

Les 2 échecs de la campagne 1 ont été analysés et corrigés avant la campagne 2 :
- `ui-lab-a11y › dashboard réel` : défaut produit (contraste du bouton « Personnaliser » en sombre, section 6 n° 5) ;
- `workforce-lot2-drawer-reserve › mobile` : double ouverture du menu « Plus » dans la spec (réalignement 4.0) — corrigé.

**Tests ignorés de la campagne 2 (6)** :

| Spec | Motif | Statut |
|---|---|---|
| `adr774-ux-audit-journeys` | outil d'audit, s'exécute avec `AUDIT_STEPS` (ADR-830) | justifié |
| `e3r-r3` (laboratoire SmartActionBar), `presentation-resize`, `ui-lab-a11y › ui-lab`, `ui-lab-capture` | laboratoires `devOnly` absents du produit construit (ADR-818) | justifié (E) |
| `workforce-lot4c1-mobile › A` | saut artificiel « shift non atteint » : dépendait du jour de la semaine (navigation uniquement vers l'avant) | **corrigé** (`4328fe01`) : la spec navigue vers la date exacte et échoue au lieu de s'ignorer ; rejouée **2 fois, 4/4 réussis** sur le clone de la campagne 2 |

**Résultat consolidé et reproductible** : 465 tests = **460 réussis, 0 échec, 5 ignorés justifiés** (1 outil
d'audit, 4 laboratoires), **0 retiré**, 0 relance automatique (aucun test « flaky »).
Rapports détaillés conservés hors dépôt (JSON Playwright des deux campagnes) ; synthèse
`docs/bmad/leezr-4-0/rc2/campaigns.json`.

## 11. Résultats PHPUnit

Suite complète : **4 767 réussis, 12 ignorés, 3 échecs** — les 3 `ControllerSizeInvariant` historiques, liste
des contrôleurs en cause **identique** à la 4.0 (comparaison des sorties). +10 tests (sonde d'identité).

## 12. Résultats JS

`pnpm test:js` : **8/8** (5 `RealtimeClient` + 3 isolation de l'onglet de navigation).

## 13. Build

`pnpm build` propre (trois fois au cours de la mission, la dernière après le dernier correctif).

## 14. Contrôles RBAC et multi-tenant

| Famille (suite complète) | Classes | Tests réussis | Échecs |
|---|---|---|---|
| RBAC / permissions | 26 | 249 | 0 |
| Multi-tenant / isolation | 7 | 38 | 0 |
| Relances Billing / concurrence (dont `Adr830DunningConcurrencyTest`) | 4 | 43 | 0 |
| Gardes de recette (ADR-827/828/831) | 4 | 23 | 0 |

En navigateur : `security-cross-tenant`, `workforce-two-company-isolation`, la matrice de navigation par profil et
la fiche collègue vue par un livreur sont verts dans la campagne intégrale.

## 15. Absence d'écriture sur DEV

Empreintes `CHECKSUM TABLE` de la base `leezr` avant la mission et après la première campagne : seules
`sessions` et `platform_user_dashboard_layouts` diffèrent. Journal binaire décodé depuis la position de départ
(`binlog.000007:455690677`) : **595 UPDATE `sessions` + 6 UPDATE `platform_user_dashboard_layouts`, aucun INSERT ni
DELETE** ; les 595 mises à jour portent sur **une seule** session préexistante, administrateur Platform n° 1,
agent `Chrome/157` **non headless** (navigateur interactif) ; les 6 mises en page appartiennent au même
utilisateur. Toutes les écritures « HeadlessChrome » des campagnes portent sur les clones.

Campagne 2 (fenêtre `binlog.000007:557688037` → fin) : **333 UPDATE `sessions`**, la même session interactive, et
**aucune autre écriture** sur `leezr`. Aucune écriture de recette sur le DEV, aucune écriture sur Company 1 réelle.
Les seules données créées l'ont été sur les clones `leezr_recipe831`, `leezr_recipe831b`, `leezr_recipe831c`
(conservés ; leur suppression est irréversible et reste soumise à autorisation).

## 16. Risques résiduels

| Risque | Niveau | Traitement |
|---|---|---|
| Production non vérifiée (révision, migrations, FPM, proxy, workers, cron, Redis, sauvegardes) | élevé | D-11 (lecture seule) avant toute décision |
| Primaire de marque peu contrasté en texte sur fond sombre (hors écrans mesurés) | faible | jeton prêt ; décision produit |
| Sauts conditionnels historiques liés aux données dans d'autres specs (« base saturée »…) | faible | aucun ne s'est déclenché sur les clones neufs, hors outil d'audit et laboratoires |
| Durée de la campagne intégrale (~1 h 30) | faible | exécution sur clone dédié |
| Mode « lot » de l'outil `adr774` ignoré sans `AUDIT_STEPS` | nul | outil d'audit, pas une recette |
| D-8, D-10, D-13, D-17 | — | inchangés, décisions en attente (rapport 4.0 §20) |

## 17. Verdict A — Qualité du code

**GO sous conditions.**

Fondements : 465 E2E compris individuellement et reproductibles (0 échec, 5 sauts justifiés, aucun test retiré),
PHPUnit 4 767 verts (3 échecs historiques connus et identiques), JS 8/8, build propre, familles RBAC / multi-tenant /
relances / gardes vertes, six défauts produit trouvés et corrigés avec leur test, recettes incapables d'écrire sur le
DEV (preuve serveur), aucune écriture de recette sur le DEV (journal binaire).

Conditions :
1. les 3 échecs historiques `ControllerSizeInvariant` restent une dette d'architecture connue (non introduite) ;
2. le contraste du primaire de marque en texte sur fond sombre hors écrans mesurés reste à décider (section 9) ;
3. la campagne intégrale doit être rejouée sur clone neuf avant toute promotion (procédure : section 10) ;
4. les décisions D-8, D-10, D-13 et D-17 restent ouvertes et ne bloquent pas le code ;
5. aucune promotion sans le verdict B.

## 18. Verdict B — Production

**NO-GO.** Aucune vérification réelle de production n'a été effectuée ni autorisée par cette mission. Une Release
Candidate validée localement n'est pas une autorisation de déployer. Préalables inchangés : D-11 (vérifications en
lecture seule), restauration d'essai d'une sauvegarde hors production, validation des migrations sur un clone de la
production, configuration (profil polling, `BILLING_ASYNC_QUEUES_ENABLED=false`, préfixes Redis distincts), puis
décision explicite de déploiement.
