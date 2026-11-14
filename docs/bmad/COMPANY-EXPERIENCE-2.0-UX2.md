# Company Experience 2.0 — UX2 : Équipe, fiche unifiée, parcours simplifiés (ADR-777)

**Date** : 2026-11-14 · **Base** : `6b3eabaf` · **Suite de** : `COMPANY-EXPERIENCE-2.0-UX1.md` (ADR-776) · **Preuves** : `docs/bmad/ux2-777/`

## 0. Décisions UX1 appliquées

| Décision | Réalisation |
|---|---|
| « Mon activité » mène à l'espace Workforce personnel | le bouton de l'accueil ouvre `company-workforce-me`, comme l'entrée du menu de l'avatar |
| `/home` identifié comme « Accueil personnel » | intitulé affiché en tête de `/home` ; entrée « Accueil personnel » dans le menu de l'avatar d'un gestionnaire aussi salarié (entrée de manifeste, conditionnée à la fiche salarié) |
| Préréglage Driver | nouveau lot `members.team_view` (`members.view` seul) ; le rôle Driver du préréglage logistique l'utilise à la place de `members.team_access`. Vaut pour les entreprises créées ensuite |
| Rôles existants | **non modifiés** ; analyse en section 9 |
| Liens contextuels incorrects | « Intégrations à compléter » ouvre la liste Équipe unique ; « Signatures en attente » ouvre la liste des signatures filtrée sur « en attente » |

Non corrigé : « Expéditions à affecter » — la liste n'a pas de filtre « non affectée » (condition posée : destination et filtre existants).

## 1. Architecture Équipe avant / après

| Sujet | Avant | Après |
|---|---|---|
| Ajout d'une personne | menu « Nouveau » à deux entrées techniques (« Inviter un utilisateur » / « Créer une fiche salarié »), deux formulaires, deux composants | un bouton « Ajouter une personne », un tiroir |
| Fiche | 11 onglets (10 sans compte, 6 membre seul) | 5 rubriques au plus (4 sans Workforce) |
| Actions de la fiche | « Modifier » + icônes sans libellé (contrat, accès, rattachement) | « Modifier » + menu « Actions » libellé |
| Fin de collaboration | endpoint existant, **aucune interface** | action « Terminer la collaboration » (motif obligatoire) |
| Liste | colonnes Compte et Rôle séparées (deux puces) | une colonne « Accès » : état en puce, rôle en clair |
| Après création | retour à la liste (invitation) ou fiche (salarié) | toujours la fiche créée, avec confirmation |
| Mobile | onglets en icônes seules | rubrique courante nommée sous la barre ; actions dans l'écran |

Une personne = une ligne : la liste repose sur `person_ref` (inchangé) ; vérifié qu'une personne ajoutée avec fiche et compte n'apparaît qu'une fois.

## 2. Parcours d'ajout unique

Tiroir `_PersonAddDrawer.vue`. Champs demandés : prénom, nom, puis la question « Cette personne aura-t-elle besoin de se connecter à Leezr ? ».

| Réponse / capacité | Champs ajoutés | Ce qui est créé | Endpoints (inchangés) |
|---|---|---|---|
| Non | date d'entrée (préremplie à aujourd'hui, modifiable) | fiche sans compte | `POST /workforce/employees` |
| Oui, personne du personnel | e-mail, rôle, date d'entrée | fiche **puis** invitation liée à la fiche | `POST /workforce/employees`, `POST /workforce/employees/{id}/access` |
| Oui, hors personnel (interrupteur décoché) | e-mail, rôle | invitation seule | `POST /company/members` |
| Entreprise sans Workforce, ou sans `workforce.manage` | e-mail, rôle ; **question non posée**, aucune information RH | invitation seule | `POST /company/members` |
| Sans `members.invite` | date d'entrée ; question non posée | fiche sans compte | `POST /workforce/employees` |

