# Leezr 5.0 — 16 · L7 · Harmonisation Platform et Company (ADR-860) — **livraison partielle**

L7 est livré **en partie**, en 5 sous-lots commités séparément, en local sur `dev`. Il n'y a ni push, ni déploiement, ni migration, ni changement de permission, ni nouveau design system. **L7 n'est pas terminé** : l'état par famille est au §2.

## 1. Ce qui change (synthèse)

| Sous-lot | Commit | Effet visible |
|---|---|---|
| **L7.1 · Fondations** | `4456f891` | En-tête de page unique `AppPageHeader` : typographie V22/V35 et fil de contexte (« Famille › section »). L'engrenage du thème n'apparaît plus que sur Paramètres › Thème, et ne chevauche plus les onglets. Standard d'accessibilité : 184 boutons-icônes reçoivent un nom (fermer, supprimer avec infobulle, modifier, retour, actions…). |
| **L7.2 · Facturation Platform** | `fc9bab4c` | Les 15 pages ne portent plus toutes le titre « Facturation ». Chacune a son fil (« Facturation › Factures et paiements »), son titre, une phrase qui dit ce qu'elle montre, et un titre d'onglet de navigateur distinct. Le bandeau répété au-dessus de chaque groupe est supprimé. |
| **L7.3 · Points métier hérités de L6** | `3b8840d5` | Permis de conduire : simulation de l'obligation selon la fonction, sans rien appliquer (§3). Terminologie : « Obligatoire à la saisie » d'un côté, « Demandé au dossier » de l'autre. |
| **L7.4 · Paramètres Platform** | `9474ee51` | 8 onglets à un seul niveau deviennent 5 groupes, puis les pages du groupe (même modèle que Facturation). L'en-tête dit où l'on est. Les menus d'actions « ⋯ » ont désormais un nom accessible. |
| **L7.5 · Familles Company** | `92b23369` | Les écrans représentatifs Documents, Expéditions, Modules, Réglages, Contrats, Préparation de la paie et Mon espace utilisent le même en-tête que Platform. |

Composants communs réutilisés : `AppPageHeader` (enrichi), `AppKpiTile`, `AppWidgetShell`, `AppEmptyState`, `AppStatusChip`, et les modèles « groupes → pages » de Facturation et Paramètres.

## 2. État par famille

