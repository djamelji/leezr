# Leezr 3.1 — JobDomain simplifié, dépendances des modules, refonte Platform & Company (ADR-821)

> Branche `dev` locale, **aucun push**, aucun déploiement. Recette écrivante sur le clone isolé
> `leezr_audit821` (sociétés fictives créées pour l'occasion ; société 1 consultée en lecture seule).
> Preuves : `docs/bmad/company-3-1/`.

## 1. Commits locaux (`80050db9` → …)

| Commit | Objet |
|---|---|
| `82d25542` | Lot 2 — résolution unique des dépendances (JobDomain, offres, sociétés), désactivation guidée |
| `49dd8019` | Lot 1 — fiche JobDomain simple : Métier · Modules · Configuration initiale |
| `b01f679b` | Lot 5.1 — catalogue des modules Company refondu, activation via l'aperçu des dépendances |
| `53a23430` | Lot 3 — modules de l'offre (Plan) : inclus / option / non inclus / dépendances obligatoires |
| `c41c11c8` | Lot 6 — valeurs initiales du planning depuis un profil publié ; Workforce propriétaire |
| `8963fb7b` | Lot 4.1/4.2 — catalogue des modules et fiche module Platform refondus |
| `c6645ee7` | Lot 4.3 — fiche Société Platform : synthèse, onglet Configuration, libellés traduits |
| `c1baecf8` | Barre de recherche animée des modules remise en place (demande), mention « inclus » courte |
| `5ae226f1` | Lot 5.2/5.5 — liste Équipe et liste Expéditions refondues |
| `ea4a4dd1` | Lot 3.1 — une option payante prévue par le métier n'est plus accordée gratuitement |
| `1d19cc86` | Correctifs trouvés par la recette dépendances (cache en cascade, marquage, achats annoncés) |
| `25c9fe13` | Requalification des E2E historiques ; dossiers d'embauche les plus récents d'abord |
| `63beff61` | Correctifs visuels (onglet actif, dépendances en double, colonne Offres compacte) |

## 2. Synthèse — livraison PARTIELLE

**Architecture livrée et recettée** :
- une résolution unique des dépendances, lue dans les modules ;
- JobDomain simplifié ;
- offres contrôlées ;
- activation et désactivation Company cohérentes ;
- options payantes jamais offertes ;
- valeurs initiales du planning possédées par Workforce.

**Pages refondues** : JobDomain, catalogue des modules et fiche module Platform, fiche Société Platform, catalogue des modules Company, listes Équipe et Expéditions.

**Pages non refondues** (§20) : la plupart des pages Workforce, la fiche personne, Postes et Livraisons. La mission n'est donc **pas** déclarée intégralement terminée.

## 3. JobDomain (lot 1) — implémenté et recetté

`/platform/jobdomains/{id}/{general|modules|setup}` :

| Section | Contenu |
|---|---|
| **Métier** | Identité, vocabulaire et conventions ; expérience (ordre du menu, points d'entrée) en panneau facultatif |
| **Modules** | Un seul tableau : compatible, au démarrage, recommandé, statut par offre. Les dépendances sont ajoutées et expliquées automatiquement : « requis par Planning » ; « Planning nécessite également Ressources humaines : ce module a été ajouté automatiquement. » |
| **Configuration initiale** | Bibliothèque de départ (modifier / publier) et publication (actif, inscription, chemin de création). Les autres familles (rôles, organisation, exigences suggérées, planning) et les diagnostics sont en options avancées. |

Règles complémentaires :
- La compatibilité reste stockée sur le module, son propriétaire.
- Une compatibilité utilisée par une société active ne peut pas être retirée (droit historique).
- Les anciennes sections et les anciens `?tab=` sont redirigés.

## 4. Responsabilités Modules / Plans

| Responsable | Décide |
|---|---|
| **Module** | Dépendances (`requires`), données (`dataNeeds`), pages, assistant |
| **Plan** | Droits commerciaux, lus depuis l'offre minimale et le prix d'option du module (le plan ne porte pas de liste) |
| **JobDomain** | Compatibilité, démarrage, recommandations ; aucun droit commercial |

## 5–6. Résolution automatique des dépendances, cas transitifs — implémenté et recetté

- `ModuleDependencyResolver` lit `requires` dans le registre des modules ; aucune seconde liste.
  - Il calcule la fermeture transitive, sans doublon, et indique qui a besoin de quoi.
  - Il détecte les cycles et les modules inconnus.
  - `ModuleActivationEngine` et l'ancien `DependencyResolver` lui délèguent.
- **Cas transitif** : Préparation de la paie → Absences et congés → Ressources humaines.
  - Vérifié en test, dans l'aperçu de l'offre et dans la recette (activation de la Paie qui ajoute les Congés).
- **Tests** (`Adr821ModuleDependencyTest`, 10 tests) :
  - dépendance simple, multiple, transitive, déjà active, manquante (module inconnu), en cycle ;
  - option payante non couverte, désactivation en cascade, parent partagé, réactivation ;
  - échec transactionnel (tout ou rien), isolation entre sociétés, offre incohérente, option jamais offerte.

## 7. Contrôles commerciaux — implémenté et recetté

- **Cohérence des offres** : une offre qui inclut un module doit inclure ses dépendances. Une option exige des dépendances proposées au moins en option.
  - Une modification de palier ou de prix qui crée un écart est refusée.
  - Recetté : passer « Absences et congés » en Business alors que la Paie est vendue dès Starter → 422, rien n'est enregistré.
- **Fiche Plan** : carte « Modules de l'offre » (inclus, option, non inclus, dépendance obligatoire, écarts signalés).
  - Écart existant signalé sur DEV : Parrainage (sans prix) dépend de RH (option payante).
- **Option payante prévue par le métier** :
  - La source d'entitlement « JobDomain » l'accordait gratuitement. Désormais, seulement aux sociétés qui l'ont déjà, ce qui conserve les droits historiques.
  - Une nouvelle initialisation ne l'active pas.
  - L'aperçu d'activation l'annonce comme un achat (`purchase`). Le parcours d'achat (devis, facturation) est inchangé.

## 8. Activation et désactivation Company — implémenté et recetté

- **Activation d'un module sans achat** : aperçu serveur, puis confirmation.
  - Message affiché : « Planning nécessite également Ressources humaines. Ce module sera ajouté automatiquement. »
  - Les deux modules sont activés, puis l'assistant de configuration du module s'ouvre.
- **Désactivation d'un module dont d'autres dépendent** :
  - API : refus 409 avec la liste des dépendants.
  - Interface : avertissement ; la confirmation reste bloquée tant que la cascade n'est pas cochée.
  - Avec cascade, les modules sont désactivés ensemble et les données conservées.
  - Recetté sur Paie → Congés. RH, prévu par le métier, est « inclus » et ne se désactive pas depuis le catalogue (règle ADR-807).
- **Défaut trouvé puis corrigé** : en cascade, le cache `company_modules` du dépendant n'était pas resynchronisé.

## 9. Compatibilité historique — vérifiée (tests et recette)

- Modules actifs conservés, même s'ils deviennent payants ; aucune société existante réinitialisée.
- Compatibilité non retirable tant qu'un module est utilisé.
- Les sociétés existantes gardent leurs objets Planning importés.

## 10. Paramètres Workforce (lot 6) — implémenté et testé (non recetté en navigateur)

- L'import Workforce lit le **profil « planning » publié** du métier (versionné). La colonne historique `jobdomains.workforce_presets` n'est plus qu'un repli.
- Migration : le profil Logistique est publié depuis les valeurs historiques (configuration Platform seulement).
- Test : le profil publié est la source ; un objet personnalisé dans Workforce n'est jamais réécrit par une nouvelle version.

## 11. Pages Platform

| Page | Changé visuellement | Statut |
|---|---|---|
| Fiche JobDomain (3 sections) | Oui | Refondue, recettée (clair, sombre, mobile) |
| Catalogue des modules | Oui (filtres, recherche, dépendances, offre, commercialisation) | Refondu, recetté |
| Fiche module (7 sections routées) | Oui (Général, Fonctionnalités, Dépendances, Permissions, Commercialisation, Sociétés, Avancé) | Refondue, recettée |
| Fiche Plan | Ajout de la carte « Modules de l'offre » | Recettée |
| Fiche Société | Oui (synthèse, onglet Configuration, statut traduit, libellés en français) | Refondue, capture prise |
| Permissions Platform | Inchangé | Existant et vérifié : un utilisateur limité ne voit ni Catalogue, ni Entreprises, ni Facturation ; API de composition et d'offre refusées (403) |

## 12. Pages Company

| Page | Changé visuellement | Statut |
|---|---|---|
| Catalogue des modules | Oui (catalogue filtré unique, cartes, dépendances, aperçu d'activation, désactivation guidée) ; **barre de recherche animée d'origine conservée** (demande) | Refondu, recetté |
| Équipe (liste) | Oui (en-tête sur une ligne, 7 colonnes → 4, statut en puce) | Refondue, captures avant / après |
| Expéditions (liste) | Oui (en-tête, filtres en puces, trajet, chauffeur et identifiant) | Refondue, captures avant / après |
| Embauches (liste) | Tri du plus récent au plus ancien | Correctif, non refondue |
| Fiche personne, Postes, Planning, Pointage, Congés, Contrats, Documents RH, Paie, Espace salarié, Livraisons | Non | **Non réalisé** |

## 13. Vuexy

- Les pages refondues utilisent `VCard` / `VCardItem` / `VCardText` / `VCardActions`, `VTabs` routés, `VDataTable`, `VChipGroup`, `VList`, `VExpansionPanels` et `VDialog`.
- Pas de nouveau composant générique. Les composants `App*` d'ADR-782 ne sont pas retirés des 6 pages qui les utilisent (non réalisé).

## 14. Routing BMAD — vérifié

- Nouvelles sections dans le chemin : `/platform/jobdomains/{id}/{section}`, `/platform/modules/{key}/{section}`, `/platform/companies/{id}/configuration`.
- Le garde-fou `Adr820TabRoutingConventionTest` passe : aucun `?tab=` réintroduit.

## 15. Captures (`docs/bmad/company-3-1/shots/`)

- **Avant / après** : catalogue des modules Platform, fiche Société, Équipe, Expéditions.
- **Après** : JobDomain (Modules, Configuration initiale), fiche module (sombre), catalogue Company (mobile), dépendances (JobDomain, Plan, aperçu d'activation, désactivation guidée).
- **Variantes** (`variants.json`) : 33 captures (11 pages × clair, sombre, mobile), sans débordement horizontal ni clé brute.
- **Anglais non vérifié** dans le navigateur : la langue suit la préférence du compte. Les traductions EN sont ajoutées.

## 16. Tests unitaires et d'intégration

- Nouveaux et adaptés : `Adr821ModuleDependencyTest` (10), `WorkforcePresetImportM5Test` (profil publié), `ModuleDependencyTest`, `RealtimePublishTest`, `PageModuleAlignmentTest`.
- **Suite complète** : **4 697 réussis, 12 ignorés, 3 échecs**. Ce sont les trois échecs historiques de `ControllerSizeInvariantTest`, une catégorie à part ; aucun contrôleur créé ou modifié par ADR-821 n'y figure. **Aucun nouvel échec.**
- **Build** : propre.

## 17. Résultats E2E

- **Recette dépendances** (`acceptance-deps.json`) : **23/23** (JobDomain, Plan, société, permissions Platform).
- **Scénario métier complet** (`acceptance-main.json`) : **38/38**.
  - Configuration du métier, publication, inscription, catalogues, mapping, poste, embauche à distance, validation RH, accès salarié.
  - L'inscription passe par l'endpoint réel ; validation et finalisation RH par API depuis la session.
- **Specs historiques requalifiées** :

| Spec | Résultat |
|---|---|
| `adr796-onboarding` (Mailpit local) | **Vert** (délais allongés : `php -S` lent pour la société 1). Défaut trouvé et corrigé : un dossier venant d'être soumis apparaissait en page 2. |
| `adr797` | Mes sections passent ; échec **historique** : dépend d'un état où le RBAC Logistique n'a pas de preset |
| `adr798` | Mes sections passent ; échec **historique** : bouton masqué pour une famille possédée par un domaine (ADR-802) |
| `m7-lot4` | Test des routes **vert** après alignement des attentes devenues fausses (ADR-778, ADR-802, ADR-816, ADR-820). Test suivant en échec **historique** : rôle « admin » introuvable dans la société 1. Tests suivants non exécutés (série). |

## 18. Multi-tenant — vérifié

- Aperçu d'activation d'une autre société refusé.
- Prévisualisation de désactivation limitée à sa société.
- Données de mapping et d'embauche inaccessibles entre sociétés (recette ADR-820 rejouée).

## 19. Régressions et anomalies trouvées (toutes corrigées)

- Cache non resynchronisé lors d'une désactivation en cascade.
- Dépendance non marquée après rechargement (JobDomain).
- Option payante accordée gratuitement via le métier.
- Fiche Société : libellés en anglais, statut brut.
- Fiche module : onglet actif erroné, dépendances affichées en double.
- Liste des embauches triée de la plus ancienne à la plus récente.

## 20. Non réalisé

- **Refonte visuelle Company** :
  - fiche personne, Postes (liste et fiche), Organisation ;
  - Planning, Pointage, Absences et congés, Contrats, Documents RH, Préparation de la paie, Espace salarié ;
  - Livraisons (`/my-deliveries`), fiche expédition.
- **Hub Réglages** : déjà refondu en ADR-820, non repris ici.
- **Composants App\*** d'ADR-782 : non remplacés.
- **Inscription par l'assistant** et **anglais** : non vérifiés dans le navigateur.

## 21. Risques résiduels

- **Changement de comportement à annoncer** : une option payante prévue au démarrage par un métier n'est plus accordée aux nouvelles sociétés. Les sociétés existantes la gardent.
- **Écart d'offre existant** (Parrainage inclus, RH en option) : signalé, non corrigé. C'est une décision commerciale.
- **Cascade de désactivation** : réservée aux modules non inclus. Les modules « inclus » par le métier ne se désactivent pas depuis le catalogue (ADR-807).
- **Serveur de test** : les délais E2E du dépôt sont trop courts pour la société 1.
