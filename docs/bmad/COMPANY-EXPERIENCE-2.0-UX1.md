# Company Experience 2.0 — UX1 : accueil intelligent et cockpit opérationnel (ADR-776)

**Date** : 2026-11-14 · **Base** : `9d670877` · **Suite de** : `COMPANY-EXPERIENCE-2.0-UX0.md` (ADR-775) · **Preuves** : `docs/bmad/ux1-776/`

## 1. Audit de l'accueil avant modification

Page `resources/js/pages/dashboard.vue`, mesurée pour le propriétaire à `9d670877` (données ADR-774) : 1977 px de haut, 17 cartes, 21 puces, 2 graphiques, 11 boutons.

| Constat | Détail |
|---|---|
| Priorités partielles | « À traiter » ne lisait que Workforce (`/workforce/dashboard/priorities`) et n'apparaissait qu'avec `workforce.view`. Rien pour les documents ni pour l'activité métier. Une entreprise sans Workforce n'avait aucune priorité. |
| Priorités volumineuses | 4 grandes cartes avec chiffre coloré et bouton « Traiter » chacune : 4 boutons concurrents pour 4 liens. |
| Raccourcis | Corrigés en UX0 (actions contribuées par les modules), mais présentés en bandeau horizontal multicolore. |
| Cartes « première visite » | 3 grandes cartes (profil, documents, membres) affichées tant qu'aucun widget n'existe : redondantes avec la liste de mise en route et avec les actions. |
| Réglages trop visibles | « Ajouter un widget », « Enregistrer la disposition », poignées de déplacement et bannière de suggestions affichés en permanence aux administrateurs. |
| État vide encombrant | Grande carte centrée avec avatar de 80 px lorsqu'aucun widget n'est configuré. |
| Sous-titre sans information | « Votre espace de gestion centralisé ». |
| Données répétées | Aucune nouvelle redondance relevée entre priorités et indicateurs (déjà traitée par ADR-745). |
| Requêtes | Aucune requête interdite : la conformité documentaire et le profil salarié sont déjà conditionnés aux capacités. |

Ce qui existait et a été conservé tel quel : le moteur de widgets (`app/Modules/Dashboard`, grille, redimensionnement, modes de présentation), les dispositions enregistrées par utilisateur, les dispositions par défaut par rôle issues du jobdomain, le repli de disposition calculé, la liste de mise en route.

## 2. Architecture de l'accueil avant / après

| Avant | Après |
|---|---|
| En-tête + bouton « Mon espace salarié » | En-tête : salutation, nom de l'entreprise, « Mon activité » (si fiche salarié), actualiser |
| Bannière de suggestions de widgets | déplacée dans le mode « Personnaliser » |
| Mise en route | inchangée (se masque seule une fois terminée ou fermée) |
| Bandeau « Actions rapides » | carte **Actions** (liste verticale, une seule couleur) |
| « À traiter » : 4 cartes Workforce | carte **À traiter** : une ligne par sujet, tous modules actifs |
| 3 cartes « première visite » | supprimées |
| « Tableau de bord » + boutons d'édition permanents | **Vue d'ensemble** + bouton « Personnaliser » |
| Grille toujours modifiable pour un administrateur | grille en lecture ; modifiable seulement en mode « Personnaliser » |
| Grand état vide | une ligne de texte |

