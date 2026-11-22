# Leezr 5.0 — 09 · Synthèse pour le propriétaire

## En une phrase

Leezr est **stable** (0 erreur sur 128 pages explorées) mais **pas encore désirable**. La bonne nouvelle : l'essentiel du moteur nécessaire existe déjà. Il faut surtout **une identité commune et une composition**, pas une nouvelle architecture.

## Ce que j'ai constaté

1. **Les dashboards sont fades parce qu'ils sont mal composés.** Le moteur de widgets est bon : 47 widgets, déplacement, redimensionnement, disposition par rôle et adaptation à la taille réelle. Mais :
   - 8 widgets seulement ont un graphique ;
   - les tuiles KPI tombent toujours dans le mode « minuscule », car les seuils sont faits pour des graphiques ;
   - chaque widget a son propre style.
2. **Trois tentatives de design system ont été lancées et jamais généralisées.** La couche « Leezr Premium » n'est utilisée que par 2 fichiers.
3. **Les pages se ressemblent sans aider.**
   - 14 pages de facturation portent le même titre ;
   - 23 pages Platform ont 8 onglets ou plus ;
   - des bandeaux d'explication occupent le haut des pages ;
   - 697 boutons-icônes n'ont pas de libellé.
4. **Le bouton « Outils » de Personnes est obsolète, preuve à l'appui.**
   - Il règle les mêmes champs que Réglages › Catalogues.
   - Il permet de rendre un champ obligatoire pour toute la société, alors que la règle décidée est « l'obligation se règle par poste ».
   - Il y a donc **deux sources de vérité** pour l'obligation, et le sujet se règle à **4 endroits**.

## Ce que je propose (à voir dans les prototypes)

| | Avant | Après |
|---|---|---|
| **Platform** | « Cockpit » gris, MRR caché sous une courbe, cartes de tailles différentes | **un cockpit V35** : accueil, 3 KPI vivants, **panneau sombre de santé en direct**, revenus et encaissements, « À traiter » trié par impact, inscriptions, fins d'essai, adoption des modules. Les chiffres financiers **disparaissent** pour un profil sans droit. |
| **Company** | « Bienvenue » gris, pointage du dirigeant en premier, tuiles « 0 » | **un espace de travail V22** : bandeau de marque avec la phrase du jour, KPI **du métier**, agenda du jour, actions avec compteurs, équipe en ce moment, fil d'activité ; **démarrage guidé** pour une nouvelle société ; contenu propre au chauffeur. |
| **Personnes** | tableau plat, « Outils » ambigu | segments avec compteurs, **prochaine action par personne**, complétude du poste, consultation rapide en tiroir |
| **Widgets** | contenu rapetissé | **recomposé selon la taille** (5 tailles démontrées), états chargement, vide et erreur, tailles refusées |

Captures : `captures/proto/` (desktop, mobile, sombre), à comparer avec `captures/current/`. Les adresses des prototypes sont dans [07](07-prototypes.md).

## Ce qui n'existe pas et que je n'ai pas inventé

- **Rendez-vous et missions** pour les métiers de services : il faut le module *scheduling*.
- **Historique de santé** de la plateforme : nouvelle mesure, petite.
- **Heure d'arrivée** des tournées.
- **Rapport mensuel.**

Chacune est proposée comme évolution, avec sa source de données ([04 §5](04-dashboards-widgets.md)).

## Ordre recommandé

1. **L0 · Fondations communes** : un seul laboratoire et des composants promus en presets.
2. **L1 · Cockpit Platform** : résultat visible tout de suite, aucune donnée modifiée.
3. L4 · Widgets responsives, puis L3 · Accueil Company.
4. L5 et L6 · Personnes et nettoyage, dont la migration de l'obligation des champs vers les postes, sur clone d'abord.
5. L7 · Harmonisation des autres pages ; L8 · Nouveaux modules, à arbitrer avec la feuille de route produit.

Détail : [08-roadmap.md](08-roadmap.md).

## Décisions attendues de votre part

1. **La direction visuelle** des prototypes vous convient-elle (cockpit V35, espace de travail V22) ?
2. **Déprécier « Outils › Champs »** et faire porter l'obligation par les postes seulement (migration réversible) ?
3. **GO L0 + L1**, ou un autre ordre ?

## Ce qui n'a pas été fait

- Aucune refonte de production.
- Aucun push ni déploiement.
- Aucune donnée modifiée : les explorations sont en lecture seule, sur le clone marqué.
- Aucune suppression.
- Les prototypes sont des pages `devOnly` : le routeur les refuse hors développement.
