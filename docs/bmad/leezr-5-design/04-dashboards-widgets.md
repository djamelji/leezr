# Leezr 5.0 — 04 · Dashboards et widgets

> Prototype : `/platform/design-lab/widgets` — [desktop](captures/proto/platform-widgets-desktop.jpg) · [mobile](captures/proto/platform-widgets-mobile.jpg).

## 1. Ce qui existe déjà (à réutiliser, pas à reconstruire)

| Brique | État | Preuve |
|---|---|---|
| Registre de widgets (manifeste, permission, module, audience) | **47 widgets** côté serveur | `app/Modules/Dashboard/Widgets/*` |
| Grille 12 colonnes × lignes de 80 px ; déplacement, redimensionnement, aperçu pendant le geste | en place | `DashboardGrid.vue`, `useDashboardGrid.js` |
| Dimensions min / max / défaut par widget | en place | `WidgetLayoutDefaults::layout()` |
| Disposition **par utilisateur → par rôle → par société → par défaut du métier** | en place | `CompanyDashboardLayout`, `JobdomainDashboardDefault` |
| Catalogue « Ajouter un widget » et suggestions | en place | `GET /dashboard/widgets/catalog`, `/dashboard/suggestions` |
| Préréglages de disposition Platform | en place | `GET /platform/dashboard/layout/presets` |
| **Présentation selon la taille réelle du conteneur** (5 modes, largeur **et** hauteur) | en place ; **46/47 widgets** l'utilisent | `presentationMode.js` (ADR-748), `useSurfacePresentation` (ADR-750) |

**Conclusion.** L'architecture est solide. Les défauts constatés (fadeur, contenu coupé, vides) viennent de **trois causes précises** :
1. **Un seul profil de seuils pour toutes les familles.** `micro` s'applique sous **210 px de haut**, une hauteur pensée pour un graphique. Or une tuile KPI fait 2 lignes de grille (160 px) : **elle est toujours en `micro`** et n'affiche jamais son contexte, d'où les tuiles « 0 » vides. C'est démontré dans le prototype (§2 de la page widgets).
2. **Peu de visuel** : **8 widgets sur 47** contiennent un graphique.
3. **Le gabarit visuel n'est pas partagé** : en-tête, rythme, couleurs et états vides varient d'un widget à l'autre ; la couche `lzr-card` n'est presque pas utilisée.

## 2. Comportement par taille (démontré dans le prototype)

Le **même** widget « Tendance MRR » est placé dans 5 conteneurs fixes. Le mode est mesuré par le moteur existant et le contenu est **recomposé**, jamais rapetissé :

