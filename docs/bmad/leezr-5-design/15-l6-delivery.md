# Leezr 5.0 — 15 · Livraison L6 · Dossiers collaborateurs et interfaces redondantes (ADR-859)

Livré en local sur `dev`, sans push ni déploiement. Aucun nouveau moteur de champs, aucune modification générale du RBAC, aucune migration, aucune écriture sur les sociétés historiques. La recette tourne sur le clone marqué `leezr_audit842`.

## 1. Complétude des dossiers RH

**Avant (L5)** : la complétude n'était calculée que pour les personnes ayant un compte. Elle portait sur le formulaire de profil du compte, sans le poste ni les pièces. Une fiche RH sans compte n'avait ni jauge ni prochaine étape.

**Après** : `PersonFileCompleteness` évalue **chaque Employee visible**, avec ou sans compte. Aucun compte n'est jamais créé pour cela.

| Élément | Source réutilisée |
|---|---|
| Champs exigés | `FieldObligationSources`, c'est-à-dire les entrées que le moteur de formulaires applique déjà : catalogue Leezr, modules actifs, métier en autorité « rôle », obligation de toute la société, calque du rôle d'accès si un compte est lié (un champ masqué pour ce rôle n'est pas compté) |
| Exigences du poste | registre (`EffectiveRequirementResolver`) : tout le registre en autorité « poste » ; en autorité « rôle », uniquement les **décisions de la société** (source `company`), jamais les modèles Platform ni les propositions de modules |
| Pièces exigées | `DocumentResolverService::subjectMap`, comme les écrans Documents, plus les exigences de poste |
| Présence | `EmployeeFieldStore::presence` (fiche, compte lié, appartenance), coffre documentaire (fiche ou compte lié), preuves de module |
| Données déjà connues | date d'embauche, statut, type de contrat et intitulé de poste comptent comme renseignés quand la fiche RH les porte déjà (lecture seule) |

Quatre états sont distingués :
- **Disponible** : le champ est proposé, mais non exigé, et n'est pas compté.
- **Requis pour le dossier** : il est compté.
- **Indispensable à une étape** : une exigence d'étape `hire` est due dès l'embauche.
- **Manquant mais acceptable temporairement** : pendant l'intégration (recrue en préparation), un élément dû à une étape ultérieure est « à collecter ». Le dossier n'est pas marqué incomplet, et la prochaine étape reste « Finaliser l'intégration ».

Confidentialité :
- la complétude du dossier RH n'est servie qu'avec la facette RH (`workforce.view`), dans le périmètre RH de l'acteur (ADR-767) ;
- elle contient des libellés et des nombres, jamais une valeur ;
- un profil qui administre seulement les accès garde la complétude du compte, sans le dossier RH ;
- garde tenant : un Employee d'une autre société n'est jamais évalué.

Effet sur la page Personnes :
- la colonne « Dossier » couvre tous les collaborateurs visibles ;
- l'état « N à collecter » apparaît pendant l'intégration ;
- l'aperçu rapide nomme les éléments à renseigner ;
- la tuile « Dossiers incomplets » compte aussi les fiches sans compte.

## 2. Exigences des postes et intégration

Le parcours existant a été vérifié et laissé tel quel, car il est déjà cohérent :
- **Ajout rapide** : il n'impose aucune exigence. Le dossier incomplet est permis : test PHPUnit et recette (création acceptée, puis « Compléter le dossier »).
- **Embauche complète** : le portail refuse la soumission tant qu'un élément requis manque. Une révision après soumission renvoie en « modifications demandées ». La validation exige une décision sur chaque valeur et refuse une pièce non analysée. La finalisation exige un dossier validé. Une étape déclarée complète n'ignore donc pas ses exigences.
- **Après finalisation**, le dossier est évalué selon le poste attribué. Ce qui a été collecté pendant l'intégration (la nationalité exigée pour Livreur, par exemple) n'est plus manquant.

## 3. Règles historiques par rôle (analyse, aucune conversion)

`php artisan fields:rules-audit --roles` classe chaque règle d'après son usage réel. Le rapport du clone est dans `l6-role-rules-clone.json`.

| Classe | Signification | Constat sur le clone |
|---|---|---|
| `visibility` | `visible:false` : qui voit un champ (logique de permission logicielle) | majoritaire sur tous les rôles |
| `role_data` | obligatoire pour un rôle dont les membres occupent plusieurs postes, ou aucun | Dispatcher, Driver (société 1) ; tous les rôles de la société 101 |
| `position_candidate` | obligatoire pour un rôle dont tous les membres occupent un seul poste : peut-être un besoin de ce poste, **ambigu** | société 1 : « Propriétaire » (1 membre, « Responsable d'exploitation ») |
| `unused` | rôle sans membre | Manager, Operations Manager (société 1) ; 4 rôles (société 2) |

Rien n'est converti : une conversion en exigence de poste reste une décision explicite de la société (bascule d'autorité, ADR-818).

## 4. Interfaces redondantes (inventaire 05 et constats L5)

| Interface | Usage vérifié | Référence | Décision |
|---|---|---|---|
| Personnes › Outils › Champs | retirée en L5 | Company › Catalogue › Champs | **retirée** (L5) ; recette L6 : `/company/people` sans « Outils » |
| Deux réglages « tout le monde » : catalogue (`required_override`, appliqué par les formulaires) et éditeur d'exigences « Tous les collaborateurs » (registre : complétude, intégration, pièces) | les deux sont utilisés et n'ont pas le même effet | les deux | **conservés, rôle expliqué** dans l'éditeur, avec un lien vers le catalogue ; aucune donnée changée |
| Modules : « Désactiver » au niveau de « Configurer » (05 §4) | action rare et lourde (ADR-805) | menu « ⋯ » de la carte | **déplacé** : même dialogue de confirmation et d'impact ; `data-test` conservé |
| Modules : pastille « Configuré » grise (05 §4) | se lit comme « désactivé » | — | **corrigé** : verte avec une coche |
| `/company/members`, `/company/roles`, `/company/people?tab=hr` (05 §3) | alias de compatibilité | Personnes / Rôles / Gestion RH | **conservés**, redirections vérifiées par la recette |
| Laboratoires `/company/dev-lab`, `/ui-lab`, `/platform/presentation-lab` (05 §2) | bancs de test `devOnly`, utilisés par des E2E (redimensionnement, accessibilité) et liés depuis le laboratoire unique | `/platform/design-lab` | **conservés** : les rediriger casserait ces tests sans bénéfice pour l'utilisateur |

## 5. Recette navigateur (clone) : `audit842-files859-recipe`

| Vérification | Résultat |
|---|---|
| Collaborateur sans compte : jauge, « Compléter le dossier », éléments nommés dans l'aperçu | ✅ |
| Collaborateur avec compte : dossier évalué | ✅ |
| Dossier complet (société fictive 101, fixture `files859`) : « À jour » | ✅ |
| Exigences selon le poste : « Nationalité » pour Livreur, absente pour un autre poste | ✅ |
| Ajout rapide : dossier incomplet accepté | ✅ |
| Embauche complète, jusqu'à l'intégration finalisée : invitation, portail mobile, code de vérification, pièce analysée, validation, finalisation, puis dossier évalué selon le poste (la nationalité collectée n'est plus manquante) | ✅ |
| Règles historiques par rôle visibles dans le catalogue | ✅ |
| Droits RH limités, utilisateur limité : aucun chiffre RH hors facette, aucune valeur | ✅ |
| Isolation : la société 2 ne liste jamais les fiches de la société 1 | ✅ |
| Interfaces remplacées : menu Modules, encart de l'éditeur, anciennes adresses | ✅ |
| Mobile : liste et aperçu sans débordement | ✅ |

Le serveur de recette tourne avec `DOCUMENT_SCANNER=simulated` (pilote DEV et test, refusé en production) pour qu'une pièce propre sorte de la quarantaine. Le code n'est pas modifié.

Captures :
- `captures/l6/avant/modules-desktop.jpg` ;
- `captures/l6/apres/` : Personnes en desktop, mobile et sombre, société 101, Modules et son menu, exigences ;
- `captures/l6/recipe-*` : captures de la recette.

Personnes avant L6 : `captures/l5/apres/`.

## 6. Tests

- PHPUnit `Adr859PersonFileCompletenessTest` 6/6 :
  - fiche sans compte (complétude et prochaine étape, aucun compte créé) ;
  - données déjà connues non redemandées ;
  - exigence propre à un poste ;
  - ajout rapide incomplet accepté, puis « à collecter » pendant l'intégration et manquant une fois actif ;
  - lecture RH uniquement et garde tenant ;
  - règles par rôle classées, jamais converties.
- E2E `audit842-files859-recipe` (5 scénarios) ; `company-module-toggle` mis à jour (menu « ⋯ »).
- E2E `audit842-files859-recipe` 5/5, dont l'embauche complète jusqu'à la finalisation. Non-régression : `people858` 5/5, `workspace857` 10/10, `widgets856` 4/4, `cockpit855` 5/5.
- PHPUnit complet : **4920 réussis, 13 ignorés, 0 échec**. Trois tests mis à jour :
  - `M7Lot4PerformanceTest` : budget de la liste Personnes porté de 25 à 50 requêtes, car la complétude des dossiers ajoute un ensemble **fixe** de lectures groupées (45 requêtes, identique à 3 000 personnes, sans N+1) ;
  - `Adr858` : la facette RH lit désormais la complétude du dossier.
- JS 56/56 ; build OK.

## 7. Limites restantes

- **Module flotte** : il déclare permis et pièces obligatoires pour toutes les personnes (`required_by_modules`). En autorité « rôle », un employé de bureau sans compte les voit donc manquants. C'est la règle existante (le conflit `conflict_hidden` était déjà signalé en L5). La société la corrige par poste, ou Platform par le module.
- **Les deux réglages « tout le monde »** restent distincts : le formulaire n'applique que celui du catalogue. Leur fusion demande une décision produit (bascule d'autorité).
- **Règle ambiguë par rôle** (`position_candidate`) : signalée, à décider par la société.
- **Embauche complète en local** : sans antivirus, une pièce reste en quarantaine. La recette utilise le pilote simulé, et la limite d'envoi des codes (3 par tranche de 10 minutes) impose d'espacer les exécutions.
