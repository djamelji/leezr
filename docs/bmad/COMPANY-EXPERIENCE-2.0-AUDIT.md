# Company Experience 2.0 — Audit produit, UX et UI de l'espace Company (ADR-774)

**Date** : 2026-11-14 · **Base** : `09da42ab` (DEV local `leezr.test`) · **Nature** : audit en lecture seule. Aucun code applicatif modifié, aucune donnée créée.

## 0. Méthode et limites

| Élément | Ce qui a été fait |
|---|---|
| Inventaire | 34 surfaces Company visitées dans le navigateur (19 entrées de menu + 15 pages hors menu), compte propriétaire. Mesures par page : onglets, boutons, champs, cartes, puces, hauteur. Données : `ux-774/surfaces-owner.json`. |
| Parcours | 17 parcours ouverts dans l'interface réelle : chaque écran et chaque formulaire a été ouvert et mesuré. **Aucun formulaire n'a été soumis** (contrainte « aucune donnée »). Données : `ux-774/journeys-{a,b,c}.json`. |
| Profils | Propriétaire, Dispatcher (`admin@leezr.test`), Driver (`bob@leezr.test`). Données : `ux-774/surfaces-{dispatcher,driver}.json`, `journeys-dispatcher.json`. |
| Formats | Desktop 1440×900, tablette 820×1180, mobile 390×844, mode sombre : 11 pages clés chacun. |
| Outils | `tests/e2e/adr774-ux-audit-surfaces.spec.js`, `tests/e2e/adr774-ux-audit-journeys.spec.js` (rejouables, lecture seule). |

Limites à garder en tête :

- **Non exécuté** : parcours 1 (première connexion d'une Company : l'inscription crée une entreprise), soumission des formulaires, import réel de bulletins, signature de bout en bout, approbation d'un congé. Ces enchaînements sont couverts par les tests des lots M7 et ADR-771 à 773, pas par cet audit.
- **Contrôle visuel sur échantillon** : toutes les captures existent (149 fichiers dans `ux-774/`), une dizaine ont été examinées une à une. Tablette : seul le débordement horizontal a été vérifié automatiquement.
- **Aucune mesure de temps utilisateur** : les frictions sont décrites en nombre d'écrans, de clics et de champs, pas en secondes.
- **Chargement à froid** : au premier passage (rechargement complet de chaque page), 14 chargements sur 34 sont restés sur l'écran de démarrage plus de 12 s, avec `ERR_NETWORK_CHANGED` en console. En navigation interne, toutes les pages s'affichent. Cause probable : réseau local ; **à requalifier** hors de ce poste avant toute conclusion.
- Données DEV : l'entreprise auditée porte des données de test (noms `Thomas Bernard001407`, 60 documents manquants), ce qui grossit les compteurs sans fausser la structure.

## 1. Diagnostic général

Le socle fonctionnel est large et les garde-fous sont solides. L'expérience, elle, reflète l'histoire de la construction : chaque module a apporté ses pages, ses onglets et ses réglages, et l'utilisateur doit recomposer lui-même le métier.

Sept constats structurants :

1. **Le menu suit les modules, pas les intentions.** 18 entrées en 4 groupes pour le propriétaire. « Accueil » est la 11ᵉ entrée, sous « Entreprise », après tout le bloc RH. Cause : le groupe `company` est le groupe par défaut d'ordre 900 dans `NavGroupRegistry`. Capture : `ux-774/11-owner-dashboard.jpg`.
2. **Deux écrans « Collaborateurs » coexistent**, avec deux titres, deux barres d'actions et deux tableaux différents : `/company/people` (`12-owner-company_people.jpg`) et `/company/workforce/employees` (`25-owner-company_workforce_employees.jpg`), atteignable par « Outils → Import, export et actions groupées ».
3. **L'ajout d'une personne demande de choisir un concept technique** : « Inviter un utilisateur — crée un accès applicatif (compte + rôle) » ou « Créer une fiche salarié — fiche RH sans compte ». La distinction compte / fiche est exposée au lieu d'être déduite. Capture : `j-a-01-people-new.jpg`.
4. **Documents = 8 onglets, 11 avec les sous-onglets RH**, plus une page Signatures hors menu. Les cinq gestes attendus (déposer, demander, trouver, faire signer, voir ce qui manque) sont répartis sur six onglets. Capture : `15-owner-company_documents_overview.jpg`.
5. **La fiche collaborateur a 11 onglets** et ses deux actions principales (« Générer un contrat », « Gérer l'accès ») sont des boutons-icônes sans libellé. Sur mobile, les onglets deviennent des icônes seules. Captures : `j-dark-04-file-docs.jpg`, `j-mobile-04-file-docs.jpg`.
6. **Les réglages sont dispersés dans sept endroits** : Profil entreprise, Organisation, Paramètres RH, Documents → Paramètres (42 champs dont 37 interrupteurs), Modules, Champs personnalisés (`/company/members`), Rôles.
7. **L'espace personnel existe en quatre variantes** : « Mon espace », « Mon activité » (dont la page s'intitule aussi « Mon espace »), « Paramètres du compte », et le bouton « Mon espace salarié » de l'accueil.

