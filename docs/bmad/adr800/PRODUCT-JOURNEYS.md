# ADR-800 : parcours produit, actuels et cibles

> **Méthode.** Les écrans, étapes et API viennent du code (`definePage`, composants montés, appels `$api` / `$platformApi`, `route:list`), au SHA `c3f468fa`.
>
> Les « étapes » sont des **étapes d'écran comptées dans le code** (changement d'écran, d'onglet ou de dialogue, ou validation). Ce **ne sont pas des clics mesurés** : aucune télémétrie d'usage n'existe (`funnel-track` n'est pas branché).
>
> Les valeurs cibles sont des **estimations de conception**, à valider en recette.

## A. Platform crée un JobDomain

- **Actuel.**
  - Parcours : Catalogue (`platform/catalog/:tab`) → onglet JobDomains (`_CatalogJobdomains`) → Créer (`POST /platform/jobdomains`) → fiche `/platform/jobdomains/:id`, en six onglets (ADR-799).
  - **4 étapes.**
  - Permission : `manage_jobdomains`.
- **Inutile.** Rien de bloquant. La fiche n'a pas d'entrée de menu propre : elle est rangée dans le Catalogue.
- **Cible.** Inchangé : 4 étapes. Garantie conservée : la clé est unique et contrôlée côté serveur.

## B. Platform configure les recommandations initiales

- **Actuel.** Sur la fiche JobDomain :
  - Modules : `PUT /jobdomains/{id}` `default_modules` ;
  - Rôles, Champs & documents, Planning : Modifier → `_PresetEditorDialog` → Enregistrer (`/presets/{kind}/draft`) → Publier (`…/publish`, qui lie au domaine) ;
  - **Organisation** : sélection d'un preset publié (`preset-recommendations`). Pour le **modifier**, il faut quitter la fiche pour `/platform/organization-presets/:id` (autre page, autre menu, publication séparée) puis revenir ;
  - Cadre conventionnel : onglet Général ;
  - **Modèles de contrat** : autre page (`/platform/contract-templates`), autre menu ;
  - **Overlays de marché** : plus aucune interface depuis ADR-799.
- **Étapes.** Pour un domaine complet : 1 (modules) + 3×3 (presets) + 6 (organisation, aller-retour) + 3 (contrats) + 1 (convention), soit **≈ 20 étapes sur 3 pages**.
- **Inutile ou en double.**
  - Le catalogue Presets permet aussi de publier, mais **sans** lier au domaine (D8).
  - Organisation et contrats sont hors de la fiche.
  - Les rôles **réellement appliqués** sont encore ceux de `default_roles`, invisibles (M2).
- **Cible.**
  - Tout se fait dans la fiche. L'onglet Organisation s'édite en place, comme les autres familles. Un onglet « Documents » couvre les modèles de contrat recommandés, ou à défaut un lien contextuel.
  - Le catalogue Presets passe en lecture.
  - **≈ 14 étapes sur 1 page.**
- **Garanties.** Versionnement, concurrence (`revision`), refus d'archiver un preset recommandé, aucun effet sur les sociétés existantes.

## C. La société s'inscrit et choisit son activité

- **Actuel.**
  - `/register` en **6 étapes** : activité, plan, options, société, compte, récapitulatif.
  - API : `/public/*`, `POST /register`.
- **Inutile.** Rien d'évident. L'étape « options » propose des modules **avant** que la société sache ce qu'elle y trouvera.
- **Cible.**
  - 5 étapes. Les options deviennent une recommandation pré-cochée issue de l'activité (`default_modules`), modifiable dans le récapitulatif.
  - À valider côté Billing, qui est hors périmètre.

## D. La société reçoit sa configuration minimale

- **Actuel.**
  - `RegisterCompanyUseCase` appelle `JobdomainGate::assignToCompany` : modules, champs, documents, **rôles issus de `default_roles`**.
  - Rien n'est montré à la société ; aucune confirmation.
  - Le chemin versionné (`VersionedCompanyInitializer`, qui permet de confirmer les rôles) est désactivé par flag.
- **Étapes.** 0 étape visible.
- **Inutile ou risqué.** Deux moteurs (M1), trois sources de rôles (M2).
- **Cible.**
  - Une seule source : presets versionnés recommandés, avec repli sur l'historique tant qu'aucun preset n'est publié.
  - Affichage « Voici ce qui a été préparé pour vous » dans l'accueil, avec un lien vers l'assistant de chaque module.
  - **0 étape obligatoire, 1 écran informatif.**
- **Garanties.** Aucune application silencieuse d'un preset à une société existante ; parité mesurée (`CreationParity`).

## E. La société active un module

- **Actuel.**
  - `/company/modules` → Activer → dialogue de devis si payant (`/modules/quote`) → `PUT /modules/{key}/enable`.
  - **2 à 3 étapes.**
  - Permission : `modules.manage`.
- **Inutile.** Rien. En revanche, **rien ne suit** : ni droits, ni réglages.
- **Cible.** Activer → devis si payant → **ouverture automatique de l'assistant du module** (F à H).
- **Garanties.** Contrôle commercial ADR-732, dépendances transitives, entitlements.

## F. L'assistant du module demande « qui peut faire quoi »

- **Actuel.**
  - **Absent.**
  - Il faut aller dans Collaborateurs → onglet Rôles → choisir un rôle → cocher dans `_PermissionMatrix` (mode simple par bundles) → enregistrer, **puis recommencer pour chaque rôle**.
  - Pour 3 rôles : **≈ 12 étapes**.
  - L'étape `roles` de l'assistant Workforce liste les rôles sans rien proposer.
