# Leezr 5.0 — 18 · L7 · Clôture avant validation (ADR-862)

Livré en local sur `dev`, sans push ni déploiement, sans modification des sociétés historiques. Ce document fait suite au [17](17-l7-completion.md).

## 1. Sous-lots

| Sous-lot | Commit | Effet |
|---|---|---|
| L7.12 · Régressions et démarrage | `32be846c` | cockpit « Facturé ce mois (HT) » (le libellé L1 disait « Encaissé ») ; cache des indicateurs de facturation qui suit le grand livre ; recettes M1/M4 réancrées ; environnement de la recette d'embauche déclaré ; thème public mis en cache |
| L7.13 · Derniers défauts visuels | `dc022ad8` | Planning : 3 actions d'en-tête au lieu de 5 ; Documents : conformité, modèles et signatures refaits sur desktop et téléphone |
| L7.14 · Titres d'onglet | `27d520b6` | fiche JobDomain (watcher mort dans la TDZ), « Entreprises · Entreprises », titre remis à zéro à chaque navigation |
| Galerie | `8d310659` | revue visuelle avant / après, 23 pages |

## 2. Régressions de tests fermées

- **M1-D et M4-A n'étaient pas seulement obsolètes, elles ont trouvé deux vrais défauts.**
  - Le cockpit V35 (L1) intitulait la tuile `billing.revenue_mtd` « Encaissé ce mois — Paiements reçus depuis le 1er ». Or elle additionne les écritures `invoice_issued` / `REVENUE` du grand livre : c'est du **facturé HT**, pas de l'encaissé. Comme le dit ADR-841, le libellé redevient « Facturé ce mois (HT) », et la tuile mène aux factures.
  - Le chiffre restait en cache pendant 10 minutes (ADR-312). Une facture émise juste avant n'était donc pas comptée : la recette lisait 4 294 € au lieu de 5 288 €. La clé du cache porte désormais le dernier identifiant du grand livre, qui est immuable : une écriture nouvelle invalide le cache.
  - Les recettes s'appuient sur des repères stables (`cockpit-mrr`, `cockpit-invoiced-mtd`, `cockpit-active-subscriptions`) et gardent leurs assertions métier : même MRR partout, facturé du mois égal au grand livre, même nombre d'abonnements. La lecture des indicateurs de la liste Entreprises suit la bande commune (L7.7).
- **Recette d'embauche.**
  - `scripts/audit842-serve.sh` est le serveur de recette versionné. Il refuse toute base qui n'est pas un clone **marqué**, et c'est le seul endroit où `DOCUMENT_SCANNER=simulated` est posé.
  - La recette vérifie sa condition au lieu de la supposer : les pièces déposées doivent avoir été analysées par le moteur `simulated`, et aucune ne doit rester en quarantaine.
  - En production, le pilote simulé est refusé même s'il est configuré, y compris par la commande `--simulate`. Test : `Adr862RecipeEnvironmentTest`.

## 3. Appels publics de démarrage (600 par minute et par IP)

| Point vérifié | Constat |
|---|---|
| `/api/public/theme`, `/api/public/world` | GET anonymes, sans authentification et sans écriture (test). |
| Coût avant | `theme` : 7 requêtes SQL, 1,8 ms. `world` : 1 requête, 0,8 ms. |
| Coût après | `theme` en cache : 0 requête, 0,5 ms. `world` : 1 lecture indexée, 0,7 ms. |
| Pire cas pour une IP | 10 requêtes par seconde, soit environ 5 à 7 ms de calcul par seconde. Les deux appels sont sans données personnelles. |
| Autres limites | Compteurs séparés : 35 appels `theme` ne consomment pas les 30 de `/public/plans` (test). Aucune autre limite de l'API n'est modifiée ; connexion, OTP, réinitialisation et inscription gardent les leurs. |

Le thème est mis en cache (`public:theme:v1`, 5 minutes). Le cache est oublié dès que `PlatformSetting`, `PlatformFontFamily` ou `PlatformFont` changent : un changement de thème est servi immédiatement (test).