| Taille | Mode | Contenu |
|---|---|---|
| Petite (≈ 300 × 170) | `micro` | valeur, variation, mini-courbe |
| Moyenne étroite (≈ 420 × 300) | `compact` | valeur et variation, courbe sans axes |
| Moyenne (≈ 620 × 320) | `standard` | en-tête de KPI, courbe avec axes, choix de période |
| Grande panoramique (≈ 940 × 270) | `wide` | courbe dominante, KPI en bande (ARR, abonnements) |
| Très grande (≈ 1100 × 420) | `expanded` | courbe et **ventilation** (nouveaux, montées d'offre, résiliations, remises) avec une action |

**Règles techniques.**
- Hauteur de graphique **fixe par mode**. Le prototype a montré qu'une hauteur recalculée pendant la mesure fait échouer ApexCharts (`resetSeries` sur null) et laisse le graphique vide.
- Le graphique est **recréé à chaque changement de mode** (clé = mode), jamais déformé.
- Les états (chargement, vide, erreur) gardent la même enveloppe et la même hauteur : pas de saut à l'arrivée des données.

## 3. Profils de taille par famille (proposition)

Chaque famille déclare ses seuils, avec la **même mesure** que le moteur existant (pas de second moteur), et **ses tailles refusées**. La poignée de redimensionnement s'arrête à la limite : on n'affiche jamais un rendu cassé.

| Famille | Exemples (existants) | Min | Défaut | Max | Refuse | Seuils propres |
|---|---|---|---|---|---|---|
| **KPI** | MRR, livraisons du jour, présents, retards | 2 × 2 | 3 × 2 | 6 × 3 | hauteur > 3 | `micro` < 190 px de large ; `compact` < 215 ; `standard` ; `wide` ≥ 520 |
| **Tendance** | revenus, heures, encaissements | 3 × 2 | 6 × 4 | 12 × 6 | largeur < 3 | seuils actuels (ADR-748) |
| **Liste / file** | à traiter, essais, factures, relances | 3 × 3 | 4 × 4 | 6 × 8 | largeur > 6 | lignes visibles = hauteur ÷ 56 px, puis « Voir les N autres » |
| **Répartition** | offres, état des livraisons, conformité | 3 × 3 | 4 × 4 | 6 × 5 | hauteur < 3 | légende à droite si largeur ≥ 360, sinon dessous |
| **Supervision** | santé plateforme, pointage en direct | 4 × 4 | 4 × 6 | 6 × 8 | `micro`, `compact` | panneau sombre, toujours complet |
| **Équipe** | équipe en ce moment, chauffeurs | 3 × 3 | 4 × 4 | 12 × 4 | hauteur > 5 | colonnes = largeur ÷ 92 px |

**Tableaux dans une carte.** Jamais de pagination dans un widget. On affiche N lignes selon la hauteur, puis « Voir les N autres » vers la page complète. Colonnes réduites à 3 en `compact`.

**Mobile.** La grille passe à 6 colonnes sous 1280 px. Sur téléphone, deux tuiles de 3 colonnes tiennent côte à côte, à environ 170 px chacune. Avec les profils par famille, une tuile KPI y reste **lisible** (libellé et valeur), au lieu de tomber en `micro`. Les familles Supervision et Tendance prennent toute la largeur.

**Performance.**
- Mesure via un seul `ResizeObserver` par widget, avec anti-rebond (existant).
- Animations des graphiques à 500 ms, une fois, puis `dynamicAnimation: false` (aucune animation à chaque rafraîchissement).
- Données par lot (`POST /dashboard/widgets/data`, existant).

## 4. Catalogue cible des widgets

### Platform

| Widget | Famille | Statut | Source |
|---|---|---|---|
| MRR / ARR | KPI, Tendance | existe (`BillingMrr`, `RevenueTrend`) → refonte visuelle | `recurringPortfolio` |
| Revenus et encaissements (facturé / encaissé / taux) | Tendance | **à composer** à partir de `RevenueTrend` et `CashflowTrend` | factures, paiements |
| À traiter en priorité | Liste | **nouveau** (agrège les existants) | relances, abonnements à valider, essais, support |
| Santé de la plateforme | Supervision | existe (`PlatformSystemHealth`) → panneau sombre | `SystemHealthService` |
| Historique des temps de réponse | Tendance | **nouvelle mesure** (échantillon par minute, 24 h) | à créer |
| Parcours d'inscription | Liste ou entonnoir | existe en page (ADR-849) → en widget | `registration_funnel_events` |
| Essais qui se terminent | Liste | **nouveau** | abonnements en essai, mise en service |
| Adoption des modules | Répartition | **nouveau** | `company_modules` |
| Répartition par offre | Répartition | existe (`PlatformPlanDistribution`) | abonnements |
| Activité récente | Liste | existe en page (Activité) → widget | `platform_audit_logs` |
| Support, sécurité, catalogue | KPI | existent → profil KPI | — |

### Company

| Widget | Famille | Statut | Source |
|---|---|---|---|
| Livraisons du jour, en retard, non assignées, en cours | KPI | existent (`ShipmentsToday/Late/Unassigned/InTransit`) | `shipments` |
| Livraisons — état du jour | Répartition | existe (`ShipmentsOverview`) | `shipments` |
| Chauffeurs actifs / équipe en ce moment | Équipe | existe (`DriversActive`) → étendu à tous les rôles | pointages, absences |
| Pointage en direct | Supervision | existe (`ClockLive`) | pointages |
| Heures pointées et planifiées | Tendance | existe (`HoursTrend`) → ajout des heures planifiées | pointages, planning |
| Feuilles de temps à valider, congés à traiter | KPI ou Liste | existent | timesheets, congés |
| Anomalies de pointage | Liste | existe (`WorkforceAnomalies`) | moteur de pointage |
| Conformité documentaire | Répartition | existe (`ComplianceRate/Pending/Overdue`) | DMS |
| Agenda du jour | Liste | **nouveau** (vue des créneaux existants) | `PlanningShifts` |
| Fil d'activité | Liste | **nouveau** (agrégation d'événements existants) | livraisons, pointages, documents, congés |
| Actions rapides contextualisées | (hors grille) | **nouveau** (agrège les compteurs « à traiter » existants) | à traiter (accueil 4.2) |

## 5. Capacités manquantes, proposées comme modules (sans widget fictif)

| Proposition | Valeur | Source de données à créer | Dépendances |
|---|---|---|---|
| **scheduling** (rendez-vous et missions, ADR-563) | accueil « Services » utile, 2ᵉ vertical | `scheduling.appointments` (intervenant, client, créneau, lieu, statut) | contacts, planning |
| **Tournées** (ordre des arrêts, heure d'arrivée estimée) | accueil chauffeur riche, retards anticipés | itinéraire par tournée, géocodage | expéditions |
| **Historique de santé Platform** | courbes du panneau en direct | échantillons par minute (24 h) | Opérations |
| **Rapport mensuel Platform** (PDF ou CSV) | partage au propriétaire et aux associés | agrégats du cockpit, rendu PDF existant (`InvoicePdfRenderer`) | facturation |

## 6. Personnalisation

| Besoin | État | Proposition |
|---|---|---|
| Ajouter / retirer un widget | existe | catalogue visuel : aperçu miniature, famille, taille par défaut |
| Réordonner, redimensionner | existe | arrêt aux tailles refusées (§3) ; indication de la taille cible pendant le geste |
| Disposition personnelle | existe (par utilisateur) | — |
| Disposition recommandée par rôle et par métier | existe (rôle, société, défaut du métier) | l'exposer : « Disposition recommandée pour Dirigeant · Logistique » |
| **Restaurer la disposition par défaut** | **absent** | bouton dans le mode édition, avec confirmation et annulation possible |
| Préréglages Platform | existe | même catalogue visuel |