Ce qui fonctionne et doit être conservé : le bloc « À traiter » de l'accueil (4 cartes actionnables), la liste unifiée des personnes avec état du compte, la liste d'intégration en tête de fiche, le pilotage de la navigation par manifestes et permissions, l'absence d'erreur HTTP sur les 17 parcours du propriétaire.

## 2. Inventaire des surfaces

Menu servi au propriétaire (`GET /api/nav`, ordre affiché) :

| Groupe | Entrées | Permission |
|---|---|---|
| Ressources humaines | Temps de travail, Planning, Congés & absences, Paie & déclarations, Organisation, Paramètres | `workforce.*` |
| Compte | Mon espace, Mon activité, Paramètres du compte | aucune |
| Opérations | Expéditions | `shipments.view` |
| Entreprise | Accueil, Collaborateurs, Workflows, Profil entreprise, Documents, Facturation, Journal d'audit, Modules | variable |
| Pied | Support, Centre d'aide | — |

Mesures par surface (propriétaire, desktop) :

| Surface | Route | Onglets | Boutons texte + icônes | Champs | Hauteur | Remarque |
|---|---|---|---|---|---|---|
| Accueil | `/dashboard` | 0 | 11 + 11 | 0 | 1977 px | 17 cartes, 21 puces, 2 graphiques |
| Collaborateurs | `/company/people` | 2 | 6 + 15 | 12 | 1050 | onglet Rôles intégré |
| Fiche | `/company/people/{ref}` | 11 (10 sans compte, 6 membre seul) | 21 | — | — | 2 actions en icônes |
| Employés (doublon) | `/company/workforce/employees` | 0 | 4 + 16 | 15 | 1018 | même titre « Collaborateurs » |
| Champs personnalisés | `/company/members` | 2 | 6 + 66 | 7 | 1520 | 68 puces |
| Documents | `/company/documents/overview` | 8 | 16 + 1 | 4 | 1924 | 11 cartes |
| Documents RH | `/company/documents/hr` | 8 + 3 | 18 + 32 | 21 | 1348 | 7 filtres |
| Signatures | `/company/workforce/signature` | 6 | 7 + 20 | 3 | 1215 | hors menu |
| Modèles de contrat | `/company/workforce/contract/templates` | 0 | 9 + 7 | 2 | 900 | hors menu |
| Temps de travail | `/company/workforce/time` | 3 | 7 + 3 | 13 | 900 | |
| Planning | `/company/workforce/planning` | 0 (4 modes) | 25 + 436 | 79 | 1315 | 57 puces, 3 alertes |
| Congés | `/company/workforce/leave` | 4 | 9 + 9 | 24 | 900 | |
| Paie | `/company/workforce/payroll` | 2 | 5 + 6 | 8 | 900 | |
| Organisation | `/company/workforce/organization/departments` | 3 | 11 + 12 | 15 | 1054 | |
| Paramètres RH | `/company/workforce/settings/general` | 4 | 6 | 2 | 900 | |
| Profil entreprise | `/company/profile/overview` | 1 | 3 | 20 | 2022 | un seul onglet affiché en onglet |
| Facturation | `/company/billing/overview` | 5 | 10 | 0 | 1226 | |
| Modules | `/company/modules` | 4 | 10 | 1 | 900 | 3 extensions « En préparation » |
| Journal d'audit | `/company/audit` | 0 | 0 + 49 | 2 | 1746 | pas de titre de page |
| Workflows | `/company/workflows` | 0 | 2 + 4 | 2 | 900 | pas de titre de page |
| Expéditions | `/company/shipments` | 0 | 2 + 4 | 3 | 900 | pas de titre de page |
| Mon espace | `/company/my-space/overview` | 4 | 4 | 0 | 900 | |
| Mon activité | `/company/workforce/me` | 0 | 10 + 5 | 6 | 1798 | titre « Mon espace » |
| Paramètres du compte | `/account-settings/account` | 3 | 6 | 26 | 1923 | |

Redirections constatées : `/company/roles` → `people?tab=roles`, `/company/plan` → `billing/plan`, `/company/workforce` → `/dashboard`, `/company/workforce/documents` → `documents/hr`, `/company/workforce/configuration` → `settings/worktime`, `/company/workforce/employees/{id}` → `people/employee-{id}`.

