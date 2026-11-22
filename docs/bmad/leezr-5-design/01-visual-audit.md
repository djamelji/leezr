# Leezr 5.0 — 01 · Audit visuel et fonctionnel

> Méthode : navigation réelle (Chrome sans interface) sur le clone marqué `leezr_audit842`, code actuel (`2284e887` + build du jour).
> Deux profils : **super-admin Platform** (72 pages, 9,7 min) et **propriétaire Company** de la société 1, Logistique (56 pages, 7,2 min).
> Le parcours clique chaque menu, chaque onglet et chaque lien de détail, sans jamais cliquer un bouton qui écrit.
> L'inventaire des routes de l'audit 4.2 a été réutilisé tel quel.
>
> Mesures page par page : [`01-visual-audit-annexe.md`](01-visual-audit-annexe.md), 128 lignes, chacune liée à sa capture.

## 1. Ce que disent les chiffres

| Mesure | Platform | Company | Lecture |
|---|---|---|---|
| Pages explorées | 72 | 56 | — |
| Erreurs JavaScript ou API | 0 | 0 | stable techniquement (acquis 4.2) |
| Débordement horizontal sur mobile | 0 / 10 | 0 / 29 | les fondations responsives tiennent |
| Pages sans titre de page | **15** | **7** | l'utilisateur ne sait pas où il est |
| Titres identiques sur des pages différentes | « Aperçu facturation » × **14**, « Messagerie » × 7 | « Planning & temps » × 7, « Documents » × 7, « Abonnement & factures » × 7 | le titre ne change pas avec l'onglet |
| Pages à 8 onglets ou plus | **23** | 4 | navigation par onglets saturée côté Platform |
| Boutons-icônes sans libellé | **560** | 137 | accessibilité et compréhension (icônes muettes) |
| Pages à 2 bandeaux d'alerte ou plus | 4 | 5 | le haut de page sert d'écran d'information |
| Violations d'accessibilité graves | 33 | 32 | `button-name`, `color-contrast`, `aria-*` |

**Verdict.** Leezr 4.2 est **stable mais pas encore désirable**. Aucune page ne casse, mais la plupart se ressemblent : un titre gris, une rangée d'onglets, des cartes blanches de hauteurs différentes, puis un tableau. Ce qui compte pour l'utilisateur n'est presque jamais mis en avant.

## 2. Diagnostic des écrans clés

### Platform

