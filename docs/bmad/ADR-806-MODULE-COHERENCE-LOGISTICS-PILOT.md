# ADR-806 — Cohérence des modules et pilote Logistique (`logistics_shipments`)

- **Date** : 2026-11-16, DEV uniquement, base `a317170f`.
- **Périmètre** : 51 modules (25 Company, 26 Platform), à partir des inventaires ADR-800 et des changements ADR-803 → 805.
- **Méthode** : recoupement automatique **lecture seule** sur le code courant. Pour chaque permission déclarée, il compte les routes qui la vérifient (`company.access:use-permission`, `platform.permission`) et ses occurrences hors manifeste dans `app/`, `routes/`, `resources/js`. Il contrôle aussi les permissions des bundles, les dépendances et les traductions FR/EN/AR.
- Aucune Company réelle modifiée ; aucun droit historique changé ; aucun moteur ajouté.

## 1. Incohérences prouvées et corrigées

| # | Incohérence | Preuve | Correction |
|---|---|---|---|
| 1 | **Toutes les capacités s'affichaient en anglais.** Les traductions FR existaient (13 sur 42) mais étaient **illisibles**. | Les clés du catalogue contiennent des points (`shipments.operations`) : `te('permissionCatalog.bundles.shipments.operations.label')` découpe le chemin à chaque point et renvoie toujours `false`. Vérifié avec vue-i18n 11.1.12 | Composable unique `useCatalogText` : `tm(groupe)[clé littérale]` + `rt`, avec repli sur la locale `en` puis sur le manifeste. Il remplace les 4 consommateurs : assistant, `ModuleCapabilityAccess`, aperçu, `_PermissionMatrix` Company/Platform |
| 2 | 29 capacités Company sans traduction, 42 sans arabe | Audit | 42 capacités traduites FR/EN/AR (libellé + description), relues contre leurs permissions réelles |
| 3 | **Faille : un chauffeur pouvait changer le statut de n'importe quelle expédition.** | `shipments.delivery` contient `shipments.manage_status`, qui seul gardait `PUT /shipments/{id}/status` (sans contrôle d'affectation). Test : 200 avant la correction | Cette route exige en plus `shipments.view`. Le chauffeur passe par `PUT /my-deliveries/{id}/status`, qui vérifie l'affectation. Résultat : 403, état inchangé (PHPUnit + E2E) |
| 4 | « Gestion des documents RH » promettait « gérer les modèles, gérer les signatures » | `manage_templates` et `sign` ne gardent aucune route : les modèles sont gardés par `workforce_contract.*`, les signatures par `workforce_signature.*` | Description alignée sur l'API : générer pour les personnes visibles ; modèles → Contrats, signatures → Signatures |
| 5 | « Documents RH (collaborateur) » disait « ses documents » | Depuis ADR-805 le périmètre est soi **et** ses rapports directs | Description corrigée |
| 6 | Capacités **sans aucun effet** présentées comme utilisables | `shipments.administration` (`manage_fields`, `delete`) : 0 route, 0 écran. `documentation.access` : 0 vérification, le centre d'aide est ouvert à tous. `jobdomain.management` : `PUT company/jobdomain` répond toujours 422 (secteur immuable, ADR-134) | Drapeau existant `unwired` (ADR-804) : masquées en vue normale, signalées « sans effet » en mode avancé. **Aucune permission retirée** des rôles |
| 7 | Portée de `shipments.delivery` non déclarée (classée « collective ») | Le contrôleur filtre sur `assigned_to_user_id` | `scope: own` : rangée dans « Accès à ses propres données » |
| 8 | **Aucun accès à l'assistant pour un module en « Configuration historique ».** | La création d'une société (`JobdomainGate::assignToCompany`) active les modules inclus en écrivant directement les raisons d'activation, **sans événement** : statut `legacy`. La carte du module masquait la pastille d'état pour `legacy`, et un module sans panneau de réglages n'a pas de bouton « Configurer » | Pastille affichée aussi pour `legacy` (gris), lien vers l'assistant qui affichait déjà le texte « historique ». Aucun changement d'état ni de droit |
| 9 | Noms de modules en anglais : sélecteur Équipe → Rôles (« Company Settings »), assistant, boîte d'impact, page du module | Seule la page Modules lisait `moduleCatalog.*` | Même source partout (`moduleName`) ; noms arabes ajoutés pour les 25 modules Company |
| 10 | Bascule « Par capacité / Par rôle » illisible (libellés superposés) | Dans Vuexy, `VBtnToggle` est stylé pour des icônes : largeur forcée à 36 px (`_button.scss`) | Remplacé par des onglets pill (preset `DemoTabsBasicPill`), mêmes `data-test` |