## 4. Derniers défauts visuels

- **Planning** : Exporter et Préférences rejoignent le menu « ⋯ » de la vue. L'en-tête passe de 5 contrôles à 3, et la phrase de description tient sur une ligne à 1 440 px (recette).
- **Documents · Conformité**
  - Une bande d'indicateurs remplace les pastilles « Conformes: 3 » et le taux isolé : taux, conformes, manquants, à renouveler, en cours. Le détail est dans l'aide de chaque indicateur.
  - Par type de document : une barre de progression avec « n conformes sur N », et l'export placé à côté des données.
  - « Par personne » remplace « Membre ».
  - Sur téléphone, une liste remplace le tableau à 8 colonnes, qui était coupé.
- **Documents · Modèles de contrat**
  - Le type est écrit en clair (CDI), la version est à côté du nom, le modèle par défaut est marqué d'une étoile.
  - Une seule action visible par modèle ; les autres sont dans un menu nommé.
  - L'avertissement juridique devient la phrase de la carte, sans bandeau.
  - Sur téléphone, une liste.
- **Documents · Signatures**
  - La supervision est écrite en mots simples : « Certs (émis/révoqués) » devient « Certificats émis », avec le détail en aide.
  - Le prestataire est en clair : « Leezr (interne) » au lieu de « internal ».
  - « Nouvelle demande » est à côté de la recherche, au lieu d'occuper seule une ligne.
  - Dates localisées, liste tactile sur téléphone, états vides communs.
- **Titres d'onglet**
  - Sur la fiche JobDomain, l'observateur lisait `profile` avant sa déclaration et s'arrêtait en silence : l'onglet restait « Leezr ».
  - « Entreprises · Entreprises » est dédoublonné.
  - Le titre revient à « Leezr » à chaque nouvelle adresse, et l'en-tête commun donne un titre par défaut. Une page ne garde donc plus le titre de la précédente.

## 5. Galerie de revue visuelle

- **Ouvrir** : `docs/bmad/leezr-5-design/captures/gallery/index.html`, fichier local et statique.
- **Organisation** : par espace puis par famille. Comparaison côte à côte ou au curseur ; formats desktop ou mobile, clair ou sombre.
- **Contenu** : 23 pages et 76 captures par côté.
  - Platform : Dashboard V35, Entreprises, fiche Company, JobDomains (liste et fiche), Modules (liste et fiche), Facturation, Accès Platform, Exploitation, Catalogue.
  - Company : Dashboard V22, Personnes, fiche collaborateur, Planning, Pointage, Congés, Documents (à traiter, conformité, modèles, signatures), Préparation de la paie, Expéditions.
- **Méthode** :
  - même clone marqué et mêmes données de test, sans aucune donnée de production ;
  - mêmes comptes ;
  - même script (`tests/e2e/audit842-gallery862.spec.js`) ;
  - mêmes dimensions : 1 440 × 900 et 390 × 844.
- **Versions comparées**
  - **Avant** : Leezr 4.2 (`753d5372`, la veille de L0), servi depuis un worktree avec son propre `vendor`.
  - **Après** : build courant.
  - **Seul écart** : côté « avant », la limite des appels de démarrage est relevée. Sans cela, la capture aurait rencontré le défaut 429 corrigé en L7.11 et aurait pu s'afficher en anglais et en dollars.
- **Régénérer** : `GALLERY_SET=avant|apres` puis `python3 scripts/gallery862-index.py`.

## 6. Module Flotte : cas qui demandent une décision métier

La règle reste compatible avec l'existant :

- toutes les sociétés sont sur `everyone` : DEV local 5 sur 5 ; clone 10 sur 10 (une `everyone`, neuf `NULL`) ;
- rien n'est activé automatiquement ;
- la simulation ne rend jamais quelqu'un nouvellement obligatoire (`newly_required` = 0).

Simulation en lecture seule sur le clone, société 1 (`fields:rules-audit --module-wide --company=1`), au 2026-11-22 :