Profils limités : le Driver voit 5 entrées et atterrit sur `/home` ; le Dispatcher voit 9 entrées et 6 onglets Documents sur 8.

## 3. Audit des parcours réels

« Clics » = clics depuis l'accueil jusqu'au bouton de validation, non compris. « Champs » = champs visibles du formulaire ouvert.

| # | Parcours | Chemin observé | Écrans | Clics | Champs | Friction | Constats |
|---|---|---|---|---|---|---|---|
| 1 | Première connexion | — | — | — | — | — | **Non exécuté.** |
| 2-3 | Inviter un utilisateur | Collaborateurs → Nouveau → Inviter un utilisateur → tiroir | 1 | 3 | 4 (prénom, nom, e-mail, rôle) | Moyenne | Titre du tiroir « Ajouter un membre » ≠ libellé du menu. Choix compte/fiche imposé avant. |
| 4 | Créer un salarié sans compte | Collaborateurs → Nouveau → Créer une fiche salarié → dialogue | 1 | 3 | 6 (2 obligatoires) | Forte | Même besoin métier que 2-3, autre formulaire, autre composant (dialogue au lieu de tiroir). Donner un accès ensuite = second parcours (« Rattacher à un compte » / « Gérer l'accès »). Second point d'entrée identique dans `/workforce/employees`. |
| 5 | Modifier une fiche | Fiche → Modifier (2 boutons « Modifier » visibles) ou onglet Informations | 1 | 2 | 8 (Emploi) ; 30 (Informations) | Forte | Onglet Informations : 30 champs en une page, libellés en anglais (`Job Title`, `Date of Birth`, `Emergency Contact Name`…). Identité répartie entre « Informations » et « Emploi ». |
| 6 | Créer et générer un contrat | Fiche → onglet Contrat → formulaire ; génération par bouton-icône d'en-tête | 1 | 3 + | 7 | Forte | « Générer un contrat » n'a pas de libellé visible. Création du contrat et génération du document sont deux gestes en deux endroits. Génération non exécutée. |
| 7 | Envoyer à signer | Documents → Documents RH → Signatures → Nouvelle demande | 2 | 5 | assistant 4 étapes (Document, Signataires, Prestataire, Récapitulatif) | Forte | Page Signatures hors menu. Étape « Prestataire » exposée à l'utilisateur. Aucun « Faire signer » depuis le contrat lui-même. |
| 8 | Déposer un document pour un collaborateur | Fiche → onglet Documents → Téléverser sur la ligne | 1 | 3 | — | Forte | À 1440 px la ligne déborde : « Téléverser » est coupé (`j-c-01-file-docs.jpg`). Statuts empilés contradictoires (« Valide », « Soumis », « PNG », « Inactif »). « Créer un type personnalisé » proposé dans la fiche d'une personne. |
| 9 | Déposer un document de l'entreprise | Documents → Coffre-fort → Téléverser sur la ligne | 1 | 3 | — | Moyenne | L'accueil Documents envoie d'abord vers « Accéder au coffre-fort » : un clic de plus sans valeur. |
| 10 | Demander une pièce à plusieurs salariés | Documents → Demandes → Demander un document (périmètre + type) ; ou Campagnes (5 champs) | 1 | 3 | 2 ou 5 | Moyenne | Deux mécanismes (demande / campagne) pour une même intention, sans explication de la différence. |
| 11 | Trouver et télécharger un document | Coffre-fort, Documents RH, ou fiche | 1-2 | 3-4 | 7 filtres (RH) | Forte | Aucune recherche transversale : il faut savoir dans lequel des trois endroits se trouve la pièce. Filtres « Type de sujet », « Code du modèle » en vocabulaire technique. |
| 12 | Vérifier les documents manquants | Documents → Vue d'ensemble (« Voir 60 manquant(s) ») ou Conformité | 1 | 2 | — | Faible | Fonctionne. Le taux par rôle/type occupe l'écran ; la liste nominative est au second plan. |
| 13 | Importer des bulletins | Documents → Import bulletins → Importer un lot | 1 | 3 | — | — | Écran ouvert, **import non exécuté**. |
| 14 | Créer un planning | Planning → Nouveau shift (7 champs) ou Création en lot / Préparer ma semaine | 1 | 2 | 7 | Moyenne desktop, critique mobile | 25 boutons et 436 icônes sur l'écran. Mobile : en-tête qui déborde, titre écrasé, bouton coupé (`j-mobile-07-planning.jpg`). |
| 15 | Consulter les pointages | Temps de travail (Aujourd'hui / Présences / Feuilles de temps) | 1 | 1-2 | — | Faible | Lisible. |
| 16 | Approuver un congé | Accueil → carte « Congés à approuver » → Traiter | 2 | 2 | — | Faible | Chemin court. Approbation non exécutée. Formulaire de demande : champ « Durée (centièmes de jour) ». |
| 17 | Modifier les autorisations | Fiche → Accès & autorisations ; Collaborateurs → Rôles & autorisations | 1-2 | 3 | — | Moyenne | « Gérer l'accès » en icône seule. Deux lieux (fiche / rôles), cohérents entre eux. |
| 18 | Facture / abonnement | Facturation → Factures ; Abonnement | 1 | 2 | — | Moyenne | 5 onglets dont « Vue d'ensemble » et « Abonnement » qui se recouvrent. Offres en anglais (`Up to 5 members`). Alerte « Aucun profil sectoriel assigné. Contactez le support ». |

Erreurs relevées pendant les parcours :

| Gravité | Constat | Où |
|---|---|---|
| Critique | Action rapide « Facturation » de l'accueil → page 403 pour un Dispatcher (l'entrée de menu est, elle, correctement masquée). | `/dashboard`, `journeys-dispatcher.json` |
| Forte | Erreur JS `TypeError: Cannot read properties of undefined (reading 'includes')` dans `VSkeletonLoader` à chaque ouverture de Documents (propriétaire et Dispatcher). | `/company/documents/overview` |
| Moyenne | Passer de la fiche à `?tab=documents` par navigation interne n'a pas changé d'onglet (la Vue d'ensemble est restée affichée). Observé une fois, à confirmer. | `j-dark-04-file-docs.jpg` |
| Faible | Une ressource en 404 à la connexion, pour les trois profils. | connexion |

Aucune erreur HTTP 4xx/5xx sur les appels API des 17 parcours du propriétaire.

## 4. Matrice des frictions

| ID | Friction | Niveau | Fréquence d'usage | Lot |
|---|---|---|---|---|
| F01 | Action d'accueil menant à un 403 | Critique | Quotidienne | UX0 |
| F02 | Accueil en 11ᵉ position ; menu ordonné par module | Forte | Permanente | UX0 |
| F03 | Deux écrans Collaborateurs | Forte | Quotidienne | UX2 |
| F04 | Choix « utilisateur » ou « fiche salarié » à l'ajout | Forte | Hebdomadaire | UX2 |
| F05 | Fiche à 11 onglets, actions en icônes | Forte | Quotidienne | UX2 |
| F06 | Ligne de document qui déborde, statuts empilés | Forte | Quotidienne | UX3 |
| F07 | Documents : 8 à 11 onglets, pas de recherche transversale | Forte | Quotidienne | UX3 |
| F08 | Signature : hors menu, assistant 4 étapes, prestataire exposé | Forte | Hebdomadaire | UX3 |
| F09 | Planning inutilisable en l'état sur mobile | Critique sur mobile | Quotidienne | UX4 |
| F10 | Libellés anglais dans une interface française (fiche, profil entreprise, offres, `(active)`, `/ monthly`, `logistique`) | Forte | Permanente | UX0 |
| F11 | Vocabulaire technique : « Type de sujet », « Code du modèle », « Forcer le coffre-fort », « centièmes de jour », « taux horaire (centimes) », « Prestataire » | Moyenne | Variable | UX0 → UX4 |
| F12 | Réglages dispersés en sept endroits | Moyenne | Rare | UX5 |
| F13 | Quatre variantes de l'espace personnel | Moyenne | Quotidienne (salariés) | UX0 |
| F14 | Accueil long de 2,2 écrans, 17 cartes | Moyenne | Quotidienne | UX1 |
| F15 | Casse des boutons (« Ajouter Un Employé », « Vue D'ensemble ») | Faible | Permanente | UX0 |
| F16 | Pages sans titre (Expéditions, Workflows, Journal, Support) | Faible | Variable | UX0 |
| F17 | Erreur JS sur Documents | Forte (qualité) | Quotidienne | UX0 |
| F18 | Demande et campagne : deux mécanismes pour une intention | Moyenne | Mensuelle | UX3 |
| F19 | Informations : 30 champs sur un seul écran | Moyenne | Hebdomadaire | UX2 |
| F20 | Modules « En préparation » affichés en extensions | Faible | Rare | UX5 |

Note sur F06 : le bouton « Prolonger » ajouté par ADR-773 allonge encore cette ligne. Le défaut de structure lui préexiste, mais ce bouton doit passer dans un menu d'actions au lot UX3.

## 5. Architecture de navigation proposée

Principe : **le menu nomme des intentions ; les modules y apportent des capacités.** Un module activé enrichit un espace existant (un onglet, une action, une colonne) et n'ajoute une entrée que s'il apporte une activité métier autonome.

```
Accueil
Équipe                    (Collaborateurs ; toujours présent)
Planning & temps          (si Workforce : Planning · Pointage · Congés)
Documents                 (toujours ; contenu selon modules)
Paie                      (si permission paie)
── Activité ──            (groupe apporté par le jobdomain, libellé et entrées par manifeste)
   Expéditions …
── bas de menu ──
Réglages                  (un seul point d'entrée, sections selon permissions)
Aide

Menu de l'avatar : Mon espace · Mon compte · Thème · Déconnexion
```

| Aujourd'hui | Cible |
|---|---|
| 18 entrées, 4 groupes | 5 à 7 entrées + 1 groupe métier + Réglages |
| Temps, Planning, Congés : 3 entrées | 1 entrée « Planning & temps », 3 onglets |
| Organisation, Paramètres RH, Profil entreprise, Modules, Facturation, Journal, Workflows : 7 entrées | 1 entrée « Réglages », 7 sections |
| Mon espace, Mon activité, Paramètres du compte : 3 entrées | 1 « Mon espace » (menu de l'avatar ; entrée principale pour un salarié sans rôle de gestion) |

Mise en œuvre sans casser l'architecture :

- **`NavGroupRegistry`** : nouveaux groupes d'intention ordonnés (`home`, `team`, `time`, `documents`, `payroll`, `business`, `settings`) ; `company` cesse d'être le groupe par défaut d'ordre 900.
- **`ModuleManifest`** : chaque entrée déclare son groupe d'intention ; un attribut indique si la capacité est une entrée ou un onglet d'un espace hôte. Rien n'est codé par jobdomain : le groupe « Activité » est rempli par les manifestes des modules que le jobdomain assemble.
- **`NavBuilder`** : inchangé dans son rôle ; il continue d'élaguer par permission et capacité. Un espace n'apparaît que si au moins un de ses onglets est autorisé, et il s'ouvre sur le premier onglet autorisé.
- **Mobile** : barre basse à 4 destinations (Accueil, Équipe, Planning ou Documents selon modules, Mon espace) + « Plus » ouvrant le menu complet. Un salarié simple voit Accueil, Planning, Documents, Mon espace.
- Les routes actuelles restent en alias (règle M7 : rien n'est supprimé).

## 6. Organisation cible des pages principales

**Accueil** — trois blocs, dans cet ordre : « À traiter » (existant, conservé), « Actions » (4 actions calculées selon les permissions réelles : Ajouter un collaborateur, Demander un document, Préparer le planning, Déposer un document), « Aujourd'hui » (effectifs présents, absences, créneaux non couverts). Les graphiques et le catalogue de widgets passent derrière « Personnaliser ». Tient en un écran sur desktop.

**Équipe — liste** — un seul écran. Colonnes : Personne, Poste, Département, Accès, État. Import/export et actions groupées dans la même page (sélection multiple), plus de second écran. Un bouton « Ajouter un collaborateur ».

**Fiche** — en-tête : identité, état, action principale libellée, menu « Actions » (Générer un contrat, Faire signer, Demander un document, Gérer l'accès, Fin de collaboration). Cinq onglets au lieu de onze :

| Onglet cible | Regroupe |
|---|---|
| Résumé | Vue d'ensemble + liste d'intégration |
| Profil | Informations + Emploi (sections repliables) |
| Contrat & paie | Contrat + Bulletins de paie (selon permissions) |
| Temps | Planning + Temps & pointage + Congés (si Workforce) |
| Documents | Documents + documents générés + signatures |

Accès & autorisations devient une section du Résumé avec bouton « Gérer l'accès » ; Historique passe dans le menu « Actions ».

**Documents** — quatre vues au lieu de huit onglets :

| Vue cible | Contenu | Remplace |
|---|---|---|
| À traiter | pièces à vérifier, manquantes, expirant, signatures en attente | Vue d'ensemble, Demandes, Conformité |
| Entreprise | coffre de l'entreprise | Coffre-fort |
| Collaborateurs | pièces par personne, documents générés, bulletins | Documents RH, Documents générés |
| Signatures | suivi des demandes | page hors menu |

Bouton unique « Nouveau » : Déposer · Demander · Générer · Faire signer · Importer des bulletins. Recherche transversale en tête. Campagnes, modèles et types rejoignent Réglages → Documents.

**Planning & temps** — onglets Planning, Pointage, Congés ; vue « jour » par défaut sur mobile.

**Réglages** — sections : Entreprise, Organisation (départements, postes, lieux), Rôles & accès, Ressources humaines, Documents, Automatisations, Modules, Abonnement & factures, Journal.

**Mon espace** — une page : Aujourd'hui (pointage, planning), Mes congés, Mes documents, Mes bulletins, Mes signatures, Mon compte.

## 7. Simplification des formulaires

| Formulaire | Aujourd'hui | Cible |
|---|---|---|
| Ajouter un collaborateur | 2 formulaires (4 et 6 champs), choix technique préalable | 1 tiroir : prénom, nom, e-mail (facultatif), poste. Une question : « Cette personne se connectera-t-elle à Leezr ? » Oui → rôle + invitation. Non → fiche seule. Matricule et date d'embauche repliés. |
| Informations (fiche) | 30 champs, libellés anglais | Sections repliables (Identité, Coordonnées, Urgence, Administratif) ; seules les sections incomplètes sont ouvertes ; libellés traduits. |
| Contrat | 7 champs, puis génération ailleurs | Mêmes champs, préremplis depuis les paramètres RH (durée hebdomadaire, convention). Bouton final « Créer le contrat » puis proposition « Générer le document ». Aucun préremplissage de rémunération. |
| Demande de signature | Assistant 4 étapes | 1 écran : document (prérempli si lancé depuis un contrat), signataires proposés (salarié + représentant), envoyer. Le prestataire vient des réglages. |
| Générer un document | 4 champs dont « Forcer le coffre-fort » | Modèle + collaborateur ; l'option avancée est repliée et renommée. |
| Demander un document | Demande (2 champs) ou campagne (5 champs) | 1 formulaire : quoi, à qui (une personne, plusieurs, un rôle, tous), échéance. La campagne devient le cas « plusieurs ». |
| Nouvelle absence | 7 champs, « centièmes de jour » | Type, du, au ; demi-journée en option ; durée calculée et affichée en jours. |
| Poste | « Taux horaire par défaut (centimes) » | Saisie en euros. |
| Paramètres Documents | 42 champs sur une page | Regroupés par objectif (Pièces demandées, Rappels, Vérification automatique), avancé replié. |

Règles communes : champs obligatoires limités à ce qui termine l'action ; erreurs formulées en langage métier à côté du champ ; bouton principal qui nomme le résultat (« Envoyer l'invitation », pas « Ajouter »).

## 8. Proposition de design system

Tout se compose à partir du stock existant (`resources/ui/presets/`, composants Vuetify/Vuexy, enveloppes `App*`). Rien dans `@core/` ni `@layouts/`. Les compositions récurrentes deviennent des composants applicatifs réutilisables ; si un élément manque au stock, il est consigné dans `06-ui-policy.md` avant usage.

| Élément | Règle |
|---|---|
| En-tête de page | Titre, sous-titre d'une ligne, une action principale libellée, menu « Plus ». Présent sur toutes les pages (4 n'en ont pas). |
| Action principale | Une seule par écran, en haut à droite desktop, en bas sur mobile. Verbe + objet. |
| Actions secondaires | Menu « Actions » ; jamais plus de deux boutons visibles par ligne de tableau. |
| Boutons-icônes | Réservés aux actions universelles (fermer, télécharger). Jamais pour une action métier. |
| Recherche et filtres | Recherche toujours visible ; 2 filtres au plus en ligne, le reste dans « Filtres ». |
| Tableaux | `VDataTableServer` ; colonnes prioritaires définies par écran ; sous 600 px, bascule en liste de cartes (voir `docs/ui/smart-responsive-primitives.md`). |
| Création / édition | Tiroir pour un formulaire court, page pour un formulaire long, dialogue pour une confirmation. Aujourd'hui tiroir et dialogue sont utilisés indifféremment. |
| Statut | Une seule puce par ligne, vocabulaire fermé (À fournir, À vérifier, Valide, Expire bientôt, Expiré, Refusé). |
| Onglets | 5 au plus ; au-delà, regrouper. Libellés conservés sur mobile (défilement), pas d'icônes seules. |
| États vides | Une phrase et l'action qui remplit l'écran. |
| Retours | Notification pour un succès, message à côté du champ pour une erreur, jamais de page 403 dans un parcours proposé. |
| Typographie | Supprimer la mise en capitales initiales des boutons côté application (thème applicatif, hors `@core`). |
| Sobriété | Cartes colorées pleines, puces et graphiques réservés à l'information qui déclenche une action. |

Le mode sombre est correct sur les pages examinées ; aucune correction spécifique identifiée à ce stade.

## 9. Maquettes fonctionnelles des parcours prioritaires

Ajouter un collaborateur (tiroir) :

```
┌ Ajouter un collaborateur ──────────────────────┐
│ Prénom [__________]   Nom [__________]         │
│ Poste  [__________▾]                           │
│ E-mail [______________________] (facultatif)   │
│                                                │
│ Cette personne se connectera-t-elle à Leezr ?  │
│  (•) Oui — elle reçoit une invitation          │
│      Rôle [Salarié ▾]                          │
│  ( ) Non — fiche seule, accès possible plus tard│
│                                                │
│ ▸ Plus d'informations (matricule, embauche…)   │
│                    [Annuler] [Ajouter et inviter]│
└────────────────────────────────────────────────┘
→ ouverture de la fiche, liste d'intégration en tête
```

Fiche collaborateur :

```
← Équipe
┌──────────────────────────────────────────────────────────────┐
│ (AM) Alice Martin   Dispatcher · Exploitation   ● Actif      │
│      alice@…  ·  Accès : connecté     [Générer un contrat] [Actions ▾]│
└──────────────────────────────────────────────────────────────┘
 Résumé | Profil | Contrat & paie | Temps | Documents
┌ À faire (2) ───────────────┐ ┌ Contrat ─────────┐ ┌ Accès ────────┐
│ ○ Faire signer le contrat  │ │ CDI · 35 h       │ │ Dispatcher     │
│ ○ Programmer un créneau    │ │ depuis 01/01/2026│ │ [Gérer l'accès]│
└────────────────────────────┘ └──────────────────┘ └────────────────┘
```

Documents :

```
Documents                                   [Rechercher…]  [+ Nouveau ▾]
 À traiter (6) | Entreprise | Collaborateurs | Signatures
┌──────────────────────────────────────────────────────────────┐
│ À vérifier (3)   Carte d'identité — Alice Martin   [Ouvrir]  │
│ Manquants (2)    Permis — Bob Dupont               [Relancer]│
│ Expire bientôt   K-bis — entreprise · 10 j         [Renouveler ▾]│
└──────────────────────────────────────────────────────────────┘
```

Ligne de document (remplace la ligne qui déborde) :

```
Carte d'identité   Obligatoire   ● À vérifier   déposé le 12/11   [Ouvrir] [⋮]
                                                 ⋮ = Approuver · Refuser · Prolonger · Remplacer
```

Accueil :

```
Bonjour Djamel                                   Leezr Logistics
À traiter   [Congés 1] [Feuilles de temps 2] [Signatures 7] [Intégrations 14]
Actions     [Ajouter un collaborateur] [Demander un document] [Préparer le planning]
Aujourd'hui  Présents 0/52 · En congé 0 · Créneaux à couvrir 28        [Personnaliser]
```

## 10. Comparatif avant / après

Les valeurs « après » sont des cibles de conception, pas des mesures.

| Sujet | Avant (mesuré) | Après (cible) |
|---|---|---|
| Entrées de menu (propriétaire) | 18 | 7 à 8 |
| Position de l'accueil | 11ᵉ | 1ʳᵉ |
| Écrans « Collaborateurs » | 2 | 1 |
| Formulaires d'ajout d'une personne | 2 | 1 |
| Onglets de la fiche | 11 | 5 |
| Onglets Documents | 8 (11 avec sous-onglets) + 1 page hors menu | 4 |
| Étapes d'une demande de signature | 4 | 1 écran |
| Endroits où régler quelque chose | 7 | 1 |
| Entrées d'espace personnel | 4 | 1 |
| Actions d'accueil menant à un refus | 1 constatée | 0 |
| Hauteur de l'accueil (1440×900) | 1977 px | un écran |

## 11. Améliorations prioritaires

1. Supprimer toute action proposée qui mène à un refus (F01) : les actions de l'accueil suivent les permissions réelles.
2. Remettre l'accueil en tête et ordonner le menu par intention (F02).
3. Traduire les libellés anglais et retirer le vocabulaire technique des écrans courants (F10, F11).
4. Un seul parcours « Ajouter un collaborateur » (F03, F04).
5. Fiche à cinq onglets avec actions libellées (F05).
6. Ligne de document lisible, statut unique (F06).
7. Documents en quatre vues avec bouton « Nouveau » et recherche (F07, F08).
8. Planning utilisable sur mobile (F09).
9. Réglages regroupés (F12) et espace personnel unique (F13).
10. Corriger l'erreur JS de Documents (F17).

## 12. Roadmap UX par lots

| Lot | Contenu | Dépend de | Risque |
|---|---|---|---|
| **UX0 — Navigation et principes** | Ordre du menu par intention, accueil en tête, espace personnel unique, actions d'accueil selon permissions, en-tête de page commun, libellés (anglais, casse, vocabulaire), erreur JS Documents. Aucune page restructurée. | — | Faible |
| **UX1 — Accueil** | Blocs À traiter / Actions / Aujourd'hui ; widgets derrière « Personnaliser ». | UX0 | Faible |
| **UX2 — Équipe et fiche** | Liste unique, tiroir d'ajout unique, fiche à 5 onglets, menu Actions, Informations en sections. | UX0 | Moyen (RBAC par onglet) |
| **UX3 — Documents** | Quatre vues, « Nouveau », recherche, ligne de document, signature en un écran, demande unifiée. | UX0, UX2 pour la fiche | Moyen à fort (politiques d'accès) |
| **UX4 — Planning & temps** | Espace unique à 3 onglets, mobile, formulaires absence et créneau. | UX0 | Moyen (écran dense) |
| **UX5 — Réglages** | Point d'entrée unique, sections, Paramètres Documents regroupés, modules « en préparation » sortis des extensions. | UX0 | Faible |
| **UX6 — Recette** | Parcours complets avec écriture, 3 profils, 3 formats, 2 thèmes ; mesure avant/après avec les deux scripts d'audit. | tous | — |

Chaque lot est livrable et testable seul dans le navigateur.

## 13. Risques RBAC et modularité

| Risque | Parade |
|---|---|
| Regrouper des onglets masque une capacité autorisée par une permission métier | Un onglet regroupé s'affiche si **au moins une** de ses sections est autorisée ; test par permission et par onglet, comme l'invariant « pas de nouveau 403 » de M7. |
| Une action d'un menu « Actions » apparaît sans que l'appel soit autorisé | Les actions sont calculées côté serveur (capacités de la fiche), jamais côté interface seule. |
| Rémunération visible dans un résumé | Le Résumé n'affiche le salaire que sous `workforce.compensation_read` ; aujourd'hui la Vue d'ensemble l'affiche au propriétaire, à vérifier pour les autres profils au lot UX2. |
| Recherche transversale de documents contournant `DocumentAccessPolicy` | La recherche interroge les read-models existants, qui appliquent déjà la politique ; aucun nouvel accès direct aux tables. |
| Menu figé pour tous les secteurs | Groupes d'intention dans `NavGroupRegistry`, contenu par manifestes ; aucun jobdomain en dur ; entreprise sans Workforce : « Planning & temps » et « Paie » absents, Équipe et Documents fonctionnent. |
| Liens et favoris existants | Anciennes routes conservées en alias, comme en M7. |
| Self-service cassé par la fusion des espaces personnels | Fusion par composition des pages existantes ; tests des lots ADR-769 rejoués. |
| Préremplissages risqués | Aucun préremplissage de rémunération, de permission ou de signataire sans confirmation explicite. |

## 14. Critères de validation par lot

| Lot | Critères |
|---|---|
| UX0 | Accueil en première entrée pour les 3 profils ; aucune action d'accueil ne mène à un 403 ; 0 erreur JS sur les 34 surfaces ; 0 libellé anglais sur les écrans audités en français ; toutes les pages ont un titre ; anciennes routes toujours servies ; tests de navigation et `PageLayoutMetaTest` verts. |
| UX1 | Accueil sur un écran en 1440×900 ; chaque carte « À traiter » ouvre l'écran filtré ; les actions affichées sont toutes autorisées pour le profil. |
| UX2 | Un seul écran de liste ; ajout d'une personne avec ou sans compte par le même tiroir ; 5 onglets ; chaque action de l'en-tête est libellée ; aucun 403 nouveau par profil ; lisible à 390 px. |
| UX3 | Les cinq gestes (déposer, demander, trouver, faire signer, voir ce qui manque) partent de l'écran Documents ; aucune ligne ne déborde à 1440, 820 et 390 px ; un statut par ligne ; tests d'accès documentaires verts. |
| UX4 | Créer un créneau et poser une absence sur mobile sans défilement horizontal ; durées saisies en jours et en euros. |
| UX5 | Tout réglage atteint en deux clics depuis « Réglages » ; les sections non autorisées n'apparaissent pas. |
| UX6 | Les 18 parcours exécutés de bout en bout avec registre d'exécution ; tableau avant/après rempli par mesure. |

## 15. Recommandation du premier lot

**UX0 — Navigation et principes transversaux.**

- Il traite la seule friction critique sur desktop (F01) et celle que tout utilisateur rencontre à chaque connexion (F02).
- Il ne restructure aucune page : ordre des groupes, libellés, en-tête commun, calcul des actions d'accueil. Le risque de régression est faible et entièrement testable.
- Il pose les règles (en-tête, action principale, vocabulaire) que les lots UX2 à UX5 appliqueront, ce qui évite de les refaire.

Décisions attendues avant de démarrer :

1. Valider l'arborescence cible de la section 5, en particulier les libellés « Équipe », « Planning & temps », « Réglages ».
2. Confirmer que l'espace personnel passe dans le menu de l'avatar pour les gestionnaires et reste une entrée principale pour les salariés.
3. Confirmer l'ordre des lots, notamment UX2 avant UX3.