| Famille | État |
|---|---|
| **Platform · Plans, abonnements et facturation** | **harmonisée** pour la Facturation (titres, groupes, en-tête). La fiche Plan n'est pas traitée. |
| **Platform · Configuration et catalogues** | **harmonisée** pour Paramètres (5 groupes). Le Catalogue (6 onglets) n'est pas traité. |
| Platform · Entreprises et fiches Company | **reste à faire** |
| Platform · JobDomains | **reste à faire** |
| Platform · Modules et dépendances | **reste à faire** |
| Platform · Utilisateurs et autorisations | **reste à faire** |
| Platform · Exploitation, support, supervision (Opérations : 7 onglets ; Messagerie : 7 onglets) | **reste à faire** |
| **Company · Équipes, postes, collaborateurs** | **harmonisée** (Personnes L5/L6 ; Organisation et Postes héritent de l'en-tête) |
| **Company · Documents et signatures** | **en-têtes harmonisés** (Documents, Contrats). Le contenu des onglets n'est pas retravaillé. |
| **Company · Préparation de la paie** | **en-tête harmonisé**, contenu non retravaillé |
| **Company · Logistique** | **en-tête harmonisé** (Expéditions). La fiche Expédition n'est pas traitée. |
| **Company · Réglages, catalogues, modules** | **harmonisée** : en-têtes, et Modules en L6 (menu « ⋯ », pastille « Configuré ») |
| Company · Workforce, planning et pointage | **reste à faire** (Planning : 4 actions d'en-tête de même poids, 05 §2) |
| Company · Absences et congés | **reste à faire** |
| Company · Facturation et abonnements | **en-tête hérité** (le composant enrichi) ; contenu non retravaillé |

## 3. Points métier

**Module Flotte : le permis selon la fonction.** Les champs de permis portent à la fois `required_by_modules: [logistics_fleet]`, qui les rend obligatoires pour tout le monde quand le module est actif, et `tags: [driving]`, qui correspond à la fonction de conduite. `fields:rules-audit --module-wide` simule le changement, en lecture seule :

- **Société 1 du clone** : 72 personnes concernées aujourd'hui, 12 selon la fonction (rôle Chauffeur). 60 seraient libérées.
- **Proposition (non appliquée)** : dans Platform › Catalogue, retirer l'obligation au niveau du module pour les 4 champs de permis et garder l'étiquette `driving`. Les sociétés en autorité « poste » exigeraient alors le permis sur le poste concerné.
- **Pourquoi la proposition n'est pas appliquée** : la règle s'applique à toutes les sociétés existantes. Les dossiers ne seraient pas modifiés, mais leur évaluation changerait : c'est une décision produit à prendre.
- Rapport : `l7-module-wide-clone.json`.

**Les deux réglages « tout le monde »** n'ont pas le même effet. Seuls leurs libellés changent, aucune règle n'est fusionnée :

| Libellé | Où | Effet |
|---|---|---|
| **Obligatoire à la saisie** | Catalogue › Champs (`required_override`) | la fiche de chaque personne ne peut pas être enregistrée sans le champ |
| **Demandé au dossier** | Exigences › « Dossier de tous les collaborateurs » (registre) | compte pour la complétude, l'intégration et les pièces, sans bloquer l'enregistrement |

## 4. Accessibilité

- Standard : tout bouton-icône porte un nom (`aria-label` ou `title`), y compris un activateur de menu. Les actions de suppression ont aussi une infobulle.
- 184 boutons ont été nommés automatiquement d'après une icône sans ambiguïté.
- **65 boutons restent sans nom** (icônes ambiguës), à traiter famille par famille.
- Le test `a11y-icon-buttons` protège les pages harmonisées. La recette vérifie en navigateur qu'aucun bouton-icône sans nom n'est visible sur ces pages.

## 5. Tests et recette

- **E2E `audit842-harmony860-recipe` 4/4** :
  - titres de Facturation distincts, et titres d'onglet du navigateur ;
  - Paramètres groupés ;
  - engrenage présent seulement sur Thème ;
  - aucun bouton-icône sans nom ;
  - profil Platform limité ;
  - mobile et sombre ;
  - 7 pages Company sur desktop et mobile.
  - Un premier passage du scénario Company a relevé des appels refusés transitoires juste après le build, puis 3 passages consécutifs ont réussi. Point à surveiller.
- **Non-régression finale** : 32/32 (harmony860 4, files859 4 hors embauche, people858 5, workspace857 10, widgets856 4, cockpit855 5), plus `m5` mis à jour.
- **PHPUnit complet** : 4921 réussis, 13 ignorés, 0 échec.
- PHPUnit `Adr860ModuleWideObligationTest` 1/1 ; JS 64/64.
- **Performance** : le seul budget relevé reste celui de la liste Personnes (25 → 50 en L6, constant avec la taille : 45 requêtes à 3 000 personnes). Aucun autre budget du test de performance n'a changé. L7 n'ajoute aucune requête serveur (en-têtes et libellés seulement).

Captures :
- `captures/l7/apres/` : Facturation, Paramètres, Documents, Expéditions, Paie, Modules ; desktop, mobile et sombre ;
- `captures/l7/recipe-*` : captures de la recette ;
- « avant » à la même largeur : captures de l'audit 5.0, `captures/current/shots/` (`p-superadmin/platform_billing_*`, `c1-owner/*`).

## 6. Problèmes encore ouverts

- Familles « reste à faire » du §2.
- 65 boutons-icônes sans nom.
- Proposition Flotte à décider.
- Les deux réglages « tout le monde » restent distincts : les fusionner suppose de démontrer leur équivalence.
- Environ 70 pages gardent un titre construit à la main. Elles seront converties famille par famille avec le même convertisseur prudent, qui ne traite que le schéma standard.