| Constat | Chiffre | Décision attendue |
|---|---|---|
| Personnes à qui le permis est demandé aujourd'hui | 81 | — |
| Personnes qui le resteraient selon l'activité, sans aucune déclaration de poste | 12 (2 par livraisons, 10 par le rôle) | — |
| **Collaborateurs sans poste** | 51 sur 81, dont 36 sans compte | Avant de basculer, faut-il leur attribuer un poste ? Sinon, le permis ne leur sera plus demandé, sauf livraison récente. |
| Poste « Livreur » | 23 titulaires ; activité non déclarée (clone revenu à l'état historique) | Déclarer « conduite de véhicule » sur ce poste : c'est le prérequis de toute bascule. |
| Poste « Responsable d'exploitation » | 7 titulaires, dont 6 avec un indice de conduite | Ce poste conduit-il ? À trancher par la société. |
| Personnes retenues seulement par le **rôle** « Driver » | 10 | En autorité « poste », le rôle n'est pas pris en compte : c'est voulu, puisque le rôle n'est jamais la seule source. Cela suppose que leur poste soit renseigné. |
| Conducteur occasionnel au-delà de 90 jours sans livraison | — | Accepter la fenêtre de 90 jours, ou déclarer l'activité sur le poste. |
| Sociétés historiques | toutes sur `everyone` | **GO distinct par société** pour basculer, après déclaration des postes. |

## 7. Tests

- PHPUnit complet : 4 936 réussis, 13 ignorés, 0 échec. JS : 65/65.
- Campagne E2E des recettes du clone (85 tests) : 84 réussis au premier passage, 1 échec, celui de `harmony860`, causé par un 401 du flux temps réel après l’effacement des cookies sous une page ouverte. La recette est corrigée et passe 4/4 deux fois de suite. Recette de clôture `audit842-closure862` : 3/3.

## 8. Écarts esthétiques encore constatés

- Les deux tableaux de bord, Personnes et la fiche collaborateur ne nomment pas l'onglet du navigateur : il affiche « Leezr ». Ces pages n'utilisent pas l'en-tête commun.
- La fiche collaborateur a peu bougé depuis 4.2 : elle avait déjà été refondue en L5 et L6. La galerie le montre tel quel.
- Sur téléphone, les onglets de l'espace Documents défilent : le premier est coupé au bord de l'écran.
- Dans la bande d'indicateurs, sur 390 px, les libellés longs sont tronqués (« Taux de conf… », « En attente de sign… »). Le nom complet reste disponible dans l'aide.
- Les pages publiques (inscription, centre d'aide, signature, invitations) sont prévues pour un lot distinct.

## 9. Finitions finales et décisions Flotte (addendum)

- **Les quatre écarts du §8 sont fermés.**
  - Titres d'onglet du navigateur :
    - cockpit Platform : « Vue d'ensemble · Tableau de bord » ;
    - accueil Company : « Accueil · société » ;
    - Personnes : « Personnes · Équipe » ;
    - fiche collaborateur : « nom · rubrique · Équipe ».
  - Sur téléphone, Documents montre ses quatre vues entières, sans défilement.
  - À 390 px, les libellés des bandes d'indicateurs sont entiers.
  - La fiche collaborateur reprend l'en-tête V22.
  - Vérifié par la recette `audit842-finish863` (3/3) ; galerie « après » recapturée.
- **Décisions Flotte** : elles sont inscrites dans l'addendum de l'ADR-862. Le §6 ci-dessus reste le constat de départ.
  - Livreur conduit par défaut, posé à la création du poste uniquement, sans écraser l'existant.
  - Le responsable d'exploitation n'a aucune obligation automatique.
  - Aucun collaborateur ne reçoit de poste automatiquement.
  - Le conducteur occasionnel est déclaré sur sa fiche ; les livraisons ne sont qu'un indice.
  - Aucune bascule de société.
- **Préflight DEV** : [release/DEV-PREFLIGHT-5-0.md](release/DEV-PREFLIGHT-5-0.md).