| Écran | Constat (capture) | Gravité produit |
|---|---|---|
| **Cockpit** `/platform/dashboard/overview` ([capture](captures/current/shots/p-superadmin/platform_dashboard_overview.jpg)) | <ul><li>Titre « Cockpit » sans contexte, puis une section intitulée « Widgets du tableau de bord » : vocabulaire technique.</li><li>Le MRR, chiffre n°1, est relégué sous la courbe en petit texte.</li><li>Le donut des offres occupe une demi-largeur pour 2 valeurs.</li><li>La carte « Facturé ce mois » est vide à 70 %.</li><li>Le titre « Paiements échoués (7 derni… » est tronqué.</li><li>Les 4 cartes du bas ont toutes des hauteurs différentes.</li><li>Aucune santé plateforme visible.</li><li>Le bouton flottant du personnaliseur Vuexy (engrenage) chevauche le contenu.</li></ul> | **élevée** — c'est la première impression du propriétaire |
| **Facturation › Vue d'ensemble** ([capture](captures/current/shots/p-superadmin/platform_billing_overview.jpg)) | <ul><li>Un bandeau d'explication permanent, suivi de 3 bandeaux d'alerte empilés, avant le premier chiffre.</li><li>8 tuiles KPI de largeurs inégales, aux libellés coupés sur 3 lignes, avec une mini-courbe qui sort de sa tuile.</li><li>Le bouton « Paramètres » paraît désactivé.</li><li>11 onglets ; le titre reste « Aperçu facturation » sur les 14 sous-pages.</li></ul> | **élevée** |
| **Entreprises** ([capture](captures/current/shots/p-superadmin/platform_companies.jpg)) | <ul><li>5 tuiles KPI de 170 px de haut pour des nombres seuls (« 0 Suspendues »).</li><li>Filtres en double : boutons rapides **et** listes déroulantes.</li><li>Une colonne « slug » technique est visible par défaut.</li><li>« Nouvelle société » est noyée dans la barre de filtres.</li></ul> | moyenne |
| **Fiche entreprise** ([capture](captures/current/shots/p-superadmin/platform_companies_108.jpg)) | <ul><li>Le nom affiché est l'e-mail du propriétaire (aucun garde-fou de nommage).</li><li>La carte d'identité occupe un tiers de l'écran, sous un grand avatar « P ».</li><li>La synthèse ne montre que 3 tuiles et une liste de modules, laissant 70 % de vide.</li><li>Les onglets défilent horizontalement (« Abonner… » coupé).</li></ul> | moyenne |
| **Réglages › Thème** | le titre de page est un échantillon de typographie (« Heading 1 ») ; **Maintenance** affiche « We'll be back soon! » en anglais comme titre | faible, mais visible |

### Company

| Écran | Constat (capture) | Gravité produit |
|---|---|---|
| **Accueil** `/home` ([capture](captures/current/shots/c1-owner/home.jpg)) | <ul><li>« Bienvenue, Djamel » en titre gris : aucune identité, aucune information du jour.</li><li>Le dirigeant voit d'abord **son propre** créneau et **son** pointage, comme un salarié.</li><li>Trois tuiles « 0 » (congés, livraisons).</li><li>« À traiter » est une bonne liste mais visuellement plate.</li><li>La vue d'ensemble (widgets) commence sous la ligne de flottaison.</li><li>Rien de propre au métier Logistique (livraisons du jour, chauffeurs).</li></ul> | **élevée** |
| **Personnes** ([capture](captures/current/shots/c1-owner/company_people.jpg)) | voir §3 | **élevée** |
| **Planning** ([capture](captures/current/shots/c1-owner/company_workforce_planning.jpg)) | <ul><li>**30 boutons** visibles et 4 niveaux de navigation empilés (menu, onglets, vues, par salariés/postes).</li><li>4 actions de même poids en en-tête (« Préférences », « Exporter », « Nouveau créneau », « Préparer ma semaine »).</li><li>Le compteur « 41 à couvrir » est en petit rouge.</li><li>Une équipe « Sans équipe · 50 » est repliée en bas.</li></ul> | moyenne (outil puissant, densité excessive) |
| **Modules** ([capture](captures/current/shots/c1-owner/company_modules.jpg)) | <ul><li>Cartes catalogue de hauteurs inégales.</li><li>3 actions par carte, dont « Désactiver » en rouge au même niveau que « Configurer ».</li><li>Pastille « Configuré » grise, qui ressemble à un état désactivé.</li><li>Aucune mise en avant de ce qui manque ou de ce qui est recommandé pour le métier.</li></ul> | moyenne |

## 3. Cas concret — `/company/people` et le bouton « Outils »

**Ce que fait le tiroir.** « Outils › Champs du profil » ouvre `_ProfileFieldsSettings.vue`. Ce code a été extrait tel quel de l'ancienne liste `/company/members` en ADR-802 (ADR-164/168). Il appelle `GET/POST /api/company/field-activations` et `/company/field-definitions` pour :
- activer ou désactiver un champ ;
- **rendre un champ obligatoire pour toute la société** (`required_override`) ;
- créer des champs personnalisés.

**Réponses aux 8 questions**

1. **Encore pertinent dans Company ?** Partiellement. Activer un champ et créer un champ propre à la société restent légitimes ; leur bon emplacement est Réglages › Catalogues. Rendre un champ obligatoire pour tous ne l'est plus.
2. **Mêmes champs que le nouveau catalogue ?** Oui. Même table `field_activations`, mêmes définitions que `Réglages › Catalogues › Champs` (ADR-819), qui appelle les **mêmes API**.
3. **Duplication des sources de vérité ?** **Oui, démontrée.** L'obligation d'un champ se décide aujourd'hui à deux niveaux concurrents :
   - `required_override` (tiroir Outils), lu par `FieldValidationService:85` et par `CompanyRequirementResolver:46-47`, qui le qualifie lui-même d'« activation historique » ;
   - les **exigences par poste** (ADR-792/817), éditées dans `Informations & documents demandés` et dans `Organisation › Postes › [poste]`.

   Catalogues affiche : « rien ne devient obligatoire ici, l'obligation se règle par poste ». Le tiroir Outils contredit cette règle dans la même application.
4. **Le drawer sert-il à autre chose ?** Non. Le menu « Outils » ne contient qu'une seule entrée (ADR-802 a déjà déplacé « Gestion RH » en onglet).
5. **Supprimer, remplacer, déplacer ?** **Déprécier le tiroir**, sans rien supprimer d'abord :
   - remplacer l'entrée « Outils » par un lien « Champs des fiches → Catalogues » dans le menu « ⋯ » de la page ;
   - migrer les `required_override = true` existants en exigences « tous les postes » (même effet, une seule source) ;
   - ensuite seulement, retirer la case « obligatoire » et le tiroir.
6. **Ce qui reste personnalisable par Company :**
   - activer ou désactiver un champ du catalogue ;
   - créer un champ propre à la société ;
   - exiger un champ ou un document **par poste** ;
   - l'ordre d'affichage.
7. **Ce qui relève de Platform ou du JobDomain :**
   - la définition des champs standard (type, validation, confidentialité) ;
   - les champs recommandés par métier ;
   - les champs requis à la création d'une société (ADR-835) ;
   - les champs légaux d'un marché (Markets, jamais `if('FR')`).
8. **Page Personnes cible :** prototype [`company/design-lab/people`](captures/proto/company-people-desktop.jpg) et sa [vue rapide](captures/proto/company-people-quickview-desktop.jpg) :
   - un titre qui dit l'effectif ;
   - 4 indicateurs (effectif, accès, intégrations, documents manquants) ;
   - des **segments avec compteurs** (Tous · À traiter · Sans accès · En intégration · Fiche incomplète), à la place de deux listes déroulantes ;
   - par personne : service, accès, **taux de complétude du poste** et **prochaine action** (Inviter, Relancer, Compléter) ;
   - un **tiroir de consultation rapide** (complétude, accès, semaine), à la place d'une navigation vers la fiche pour une simple vérification ;
   - trois vues : liste, cartes par service, organigramme (proposé, données déjà présentes).

**Autres défauts de la page actuelle.**
- Le titre « Équipe » ne correspond pas au menu « Personnes ».
- Le bandeau « 22 membre(s) avec un profil incomplet » est générique.
- « Actif / CDI » se répète sur chaque ligne.
- Des lignes « Nom non renseigné » ne proposent aucune action pour corriger.
- La colonne Poste est souvent « — », alors que le poste est la clé des exigences.

## 4. Défauts transverses (toutes pages)

| # | Motif | Exemples | Décision de principe |
|---|---|---|---|
| T1 | **Bandeau d'explication permanent** en haut de page | Facturation, Documents, Messagerie | supprimer au profit d'un sous-titre et d'une aide contextuelle (06 §1) |
| T2 | **Titre figé** quand l'onglet change | 14 « Aperçu facturation » | titre = onglet courant, fil d'Ariane au-dessus |
| T3 | **Onglets à 8 ou plus** | Facturation 11, Paramètres 12, IA 11 | grouper (5 groupes max) ou passer à une navigation latérale de section |
| T4 | **Tuiles KPI géantes pour un nombre** | Entreprises, Facturation, Accueil | tuile KPI à profil de taille propre (04 §3) |
| T5 | **Hauteurs de cartes non alignées** | Cockpit, Modules, Fiche entreprise | grille à lignes de 80 px et familles de tailles (04) |
| T6 | **Actions de même poids** | Planning (4), Modules (3 par carte) | 1 principale, 1 secondaire, le reste dans « ⋯ » (06 §2) |
| T7 | **Couleur sans sens** | « Configuré » gris, « Paramètres » désactivé d'apparence | table sémantique unique (06 §3) |
| T8 | **Boutons-icônes muets** | 697 occurrences | libellé accessible obligatoire et infobulle |
| T9 | **États vides en zéros** | « 0 Congés », « 0 Mes livraisons » | un état vide dit quoi faire (06 §5) |
| T10 | **Personnaliseur Vuexy flottant** (réservé aux admins autorisés à régler le thème, `canCustomizeTheme`) | l'engrenage chevauche titres et onglets sur chaque page Platform | le déplacer dans Réglages › Thème ou dans le menu du profil ; il reste réservé à cette permission |

## 5. Ce qui est bon et doit être conservé

- Stabilité : 0 erreur sur 128 pages, aucun débordement mobile.
- La liste « À traiter » de l'accueil Company : bon contenu, mauvaise mise en forme.
- La logique d'onglets dans l'adresse (ADR-475), les permissions par onglet (M3) et l'accès en lecture seule.
- Le **moteur de présentation par taille de conteneur** (ADR-748/750) : 46 des 47 widgets l'utilisent déjà (voir 04).
- La couche « Leezr Premium » (`.lzr-card--flat/raised/active`, ADR-741) : bien conçue, mais **utilisée par 2 fichiers seulement**.
