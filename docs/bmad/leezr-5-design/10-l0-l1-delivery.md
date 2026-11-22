# Leezr 5.0 — 10 · Livraison L0 + L1 (ADR-855)

> **Dashboard Platform V35 réellement connecté**, sur le moteur existant, et fondations visuelles communes.
> Local seulement : aucun push, aucun déploiement, aucune société historique modifiée, Billing et RBAC inchangés.

## 1. Avant / après (même largeur)

| | Avant (4.2) | Après (5.0) |
|---|---|---|
| Desktop 1440 | [cockpit 4.2](captures/current/shots/p-superadmin/platform_dashboard_overview.jpg) | [cockpit V35](captures/l1/cockpit-superadmin-desktop.jpg) · [sombre](captures/l1/cockpit-superadmin-desktop-dark.jpg) |
| Tablette 1024 | — | [clair](captures/l1/cockpit-superadmin-tablet.jpg) · [sombre](captures/l1/cockpit-superadmin-tablet-dark.jpg) |
| Mobile 390 | — | [clair](captures/l1/cockpit-superadmin-mobile.jpg) · [sombre](captures/l1/cockpit-superadmin-mobile-dark.jpg) |
| Profils | — | [Finance](captures/l1/cockpit-finance-desktop.jpg) · [Support](captures/l1/cockpit-support-desktop.jpg) · [Gouvernance](captures/l1/cockpit-gouvernance-desktop.jpg) · [sans accès](captures/l1/cockpit-limite-desktop.jpg) |
| Redimensionnement | — | [disposition recommandée](captures/l1/recipe-A1-recommande.jpg) → [Revenus en 12 colonnes : vue analytique](captures/l1/recipe-A2-redimensionne.jpg) → [après rechargement](captures/l1/recipe-A3-apres-rechargement.jpg) |
| États | — | [erreur d'un bloc avec « Réessayer »](captures/l1/recipe-D1-erreur.jpg) |

**Ce qui a changé :**

- **En-tête.** Un accueil contextualisé (salutation selon l'heure, date, prénom en italique d'accent) remplace le titre gris « Cockpit ».
- **Synthèse.** La situation est résumée en une phrase, calculée sur les vraies alertes.
- **Actions.** Deux actions au plus, choisies selon les permissions (Nouvelle société, Facturation ou Opérations).
- **« À traiter ».** Le bandeau « Attention requise » devient un **panneau de priorités** : même flux, filtré par permission côté serveur, chaque ligne ouvre sa page.
- **Grille.** Une composition V35, aux zones de densité différentes :
  - une bande de 4 KPI ;
  - l'analyse des revenus à côté du **panneau sombre de santé** ;
  - les essais, l'activité des modules et la répartition par offre ;
  - le journal, le support et la sécurité.
- **Mouvement.** Entrée échelonnée, survol discret, compteurs joués une seule fois, barres qui se remplissent ; `prefers-reduced-motion` respecté.
- **Onglets.** Les onglets « pilule » deviennent un sélecteur compact : mêmes `VTabs`, mêmes permissions.

## 2. Données réelles (aucune inventée)

| Bloc | Widget serveur | Source | Permission |
|---|---|---|---|
| Sociétés actives | `platform.companies_overview` (+ tendance 30 j, statuts commerciaux) | `companies`, `subscriptions` (statuts seulement) | `view_companies` |
| Inscriptions | **`platform.signups`** (nouveau) | `OnboardingFunnelService` (ADR-849) — totaux 30 j, courbe 7 j | `view_usage_monitoring` |
| Encaissé ce mois | `billing.revenue_mtd` | lecture du tableau de bord facturation (inchangée) | `view_billing` |
| Paiements échoués 7 j | `billing.failed_payments_7d` | idem | `view_billing` |
| Revenus récurrents | `platform.commercial_overview` | `recurringPortfolio` (ADR-841) + `/billing/commercial-series` (période, N-1) | `view_billing` |
| Santé de la plateforme | `platform.system_health` | `SystemHealthService` (7 sections, état seulement) | `view_system_health` |
| Essais | **`platform.trials_ending`** (nouveau) | abonnements courants « trialing », fin la plus proche d'abord | `view_companies` |
| Activité des modules | `platform.catalog_overview` (+ noms métier) | `CompanyModule` (formule inchangée) | `manage_modules` |
| Répartition par offre | `platform.plan_distribution` | abonnements | `view_billing` |
| Activité récente | **`platform.recent_activity`** (nouveau) | `platform_audit_logs`, sans connexions ni IP | `view_audit_logs` |
| Tickets, alertes sécurité | `platform.support_overview`, `platform.security_overview` | existants | `manage_support`, `security.alerts.view` |

**Données non disponibles, affichées comme telles :**

- **Historique de santé** (courbe). Il n'est pas stocké : le panneau montre l'état de chaque service, sans courbe inventée. Prévu en L2.
- **Encaissé multi-devises.** Il affiche « — » avec un renvoi vers Facturation.
- **Un indicateur absent.** Il s'affiche « — » avec la mention « Donnée indisponible », jamais un faux zéro.

**Ce que le cockpit a révélé sur le clone** : un essai **expiré depuis 29 jours mais toujours « en essai »**, un incident IA et un planificateur à surveiller. Ce sont de vraies situations à traiter, et elles sont mises en avant.

## 3. Permissions — prouvées dans le navigateur

| Profil (clone) | Blocs servis | Finance |
|---|---|---|
| Super-admin | les 12 | oui |
| Finance (`finance-reader`) | encaissé, paiements échoués, revenus, offres | oui |
| Support avec accès au cockpit (`support-cockpit855`, fictif) | tickets, essais — **sa disposition enregistrée contenait Revenus et Encaissé : ils ne sont pas servis** | **non** |
| Gouvernance avec accès au cockpit (`gov-cockpit855`, fictif) | activité des modules | non |
| Sans `view_dashboard` (`roles-only`, `support-only`, `gov-mgr`) | refus (page 403, existant) | — |

**Correction de sécurité.** Avant, `GET /platform/dashboard/layout` ne filtrait la disposition enregistrée que par module. Une tuile financière restait donc présente pour un Support, même si ses données étaient refusées. La disposition est désormais filtrée **par le catalogue permissionné**, à la lecture **et** à l'enregistrement ; le contrôle par widget de `batchResolve` est inchangé.

**Liens cassés corrigés.** Les liens des alertes « tickets non attribués » et « alertes critiques » pointaient vers des noms de route qui n'existaient plus (`platform-support`, `platform-alerts`). Ils mènent maintenant à Communications › Support et à Opérations › Alertes.

## 4. Widgets adaptatifs (L1, Platform seulement)

- **Profils de taille par famille** : `core/surface-engine/familyModes.js`. Même mesure que le moteur ADR-748, seuils propres à chaque famille. Une tuile KPI de 3 × 2 n'est plus en `micro` : elle affiche libellé, valeur, contexte et courbe.
- **Le contenu change selon la taille.**
  - Petit : Sociétés, Inscriptions et Revenus deviennent des tuiles KPI.
  - Moyen et grand : chiffres clés, graphique et ventilation.
  - Revenus en très grand : vue analytique (variation du MRR, attrition, conversion des essais).
  - Listes : nombre de lignes selon la hauteur ; deux colonnes au-delà de 760 px de large.
- **Tailles autorisées** (`layout()` serveur) : les bornes ont seulement été **élargies**, jamais resserrées, pour qu'aucune disposition enregistrée ne devienne invalide (test dédié).
- **Graphiques** : `LzrApexChart` (existant, ADR-745/748b) monte le graphique quand le conteneur est mesurable et le recrée après un redimensionnement. La hauteur suit l'espace disponible par paliers de 30 px.
- **Mobile** : la politique de priorité mobile existante (ADR-745) s'applique aux clés du cockpit, avec une **hauteur mobile par panneau**. Les KPI vont par deux, les panneaux passent en pleine largeur. Les dashboards Company ne changent pas.

## 5. Fondations communes (L0)

- **Une seule bibliothèque : `components/app/`.**
  - `AppPageHero`, `AppKpiTile`, `AppWidgetShell` (en-tête, période, lien, états chargement, vide et erreur, mode intégré) ;
  - `AppLivePanel` (surface sombre générique), `AppSegmented`, `AppEmptyState`, `AppStatusChip`.
- **`composables/useCountUp`.** Compteur joué une fois, désactivé si le mouvement est réduit.
- **Jetons dans `styles.scss`** (couche Premium) :
  - `.min-w-0`, `.lzr-on-brand` ;
  - `.lzr-enter`, `.lzr-stagger`, `.lzr-grow` ;
  - `.lzr-live-dot`, transitions `lzr-list`.
- **Règle apprise.** Une animation d'entrée ne fait varier **que la position** (`transform`), jamais l'opacité. Un fondu figé à son état initial a laissé une tuile invisible dans une capture tablette.
- **Laboratoire unique** : `/platform/design-lab`, en développement seulement. Il donne accès au cockpit réel, à la bibliothèque de widgets, aux composants et aux propositions L3 et L5.
  - Le prototype cockpit est retiré : son contenu vit dans le vrai dashboard, et il reste dans l'historique (`753d5372`).
  - Les trois laboratoires historiques sont **conservés** et listés avec ce qui en a été repris ; `/company/dev-lab` reste à reprendre en L3/L4 avant tout retrait.
- **Grille.** Option `premium` de `DashboardGrid` (élévation, survol, entrée), activée **uniquement** sur le cockpit Platform jusqu'à L4.

## 6. Tests

| Type | Résultat |
|---|---|
| PHPUnit `Adr855PlatformCockpitTest` | 8 / 8 : filtrage de la disposition (lecture et écriture), permissions des nouveaux widgets, ordre des essais (expiré en tête), journal sans connexions ni IP, parcours sans taux inventé, clés seulement ajoutées, bornes seulement élargies |
| JS `family-modes.test.mjs` | 4 / 4 (46 / 46 au total) |
| E2E `audit842-cockpit855-recipe` (clone) | 5 / 5 : recommandé, redimensionnement, déplacement, sauvegarde, rechargement, retour au recommandé ; navigation alerte → Facturation, essai → fiche société ; Support sans finance (0 appel refusé) ; Gouvernance ; sans accès → 403 ; erreur puis « Réessayer » |
| Non-régression E2E | M3 7 / 7, M10 3 / 3, M5 5 / 5. La recette M5 cherchait « Audit 842 Services » et ouvrait le premier résultat ; d'autres recettes ont depuis créé des noms proches. Elle cherche désormais le nom exact de la société 106. |
| Navigateur | 5 profils, desktop, tablette, mobile, clair et sombre : 0 erreur console, 0 appel refusé, 0 débordement horizontal |
| PHPUnit complet, build | voir le message de commit |

## 7. Restant pour L4 et L3

**L4 — bibliothèque de widgets**

- Appliquer `AppWidgetShell` et les profils par famille aux widgets Company. L'option `premium` de la grille s'étend alors à tous les dashboards.
- Sur mobile étroit, les étiquettes de l'axe des X sont serrées dans `LzrApexChart` : il faut limiter leur nombre selon la largeur.
- Signaler, pendant le geste, qu'une taille est refusée (les bornes sont appliquées, mais en silence).
- Retirer les anciens composants de widgets Platform (`views/platform-dashboard/_Platform*Widget.vue`), aujourd'hui gardés mais inutilisés.

**L3 — accueil Company V22**

- Repartir de `/company/design-lab/workspace`, avec `AppPageHero` (variante espace de travail), `AppKpiTile` et `AppWidgetShell`.
- Brancher les sources listées en 03 §3.
- Remplacer les contrôles segmentés locaux des prototypes Company par `AppSegmented`.

**L2 — santé historisée** : échantillonner les mesures de `SystemHealthService`, pour tracer une vraie courbe dans le panneau sombre.

**Hors périmètre, constaté.** Le personnaliseur de thème flottant (engrenage) chevauche toujours les onglets. Il est réservé aux administrateurs autorisés à régler le thème, et son déplacement relève du lot L7.

## 8. Décision enregistrée pour L5

La dépréciation de « Outils › Champs » est validée, mais **rien n'est supprimé en L0 + L1**. Il faudra distinguer :

- le catalogue global Platform ;
- la configuration du JobDomain ;
- le catalogue de champs Company ;
- les obligations par poste.

Une migration **réversible** de `required_override` vers les exigences « tous les postes » sera préparée et simulée sur un clone, **avant** tout retrait d'interface.
