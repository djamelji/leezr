# Company Experience 2.0 — UX6 : qualité visuelle et recette (ADR-782)

> Base `cdf74a0c` · DEV uniquement · aucun moteur, aucune migration, aucune permission modifiée ; `@core` et `@layouts` intacts.
> Preuves : `docs/bmad/ux6-782/` (`before-<page>-<format>-<thème>.jpg`, `after-…`, `report-before.json`, `report-after.json`) · outil de capture `tests/e2e/ux6-capture.spec.js`.

## 0. Cadre

- **Références** : aucune interface tierce n'a été consultée en ligne pendant ce lot. Les choix s'appuient sur des principes connus des produits cités (réglages en liste avec navigation latérale, surfaces peu contrastées, états en texte, peu de bordures), pas sur une inspection de leurs écrans.
- **Politique UI** (`06-ui-policy.md`, section ADR-782) : les primitives restent celles de Vuexy/Vuetify ; Leezr ajoute un petit socle de composants de **composition** construits avec ces primitives et les variables du thème.

## 1. Diagnostic visuel initial (captures réelles examinées)

| Écran | Défauts | Gravité |
|---|---|---|
| Réglages | une VCard par réglage, hauteur forcée (180 px) pour deux lignes de texte, tiers de largeur, titre répété (« Entreprise › Entreprise »), badge sur chaque entrée essentielle, page très longue | majeure |
| Profil entreprise | pas de titre de page ; onglet unique « Vue d'ensemble » ; une carte par groupe de champs ; champs pleine largeur | majeure |
| Abonnement | pas de titre ; 5 onglets en pilules ; titres de carte tronqués (« Moyen de paiem… ») | moyenne |
| Paramètres RH | codes bruts (« FR », « logistique ») ; « Enregistrer » détaché de son champ ; onglets en pilules | moyenne |
| Organisation | onglets en pilules, en-tête différent des autres pages | faible |
| Équipe | filtres dispersés sur toute la largeur | faible |
| Fiche | « Prochain shift », statut brut « (completed) » | faible |
| Mon activité | états vides en alertes bleues vives dans chaque carte ; deux pastilles par document qui tronquent le titre sur mobile | moyenne |
| Accueil, Documents, Planning, Temps, Absences | composition correcte ; densité acceptable | — |

## 2. Réglages avant / après

| | Avant (UX5) | Après |
|---|---|---|
| Composition | grille de cartes, une par réglage | navigation des rubriques à gauche (ancres, rubrique active suivie au défilement) ; à droite, groupes « titre + phrase + une surface de lignes » |
| Ligne | carte 180 px | ligne de 56 px : icône discrète, nom, description, état, chevron ; ligne entière cliquable, focus clavier visible |
| États | puce colorée | pastille + texte court (« 2 information(s) à compléter », « Prestataire de test ») |
| Essentiel | badge | mention discrète à droite du nom |
| Avancé | carte repliable | groupe discret en bas, une ligne « Afficher les réglages avancés (2) » |
| Recherche | à droite, sans alignement | dans l'en-tête, alignée sur la colonne de contenu ; pleine largeur sur mobile |
| Mobile | cartes empilées | rubriques en barre défilante (le défilement reste dans la barre), lignes pleine largeur, état sous le texte |
| Hauteur de page (desktop) | ≈ 3 écrans | 1 698 px pour 12 réglages, sans débordement en clair, sombre, tablette ou mobile |

La logique fonctionnelle UX5 est inchangée : entrées du serveur, permissions, rubriques, recherche, états, avancé, liens de retour. Les sélecteurs de recette sont conservés.
Libellés : « Profil de l'entreprise » et « Sites, services & postes » (fin de la répétition rubrique › ligne) ; rubriques « Abonnement », « Automatisations ».

Captures : `before` = `docs/bmad/ux5-781/j-desktop-01-hub.jpg` ; `after-settings-{desktop,tablet,mobile}-{light,dark}.jpg`.

## 3. Conventions du design system

Voir `06-ui-policy.md`. Composants : `AppPageHeader`, `AppSettingsGroup`, `AppSettingRow`, `AppStatusText`, `AppSurface`, `AppFormSection` (`resources/js/components/app/`). Règles : onglets soulignés et masqués quand il n'y en a qu'un ; états vides en texte ; alertes réservées à ce qui bloque ; VCard seulement quand elle porte un sens ; jamais de code brut à l'écran.

## 4. Pages améliorées

