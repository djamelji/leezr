# Leezr 4.2 — 02 · Audit Platform (navigation réelle)

> Captures : sélection versionnée dans `captures/` (nommées `<profil>__<page>.jpg`, parcours `J*.jpg`) ; l'ensemble des ~900 captures reste en local dans `data/crawl/shots/` (non versionné, 44 Mo).

## Méthode et profils

- Navigation **réelle** dans un navigateur (Playwright, Chrome), sur la base de recette `leezr_audit842` : clics sur chaque entrée de menu, **clic sur chaque onglet**, ouverture des fiches découvertes depuis les listes, passage mobile 390 px, contrôle d'accessibilité axe (WCAG A/AA, gravité *serious/critical*), relevé des erreurs API/JS. Aucune action d'écriture n'a été lancée par le robot de navigation ; les écritures sont faites dans les **parcours** (J1, J5).
- Données : `data/crawl/crawl-p-*.json`, captures `data/crawl/shots/p-*/`, parcours `data/journeys.json`.

| Profil | Compte (fictif / local) | Permissions | Pages visitées | Résultat |
|---|---|---|---|---|
| Super-administrateur | `admin@leezr.com` | toutes | 140 + 40 (2ᵉ vague) | 0 erreur JS ; 1 module en 404 ; clés brutes dans les journaux d'e-mails |
| Gouvernance | `gov-mgr@leezr.com` | 10 | 60 | pages interdites → redirection silencieuse |
| Finance (lecture) | `finance-reader@leezr.com` | 2 | 23 | **13 réponses 403** évitables (tableau de bord, onglets Facturation) |
| Support | `support-only@leezr.com` | 1 | 3 | correct |
| Opérateur de création | `creator842@platform.test` (créé pour l'audit) | `create_companies` | 4 + parcours J1 | création complète réussie ; liste « mes sociétés » claire |

## Parcours complets joués

| ID | Parcours | Résultat |
|---|---|---|
| J1 | Opérateur de création : liste → « Nouvelle société » → Activité (Beta Services) → Société → Modules → Abonnement (Starter) → Administrateur → Initialisation → Récapitulatif → **Créer** → fiche « Mise en service » | **Réussi** (société 106 créée ; « Créée le … par Créa Audit » affiché) |
| J2 | E-mail d'activation (Mailpit isolé) → lien → mot de passe → première connexion | **Réussi** (« Bienvenue … 2/5 étapes ») |
| J5 | Ticket créé par une société → visible dans Platform › Communications › Support | **Réussi** |

## Constats (UXP)

| ID | Page / composant | Problème | Preuve | Impact | Gravité | Correction | Effort | Prio |
|---|---|---|---|---|---|---|---|---|
| UXP-01 | Entreprises (liste) | Le plan se change **directement dans la liste** (sélecteur par ligne) **sans confirmation ni aperçu**, et l'appel envoie toujours `interval: monthly` : un client annuel repasse en mensuel. | `_CompaniesTab.vue:503-514`, `companies.store.js:54` ; la suspension, elle, demande confirmation. | Changement commercial accidentel d'un client (prix, échéance). | **Élevée** | Retirer le sélecteur de la liste ; changement de plan uniquement depuis la fiche › Facturation, avec aperçu (`plan-change-preview`), périodicité conservée et confirmation. | S | P1 |
| UXP-02 | Fiche module `workforce_referral` | `GET /api/platform/modules/workforce_referral` → **404** (module déclaré dans le code, absent de `platform_modules` tant que la synchro n'a pas tourné) ; la page s'ouvre vide. | Crawl super-admin + gov-mgr. | Page cassée après tout ajout de module non synchronisé. | Moyenne | Fiche lisible depuis le manifeste même sans ligne en base + bandeau « synchronisation requise ». | S | P2 |
| UXP-03 | Tableau de bord, Facturation | Les widgets/onglets ne tiennent pas compte des permissions : un profil Finance déclenche 13 refus 403 (activité, funnel, coupons, plans, modules, revue tarifaire). | `crawl-p-finance.json`. | Écran à moitié vide, erreurs console. | Moyenne | Chaque widget/onglet déclare sa permission (même source que la nav) ; masqué ou remplacé par « Accès réservé ». | M | P2 |
| UXP-04 | Toutes les pages interdites | Une page non permise **redirige silencieusement** vers la première page permise (ex. gov-mgr : `/platform/companies/create` → Catalogue › Modules). | Crawl gov-mgr / creator. | L'opérateur ne comprend pas pourquoi il « atterrit ailleurs ». | Faible | Page 403 Platform explicite (permission manquante + qui contacter), comme côté Company. | S | P3 |
| UXP-05 | Facturation | **15 onglets** au même niveau (Tableau de bord, Abonnements, Factures, Paiements, Relances, Prélèvements programmés, Avoirs, Coupons, Portefeuilles, Forensics, Gouvernance, Grand livre, Recouvrement, Rapprochement, Revue tarifaire), défilement horizontal. | Capture `platform_billing_overview.jpg`. | Surcharge : l'usage quotidien (factures, paiements, relances) est noyé dans les outils d'expert. | Moyenne | 3 groupes : **Quotidien** (Tableau de bord, Abonnements, Factures, Paiements, Relances) · **Comptabilité** (Avoirs, Grand livre, Rapprochement, Prélèvements) · **Contrôle** (Gouvernance, Forensics, Recouvrement, Revue tarifaire, Coupons, Portefeuilles). | M | P2 |
| UXP-06 | Fiche entreprise | Informations **répétées** : panneau gauche et carte « L'entreprise » montrent tous deux métier, abonnement, propriétaire, marché, date. | Capture `platform_companies_1.jpg`. | Bruit, pas de hiérarchie. | Faible | Panneau = identité + actions ; centre = situation (mise en service, facturation, modules, alertes). | S | P3 |
| UXP-07 | Tableau de bord | Widget « 30 600,00 € » **sans titre** ; « 23 companies » (anglais) ; « Abonnements a valider » (accent) ; « Funnel inscription » (franglais). | Capture `platform_dashboard_overview.jpg`. | Indicateur incompréhensible. | Moyenne | Titre obligatoire par widget ; passe i18n. | XS | P2 |
| UXP-08 | Indicateurs | **MRR incohérent** : 174 € (Tableau de bord, Facturation) contre **261 € « MRR total »** (Entreprises). | Captures dashboard / billing / companies. | Chiffre clé non fiable aux yeux du propriétaire. | Moyenne | Une seule définition du MRR (read-model Billing) réutilisée partout ; libellé de la règle (HT, add-ons inclus ?). | S | P2 |
| UXP-09 | Messagerie › Journaux | Sujets affichés en **clés brutes** (`email.billing.invoice_created.subject`). | Crawl (rawKeys). | Journal illisible. | Faible | Résoudre la clé à l'affichage (même résolveur que l'envoi). | XS | P3 |
| UXP-10 | Entreprises (liste) | « 9 hours ago » (anglais), filtres tronqués (« Filtrer par sta… »), « Trial expire ». | Capture `platform_companies.jpg`. | Finition. | Faible | Dates relatives localisées, libellés courts, i18n. | XS | P3 |
| UXP-11 | Toutes les pages | **Boutons-icônes sans nom accessible** : jusqu'à 64 par page (Entreprises, Utilisateurs), 109 pages concernées au total (Platform + Company). | axe `button-name` (critical). | Inutilisable au lecteur d'écran ; infobulles absentes. | Moyenne | `aria-label` + `VTooltip` sur `IconBtn` (composant partagé : correction centralisée). | S | P2 |
| UXP-12 | Toutes les pages | Personnalisateur de thème Vuexy (engrenage flottant) visible par **tous** les profils, y compris l'opérateur de création. | Captures. | Outil de démonstration du template dans un produit. | Faible | Retirer en production (ou réserver à `manage_theme_settings`). | XS | P3 |
| UXP-13 | Menu Gouvernance | « Modèles de contrat » est une entrée de menu à part, alors que les autres catalogues (modules, métiers, presets, champs, documents, plans) sont des **onglets** de Catalogue ; les **Marchés** sont dans Paramètres › International. | Nav super-admin. | Le propriétaire cherche le même type d'objet à trois endroits. | Faible | Catalogue = tout ce qui se **publie** vers les sociétés (y compris modèles de contrat et marchés). | S | P3 |
| UXP-14 | Fiche module | 7 à 8 onglets (Modules, Membres, Activité, Fonctionnalités, Dépendances, Permissions, Commercial, Avancé) dont plusieurs vides pour un module simple. | Crawl. | Navigation longue pour une information courte. | Faible | Onglets conditionnels (masqués si vides). | S | P3 |
| UXP-15 | Accessibilité | `color-contrast` (37 pages), champs sans libellé (`label` 28, `aria-input-field-name` 25), barres de progression sans nom (15). | axe. | Lisibilité et lecteurs d'écran. | Faible | Passe accessibilité ciblée sur les composants partagés. | M | P3 |

## Bons points

- Menu **construit depuis les manifestes** et filtré par permission : chaque profil voit exactement ses rubriques (gouvernance 3 entrées, finance 2, support 1, opérateur de création 1).
- L'assistant « Nouvelle société » (ADR-834/835) se parcourt d'un bout à l'autre ; écran « Sociétés que vous avez créées » clair (titre, explication, action principale, état vide utile).
- Aucune erreur JavaScript, aucune erreur serveur (5xx), **aucun débordement horizontal à 390 px** sur les 43 pages de menu testées en mobile.

## Notes par section (sur 100)

Critères (20 pts chacun) : **Clarté** (objectif compris en 5 s), **Action** (action principale évidente, retours), **Fiabilité** (aucune erreur, chiffres cohérents), **Permissions** (rien d'affiché sans droit, refus expliqués), **Finition** (langue, accessibilité, mobile). Notes = jugement de l'auditeur à partir des preuves ci-dessus.

| Section | Clarté | Action | Fiabilité | Permissions | Finition | **Note** | Principaux constats |
|---|---|---|---|---|---|---|---|
| Tableau de bord | 15 | 14 | 13 | 10 | 11 | **63** | UXP-03, 07, 08 |
| Messagerie | 15 | 15 | 17 | 16 | 12 | **75** | UXP-09 |
| Entreprises (liste + fiche) | 16 | 12 | 13 | 17 | 11 | **69** | UXP-01, 06, 10, 11 |
| Nouvelle société (assistant) | 18 | 18 | 18 | 18 | 15 | **87** | (ADR-834/835) |
| Communications (support, documentation) | 16 | 16 | 18 | 17 | 13 | **80** | J5 réussi |
| Facturation | 13 | 14 | 13 | 10 | 13 | **63** | UXP-03, 05, 08 |
| Catalogue (modules, métiers, presets, champs, documents, plans) | 15 | 15 | 15 | 17 | 13 | **75** | UXP-02, 13, 14 |
| Gestion des accès | 16 | 16 | 18 | 18 | 10 | **78** | UXP-11 (64 boutons) |
| Paramètres | 15 | 15 | 18 | 17 | 13 | **78** | — |
| Opérations | 15 | 14 | 18 | 17 | 13 | **77** | — |
| **Moyenne Platform** | | | | | | **74** | |