L'accueil répond dans cet ordre à : *qu'est-ce qui demande mon intervention* (À traiter), *que puis-je faire maintenant* (Actions), *que se passe-t-il* (Vue d'ensemble). Sur mobile, les actions passent en premier.

## 3. Priorités et actions intégrées

### Registre de priorités

Un module contribue ses sujets à traiter par son manifeste : `Capabilities::$priorityProviders` (classes implémentant `App\Core\Home\HomePriorityProvider`). `HomePrioritiesBuilder` assemble les contributions des modules **actifs**, écarte les compteurs nuls et les doublons, trie par urgence. Point d'entrée : `GET /api/dashboard/priorities`.

Contrat d'une contribution : **réelle** (lue dans la source de vérité du module), **autorisée** (le fournisseur vérifie les droits du demandeur), **actionnable** (`to` ouvre le traitement), **cloisonnée** (entreprise de la requête). Une contribution qui échoue est isolée : elle est signalée dans `unavailable`, le reste s'affiche.

| Sujet | Module | Droit requis | Ouvre |
|---|---|---|---|
| Documents à valider | Documents | `documents.view` + `documents.manage` | Demandes, filtre « soumises » |
| Documents expirés | Documents | `documents.view` | Conformité, filtre « expirés » |
| Documents qui expirent bientôt | Documents | `documents.view` | Conformité, filtre « expirant » |
| Congés à approuver | Workforce | `workforce.view` + `workforce.leave_approve` | Congés |
| Feuilles de temps à valider | Workforce | `workforce.view` + `workforce.timesheet_approve` | Pointage, onglet feuilles |
| Anomalies de pointage | Workforce | `workforce.view` + `workforce.timesheet_view` | Pointage |
| Signatures en attente | Workforce | `workforce.view` + `workforce_signature.view` | Documents RH |
| Périodes de paie à contrôler | Workforce | `workforce.view` + `workforce.payroll_prepare` | Paie |
| Intégrations à compléter | Workforce | `workforce.view` | liste des salariés |
| Expéditions en retard | Expéditions | `shipments.view` | Expéditions, filtre « en transit » |
| Expéditions à affecter | Expéditions | `shipments.view` + `shipments.assign` | Expéditions |

Les sujets Workforce viennent du read-model existant (ADR-682), sans recalcul ; les sujets documentaires viennent du read-model de conformité ; les sujets Expéditions reprennent les définitions des indicateurs existants. Pour que les liens ouvrent le bon traitement, les onglets Demandes et Conformité et la liste des expéditions lisent désormais leur filtre dans l'adresse.

### Actions

L'accueil affiche les actions du registre UX0 marquées `home`, filtrées par le serveur : Ajouter une personne, Préparer le planning, Demander un document, Déposer un document. « Générer un contrat » n'y figure pas : elle exige une personne (action de contexte, pour la fiche en UX2). Un profil sans action autorisée n'a pas de carte Actions.

## 4. Adaptation par modules et jobdomain

- Les priorités viennent des modules actifs : une entreprise sans Workforce n'a ni sujet ni appel Workforce (vérifié en navigateur sur « Trial Logistics ») ; une entreprise sans le module Expéditions n'a pas de sujet d'expédition même si des données existent (test).
- Les indicateurs restent ceux du moteur existant : widgets déclarés par module, filtrés par module actif et permission, disposition par défaut par rôle définie par le jobdomain (`dashboard_widgets`), repli calculé sinon.
- Le Core (`app/Core/Home`) ne nomme aucun métier (test). Aucun second moteur de widgets n'a été créé.

## 5. Personnalisation préservée

- Dispositions enregistrées, widgets, règles de redimensionnement, modes de présentation, permissions : inchangés (aucun fichier du moteur modifié).
- « Personnaliser » active l'édition : ajout depuis le catalogue, déplacement, redimensionnement, suggestions. « Terminer » enregistre si la disposition a changé.
- Le bouton n'est proposé qu'aux profils qui pouvaient déjà modifier la disposition.
- Un nouvel utilisateur obtient la disposition par défaut de son rôle sans rien configurer.

## 6. États et erreurs

- Chargement : squelette propre à la carte « À traiter » ; la grille garde son chargement existant. Aucun bloc n'attend l'autre.
- Rien à traiter : une ligne « Rien à traiter ».
- Erreur de chargement des priorités : message local avec « Réessayer », le reste de l'accueil reste utilisable.
- Contribution indisponible : mention « Certaines informations sont momentanément indisponibles ».
- Bouton « Actualiser » : recharge priorités et indicateurs.
- Actions non autorisées : absentes.

## 7. Parcours mesurés

« Après » : exécuté dans le navigateur par `tests/e2e/ux1-home.spec.js`, avec écritures réelles sous registre d'exécution (personne créée puis supprimée par le démontage, demandes et pièces liées supprimées en cascade). Clics et pages comptés par instrumentation. « Avant » : chemins relevés par l'audit ADR-774, formulaires ouverts mais non soumis ; les clics de saisie identiques sont ajoutés pour comparer à périmètre égal.

| Parcours | Avant | Après (mesuré) |
|---|---|---|
| Consulter les priorités | 4 sujets Workforce seulement, aucun sujet documentaire ni métier | 0 clic, 7 sujets visibles à l'ouverture |
| Accéder à une demande à valider | 2 clics (Documents → Demandes), file non filtrée | 1 clic, 1 page, file filtrée sur « soumises » |
| Valider la demande | 4 clics | 3 clics (sujet, approuver, confirmer), 1 page |
| Ajouter une personne | 6 clics (Collaborateurs → Nouveau → Inviter, rôle, envoi) | 4 clics (action, rôle ×2, envoi), 1 page |
| Déposer un document de l'entreprise | 2 clics jusqu'au coffre | 1 clic jusqu'au coffre (envoi de fichier non exécuté) |
| Accéder au planning | 1 clic avant UX0, 2 depuis le menu UX0 | 1 clic |

Requêtes en erreur sur ces parcours : 0. Aucun gain de temps n'est avancé.

Hauteur de l'accueil du propriétaire : 1977 px avant, 2004 px après ; cartes : 17 → 14. La hauteur ne baisse pas pour ce compte parce que sa disposition enregistrée contient 11 widgets, conservés. Dispatcher : 1384 px, 7 cartes. Manager : 1068 px.

## 8. Rôle Driver et `members.invite`

- **Origine** : préréglage du jobdomain logistique (`JobdomainRegistry`, rôle `driver`), qui attribue le lot `members.team_access`. Ce lot réunit `members.view` et `members.invite` ; il n'existe pas de lot « voir l'équipe » sans invitation.
- **Portée en DEV** : 7 rôles Driver, 12 adhésions concernées.
- **Risque réel** : limité. Un acteur non administratif ne peut inviter que dans un rôle non administratif (`MembershipSecurityPolicy`, contrôle avant toute écriture).
- **Cohérence** : faible. Un rôle de terrain (`field_worker`) n'a pas vocation à inviter des collègues ; la permission vient du regroupement du lot, pas d'un besoin exprimé.
- **Effet sur l'accueil** : nul pour un Driver, qui arrive sur `/home` (espace personnel) où les actions d'entreprise ne sont pas proposées.

Verdict : anomalie de préréglage, pas faille. **Rien n'a été modifié.** Correction sûre proposée, à valider :

1. Créer un lot `members.team_view` (`members.view` seul) et l'utiliser pour les rôles de terrain du préréglage. Effet : nouvelles entreprises uniquement.
2. Pour les rôles existants, retirer `members.invite` rôle par rôle après vérification qu'aucun Driver n'a émis d'invitation (journal d'audit), par une opération réversible. À ne faire que sur décision explicite.

