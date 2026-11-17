# ADR-815.2 — 00. Évaluation exécutive

## Verdict

**L'espace Company fonctionne, mais il n'est pas conçu comme un produit.**
- **Ce qui marche** : les briques métier fonctionnent. Les parcours rejoués sur une société neuve aboutissent pour l'essentiel : inscription, activation, contrat, planning, pointage, documents, expéditions, départ, retrait d'accès.
- **Ce qui ne va pas** : l'assemblage est incohérent.
  - Trois modèles se superposent (rôle d'accès, poste, champs personnalisés).
  - Les mêmes informations sont saisies plusieurs fois.
  - Chaque règle se configure dans plusieurs écrans.
  - La gestion et l'espace personnel sont mélangés.
  - Il existe deux accueils.
  - Quatre parcours se terminent dans une impasse.

**Recommandation** : refondre Company **par résultats utilisateurs**, en réutilisant le backend (aucun nouveau moteur, aucune migration de schéma). La refonte doit être précédée d'un **lot de sécurité immédiat** (L0) et du **modèle Personne / Poste / Rôle d'accès** (L1), dont dépend tout le reste.

## Ce qui existe (périmètre audité)

- **Modules Company** : 26. Core 15, Ressources humaines 7, Logistique 4, dont 3 sans aucune interface.
- **Pages routées** : 57 hors Platform. 45 pages fonctionnelles et 12 redirections : 9 alias simples, plus `/company/roles`, `members/:id` et `employees/:id`, qui redirigent mais gardent une ancienne interface.
- **Composants de dialogue nommés** : 18, plus 119 occurrences de balises de dialogue ou de tiroir réparties dans les pages (un composant partagé est compté pour chaque page qui l'utilise).
- **Routes API** : 473 portant le contexte société.
- **Parcours** : 26 demandés. 23 rejoués au navigateur sur un clone isolé, 1 partiel, 2 non rejoués (raisons données).

## Ce qui ne fonctionne pas (confirmé)

| Gravité | Constat | Preuve |
|---|---|---|
| **P0** | Un salarié reçoit des indicateurs de **toute l'entreprise** (présents, anomalies, congés des autres) | HTTP, ADR-815 |
| **P1** | Un **oubli de sortie** ne peut pas être corrigé (refus serveur, message anglais brut) | Parcours 14 |
| **P1** | Un salarié au contrat actif **ne peut pas demander de congé** si le module Congés a été activé après le contrat (message trompeur « activez votre contrat ») | Parcours 15 |
| **P1** | Une personne ré-invitée qui se connecte avant d'accepter voit des **écrans vides et des 400**, sans être guidée | Parcours 26c |
| **P1** | Le chauffeur lit l'annuaire complet (e-mails de connexion, rôles) ; le rôle « RH » ne peut ouvrir aucune fiche | HTTP, ADR-815 |
| **P1** | Embaucher = deux créations de salarié différentes ; le poste, le contrat et l'accès sont dispersés | Parcours 4, 5, 6, 9, 22 |
| **P2** | Fiche personne : **3 formulaires et 3 boutons d'enregistrement** dans un onglet ; poste, date d'entrée, type de contrat et statut en double | Parcours 22 |
| **P2** | Un même module porte **jusqu'à 3 noms** ; modules non traduits dans l'éditeur de rôles ; dates en anglais | Parcours 3, 10, 25 |
| **P2** | « Continuer » désactivé sans signaler le champ obligatoire (dossier à distance) | Parcours 5 |
| **P2** | Un salarié **sorti** est proposé comme responsable | Parcours 24 |
| **P2** | Écritures de données lors de simples lectures (deux accueils, documents) | Code |

## Ce qui est compliqué ou mal conçu

- **Le rôle d'accès fait office de métier** : il décide de l'arrivée, des widgets, des pièces, des champs et de la conformité. Le poste ne sert qu'à l'intégration.
- **Les exigences ont trois autorités**. La page dédiée (« Informations & documents demandés ») se déclare elle-même « sans effet ».
- **La configuration est éparpillée**. Il y a trois assistants qui se recoupent ; les rôles sont dans Équipe, les types de pièces dans Documents, les types d'absence dans Absences.
- **La gestion se mélange au personnel**. « Mon activité » est un onglet du hub de gestion ; l'écran de gestion du temps est ouvert au salarié.
- **Le mot « onboarding » a quatre sens.**

## Ce qui manque aux parcours

- Une **finalisation d'embauche** (activer, contrat, accès en une étape).
- Un mode **« sur place »** pour le dossier d'intégration.
- La **clôture d'un pointage oublié**.
- L'**ouverture des soldes** à l'activation tardive d'un module.
- Un **écran de prérequis** avant la paie.
- Une **invitation proposée à la connexion**.

## Ce qui doit disparaître

- Le **second accueil** (`/dashboard` devient un alias).
- Les **doublons de champs** de la fiche.
- La **page d'exigences parallèle** (fusionnée dans la fiche Poste).
- L'**assistant RH** (fusionné dans l'assistant du module).
- Les **modules non livrés** du catalogue.
- Les **laboratoires** de la production.
- Le **code résiduel** des anciennes fiches.

## Ce qui doit être conservé (et pourquoi)

- **Mon espace** : modèle de clarté.
- **Le moteur de planning et ses vues.**
- **Le portail recrue sécurisé.**
- **La revue de dossier.**
- **La file « À traiter » des documents.**
- **Le hub Réglages et sa recherche.**
- **Le dialogue d'activation avec devis et la désactivation avec impact.**
- **La fin de collaboration et le retrait d'accès**, qui conservent le dossier.
- **Le bandeau d'honnêteté de la paie.**

Toutes ces pièces ont été rejouées ou observées avec succès.

## Le plan (détail dans `08`)

| Ordre | Lot | Catégorie | Complexité |
|---|---|---|---|
| L0 | Sécurité immédiate | SÉC | S |
| L1 | Personne / Poste / Rôle d'accès | BLQ | L |
| L2 | Embaucher, sur place ou à distance | BLQ | L |
| L3 | Une seule chaîne documentaire | BLQ | M |
| L4 | Fiche et Équipe refondues | ERG | M |
| L5 | Un seul accueil | ERG | M |
| L6 | Travail : gestion et personnel séparés, plus déblocages pointage et congés | BLQ + ERG | M |
| L7 | Une seule porte de configuration | ERG | M |
| L8 | Comptes sans impasse | BLQ | S |
| L9 | Cohérence (noms, états, traductions, mobile) | ERG | M |
| L10 | Rationalisation technique | TEC | M |

**Ce qui est préservé** : les données (aucune suppression, aucune migration de schéma), les permissions existantes (on ne fait que restreindre là où un P0 ou P1 le justifie), les alias de toutes les anciennes URL, et l'invariant « pas de nouveau 403 ».