## 2. Permissions et capacités toujours inertes (décision proposée)

Aucune n'a été supprimée : elles peuvent être attribuées à des rôles existants. Le retrait exige un plan de compatibilité (requête C1 bis ci-dessous, puis migration des pivots validée).

| Permission | Capacité | État | Décision proposée |
|---|---|---|---|
| `workforce.leave_adjust` | `workforce.leave_adjustment` (`unwired` depuis 804) | Le registre `GrantLeaveUseCase`/`StornoLedgerEntryUseCase` existe, sans route ni écran | Garder inerte. La brancher le jour où l'ajustement de solde est ouvert (extension, gelée par ADR-561) |
| `workforce_documents.manage_templates` | dans `workforce_documents.management` (avec 2 permissions réelles) | Doublon conceptuel de `workforce_contract.manage_templates` | **Retirer du bundle puis du catalogue** (lot dédié). Le retrait du bundle ne change aucun droit effectif ; seule la complétude « partielle/complète » affichée change |
| `workforce_documents.sign` | idem | Doublon de `workforce_signature.*` | Idem |
| `workforce.admin` | dans `workforce.hr_management` (10 permissions) | 0 vérification | Retirer du bundle et du catalogue, même lot |
| `shipments.manage_fields`, `shipments.delete` | `shipments.administration` (`unwired`) | Pas de champs personnalisés ni de suppression | Garder masquées tant que le module Expéditions n'est pas étendu ; sinon retirer |
| `documentation.view` | `documentation.access` (`unwired`) | Centre d'aide ouvert | Retirer, sauf décision de restreindre l'aide |
| `jobdomain.manage` | `jobdomain.management` (`unwired`) | Garde une action toujours refusée | Retirer avec la route `PUT company/jobdomain` (ADR-800 §2) |
| `manage_audience` (Platform) | `audience.management` | 0 route | Hors périmètre Company ; retirer ou brancher dans un lot Platform |

**Bundles qui embarquent les permissions d'autres modules.** `workforce.team`, `.hr_management`, `.manager` et `.operations` contiennent des permissions Planning et Congés. C'est voulu (rôles composites) et sans danger : les routes de ces modules restent gardées par l'activation. Attribuer `workforce.manager` sans le module Congés donne donc des permissions sans effet, jusqu'à l'activation. **Non modifié** : le changer redéfinirait des rôles existants.

## 3. Écrans, routes et fonctions : état réel

- **Pages Company** : toutes déclarent `meta.module`, sauf `my-space` (espace personnel, onglets conditionnés) et `settings/index` (redirection). Le garde du routeur bloque un module désactivé.
  - Les onglets de l'espace Documents et des réglages RH sont conditionnés côté client **et** serveur.
  - Aucun écran accessible avec son module désactivé.
- **Dépendances déclarées** : toutes résolues. Les permissions de bundles inconnues ne sont que les références volontaires du §2.
- **Fonctions disponibles sans écran** (revérifiées sur le code courant) :
  - signature `reset-key` ;
  - campagnes `launch`, `cancel`, `recipients` ;
  - revue des bulletins `split`, `regroup`, `period`, `preview`, `revoke` ;
  - `profile/disclosures` ;
  - `world-settings`, `metrics-export`, `dashboard/health`, `simulate`, `funnel-track` ;
  - Billing `checkout/status`, `billing-day`, `pay-now`.

  `rescan` a désormais un écran (`_OnboardingReviewDialog`). Classement inchangé par rapport à ADR-800 : prévues, à exposer ou déprécier sur décision. Aucune n'a été créée ici.
- **Modules opérationnels / prévus / obsolètes** :