- Champs repliés sous « Plus d'informations », jamais obligatoires : téléphone, matricule, e-mail de contact.
- Les obligations viennent des validations serveur existantes (prénom, nom, date d'entrée pour une fiche ; e-mail et rôle pour une invitation). Aucune n'a été assouplie.
- Compte déjà existant : l'invitation reste en attente d'acceptation explicite (moteur ADR-768 inchangé) ; le tiroir l'annonce sous le champ e-mail.
- Quotas, anti-escalade de rôle, confidentialité : contrôlés par les mêmes cas d'usage serveur.
- Si l'invitation échoue après création de la fiche, la fiche est conservée, le motif est affiché et la fiche s'ouvre sur « Accès & autorisations ».
- Aucun moteur d'invitation nouveau. Aucune fiche salarié créée pour une entreprise sans Workforce.
- Les anciens liens `?action=invite` et `?action=create` ouvrent le tiroir avec la réponse préremplie.

## 3. Fiche simplifiée

Le serveur continue de décider des **sections** visibles (`available_sections`, une par facette de droits). L'interface les range en rubriques ; une rubrique n'existe que si l'une de ses sections est servie. Partager une rubrique ne fusionne aucune permission : chaque section garde sa garde et son composant d'origine.

| Rubrique | Sections regroupées | Remarque |
|---|---|---|
| Vue d'ensemble | Vue d'ensemble, Historique | historique replié, chargé à l'ouverture |
| Informations & emploi | Informations personnelles, Emploi | |
| Documents & contrats | Contrat, Documents (pièces, documents générés, signatures), Bulletins de paie | bulletins : section servie uniquement à l'autorité paie |
| Temps & absences | Planning, Pointage, Congés & absences | pointage et congés chargés à l'affichage |
| Accès & autorisations | Accès & autorisations | |

- Entreprise sans Workforce : 4 rubriques (pas de « Temps & absences », ni contrat). Vérifié en navigateur.
- Fiche sans compte : les 5 rubriques, exploitable de bout en bout (parcours 1 à 5 exécutés sur une fiche sans compte).
- Anciens liens : `?tab=contract`, `?tab=payslips`, `?tab=leave`… ouvrent la rubrique correspondante, positionnée sur la section, sans changer d'adresse. La redirection de `/company/workforce/employees/{id}?tab=…` conserve son paramètre.
- Défaut de l'audit ADR-774 corrigé : un lien interne vers la même fiche avec un autre `?tab=` change bien de rubrique.

## 4. Actions contextuelles

Action principale : **Modifier** (ouvre la section en édition). Menu **Actions**, chaque entrée affichée seulement si la capacité serveur correspondante est vraie :

| Action | Capacité | Effet |
|---|---|---|
| Ajouter un document | `documents.manage` + section Documents | ouvre la section Documents |
| Demander un document | idem + compte existant | ouvre la section Documents |
| Générer un contrat | `can.generate_contract` | dialogue existant |
| Faire signer | `can.view_signatures` + `workforce_signature.request` | page Signatures |
| Gérer l'accès | `can.manage_access` + fiche | dialogue existant |
| Créer la fiche salarié / Rattacher à un compte | `can.create_hr_record` / `can.link_account` | dialogues existants |
| Historique | `can.view_history` | section Historique |
| Suspendre l'accès | `can.suspend_access` | rubrique Accès, dialogue de motif ouvert |
| Terminer la collaboration | `can.edit_hr`, salarié non sorti | dialogue (motif obligatoire) → `POST /workforce/employees/{id}/terminate` |

Les deux dernières sont séparées et en rouge. Sur mobile, « Modifier » et « Actions » tiennent dans l'écran (test de position).

## 5. Liste

- Recherche immédiate (inchangée), filtres Accès et Emploi, pagination serveur.
- Colonnes : Personne, Accès (état + rôle), Emploi, Poste, Département. Les colonnes suivent toujours les facettes servies.
- Clic sur la ligne : fiche.
- Bouton unique « Ajouter une personne », proposé seulement si l'utilisateur peut inviter ou créer une fiche.

## 6. Fonctions historiques préservées

- « Outils » : import, export et actions groupées (liste des salariés), champs personnalisés — inchangés.
- Composants `AddMemberDrawer` et `PersonCreateEmployeeDialog` conservés pour les écrans historiques.
- Routes, redirections et contrats d'API inchangés. Une seule évolution d'API, additive : `POST /company/people/{ref}/documents/{code}` accepte `files[]` en plus de `file` (voir section 8).
- Recette historique `m7-lot2-people.spec.js` portée sur le nouveau parcours : 4 scénarios sur 4 (invitation, édition, rôle, suspension, réactivation, retrait, contrat, chevauchement refusé, rattachement à un compte, compte existant en attente).

## 7. Matrice RBAC

| Section de la fiche | Servie si |
|---|---|
| Informations personnelles | adhésion + `members.view` |
| Accès & autorisations | `members.view` |
| Emploi, Contrat | fiche salarié + `workforce.view` + périmètre (soi, rapports directs, ou profil RH) |
| Planning, Pointage, Congés | fiche + Workforce + droits du domaine |
| Documents | `documents.view` ; documents générés : `workforce_documents.view` ; signatures : `workforce_signature.view` |
| Bulletins de paie | autorité paie (`canViewPayslips`) |
| Historique | `audit.view` |

Autorités inchangées : `MembershipSecurityPolicy`, `GlobalIdentityPolicy`, `WorkforceAccess`, `DocumentAccessPolicy`. Aucune permission ajoutée aux rôles ; une retirée d'un préréglage (Driver).

| Scénario | Vérification |
|---|---|
| Entreprise sans Workforce | navigateur : tiroir sans question ni champ RH, fiche à 4 rubriques |
| Employee sans User | navigateur : création, fiche, édition, contrat, dépôt |
| User sans Employee | navigateur : invitation hors personnel ; fiche à 4 rubriques |
| Employee lié | navigateur : ajout avec accès, une seule ligne |
| Admin aussi Employee | navigateur : fiche du propriétaire, bulletins |
| Members sans Workforce (Dispatcher) | navigateur : ajout = invitation seule |
| RH sans droits Members, manager limité, consultation | navigateur : liste vide (périmètre), aucun bouton d'ajout, aucun refus réseau |
| Gestionnaire paie | navigateur : pas d'espace Équipe |
| Gestionnaire documentaire | couvert par les tests documentaires existants (`viewer` → 403) |
| Multi-company | navigateur : changement d'entreprise |
| Invitation en attente | navigateur : compte existant → « en attente », jamais activé |

Constat sur les données DEV : les rôles « RH (sans paie) », « Manager équipe » et « Consultation » ont `workforce.view` sans `workforce.manage` et ne sont liés à aucune fiche ; leur périmètre est donc vide et la liste Équipe aussi. C'est la configuration de ces rôles, non la navigation.

## 8. Mesures avant / après

« Après » : exécuté par `tests/e2e/ux2-team.spec.js` avec écritures réelles sous registre d'exécution, clics et champs comptés par instrumentation. « Avant » : reconstitué à partir de l'audit ADR-774 (formulaires ouverts, non soumis) et de la recette M7 ; ce n'est pas une mesure instrumentée.

| Parcours | Avant | Après (mesuré) |
|---|---|---|
| Ajouter sans accès | 3 clics, choix technique préalable, 6 champs affichés | 3 clics, 2 champs saisis, 1 page |
| Ajouter avec accès (fiche + compte) | 2 parcours : créer la fiche, puis ouvrir la fiche et « Gérer l'accès » ; 2 formulaires | 5 clics, 4 champs, 1 formulaire, 1 page |
| Inviter un compte existant | 5 clics, 4 champs | 6 clics, 4 champs (1 clic de plus : interrupteur « personnel ») |
| Trouver une personne | recherche + 1 clic | 1 champ, 1 clic |
| Modifier ses informations | 2 clics | 2 clics, 1 champ |
| Générer un contrat | icône sans libellé + 2 clics | 3 clics (Actions, Générer un contrat, Générer) |
| Déposer un document | **échouait** depuis la fiche (422) | 3 clics, 1 fichier |
| Suspendre un accès | 3 clics (onglet, bouton, confirmation) | 3 clics (Actions, Suspendre, confirmation) |
| Trouver un bulletin | 1 clic parmi 11 onglets | 1 clic parmi 5 rubriques |

Lecture honnête : le nombre de clics baisse nettement pour l'ajout avec accès et reste voisin ailleurs ; il augmente d'un clic pour deux parcours. Le gain porte sur l'absence de choix technique, le nombre de formulaires, les libellés, et un parcours qui ne fonctionnait pas. Les durées relevées (0,5 à 2 s) sont celles d'un automate et ne sont pas des temps utilisateur. Requêtes en erreur sur les parcours : 0.

Défauts réels trouvés et corrigés par ces parcours :

- **Dépôt de document depuis la fiche** : le panneau envoyait `files[]`, la route « personne » n'acceptait que `file` → 422. La route accepte maintenant les deux formes, mêmes règles par fichier (test).
- **Tiroir de visualisation à moitié visible sur mobile** : fermé, il recouvrait la moitié droite de la section Documents (largeur `100%`). Largeur numérique.
- **« Modifier » cliqué avant la fin du chargement** ouvrait la mauvaise section : la fiche attend la fiche RH.

## 9. Rôle Driver — analyse des rôles existants

| Élément | Valeur (DEV) |
|---|---|
| Rôles Driver détenant `members.invite` | 7, dans 7 entreprises |
| Adhésions concernées | 12 |
| Invitations émises par ces comptes (journal d'audit `member.invitation_sent`) | 0 |

Aucun usage constaté. Retirer la permission aux rôles existants ne casserait aucun parcours observé, mais modifierait des droits attribués : **non fait**. Si vous le décidez, l'opération sûre est un retrait rôle par rôle, journalisé, réversible, précédé du même contrôle sur la base cible (les chiffres ci-dessus sont ceux de DEV).

## 10. Tests

| Niveau | Contenu | Résultat |
|---|---|---|
| Backend | `Ux2TeamTest` (3) : préréglage Driver, lots, rôles qui gardent l'invitation, aucune migration ; dépôt `file` / `files[]` avec refus 403, 404 et type interdit ; liens de priorités ; attentes UX0 mises à jour (« Accueil personnel ») | vert |
| Suite complète | une fois | 4278 verts, 12 ignorés, 3 échecs historiques de `ControllerSizeInvariantTest` |
| Navigateur UX2 | `ux2-team.spec.js` : parcours avec écriture, 5 profils, entreprise sans Workforce, mobile | voir checkpoint |
| Régression | recettes UX0, UX1, cycles documentaires, `m7-lot2-people` | voir checkpoint |

`PersonDocumentController` : 259 lignes avant, 258 après (pas d'augmentation d'un contrôleur déjà hors seuil).

Nettoyage : toutes les données créées sont inscrites au registre et supprimées par le démontage. L'invitation d'un compte existant est retirée par l'opération de l'application ; une première exécution en avait laissé une, supprimée après preuve par le registre.

## 11. Captures

`docs/bmad/ux2-777/` : `file-accountless.jpg`, `file-actions-menu.jpg`, `add-dispatcher.jpg`, `add-no-workforce.jpg`, `file-no-workforce.jpg`, `list-<profil>.jpg`, `file-<profil>.jpg`, `mobile-file.jpg`, `mobile-actions.jpg`, `mobile-add.jpg`. Tablette et mode sombre : captures de la recette `m7-lot2` (liste et fiche, desktop/mobile, clair/sombre) ; pas de capture tablette dédiée à ce lot.

## 12. Défauts résiduels

1. L'écran historique `/company/workforce/employees` reste accessible par « Outils » (import, export, actions groupées) : la parité n'est pas reprise dans la liste unique.
2. « Ajouter un document » et « Demander un document » ouvrent la section Documents ; ils ne déclenchent pas encore directement le dépôt ou la demande.
3. La ligne de document reste dense (actions multiples) : traitée en UX3.
4. « Faire signer » ouvre la page Signatures sans préremplir le document.
5. Inviter un compte hors personnel coûte un clic de plus quand Workforce est actif.
6. Les libellés d'historique sont les codes d'action bruts du journal.
7. « Informations personnelles » affiche toujours l'ensemble des champs configurés (30 pour certains rôles) : pas de repli par section.
8. « Expéditions à affecter » : pas de filtre de liste.
9. Recettes `m7-lot3` et `m7-lot4` : sélecteurs portés, non rejouées dans ce lot.
10. Résiduels UX0/UX1 inchangés.

## 13. Conditions pour UX3 (Documents)

- Décider du retrait de `members.invite` aux rôles Driver existants (section 9).
- Valider les libellés des rubriques : « Informations & emploi », « Documents & contrats », « Temps & absences », et des actions.
- Confirmer que l'historique reste replié dans la Vue d'ensemble.
- UX3 reprendra : la ligne de document (statut unique, menu d'actions), le dépôt et la demande déclenchés directement depuis la fiche, « Faire signer » depuis le contrat, les quatre vues de l'espace Documents.