## 9. Tests

| Niveau | Contenu | Résultat |
|---|---|---|
| Backend | `Ux1HomePrioritiesTest` (5 tests) : sujets réels, actionnables et triés ; filtrage par droits ; module inactif ; cloisonnement entre entreprises et refus 403 hors adhésion ; contribution en échec isolée ; aucun métier dans le Core ; libellés dans les deux langues | vert |
| Navigateur | `ux1-home.spec.js` : 7 profils (propriétaire, Dispatcher, RH, manager, paie, consultation, Driver) — affiché = servi, chaque sujet et chaque action ouverts, aucun refus réseau ni erreur JS ; parcours avec écriture ; entreprise sans Workforce après changement d'entreprise | 9 / 9 |
| Formats | mobile 390 px, tablette 820 px, sombre : pas de débordement horizontal, aucune erreur | captures |
| Régression | suite complète et build : voir le checkpoint | — |

## 10. Captures

Dans `docs/bmad/ux1-776/` : `after-<profil>-desktop.jpg` (6 profils), `after-owner-no-workforce.jpg`, `j-owner-01-home.jpg`, `j-owner-02-customize.jpg`, `journey-to-review.jpg`, `j-mobile-01-home.jpg`, `j-tablet-01-home.jpg`, `j-dark-01-home.jpg`. Avant : `docs/bmad/ux-774/11-owner-dashboard.jpg`.

## 11. Défauts résiduels

1. « Intégrations à compléter » ouvre encore l'ancienne liste des salariés (`/company/workforce/employees`) : à reprendre avec la liste unique d'UX2.
2. « Expéditions à affecter » ouvre la liste sans filtre : la liste n'a pas de filtre « non affectée ».
3. « Signatures en attente » ouvre Documents RH et non la liste des signatures filtrée.
4. Gestionnaire paie : accueil presque vide (aucun sujet, aucune action, aucun indicateur par défaut pour ce rôle).
5. La disposition par défaut n'a pas été revue : les utilisateurs existants gardent leurs widgets, parfois nombreux.
6. Le bouton « Mon activité » de l'en-tête mène à `/home` alors que l'entrée du même nom dans le menu de l'avatar mène à la page Workforce.
7. Les titres des boîtes de confirmation restent ceux fournis par chaque écran ; seuls les boutons « Confirm » / « Cancel » ont été traduits.
8. Résiduels UX0 inchangés (offres en anglais, pages sans titre).

## 12. Conditions pour UX2

- Décider de la destination de « Mon activité » dans l'en-tête de l'accueil : `/home` (actuel) ou la page Workforce.
- Décider du préréglage Driver (section 8).
- Valider les libellés « À traiter », « Actions », « Vue d'ensemble », « Personnaliser ».
- UX2 branchera « Générer un contrat » et « Ajouter une personne » sur la fiche et la liste unique, et redirigera « Intégrations à compléter » vers cette liste.
