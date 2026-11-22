# Leezr 5.0 — 17 · L7 · Reprise et fin de l'harmonisation (ADR-861)

Livré en local sur `dev`, sans push ni déploiement. La seule migration est additive (une colonne nullable), et aucune permission n'a changé. Ce document fait suite au [16](16-l7-delivery.md) (L7.1 à L7.5).

## 1. Sous-lots

| Sous-lot | Commit | Effet |
|---|---|---|
| L7.6 · Flotte selon l'activité réelle | `410ac4df` | le permis n'est demandé qu'aux personnes qui conduisent réellement (poste déclaré, livraisons, rôle en autorité « rôle ») ; réglage par société, désactivé partout sauf sur le clone |
| L7.7 · Familles Platform | `a486d351` | Exploitation, Messagerie, Catalogue et Accès sont regroupés et situés ; Entreprises et fiche entreprise sont recomposées ; JobDomain, Module, Plan, Utilisateur et Ticket ont un en-tête avec retour et pastilles |
| L7.8 · Familles Company temps et absences | `53695159` | Planning, Temps et Absences ont chacun leur titre ; Absences s'affiche en liste tactile sur téléphone ; filtres de pointage alignés ; fiche Poste |
| L7.9 · Accessibilité | `860bcba1` | 65 boutons-icônes nommés (il n'en reste aucun sans nom) ; tiroirs avec une fermeture nommée ; test sur toute l'application |
| L7.10 · Titres anciens | `c9765f92` | Feuille de temps, Période de paie, Expédition, DSN, Intégration, Mes livraisons, Communications, Modèles de contrat, Modèles d'organisation |
| L7.11 · Recette stabilisée | (voir §7) | cause démontrée : 429 sur les appels de démarrage ; correctif et non-régression |

## 2. Les neuf familles

| # | Famille | Statut | Ce qui change concrètement |
|---|---|---|---|
| 1 | Entreprises et fiches Company | **terminé** | Liste : action principale dans l'en-tête, bande de 5 indicateurs à la place de 5 grandes cartes, segments sur la table (au clavier). Fiche : nom et contexte en titre, 7 onglets sur toute la largeur sans flèches, bande de 4 indicateurs. |
| 2 | JobDomains | **terminé** | En-tête avec retour vers le Catalogue, pastilles d'état, titre d'onglet du navigateur par section. |
| 3 | Modules et dépendances | **terminé** | En-tête avec retour ; clé et pastilles sous le titre ; l'interrupteur global devient l'action de la page. |
| 4 | Utilisateurs et autorisations | **terminé** | Une famille « Accès Platform » avec une phrase par page ; fiche Utilisateur avec retour et pastilles de rôle et de statut ; tiroirs avec fermeture nommée. |
| 5 | Exploitation, support et messagerie | **terminé** | Exploitation : 7 onglets en 4 groupes (Supervision, Sécurité, Consommation, Automatisations). Messagerie : 7 onglets en 3 groupes (Réception, Contenus envoyés, Délivrabilité). Titres internes en double retirés ; fiche Ticket avec le sujet en titre. |
| 6 | Catalogue et configuration | **terminé** | 6 onglets en 3 groupes (Offre, Métiers, Données demandées) ; Modèles de contrat et Modèles d'organisation passent sur l'en-tête commun. |
| 7 | Fiche Plan | **terminé** | En-tête avec retour, phrase sur la migration tarifaire, boutons de tri nommés. |
| 8 | Workforce, planning et pointage | **terminé** | Chaque vue a son titre, sous le fil « Planning & temps » ; filtres alignés ; Feuille de temps avec en-tête, actions et bande des totaux ; fiche Poste avec les activités exercées. |
| 9 | Absences et congés | **terminé** | Navigation de sections identique à Temps ; liste tactile sur téléphone, avec actions nommées et les mêmes droits. |

Documents, Paie et Expéditions : la DSN, la Période de paie et la fiche Expédition passent sur l'en-tête commun. Le contenu des autres onglets de Documents n'a pas été retravaillé (voir §6).

## 3. Module Flotte : décision technique

- **Règle** : un champ exigé par un module pour une **activité** (étiquette `driving`) n'est exigé que des personnes qui l'exercent. Une personne exerce une activité dans l'un de ces cas :
  - son **poste** la déclare : fiche Poste › « Activités exercées dans ce poste ». Cela vaut aussi pour les collaborateurs sans compte Leezr ;
  - le **module Expéditions** la prouve : livraison en cours, ou affectée dans les 90 derniers jours. C'est le cas du manager qui conduit de temps en temps ;
  - le **rôle d'accès** l'exige, mais seulement en autorité « rôle ». Le rôle n'est jamais la seule source.
- **Règles explicites conservées** : « obligatoire à la saisie », règles par rôle, exigences de poste, obligation Platform.
- **Personas testés** : chauffeur, employé de bureau, manager qui conduit de temps en temps, collaborateur sans compte, règle explicite, autorité « poste ». Test `Adr861FunctionScopedModuleFieldsTest`, 6/6.
- **Sur le clone, société 1** :

  | Étape | Personnes à qui le permis est demandé |
  |---|---|
  | Aujourd'hui | 76 |
  | Selon l'activité, avant toute déclaration de poste | 12 |
  | Une fois le poste Livreur déclaré | 31 (19 par le poste, 2 par les livraisons, 10 par le rôle) |

  La règle ne rend personne nouvellement obligatoire (`newly_required` = 0). Rapport : `l7-fleet-function-clone.json`.
- **Non appliqué** : DEV et les sociétés historiques restent sur `everyone`. Dans la société 1, 50 personnes n'ont aucun poste : avant de basculer, il faut déclarer les postes qui conduisent. La simulation (`fields:rules-audit --module-wide`) liste les postes à revoir. **Basculer une société demande un GO distinct.**

## 4. Recette instable : cause et correctif

- **Cause démontrée** : une réponse 429 sur `/api/public/world` et `/api/public/theme`. Ces deux appels :
  - sont lus à chaque chargement complet, et deux fois pour `world` ;
  - étaient limités à 30 par minute et par IP.

  Ce n'est ni un problème de droits, ni un état d'authentification, ni un asset périmé. Le défaut a été reproduit au 3ᵉ rejeu consécutif.
- **Impact réel** : dans un bureau dont tous les postes sortent par la même IP, Leezr retombait en silence sur en-US, USD et le thème par défaut.
- **Correctif** :
  - limite portée à 600 par minute et par IP pour ces deux appels publics, qui ne dépendent pas de l'utilisateur ;
  - une seule requête `world` par chargement ;
  - le groupe API reste en `no-store`.
- **Non-régression** :
  - `Adr861PublicBootstrapBudgetTest` simule 40 personnes derrière une même IP ;
  - la recette vérifie au plus un appel `world` par chargement et aucun refus ;
  - 5 rejeux consécutifs sans aucun refus.

## 5. Tests

Résultats au §7.

## 6. Anomalies restantes

- Les pages publiques (inscription, centre d'aide, signature, invitations) gardent leurs titres. Elles sont hors du périmètre de L7.
- Contenu des onglets Documents (conformité, modèles, signatures) : seuls les en-têtes sont harmonisés.
- Le Planning garde ses 5 actions d'en-tête. Sur un écran de 1 440 px, elles réduisent la largeur de la phrase de description.
- Les « avant » des pages Exploitation, Messagerie, Catalogue et Accès sont les captures de l'audit 5.0 (`captures/current/shots/`). Les autres « avant » sont dans `captures/l7/avant-861/`.
- Points ouverts hérités : OP-2bis n'est toujours pas appliqué en PROD ; le compte Platform n° 1 de DEV accepte encore le mot de passe public.

## 7. Résultats

- **PHPUnit complet** :
  - premier passage : 4928 réussis, 13 ignorés et 1 échec. Le test `Adr830PublicThrottleTest` figeait l'ancienne limite de 30 appels par minute sur `/public/theme` ; il éprouve désormais la même intention sur la famille des tarifs publics, toujours à 30 ;
  - ensuite : `Adr830PublicThrottleTest` et `Adr861*`, 10/10 ;
  - tests nouveaux : `Adr861FunctionScopedModuleFieldsTest` 6/6 et `Adr861PublicBootstrapBudgetTest` 2/2 ;
  - tests liés aux champs, exigences, postes et membres : 466/466.
- **JS** : 65/65, dont le test d'accessibilité qui couvre toute l'application.
- **Recettes navigateur sur le clone**, 88 tests (recettes 4.2 et 5.0) : 84 réussis, 1 ignoré et 3 échecs.
  - Ces 3 échecs ne viennent pas de L7 :
    - **m1-D et m4-A** cherchent sur le tableau de bord Platform le widget « Facturé ce mois », que le cockpit V35 (L1, ADR-855) n'affiche plus. Les tests sont obsolètes depuis L1 ; depuis L7 partiel, le tableau de bord n'a reçu qu'un `aria-label`. Ils sont à requalifier.
    - **files859 · Embauche** exige le serveur avec antivirus simulé. Relancé avec ce serveur : réussi.
  - **Nouvelle recette `audit842-harmony861-recipe`** : 3/3 au premier passage. Elle couvre :
    - Flotte : clics réels sur le poste, persistance, Catalogue ;
    - familles Platform : groupes, fiches, retour, boutons nommés ;
    - Company sur desktop et mobile : titres distincts, liste tactile, aucun débordement, au plus un appel `world` par chargement, aucun refus.
- **Captures** : `captures/l7/avant-861/` (14) et `captures/l7/apres-861/` (45, desktop et mobile), ainsi que `captures/l7/recipe861-*`.
