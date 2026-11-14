# Company Experience 2.0 — UX0 : navigation par intention et fondations produit (ADR-775)

**Date** : 2026-11-14 · **Base** : `33d432f0` · **Suite de** : `COMPANY-EXPERIENCE-2.0-AUDIT.md` (ADR-774) · **Preuves** : `docs/bmad/ux0-775/`

## 1. Architecture de navigation implémentée

Le menu Company nomme des **intentions**. Chaque intention est un **espace** ; les modules y contribuent par leur manifeste ; le serveur compose.

| Ordre | Espace (`group`) | Libellé | Contenu actuel | Présent si |
|---|---|---|---|---|
| 1 | `home` | Accueil | tableau de bord | toujours |
| 2 | `team` | Équipe | Collaborateurs (onglet Rôles inclus) | `members.view`, `workforce.view` ou `roles.view` |
| 3 | `time` | Planning & temps | Planning · Pointage · Congés & absences | au moins une capacité Workforce correspondante |
| 4 | `documents` | Documents | espace documentaire transversal | une permission documentaire |
| 5 | `payroll` | Paie | Paie & déclarations | `workforce.payroll_prepare` |
| 6 | `activity` | Activité | modules métier assemblés par le jobdomain (aujourd'hui : Expéditions, Mes livraisons) | un module métier actif et autorisé |
| 7 | `settings` | Réglages | Entreprise · Organisation · Ressources humaines · Automatisations · Modules · Abonnement & factures · Journal d'audit | au moins une section autorisée |
| — | `personal` | Mon espace | Mon espace · Mon activité · Mon compte | toujours (placement variable) |

Règles de présentation (une seule implémentation, `NavBuilder::composeSpaces`, règles dans `NavGroupRegistry::COMPANY_SPACES`) :

- **Un espace = une entrée principale.** Un seul item visible : l'item est l'entrée. Plusieurs : une entrée au nom de l'espace, les items en sous-entrées (un seul niveau).
- **Jamais de section vide** : un espace sans item autorisé n'existe pas.
- **Plus d'intertitres** de section : l'ordre des intentions suffit.
- **Réglages avec une seule section autorisée** : une entrée directe « Réglages », sans sous-menu.
- **Espace personnel** : entrées visibles pour une personne sans entrée de gestion (Équipe, Documents, Paie, Réglages) ; menu de l'avatar pour les autres. « Mon compte » est toujours dans le menu de l'avatar.
- **Faits personnels** : une entrée peut exiger un fait sur la personne (`requires`). « Mon activité » exige une fiche salarié active ; la source est celle de « Mon espace » (`PersonalSpaceReadModel`).

Ce qui reste l'autorité, inchangé : `ModuleManifest` (chaque module choisit son espace et déclare ses entrées), `NavBuilder` (filtres module, plan, jobdomain, permissions, niveau de rôle), `NavGroupRegistry` (ordre et présentation), RBAC, contexte d'entreprise. Aucun rôle, aucun jobdomain n'est nommé dans la composition (test dédié). Un module métier de surface `operations` rejoint « Activité » sans rien déclarer. Le menu n'est pas décrit dans Vue : le frontend affiche ce que l'API sert.

## 2. Matrice ancien menu → nouveau menu

| Ancienne entrée (groupe) | Nouvelle place | Route |
|---|---|---|
| Accueil (Entreprise, 11ᵉ) | **Accueil** (1ʳᵉ) | inchangée |
| Collaborateurs (Entreprise) | **Équipe** | inchangée |
| Collaborateurs (RH, profils opérationnels) | **Équipe** | inchangée |
| Planning (RH) | Planning & temps → Planning | inchangée |
| Temps de travail (RH) | Planning & temps → Pointage | inchangée |
| Congés & absences (RH) | Planning & temps → Congés & absences | inchangée |
| Documents (Entreprise) | **Documents** | inchangée |
| Paie & déclarations (RH) | **Paie** | inchangée |
| Expéditions / Mes livraisons (Opérations) | **Activité** (entrée directe tant qu'il n'y a qu'un item) | inchangée |
| Profil entreprise (Entreprise) | Réglages → Entreprise | inchangée |
| Organisation (RH) | Réglages → Organisation | inchangée |
| Paramètres (RH) | Réglages → Ressources humaines | inchangée |
| Workflows (Entreprise) | Réglages → Automatisations | inchangée |
| Modules (Entreprise) | Réglages → Modules | inchangée |
| Facturation (Entreprise) | Réglages → Abonnement & factures | inchangée |
| Journal d'audit (Entreprise) | Réglages → Journal d'audit | inchangée |
| Mon espace (Compte) | menu de l'avatar (gestionnaires) ou entrée visible (salariés) | inchangée |
| Mon activité (Compte) | idem, si fiche salarié active | inchangée |
| Paramètres du compte (Compte) | menu de l'avatar → Mon compte | inchangée |

Aucune route n'a été modifiée ni supprimée. Les redirections historiques sont vérifiées en navigateur : `/company/roles` → Équipe (onglet Rôles), `/company/plan` → abonnement, `/company/workforce` → accueil, `/company/workforce/documents` → Documents RH, `/company/workforce/configuration` → Paramètres RH.

## 3. Entrées avant / après par profil

Mesuré dans le navigateur sur l'entreprise DEV n° 1 (`result-*.json`). « Avant » = entrées du menu latéral à `33d432f0` ; « Après » = entrées principales du menu latéral.

| Profil | Avant | Après | Espaces affichés | Menu de l'avatar |
|---|---|---|---|---|
| Propriétaire (aussi salarié) | 18 | **7** | Accueil, Équipe, Planning & temps, Documents, Paie, Expéditions, Réglages | Mon espace, Mon activité, Mon compte |
| Dispatcher (aussi salarié) | 8 | **5** | Accueil, Équipe, Documents, Expéditions, Réglages | Mon espace, Mon activité, Mon compte |
| RH sans paie | 11 | **5** | Accueil, Équipe, Planning & temps, Documents, Réglages | Mon espace, Mon compte |
| Manager d'équipe | 10 | **4** | Accueil, Équipe, Planning & temps, Réglages | Mon espace, Mon compte |
| Gestionnaire paie | 5 | **2** | Accueil, Paie | Mon espace, Mon compte |
| Consultation | 7 | **3** | Accueil, Équipe, Organisation | Mon espace, Mon compte |
| Driver | 5 | **4** | Accueil, Mes livraisons, Mon espace, Mon activité | Mon compte |
| Salarié | 5 | **4** | Accueil, Pointage, Mon espace, Mon activité | Mon compte |

Entreprise sans Workforce (« Trial Logistics », même propriétaire) : Accueil, Équipe, Documents, Expéditions, Réglages. Ni « Planning & temps » ni « Paie ».

## 4. Doublons supprimés

| Doublon | Traitement |
|---|---|
| Trois entrées personnelles + bouton d'accueil | une place unique par profil (menu latéral ou menu de l'avatar) |
| Section « Ressources humaines » calquée sur le module, à côté d'« Entreprise » | dissoute dans les intentions |
| Réglages répartis dans deux groupes et sept entrées de premier niveau | un espace « Réglages » |
| « Mon activité » proposée à des gestionnaires sans fiche salarié (appels en 404) | entrée conditionnée à la fiche |
| « Paramètres RH » proposée à un profil en lecture seule (page refusée) | entrée alignée sur la garde de la page |
| Raccourcis d'accueil codés en dur, en double du menu (Profil entreprise, Documents, Facturation) | remplacés par les actions contribuées par les modules |

Non traité ici (périmètre UX2) : les deux écrans « Collaborateurs » (`/company/people` et `/company/workforce/employees`). Le second n'a jamais été une entrée de menu ; il reste accessible par « Outils » tant que la parité n'est pas démontrée.

## 5. Fonctions préservées

- Toutes les pages précédemment au menu restent atteignables : 18 feuilles pour le propriétaire, ouvertes une à une dans le navigateur sans refus.
- Les deux retraits d'entrée concernent des entrées qui échouaient déjà : « Paramètres RH » pour un profil opérationnel (page 403) et « Mon activité » sans fiche salarié (API 404).
- Un administrateur aussi salarié garde tous ses parcours personnels : Mon espace, Mon activité, Mon compte dans le menu de l'avatar ; le bouton « Mon espace salarié » de l'accueil (ADR-751b) est conservé.
- Notifications, support, centre d'aide, sélecteur de thème : inchangés.
- Aucune permission ajoutée ni élargie. Aucune garde de route ou d'API modifiée.

## 6. Convention d'actions contextuelles

Un module déclare les actions courantes qu'il rend possibles dans son manifeste (`Capabilities::$actions`). Un hôte demande les actions de **son** espace et de **son** contexte. Il n'existe pas de bouton global.

| Champ | Rôle |
|---|---|
| `key` | `<espace>.<verbe>` ; libellé i18n `actions.<key>` |
| `space` | espace d'intention |
| `to` | destination ; `null` si l'hôte ouvre l'action en place |
| `context` | `space` (défaut) ou un sujet, par exemple `person` |
| `permission`, `anyPermission`, `excludePermission` | mêmes sémantiques que les entrées de menu |
| `home` | proposée sur l'accueil |
| `primary` | action principale de l'espace |

Le serveur (`ActionBuilder`) ne renvoie que les actions d'un module actif que l'utilisateur peut réellement faire. Côté interface : `useSpaceActions('team')`, `useSpaceActions('team', { context: 'person' })`, `useHomeActions()`.

Actions déclarées :

| Action | Module | Permission | Accueil |
|---|---|---|---|
| `team.add-person` — Ajouter une personne | Membres | `members.invite` | oui |
| `team.add-employee` — Ajouter une personne (variante fiche) | Workforce | `workforce.manage`, masquée si `members.invite` | oui |
| `team.generate-contract` — Générer un contrat (contexte personne) | Contrats | `workforce.contracts` | non |
| `time.plan` — Préparer le planning | Planning | `workforce.planning_manage` | oui |
| `documents.request` — Demander un document | Documents | `documents.manage` | oui |
| `documents.deposit` — Déposer un document | Documents | `documents.manage` | oui |

Seul l'accueil consomme ces actions en UX0. Les en-têtes d'espace et la fiche les brancheront en UX1 à UX3 ; les parcours eux-mêmes ne sont pas refaits ici.

## 7. Corrections UX0

| Défaut | Origine | Correction |
|---|---|---|
| Raccourci « Facturation » → 403 pour un Dispatcher | bouton affiché selon le niveau de rôle (`isAdministrative`), pas selon `billing.manage` | raccourcis codés en dur supprimés ; l'accueil n'affiche que des actions filtrées par permission côté serveur |
| Libellés anglais (fiche, profil entreprise, champs dynamiques) | l'en-tête `X-Locale` n'était jamais envoyé : le client lisait un cookie `language` qui n'existe pas sous ce nom, le serveur retombait sur l'anglais | l'API reçoit la langue active de l'interface |
| « (active) », « / monthly » sur la fiche | valeurs techniques affichées brutes | traduites |
| « Ajouter Un Employé », « Vue D'ensemble » | mise en capitales initiales du thème | casse d'origine conservée (feuille applicative, hors `@core`) |
| Erreur JS à chaque ouverture de Documents | type de squelette inexistant (`tabs`) | type valide |
| Menu de l'ancienne entreprise après un changement d'entreprise (jusqu'à 5 min) | cache de navigation non lié à l'entreprise | cache vidé au changement |
| Widgets d'en-tête et liens de pied absents après rechargement | le cache ne restaurait que les groupes | la réponse complète est mise en cache et restaurée |
| « Pointage » : appels refusés (403) pour un profil sans vue d'ensemble | la page appelait la liste entreprise sans la capacité « voir tout » | appels conditionnés aux mêmes capacités que le serveur |
| Deux pages intitulées « Mon espace » | titre de « Mon activité » | titre corrigé |

## 8. Direction visuelle posée

- Menu compact : sept entrées au plus, un seul niveau de sous-entrées, aucun intertitre.
- Une icône par intention, définie avec l'espace, stable quel que soit le contenu.
- Libellés en casse naturelle, verbes à l'infinitif pour les actions (« Ajouter une personne »).
- État actif : entrée surlignée ; l'espace parent s'ouvre sur la page courante.
- Mobile : même menu en tiroir ; clair et sombre vérifiés.
- Pas de carte décorative ni d'animation ajoutée. Les actions d'accueil utilisent une seule couleur.

Les règles d'en-tête de page, de tableaux, de formulaires et d'états vides restent celles de l'audit (section 8) ; elles s'appliqueront page par page à partir d'UX1.

## 9. Tests

| Niveau | Contenu | Résultat |
|---|---|---|
| Invariants | `Ux0IntentNavigationTest` (8 tests) : ordre validé, une entrée par intention, jamais de section vide, aucune entrée ajoutée ni perdue, espaces conditionnés aux capacités, placement personnel, faits personnels, Activité sans jobdomain en dur, actions filtrées, libellés dans les deux langues, point d'entrée HTTP | vert |
| Navigation existante | 23 fichiers de tests de navigation et de sécurité, adaptés à la lecture par feuilles (mêmes exigences de visibilité par profil) | vert |
| Navigateur | `ux0-intent-navigation.spec.js` : 8 profils réels + changement d'entreprise. Pour chaque profil : ordre, menu affiché = menu servi, chaque entrée ouverte, actions d'accueil cliquées, menu de l'avatar, anciens liens, aucun refus réseau ni erreur JS | 9 / 9 |
| Comparaison | scripts ADR-774 rejoués après changement : 29 surfaces, redirections identiques, erreur JS de Documents disparue | fait |
| Formats | mobile 390 px (menu en tiroir, sous-menu Réglages), mode sombre | captures |

Non couvert : tablette (non rejouée pour UX0), second jobdomain réel (un seul jobdomain porte des entreprises en DEV ; la règle est prouvée par test avec un jobdomain de test).

## 10. Captures

Dans `docs/bmad/ux0-775/` : `after-<profil>-desktop.jpg` et `after-<profil>-avatar.jpg` pour les 8 profils, `after-owner-no-workforce.jpg`, `j-owner-03-time-open.jpg`, `j-driver-01-home.jpg`, `j-mobile-03-menu-settings.jpg`, `j-dark-02-settings-open.jpg`. État antérieur : `docs/bmad/ux-774/11-owner-dashboard.jpg`.

## 11. Défauts résiduels

1. Offres d'abonnement en anglais (`Up to 5 members`…) : libellés du catalogue de plans, non modifiés (contrainte « aucune modification Billing »).
2. Clé de secteur brute (« logistique ») dans Paramètres RH.
3. Quatre pages sans titre (Expéditions, Automatisations, Journal d'audit, Support) : à traiter avec l'en-tête commun.
4. L'entrée « Accueil » n'est pas surlignée sur `/home` (profils opérationnels).
5. Les deux écrans « Collaborateurs » et les deux formulaires d'ajout subsistent (UX2).
6. « Pointage » reste proposé à un salarié qui n'a que ses propres feuilles ; la page ne produit plus de refus mais son contenu de supervision est vide pour lui. À reprendre en UX4.
7. Le même défaut d'en-tête de langue existe côté Platform (`platformApi.js`), hors périmètre.
8. Une ressource en 404 à la connexion (préexistante).
9. Le rôle Driver de l'entreprise DEV détient `members.invite` : l'action « Ajouter une personne » lui est donc proposée. C'est la configuration du rôle, pas la navigation.

## 12. Conditions pour UX1

- Valider les libellés livrés : « Équipe », « Planning & temps », « Réglages », « Mon compte », « Pointage », « Automatisations », « Abonnement & factures ».
- Décider du sort du bouton « Mon espace salarié » de l'accueil maintenant que le menu de l'avatar porte les parcours personnels.
- Décider si l'accueil garde le catalogue de widgets en premier niveau ou derrière « Personnaliser » (audit, section 6).
- UX1 consomme la convention d'actions : le bloc « Actions » de l'accueil existe déjà sur cette base.
