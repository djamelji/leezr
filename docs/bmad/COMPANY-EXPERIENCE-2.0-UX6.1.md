# Company Experience 2.0 — UX6.1 : finitions produit et excellence visuelle (ADR-782)

> Base `2f2a2589` (la correction Modules est postérieure à `636d8aa0`) · DEV · aucune modification serveur dans ce lot, aucun moteur, aucune permission ; `@core` intact.
> Captures : `docs/bmad/ux6-782/before61-*`, `after61-*` (pages), `after61-drawer-*` (tiroirs), `report-after61.json`.

## 1. Modules (confirmation)

- « Modules » est une **entrée principale** du menu Company (espace d'intention `modules`, avant Réglages), visible avec `modules.manage` ; **absent du hub Réglages** (commit `2f2a2589`).
- Catalogue : en-tête commun ; résumé « N activé(s) · N inclus · N souscrit(s) (si payants) · N à découvrir » ; onglets « À découvrir · Inclus dans votre formule · Activés · Tous ». « Souscrit » lit `is_subscribed` (abonnement payant réel, ADR-621) : aucune règle Billing nouvelle, aucun flux d'achat nouveau.
- Recette : entrée desktop et tiroir mobile pour le propriétaire ; ni entrée ni page pour Dispatcher, RH sans paie, Manager, Salarié.

## 2. Pages améliorées

| Page | Avant | Après |
|---|---|---|
| Accueil | indicateurs ≈ 176 px pour un chiffre | rangée de grille 60 px sur l'accueil Company (≈ 136 px) ; Platform inchangé (option `rowHeight` de la grille, dispositions enregistrées intactes) |
| Mon activité | quatre cartes d'indicateurs (2×2 grandes cartes sur mobile) | `AppStatStrip` : une bande, 4 cellules (2×2 compactes sur mobile) |
| Abonnement | alerte de paiement en deux colonnes déséquilibrées | une colonne : titre, texte, actions (ou consigne support) dessous |
| Documents (mobile) | tableau « À traiter » en défilement horizontal | lignes : personne, pièce · date, état, actions (mêmes actions et droits) ; tableau conservé sur desktop |
| Planning (Semaine) | palette de modèles et détail de couverture ouverts avant la grille | repliés par défaut (synthèse « N à couvrir » visible, choix mémorisé) : les collaborateurs apparaissent au premier écran |

## 3. Tiroirs et dialogues

`AppDrawer` (socle) : en-tête Vuexy `AppDrawerHeaderSection` (titre + fermer), corps défilant, pied fixe « Annuler · action principale » (`app-drawer-actions`, boutons laissés dans leur formulaire), largeur bornée à l'écran.

| Parcours | État |
|---|---|
| Créer un créneau | `AppDrawer` |
| Demander une absence (responsable) | `AppDrawer` |
| Demander une absence (salarié) | `AppDrawer` ; ordre « Annuler · Envoyer » rétabli, bouton fermer ajouté |
| Type d'absence | `AppDrawer` ; plafond, report et ordre d'affichage sous « Plus d'options » |
| Lieu (site) | `AppDrawer` |
| Ajouter une personne | déjà sur `AppDrawerHeaderSection` (inchangé) |
| Déposer / demander un document, générer un contrat, faire signer | dialogues Vuexy (titre de carte + actions) déjà homogènes ; non migrés |

## 4. Écrans de réglage denses (présentation seule)

- Signature : trois sections « Prestataire et mode · Valeurs par défaut · Notifications », enregistrement aligné à droite.
- Temps de travail : pastille « À compléter » en double supprimée.
- Contrats : « Ville » n'affiche plus un code pays seul.
- Types de documents : la pastille « Obligatoire/Optionnel » n'est plus répétée à côté de l'interrupteur ; elle reste quand l'obligation est imposée.
- Types d'absence : options rares repliées. Aucun paramètre supprimé, aucune règle modifiée.

## 5. Recette exécutée

| Lot | Résultat |
|---|---|
| UX1 (8 profils, parcours exécutés avec registre, sans Workforce) | 9/9 |
| UX5 (propriétaire, écritures sur Beta Services, mobile/tablette/sombre, 8 profils, changement d'entreprise) | 5/5 (parcours propriétaire vert au 3ᵉ passage : délai puis refus réseau non reproduits) |
| Modules (entrée, accès refusé à 4 profils) | 2/2 |
| Planning (couverture repliable, ajout de tâche, navigation M6.A) | 7/7 |
| UX0 (8 profils, changement d'entreprise) | 9/9 (propriétaire rejoué isolément après refus 429 sur `/api/login`) |
| UX4 (responsable, salariée mobile, profils, sans Workforce, captures) | 6/6 hors Bob |
| UX2 et UX3 (profils, sans Workforce, mobile) | 12/12 |
| Tiroirs (desktop, mobile) | 2/2 |
| HTTP ciblés (notifications simulées, soldes, navigation, self-service, système de notifications) | 82/82 |
| Build | propre | Événements de paiement simulés : `Ux5PaymentNotificationAudienceTest` (client « Paiement effectué », Platform « Paiement encaissé », aucun paiement réel).

**Non exécutés (effets persistants)** : génération de contrat et signature sandbox (documents immuables, aucun environnement de recette isolé disponible), parcours exécutés UX2 et UX3, parcours salarié de Bob.

## 6. Défauts résiduels

1. **Identité employeur des contrats** : lit les colonnes d'adresse de l'entreprise, alors que le profil enregistre l'adresse dans des champs dynamiques ; l'en-tête des contrats peut donc manquer de ville et d'adresse. Défaut de données à arbitrer (touche la génération de contrats).
2. Accueil : graphiques et cartes « Heures travaillées » / « Conformité » non retravaillés.
3. Mon activité : cartes « Mon planning », « Mon contrat » encore en VCard.
4. Dialogues documentaires (dépôt, demande, contrat, signature) non migrés vers un gabarit commun.
5. Temps de travail : un bouton « Enregistrer » par règle (comportement d'enregistrement unitaire conservé).
6. Modules : noms et descriptions en anglais (catalogue Platform) ; modules « En préparation » proposés à la découverte.
7. Recette navigateur : un parcours UX5 instable (délai de chargement, puis refus réseau non reproduit) ; refus 429 sur `/api/login` en longue série.
