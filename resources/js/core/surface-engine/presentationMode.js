/**
 * Presentation Engine — résolution du MODE de présentation d'un widget (ADR-748).
 *
 * Réutilise la mesure px (largeur ET hauteur) déjà fournie par le ResizeObserver de
 * DashboardGrid. Remplace l'ancien `computePresentationMode` (largeur-seule, par profil,
 * désactivé) par une intention de présentation width+height-aware, PARTAGÉE par :
 *   - DashboardGrid.getViewport (le widget reçoit `viewport.mode`)
 *   - les widgets eux-mêmes (auto-mesure de leur racine, source autoritaire hors grille)
 *   - le harness de recette visuelle
 *
 * Ce n'est PAS un moteur parallèle : une seule fonction pure, une seule vérité.
 *
 * Modes (intentions, pas des dimensions figées) :
 *   expanded : grande largeur ET grande hauteur → composition riche (graphe + aside)
 *   wide     : large mais peu haut (panoramique) → graphe dominant, KPI en bandeau
 *   standard : format intermédiaire → graphe pleine largeur + KPI empilés
 *   compact  : petite surface → header réduit, KPI principaux, contrôles compacts
 *   micro    : surface très réduite → KPI + sparkline, pas de graphe détaillé
 *
 * @param {number} w largeur px disponible du widget
 * @param {number} h hauteur px disponible du widget
 * @returns {'expanded'|'wide'|'standard'|'compact'|'micro'}
 */
export function resolvePresentationMode(w, h) {
  const pw = Number(w) || 0
  const ph = Number(h) || 0

  // Trop petit pour un graphe détaillé + ses contrôles → micro.
  if (ph < 210 || pw < 260) return 'micro'

  // Panoramique : beaucoup de largeur, peu de hauteur → graphe dominant, KPI en bandeau.
  if (pw >= 760 && ph < 340) return 'wide'

  // Grand dans les deux dimensions → composition riche graphe + aside.
  if (pw >= 820 && ph >= 380) return 'expanded'

  // Étroit → compact (header réduit, contrôles compacts).
  if (pw < 520) return 'compact'

  // Sinon : intermédiaire.
  return 'standard'
}

/** Ordre de richesse (utilitaire pour comparaisons/tests). */
export const PRESENTATION_MODES = ['micro', 'compact', 'standard', 'wide', 'expanded']