- **Cible.**
  - Une étape « Accès » dans l'assistant : pour chaque **capacité du module** (bundle), qui peut le faire ? Un sélecteur multi-rôles par capacité, pré-rempli par le preset `rbac` recommandé.
  - **1 écran, ≈ 3 étapes.**
  - Le mode avancé (matrice par permission) reste disponible.
- **Garanties.** `syncPermissionsSafe` (pas de permission `is_admin` hors rôle administratif). Ne pas fusionner les permissions de population (`sensitive_read`, `view_confidential`, `leave_view_all`).

## G. L'assistant demande les paramètres propres au module

- **Actuel.** Les paramètres sont dispersés :
  - Workforce → Paramètres (`general`, `worktime`, `contract`, `signature`) ;
  - assistant Workforce (organisation, congés, temps de travail) ;
  - Documents → Réglages ;
  - Planning (modèles, import des presets) ;
  - `company/modules/[key]` : un seul panneau existe, celui du thème.

  `settingsRoute` vaut `null` pour les 51 modules.
- **Cible.**
  - Chaque module déclare ses étapes de réglage dans son manifest (réutilisation de `settingsPanels`). L'assistant commun les enchaîne.
  - L'écran de paramètres permanent **réutilise les mêmes composants**, par exemple `_WorkingTimeSetup` (D7).

## H. La société confirme ; configuration et droits sont appliqués

- **Actuel.**
  - Pas de confirmation globale. Chaque écran enregistre de son côté.
  - Seul `working-time-setup` est « tout ou rien » (ADR-799).
- **Cible.**
  - Un récapitulatif « droits et réglages qui seront appliqués », avec un enregistrement transactionnel par module.
  - Audit `module.configured`, et un état `configured_at` par module et par société, qui distingue « activé » de « prêt ».

## I. La société modifie ensuite ses accès et réglages

- **Actuel.**
  - **Accès** : Collaborateurs → Rôles, plus une 2ᵉ porte pour le thème (M7).
  - **Réglages** : hub `/company/settings` (ADR-781), `profile/:tab`, `workforce/settings/:tab`, Documents → Réglages, et le planning.
- **Cible.**
  - Le hub Réglages liste **les modules** ; chaque entrée rouvre l'assistant du module en mode édition.
  - Les accès par module se règlent dans l'assistant, la vue transverse reste Collaborateurs → Rôles.

## J. La société désactive puis réactive un module

- **Actuel.**
  - `GET /modules/{key}/deactivation-preview` → `PUT /modules/{key}/disable`.
  - Le moteur retire les raisons d'activation et le cache, et désactive les dépendants orphelins (`cleanupOrphans`). Lecture du code : **aucune suppression de données métier** (non exécuté pendant l'audit).
  - La réactivation repasse par E. Les types de congés sont ré-ensemencés par listener ; leur idempotence est à vérifier.
- **Cible.**
  - Inchangé côté données.
  - À la réactivation, l'assistant s'ouvre **pré-rempli** avec les réglages et droits conservés, en simple confirmation.
- **Garanties.** Avoir ou crédit Billing (`AddonCreditListener`) sans changement.

## K. La société recrute un salarié et lui demande ses informations et documents

- **Actuel.**
  - Collaborateurs → panneau Intégration → Inviter (`_OnboardingInviteDialog`, proposition `/onboarding-files/proposal`) → envoi.
  - La recrue passe par le portail `/onboarding` : session, code, vérification, saisie, documents, envoi.
  - Retour côté société : revue (`_OnboardingReviewDialog`, `items/preview`) → acceptation.
  - Côté société : **≈ 6 étapes**.
- **Inutile.**
  - Rien de redondant.
  - La relance antivirus (`rescan`) n'a pas de bouton.
  - Les pièces demandées dépendent de 3 moteurs d'exigences (M4).
- **Cible.** Inchangé, plus un bouton de relance, et une seule source « pièces demandées » une fois M4 tranché.

## L. La société configure et utilise Planning et Pointage

- **Actuel.**
  - **Réglage** du temps de travail : à deux endroits (D7).
  - **Organisation** : `workforce/organization/:tab` ou étape de l'assistant (même composant `_OrganizationPresetCard`).
  - **Modèles** : dans le planning (`week-templates`, `templates`, `activity-presets`) et import depuis `jobdomains.workforce_presets` (M3).
  - **Usage** : `/company/workforce/planning` (contrôle `ShiftRuleValidator`), `/company/workforce/time` (cockpit), `/company/workforce/me` (pointage).
- **Inutile.**
  - Deux écrans de règles.
  - Deux sources de presets de planning.
  - L'onglet `worktime` affiche des règles sans dire lesquelles sont contrôlées.
- **Cible.**
  - Un seul écran de règles (`_WorkingTimeSetup`) ; l'import des modèles se fait depuis le preset `planning` recommandé, proposé dans l'assistant.
  - Usage inchangé.
- **Garanties.**
  - Un seul moteur de contrôle (`ShiftRuleValidator`).
  - Les règles légales vérifiées restent toujours bloquantes.
  - Aucune contrainte nouvelle imposée aux sociétés existantes.

## Parcours redondants : synthèse

| Parcours en double | Occurrences |
|---|---|
| Éditer un preset (catalogue ↔ JobDomain) | 2 |
| Éditer un preset d'organisation | 3 entrées |
| Régler le temps de travail | 2 |
| Ouvrir la liste des personnes (`people` ↔ `members` ↔ `employees`) | 3 |
| Ouvrir les modèles de contrat | 3 entrées |
| Écrire les permissions d'un rôle (matrice ↔ thème) | 2 |

**6 parcours redondants prouvés.**
