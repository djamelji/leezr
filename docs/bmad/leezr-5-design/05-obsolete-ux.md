# Leezr 5.0 — 05 · Interfaces obsolètes, redondantes ou mal placées

> **Rien n'est supprimé pendant cette mission.** Chaque ligne donne une décision proposée, son impact métier, son impact technique et la preuve (fichier, ADR ou capture).
>
> Décisions possibles : **Conserver / Améliorer / Déplacer / Fusionner / Déprécier / Ajouter**.

## 1. Gouvernance des champs et documents — une règle, un endroit

**Constat** : le même sujet, « quelles informations demande-t-on à une personne ? », se règle à **4 endroits côté Company** :

| # | Lieu | Ce qu'on y fait | Origine |
|---|---|---|---|
| a | Personnes › **Outils › Champs du profil** (tiroir) | activer, **rendre obligatoire pour toute la société** (`required_override`), créer un champ | ADR-802, extrait de l'ancienne liste membres (ADR-164/168) |
| b | Réglages › **Catalogues** › Champs / Documents | activer, créer ; « rien ne devient obligatoire ici » (champs) ; mais **« obligatoire pour tous » encore réglable sur les documents** (`required_for_everyone` → `required_override`) | ADR-819 |
| c | Workforce › **Informations & documents demandés** | exiger par poste (ou tous les postes) | ADR-792 |
| d | Organisation › Postes › [poste] | même éditeur que (c), pour un poste | ADR-817 |

Côté serveur, l'obligation provient de **deux sources concurrentes** : `required_override`, lu par `FieldValidationService:85` et par `CompanyRequirementResolver:46` (« activation historique »), et les exigences par poste.

| Décision | Élément | Impact métier | Impact technique |
|---|---|---|---|
| **Déprécier** | tiroir « Outils › Champs du profil » (a) | une seule règle compréhensible : on exige **par poste** | remplacer l'entrée par un lien vers Catalogues ; supprimer le composant seulement après migration |
| **Fusionner** | `required_override` (champs **et** documents) → exigence « tous les postes » (c) | même résultat pour la société, sans double source | migration de données réversible : les `true` existants deviennent des exigences « tous les postes » ; le résolveur garde un repli pendant une version |
| **Conserver** | Catalogues (b) pour activer et créer | « ce qu'on **peut** collecter » | — |
| **Améliorer** | Catalogues › Documents : retirer « obligatoire pour tous », pointer vers les exigences | cohérence avec les champs | interface seulement |
| **Conserver** | exigences par poste (c, d) | source unique de l'obligation | — |

## 2. Doublons d'interface

| Décision | Élément | Preuve | Justification |
|---|---|---|---|
| **Fusionner** | 3 laboratoires de design existants : `/company/dev-lab` (ADR-694), `/ui-lab` (« Premium Phase 0 », ADR-741), `/platform/presentation-lab` (ADR-748), auxquels s'ajoute le `design-lab` de cette mission | `devOnly` sur les quatre | trois efforts de design system **jamais généralisés** ; un seul laboratoire de référence (lot L0), sans quatrième |
| **Fusionner** | Entreprises : filtres rapides **et** listes déroulantes sur les mêmes critères | [capture](captures/current/shots/p-superadmin/platform_companies.jpg) | une seule barre de segments à compteurs (même modèle que Personnes) |
| **Fusionner** | Facturation Platform : bandeau d'explication et 3 bandeaux d'alerte empilés | [capture](captures/current/shots/p-superadmin/platform_billing_overview.jpg) | carte « À traiter » unique, triée par impact |
| **Fusionner** | Planning : 4 actions d'en-tête de même poids et 3 sélecteurs de vue | [capture](captures/current/shots/c1-owner/company_workforce_planning.jpg) | 1 action principale, « ⋯ », une seule barre de vue |
| **Fusionner** | Accueil : « À traiter » (liste) et « Actions » (4 boutons) | [capture](captures/current/shots/c1-owner/home.jpg) | une grille d'actions contextualisées avec compteurs |
| **Fusionner** | Messagerie Platform : 7 onglets de même niveau | annexe | boîte de réception et réglages en section secondaire |

## 3. Routes historiques et alias

| Décision | Route | Rôle actuel | Proposition |
|---|---|---|---|
| **Conserver** (redirection) | `/company/members`, `/company/plan`, `/company/people/hr`, `/company/roles` (autonome) | alias de compatibilité (ADR-376, 766, 802, 820) | mesurer leur usage pendant un mois (journal d'accès), puis **déprécier** ceux à zéro visite |
| **Améliorer** | `/platform/settings/theme`, `/platform/settings/maintenance` | le titre de page est un échantillon (« Heading 1 ») ou un texte anglais (« We'll be back soon! ») | vrai titre de page, et aperçu dans un cadre |

## 4. Actions mal placées

| Décision | Élément | Pourquoi | Où |
|---|---|---|---|
| **Déplacer** | « Mon prochain créneau » et « Mon pointage » en tête de l'accueil du **dirigeant** | il pilote la société ; son propre pointage est secondaire, voire inexistant | « Mon espace » ; sur l'accueil seulement pour les rôles qui pointent |
| **Déplacer** | « Désactiver » sur chaque carte module, au même niveau que « Configurer » | action rare et lourde (impact listé, ADR-805) | menu « ⋯ » de la carte, avec confirmation |
| **Déplacer** | personnaliseur de thème flottant (engrenage, réservé à `canCustomizeTheme`) | il chevauche titres et onglets sur chaque page Platform | Réglages › Thème et menu du profil |
| **Améliorer** | pastille « Configuré » grise (Modules) | le gris se lit « désactivé » | vert avec une coche (table sémantique, 06 §3) |
| **Améliorer** | fiche entreprise nommée par l'e-mail du propriétaire | données de test, mais **aucun garde-fou** à la création publique | refuser un nom de société égal à une adresse e-mail (règle d'inscription) |

## 5. Pages trop techniques pour leur public

| Décision | Élément | Proposition |
|---|---|---|
| **Améliorer** | colonne « slug » visible par défaut (Entreprises) | masquée par défaut, disponible dans les colonnes |
| **Améliorer** | Facturation › Contrôle (grand livre, analyse, gouvernance, revue tarifaire) au même niveau que le suivi quotidien | groupe « Contrôler », replié par défaut, réservé à `manage_billing` |
| **Conserver** | Opérations (IA, temps réel, sécurité, automatisations) | public technique légitime ; devient la page de détail du panneau en direct |

## 6. Fonctionnalités à ajouter (valeur démontrée)

| Décision | Élément | Valeur | Lot |
|---|---|---|---|
| **Ajouter** | « Restaurer la disposition par défaut » (dashboards) | absent aujourd'hui ; débloque l'utilisateur qui a cassé sa disposition | L4 |
| **Ajouter** | consultation rapide d'une personne (tiroir) | vérifier sans quitter la liste | L5 |
| **Ajouter** | « prochaine action » par personne (inviter, relancer, compléter) | le travail RH devient une file, pas une recherche | L5 |
| **Ajouter** | vue organigramme (services et postes existants) | lisibilité de la structure, sans nouvelle donnée | L7 |
| **Ajouter** | historique de santé Platform | courbes du panneau en direct | L2 |
| **Ajouter** | rapport mensuel Platform | partage avec les associés | L8 |
| **Ajouter** (module) | scheduling, tournées | voir 04 §5 | L8 |