| Page | Changement |
|---|---|
| Réglages | refonte complète (§2) |
| Profil entreprise | en-tête de page ; une surface, quatre sections « intitulé + explication / champs » ; onglet unique masqué ; titres de carte non tronqués |
| Abonnement | en-tête de page ; onglets soulignés ; titres de carte non tronqués |
| Paramètres RH | en-tête commun ; « France », « Logistique » au lieu des codes (libellés ajoutés au contexte existant de `/workforce/settings`) ; « Enregistrer » à côté du champ ; onglets soulignés |
| Organisation | en-tête commun ; onglets soulignés |
| Équipe | filtres alignés à gauche, largeurs bornées |
| Fiche | « Prochain créneau » ; statut de pointage traduit |
| Mon activité | 5 états vides en texte (au lieu d'alertes) ; une seule pastille par document |

## 4 bis. Correction de navigation : Modules

**Principe produit** : Réglages = configurer l'entreprise ; Modules = découvrir et gérer les produits additionnels Leezr.

- Nouvel espace d'intention `modules` (`NavGroupRegistry`, entre Activité et Réglages) ; l'entrée `modules` du manifeste `core.modules` y est rattachée (même permission `modules.manage`, même route). Elle sort donc du hub Réglages (plus de doublon) ; l'administration avancée ne contient plus que le Journal d'audit.
- Page `/company/modules` : en-tête commun, résumé « N activé(s) · N inclus · N à découvrir », onglets soulignés « À découvrir · Inclus dans votre formule · Activés · Tous », compteurs discrets. Cartes produit conservées (un module est un objet). Logique inchangée : activation, devis, désactivation, période de grâce, contact commercial, règles de souscription et Billing intacts.
- Accès vérifié : entrée et page pour le propriétaire ; ni entrée ni accès pour Dispatcher, RH sans paie, Manager, Salarié (`result-modules-access.json`). Desktop et tiroir mobile vérifiés (`after-modules-desktop-light.jpg`, `after-modules-mobile-light.jpg`, `after-modules-mobile-menu.jpg`).
- Tests alignés sur la nouvelle règle : `Ux0IntentNavigationTest` (ordre des espaces), `ux0-intent-navigation.spec.js`, `ux5-settings.spec.js` (Modules hors hub), `company-module-toggle.spec.js` (libellé d'onglet, non rejoué : il active et désactive des modules).

## 5. Comparatif ergonomique

Aucun clic ajouté. Sur Réglages, la navigation latérale ajoute un accès direct à chaque rubrique (1 clic) en plus de la recherche ; ouvrir un réglage reste à 1 clic. Le profil, l'abonnement et les paramètres RH gardent les mêmes champs et actions. Hauteurs mesurées (mobile) : Mon activité 2 358 → 2 240 px ; Profil 2 351 → 2 405 px (explications de section ajoutées).

## 6. Tests et recette

| Niveau | Résultat |
|---|---|
| Build final | propre |
| Suite PHPUnit complète (une fois) | 4303 réussis, 12 ignorés, **3 échecs historiques** (`ControllerSizeInvariantTest`, inchangés) |
| Régression navigateur UX0→UX5 + M6.A | 44 tests : 41 au premier passage ; UX5 propriétaire échouait (sélecteur d'état perdu par la refonte, rétabli → vert) ; UX2 et UX3 mobile n'avaient qu'un refus 429 sur `/api/login` (limite de connexions après une longue série) → verts rejoués isolément |
| Profils (UX0, UX2, UX3, UX4, UX5) | aucune action ni entrée nouvelle ; pages hors droits toujours refusées |
| Captures | 14 pages × desktop/mobile en clair, 6 pages en sombre, Réglages en tablette ; aucun débordement (`report-after.json`) |

**Non rejoués dans ce lot** (et déclarés comme tels) : parcours « exécutés » d'UX1, UX2 et UX3 — ceux d'UX2 et UX3 génèrent des contrats, documents immuables qui resteraient en base ; parcours salarié de Bob (ajoute une demande annulée) ; première prise en main, signature sandbox, notifications et multi-company de bout en bout. Ils ont été exécutés lors de leurs lots respectifs (ADR-776 à 781).

## 7. Défauts résiduels et améliorations encore nécessaires

1. Accueil : cartes d'indicateurs hautes pour un seul chiffre ; graphiques à revoir.
2. Mon activité : quatre cartes d'indicateurs empilées sur mobile ; cartes « Mon planning », « Mon contrat » encore en VCard.
3. Abonnement : alerte de paiement en deux colonnes déséquilibrées ; 5 onglets dont « Abonnement » et « Vue d'ensemble » proches.
4. Documents : tableau « À traiter » en défilement horizontal sur mobile ; carte « À suivre » redondante avec les onglets.
5. Paramètres RH (onglets temps, contrats, signature), types de documents, types d'absence : non refondus.
6. Planning desktop : grille dense (palette de modèles, couverture).
7. Drawers et dialogues : non harmonisés dans ce lot.
8. Le socle n'est appliqué qu'aux pages listées ; les autres pages gardent leurs en-têtes historiques.
9. Modules : noms et descriptions des modules en anglais (données du catalogue Platform) ; cartes « En préparation » encore proposées à la découverte.
