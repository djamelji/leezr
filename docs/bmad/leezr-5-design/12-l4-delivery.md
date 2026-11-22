# Leezr 5.0 — 12 · Livraison L4 · Widgets premium (ADR-856)

Livré en local sur `dev`, sans push ni déploiement. Même moteur (`useSurfaceEngine`, `DashboardGrid`, `DashboardWidgetRegistry`), mêmes composants L0 (`components/app/`), même RBAC. Aucune formule financière modifiée, aucune société historique touchée : la recette tourne sur le clone marqué `leezr_audit842`.

## 1. Ce qui change

| Sujet | Avant | Après |
|---|---|---|
| Tuiles KPI (23 widgets Platform et Company) | contenu minuscule dans une grande tuile, « 0 » affiché en cas d'erreur | `AppKpiTile` à 5 présentations (micro, compact, standard, wide, expanded) : la valeur, son contexte, la ventilation et le lien vers la page métier apparaissent selon la place disponible |
| Tailles | jusqu'à 12×6 pour une simple valeur | bornes par famille : compteur ≤ 6×2, KPI avec ventilation ≤ 6×3. Au-delà, la poignée refuse **et explique** (« ce widget s'arrête à 6×2 : il n'a rien de plus à montrer ») |
| Tendances (heures, revenus, trésorerie) | titre écrasé à 3×2, dates qui se chevauchent | tuile KPI quand la place est réduite ; sinon total, moyenne, meilleur jour et graphe ; vue étendue avec les meilleurs jours. Étiquettes d'axe allégées selon la largeur (`thinCategories`) |
| Listes (paiements, factures, avoirs, conformité par rôle, par type) | lignes coupées, codes techniques affichés | nombre de lignes ajusté à la hauteur, « + N autres », 2 colonnes au-delà de 760 px, lien vers l'onglet métier, erreur distincte du vide |
| Synthèse expéditions | légende coupée à 4×3 ; donut au-dessus du titre sur l'accueil (rangées de 60 px) | tuile KPI quand la hauteur est faible ; donut dimensionné sur la hauteur mesurée, sans légende Apex parasite (erreurs SVG « height négative » supprimées) |
| Taux de conformité | ventilation tronquée | ventilation sur 2 colonnes ; hauteur mobile lisible |
| Échec du lot de données | widgets vides, lus comme « aucune donnée » | chaque widget passe en « Données indisponibles » avec **Réessayer**, qui relance la résolution par le moteur |
| Disposition Company | pas de retour au défaut ; widgets hors catalogue enregistrables | « Disposition recommandée » (confirmation), recomposée pour le rôle et le JobDomain de l'utilisateur ; l'écriture filtre les widgets hors catalogue (`forArchetype`), comme Platform depuis ADR-855 |

## 2. Catalogue

Le catalogue complet des 51 widgets se trouve dans [11-widget-catalog.md](11-widget-catalog.md). Pour chaque widget, il donne la source, la permission, les bornes, les présentations, les interactions et l'état L4.

## 3. Recette navigateur (clone marqué)

| Recette | Résultat | Captures |
|---|---|---|
| Matrice de tous les widgets (min, défaut, max utile, vide, erreur, chargement) : `/platform/design-lab/matrix`, `/company/design-lab/widgets` | 51 widgets rendus, aucune erreur console | `captures/l4/avant/`, `captures/l4/apres/` (préfixes `platform-`, `company-`, `driver-`) |
| Dirigeant : disposition recommandée, ajout, retrait, redimensionnement refusé et expliqué, sauvegarde, rechargement | ✅ | `recipe-A1…A3` |
| Changement de rôle : chauffeur avec une disposition contenant des widgets interdits (`billing.plan_badge`, `workforce.headcount`), puis manager | ✅ ni servis ni demandés, aucun refus serveur | `recipe-B1`, `recipe-B2` |
| Lot de données en échec (500 simulé), puis Réessayer | ✅ erreurs récupérables, jamais de « 0 » | `recipe-C1` |
| Ancienne disposition hors bornes (KPI 6×5 et 6×4), ajout d'un widget, enregistrement | ✅ PUT 200, tuiles ramenées à 6×3 | `recipe-D1` |
| Accueil Company : desktop, tablette, mobile, en clair et en sombre | ✅ 0 erreur console, 0 débordement horizontal | `home-owner-*`, `home-manager-*` |
| Non-régression du cockpit Platform V35 (recette L1) | ✅ 5/5 | — |

## 4. Tests automatisés

- PHPUnit complet : 4908 réussis, 13 ignorés, 0 échec (les 6 tests qui utilisaient `refund_ratio` en 4×4 comme exemple sont ramenés dans les nouvelles bornes).
- PHPUnit `Adr856WidgetLibraryTest` : bornes cohérentes pour tous les widgets, bornes par famille KPI, tendance réductible en KPI, filtrage de la disposition Company à l'écriture.
- JS `tests/js/axis-labels.test.mjs` : allègement des étiquettes d'axe (la première et la dernière sont toujours conservées, jamais moins de 3).
- E2E `audit842-widgets856-recipe.spec.js` (4 scénarios, autonome, ordre libre ; fixture `scripts/audit842-widgets856-fixture.php`, réservée au clone marqué).

## 5. Bornes resserrées : sans risque pour les dispositions existantes

L'affichage ramène chaque tuile dans ses bornes courantes (`canonicalDisplay`). L'enregistrement fait de même (`saveLayout` borne sur le catalogue), car le serveur refuse une tuile hors bornes (422). Une disposition enregistrée avec un KPI en 6×5 s'affiche donc en 6×3, et elle reste enregistrable sans migration, y compris quand on ajoute seulement un widget : c'est prouvé par la recette D1.

## 6. Restant

- `onboarding.setup` et `billing.plan_badge` : non retravaillés (hors grille, bandeau).
- `compliance.rate` : la jauge n'a pas de présentation KPI dédiée.
- `workforce.clock_live` : conservé ; seules ses bornes sont alignées sur la grille (minimum 3 colonnes).
- Les états des widgets résolus côté client (`compliance.*`, `documents.detail`) ne sont pas simulables dans la matrice.
- Le donut de la synthèse expéditions reste petit en 8×3 sur l'accueil (rangées de 60 px) : il est lisible mais serré.
- Les panneaux du cockpit Platform gardent leurs présentations L1.