| Statut | Modules |
|---|---|
| Opérationnels **avec assistant, Prêt vérifié serveur** | `workforce`, `workforce_leave`, `workforce_planning`, `workforce_documents`, `workforce_contract`, `workforce_signature`, **`logistics_shipments`** |
| Opérationnels sans assistant (cœur, ou sans décision d'accès propre) | 14 modules `core.*`, `workforce_payroll` (assistant non ouvert : paie gelée) |
| Prévus (`commercialStatus: preview`, non achetables, 0 route, 0 permission) | `logistics_tracking`, `logistics_fleet`, `logistics_analytics` |
| Obsolètes | aucun module ; les capacités inertes du §2 |

## 4. Pilote Logistique : `logistics_shipments`

**Étude**
- Le module est **réellement opérationnel** :
  - création ;
  - liste et fiche ;
  - affectation à un membre ;
  - flux de statut fixe (`Shipment::TRANSITIONS`) ;
  - « Mes livraisons » ;
  - priorités d'accueil ;
  - 8 routes gardées.
- **Aucun réglage exécuté par le serveur** : pas de schéma de réglages, pas de panneau, et un flux de statut non paramétrable.
- Le module est inclus par le métier `logistique` (non vendu) : aucune écriture de facturation.

**Parcours avant / après**

| | Avant | Après |
|---|---|---|
| Entrée | Aucune : le module est activé à la création, statut historique, aucune pastille ni bouton | Modules → pastille « Configuration historique » (ou « À configurer » après une réactivation) → assistant |
| Étapes | — | **Accès → Confirmation**, aucune étape de réglage inventée |
| Capacités | Anglais. « Administration » affichée alors qu'elle ne fait rien. « Livraisons » classée collective | « Opérations d'expédition » (collectif) et « Livraisons » (soi seul) en FR/EN/AR. Administration masquée, signalée en mode avancé. Rôle opérationnel refusé pour l'administration (politique existante) |
| Prêt | — | Obligatoire : accès décidé. Recommandé : un rôle autre que le propriétaire voit ses livraisons (`delivery_assigned`). Le serveur recalcule le statut : un changement d'accès rouvre la configuration ; un changement fait dans Équipe → Rôles donne « À revoir » |
| Désactivation | Aucune information | Impact : « N expédition(s) en cours restent conservées, mais personne (livreurs compris) ne pourra changer leur statut tant que le module est désactivé » (`continues: false`). Rien n'est supprimé, et les droits sont conservés à la réactivation |
| Chauffeur | Pouvait changer le statut de toute expédition | Seulement les siennes |

**Réutilisé** :
- `ModuleSetupService`, `CapabilityAccessService`, `MembershipSecurityPolicy` ;
- `ModuleReadiness`, `ModuleDeactivationImpact` ;
- l'assistant `_ModuleSetupWizard` ;
- `ModuleCapabilityAccess` (même composant qu'Équipe → Rôles) ;
- les écrans `shipments/*` et `my-deliveries/*` ;
- `ShipmentController`, `MyDeliveryController`, les cas d'usage `CreateShipment`, `AssignShipment` et `ChangeShipmentStatus` ;
- `DocumentsSetupReadiness::nonOwnerHolds`.

**Ajouté** : `ShipmentsSetupReadiness` (un fichier) et la déclaration `setup` du manifeste.

**Limite (choix existant, non modifié)** : la liste n'offre pas de bouton « Désactiver » pour un module **inclus** par le métier. La désactivation se fait par la même API `PUT /modules/{key}/disable`, qui affiche son impact.

## 5. Préparation PROD (lecture seule, non exécutée en PROD)

Fichier : `docs/bmad/adr806/prod-readonly-checks.sql`.
- Uniquement des `SELECT`, à jouer dans `START TRANSACTION READ ONLY … ROLLBACK` ou sur un réplica.
- Validé sur DEV (MySQL 8, `ONLY_FULL_GROUP_BY`).

| Contrôle | Requête | Résultat DEV | Procédure si non vide |
|---|---|---|---|
| C1 Documents RH après restriction ADR-805 | Rôles détenant `workforce_documents.view` sans `workforce.view` + `workforce.manage`, avec effectifs ; rôles qui génèrent sans `workforce.manage` | 1 rôle de recette (société 1, « RH (sans paie) », 1 membre, 33 documents personnels dans la société) | Pour chaque société, l'entreprise décide : ajouter « Gestion des collaborateurs » au rôle (Équipe → Rôles), ou accepter le périmètre restreint. Jamais automatiquement |
| C2 Congés sans `workforce_leave` | Sociétés avec Workforce actif sans Congés, avec leurs données de congés | 2 sociétés de recette (21, 22), 0 donnée | Si des données existent : activation du module sur décision commerciale (ADR-805 §8) |
| C3 Signatures sans prestataire réel | Prestataire par société, demandes ouvertes ; demandes ouvertes par prestataire | Société 1 : 12 demandes ouvertes, 24 au total par `internal` | En PROD, `internal` ne se résout pas : ces demandes ne peuvent pas aboutir. Les annuler explicitement avec l'entreprise, ou configurer un prestataire réel (secret webhook à vérifier dans l'environnement, pas en SQL) |
| C4 Dépendances des modules payants | (a) module actif sans son prérequis ; (b) module payant actif seulement comme prérequis ; (c) modules « preview » actifs | (a) **société 21 : Paie active sans Congés** ; (b) société 5 : Congés (19 €) actifs seulement via la Paie ; (c) aucun | (a) Incohérence à corriger avec la société : activer le prérequis (facturable) ou désactiver la Paie. (b) Revue commerciale, aucun changement d'abonnement silencieux |
| C5 Sessions Platform (ADR-801.1) | Migration jouée, colonnes nullables, répartition `user_id` / `platform_user_id` | Migration jouée (batch 60), colonnes conformes | Si la migration manque : la jouer (additive, aucune ligne modifiée), puis vérifier qu'aucune nouvelle session Platform ne renseigne `user_id` |

Aucun abonnement, paiement ni facture modifié.

## 6. Traductions

- **FR, EN, AR** :
  - les 42 capacités Company ;
  - les noms des 25 modules Company ;
  - les textes du parcours Expéditions (FR/EN, l'arabe repliant sur l'anglais comme le reste de l'assistant).
- **ES : non fourni.** L'application ne déclare pas l'espagnol : aucun `es.json`, absent du sélecteur de langue (`themeConfig.app.i18n.langConfig`). Ajouter une langue est une décision produit. Le mécanisme `useCatalogText` la prendra en charge sans code.
- L'arabe reste globalement partiel : 298 clés avant ce lot, repli sur l'anglais.

## 7. Tests

- `Adr806ModuleCoherenceShipmentsTest` (7 tests, 397 assertions). Il couvre :
  - assistant Accès seul ; aucun droit à l'activation ;
  - Prêt refusé avant l'accès ;
  - réouverture puis « À revoir » ;
  - statut `legacy` des sociétés existantes sans changement de droits ;
  - rôle opérationnel refusé ; membre sans `modules.manage` refusé (403) ;
  - chauffeur limité à ses livraisons (403 sur la route générale, 404 hors affectation, état inchangé) ;
  - exploitation 201/200 avec changements d'état ;
  - isolation multi-tenant ;
  - impact, désactivation et réactivation sans perte ;
  - capacités inertes = aucune route ;
  - traductions FR/EN/AR présentes et lues via `useCatalogText` ;
  - entrée assistant pour `legacy`.
- Régression ciblée : expéditions, permissions, RBAC, modules, assistants 803 à 805 — **530 verts**.
- **E2E** (Company fictive isolée, métier `logistique`, module activé comme à la création réelle) : **3/3**. Captures dans `docs/bmad/adr806/`.

  | Acteur | Action | Résultat |
  |---|---|---|
  | owner | assistant depuis « Configuration historique » | Prêt ; droits exacts en base |
  | exploitation | créer | 201, `draft` |
  | exploitation | assigner | 200, assigné en base |
  | exploitation | planifier | 200, `planned` |
  | chauffeur | Mes livraisons | sa seule expédition |
  | chauffeur | sa livraison → en transit | 200, `in_transit` en base |
  | chauffeur | statut d'une autre expédition | **403**, inchangée |
  | chauffeur | liste générale | 403 ; écran refusé par le routeur |
  | chauffeur 2 | livraison du chauffeur 1 | 404 |
  | owner | désactiver | impact « 2 expéditions », 200 |
  | chauffeur | après désactivation | 403 ; écran refusé |
  | owner | réactiver | 200 ; expéditions et droits identiques ; mobile sombre sans débordement |

  - Équipe → Rôles affiche « Opérations d'expédition » et le module « Expéditions », sans chevauchement de la bascule.
  - **Société 1 inchangée.** Aucune écriture de facturation : 0 abonnement, 0 facture.
  - Fixture supprimée par identifiants exacts : société, 4 utilisateurs, 2 rôles, expéditions, journaux. Contrôle final à 0.
- Suite complète et build : voir l'entrée ADR-806 de `04-decisions.md`.

## 8. Dette conservée

- Les 3 échecs `ControllerSizeInvariantTest` sont historiques, identifiés et **non masqués**.
- **SHA DEV** : `6bf723fb`.
