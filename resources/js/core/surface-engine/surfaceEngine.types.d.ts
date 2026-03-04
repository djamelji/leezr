/**
 * Surface Engine type definitions (ADR-197).
 *
 * JSDoc-compatible — consumed via `@import` / `@type` annotations.
 * No runtime code, no TypeScript migration required.
 */

/** Canonical dashboard tile (12-col grid, persisted as JSON). */
export interface DashboardTile {
  key: string
  x: number
  y: number
  w: number
  h: number
  scope: 'global' | 'company'
  config: Record<string, unknown>
}

/** Widget catalog entry (from backend or client-side injection). */
export interface WidgetCatalogEntry {
  key: string
  component: string
  label_key: string
  description_key: string
  scope: 'global' | 'company' | 'both'
  category?: string
  tags?: string[]
  layout: WidgetLayoutConstraints
  default_config?: Record<string, unknown>
}

/** Layout constraints for a widget (min/max/default size). */
export interface WidgetLayoutConstraints {
  default_w: number
  default_h: number
  min_w?: number
  max_w?: number
  min_h?: number
  max_h?: number
}

/** Viewport info injected by DashboardGrid into each widget (ADR-201 WIL). */
export interface WidgetViewport {
  w: number
  h: number
  pxWidth: number
  pxHeight: number
  density: 'S' | 'M' | 'L'
  uiProfile: 'kpi' | 'kpi-rich' | 'list' | 'chart' | 'unknown'
  presentationMode: string | null
  kpiScale: number
}

/** Configuration for createSurfaceEngine factory. */
export interface SurfaceEngineConfig {
  apiFn: (url: string, options?: Record<string, unknown>) => Promise<unknown>
  scopeStrategy: 'explicit' | 'implicit'
}
