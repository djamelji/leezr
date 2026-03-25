import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useDisplay } from 'vuetify'

/**
 * Dashboard Grid Engine V6 (ADR-152 V6 — Single Source of Truth).
 *
 * Layout is ALWAYS canonical 12-col. Responsive is PURELY VISUAL.
 *
 * Visual breakpoint contract (render-only, never mutates layout):
 *   Desktop (≥1280px) → 12 cols
 *   Tablet + Mobile (<1280px) → 6 cols
 *
 * 6 cols on mobile ensures S widgets (w=3) fit 2 per row.
 *
 * Pipeline applied to EVERY layout mutation (always 12 cols):
 *   1. clampToBounds    — enforce bounds
 *   2. resolveOverlaps  — eliminate ALL overlaps
 *   3. compactLayout    — gravity up, zero holes
 *   3b. packRowsLeft    — eliminate horizontal gaps
 *   3c. reflowUpward    — lift tiles to higher rows when space available
 *   4. assertNoOverlap  — final gate
 *
 * Each widget keeps its own h (free height). No row height unification.
 * Viewport resize NEVER mutates layout — only visual clamping at render time.
 */
export function useDashboardGrid(gridRef, catalog) {
  const { smAndDown, mdAndDown } = useDisplay()

  // Canonical grid — layout is ALWAYS stored in 12-col coordinates
  const CANONICAL_COLS = 12

  // Visual breakpoint — used ONLY for CSS grid rendering, never for layout mutation
  // 6 cols on mobile/tablet so S widgets (w=3) fit 2 per row on all viewports
  const cols = computed(() => {
    if (mdAndDown.value) return 6

    return 12
  })

  const isMobile = computed(() => smAndDown.value)

  // ── State ──
  const dragging = ref(null)
  const resizing = ref(null)
  const ghostTile = ref(null)
  const placementError = ref(false)
  const placementErrorKey = ref('')

  const DRAG_THRESHOLD = 6
  const ROW_HEIGHT = 80
  const WIDGET_MIN_W = 3
  const WIDGET_MIN_H = 2
  const WIDGET_MAX_H = 8
  const DASHBOARD_MAX_H = 24

  let pendingDrag = null

  function getCellSize() {
    if (!gridRef.value) return { cellW: 80, cellH: ROW_HEIGHT }
    const rect = gridRef.value.getBoundingClientRect()

    return { cellW: rect.width / CANONICAL_COLS, cellH: ROW_HEIGHT }
  }

  // ── Geometry ──

  function overlaps(a, b) {
    return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y
  }

  // ══════════════════════════════════════════════════
  // PIPELINE
  // ══════════════════════════════════════════════════

  /**
   * Step 1: Clamp tile to grid bounds (always 12-col canonical).
   * Uses per-widget constraints from catalog when available.
   */
  function clampToBounds(tile, numCols) {
    const c = getConstraints(tile.key)
    let w = Math.max(c.min_w, Math.min(c.max_w, tile.w))

    w = Math.min(w, numCols)

    const h = Math.max(c.min_h, Math.min(c.max_h, tile.h))
    const x = Math.max(0, Math.min(tile.x, numCols - w))
    const y = Math.max(0, tile.y)

    return { ...tile, x, y, w, h }
  }

  /**
   * Step 2: Resolve ALL overlaps deterministically.
   */
  function resolveOverlaps(tiles, movedKey, numCols) {
    const layout = tiles.map(t => ({ ...t }))
    let iterations = 0

    while (iterations < 200) {
      let fixedIdx = -1
      let moveIdx = -1

      for (let i = 0; i < layout.length && fixedIdx === -1; i++) {
        for (let j = i + 1; j < layout.length; j++) {
          if (!overlaps(layout[i], layout[j])) continue

          if (layout[i].key === movedKey) {
            fixedIdx = i
            moveIdx = j
          }
          else if (layout[j].key === movedKey) {
            fixedIdx = j
            moveIdx = i
          }
          else if (layout[i].y < layout[j].y || (layout[i].y === layout[j].y && layout[i].x < layout[j].x)) {
            fixedIdx = i
            moveIdx = j
          }
          else {
            fixedIdx = j
            moveIdx = i
          }

          break
        }
      }

      if (fixedIdx === -1) break
      iterations++

      const fixed = layout[fixedIdx]
      const T = layout[moveIdx]
      let placed = false

      // 1) SHIFT RIGHT — h=1 probe: decision independent of actual h
      const rightX = fixed.x + fixed.w

      if (!placed && rightX + T.w <= numCols) {
        const probe = { ...T, x: rightX, h: 1 }

        if (layout.every((t, k) => k === moveIdx || !overlaps(probe, t))) {
          layout[moveIdx] = { ...T, x: rightX }
          placed = true
        }
      }

      // 2) SHIFT LEFT — h=1 probe: decision independent of actual h
      if (!placed) {
        const leftX = fixed.x - T.w

        if (leftX >= 0) {
          const probe = { ...T, x: leftX, h: 1 }

          if (layout.every((t, k) => k === moveIdx || !overlaps(probe, t))) {
            layout[moveIdx] = { ...T, x: leftX }
            placed = true
          }
        }
      }

      // 3) PUSH DOWN
      if (!placed) {
        const pushY = fixed.y + fixed.h
        const pushX = Math.max(0, Math.min(T.x, numCols - T.w))
        const candidate = { ...T, x: pushX, y: pushY }

        if (layout.every((t, k) => k === moveIdx || !overlaps(candidate, t))) {
          layout[moveIdx] = candidate
          placed = true
        }
      }

      // 4) FALLBACK
      if (!placed) {
        let maxY = 0

        for (let k = 0; k < layout.length; k++) {
          if (k !== moveIdx) maxY = Math.max(maxY, layout[k].y + layout[k].h)
        }

        layout[moveIdx] = { ...T, x: 0, y: maxY }
      }
    }

    return layout
  }

  /**
   * Step 3: Compact layout (gravity up, zero holes).
   */
  function compactLayout(tiles) {
    const layout = tiles.map(t => ({ ...t }))

    layout.sort((a, b) => a.y - b.y || a.x - b.x)

    for (let i = 0; i < layout.length; i++) {
      while (layout[i].y > 0) {
        const candidate = { ...layout[i], y: layout[i].y - 1 }
        let blocked = false

        for (let j = 0; j < layout.length; j++) {
          if (i === j) continue
          if (overlaps(candidate, layout[j])) {
            blocked = true
            break
          }
        }

        if (blocked) break
        layout[i] = candidate
      }
    }

    return layout
  }

  /**
   * Step 3b: Pack rows left — eliminate horizontal gaps.
   * Groups tiles by y, packs left within each row.
   * Respects cross-row overlaps (taller tiles spanning multiple rows).
   * Overflow tiles placed at bottom, x=0.
   */
  function packRowsLeft(tiles, numCols) {
    const sorted = tiles.map(t => ({ ...t }))
    sorted.sort((a, b) => a.y - b.y || a.x - b.x)

    const rowYs = [...new Set(sorted.map(t => t.y))].sort((a, b) => a - b)
    const packed = []
    const overflow = []

    for (const rowY of rowYs) {
      const row = sorted.filter(t => t.y === rowY)
      let cursor = 0

      for (const tile of row) {
        // Find leftmost x >= cursor with no overlap against already-packed tiles
        let x = cursor

        while (x + tile.w <= numCols) {
          const candidate = { ...tile, x }

          if (!packed.some(p => overlaps(candidate, p))) break
          x++
        }

        if (x + tile.w <= numCols) {
          packed.push({ ...tile, x })
          cursor = x + tile.w
        }
        else {
          overflow.push(tile)
        }
      }
    }

    // Overflow → bottom, packed left
    if (overflow.length) {
      let maxY = packed.reduce((m, t) => Math.max(m, t.y + t.h), 0)
      let cursor = 0
      let rowMaxH = 0

      for (const tile of overflow) {
        if (cursor + tile.w > numCols) {
          maxY += rowMaxH || 1
          cursor = 0
          rowMaxH = 0
        }

        packed.push({ ...tile, x: cursor, y: maxY })
        cursor += tile.w
        rowMaxH = Math.max(rowMaxH, tile.h)
      }
    }

    return packed
  }

  /**
   * Step 3c: Reflow upward — lift tiles to earlier rows when space available.
   * Processes bottom-up: tries to move each tile to the earliest (y, x)
   * where it fits without overlap.
   */
  function reflowUpward(tiles, numCols) {
    const layout = tiles.map(t => ({ ...t }))

    // Process bottom tiles first — they have the most potential to move up
    layout.sort((a, b) => b.y - a.y || a.x - b.x)

    for (let i = 0; i < layout.length; i++) {
      const tile = layout[i]
      let bestCandidate = null

      for (let y = 0; y < tile.y; y++) {
        for (let x = 0; x + tile.w <= numCols; x++) {
          const candidate = { ...tile, x, y }

          if (!layout.some((t, idx) => idx !== i && overlaps(candidate, t))) {
            bestCandidate = candidate
            break
          }
        }

        if (bestCandidate) break
      }

      if (bestCandidate) {
        layout[i] = bestCandidate
      }
    }

    return layout
  }

  // ══════════════════════════════════════════════════
  // VISUAL LAYOUT (derived, never persisted)
  // ══════════════════════════════════════════════════

  /**
   * Compute a visual layout for rendering at a given viewport column count.
   * PURE function — returns a NEW array of tiles with derived x/y/w/h positions.
   * The canonical layout is NEVER mutated, emitted, or saved.
   *
   * Algorithm:
   *   1. Copy canonical layout, sort by (y, x) to preserve reading order
   *   2. For each tile: renderW = min(w, viewCols), renderH = forceH || h
   *   3. Place using occupancy map: find first row where tile fits
   *   4. Return derived layout with visual positions
   *
   * At viewCols === CANONICAL_COLS (12) and no forceH, returns canonical positions unchanged.
   *
   * @param {Array} canonicalLayout - tiles with canonical x/y/w/h
   * @param {number} viewCols - visual column count (6 on mobile/tablet, 12 on desktop)
   * @param {number|null} forceH - if set, override all tile heights (mobile → 2)
   */
  function computeVisualLayout(canonicalLayout, viewCols, forceH = null) {
    const sorted = [...canonicalLayout].sort((a, b) => a.y - b.y || a.x - b.x)

    // Occupancy map: sparse rows × viewCols columns
    // Each cell is true if occupied
    const occupied = []

    function isOccupied(row, col) {
      return !!(occupied[row] && occupied[row][col])
    }

    function markOccupied(x, y, w, h) {
      for (let row = y; row < y + h; row++) {
        if (!occupied[row]) occupied[row] = new Array(viewCols).fill(false)
        for (let col = x; col < x + w; col++) {
          occupied[row][col] = true
        }
      }
    }

    function canPlace(x, y, w, h) {
      for (let row = y; row < y + h; row++) {
        for (let col = x; col < x + w; col++) {
          if (col >= viewCols) return false
          if (isOccupied(row, col)) return false
        }
      }

      return true
    }

    const result = []
    const halfCols = Math.floor(viewCols / 2)

    function placeTile(tile, w, h) {
      for (let y = 0; ; y++) {
        for (let x = 0; x <= viewCols - w; x++) {
          if (canPlace(x, y, w, h)) {
            markOccupied(x, y, w, h)
            result.push({ ...tile, x, y, w, h })

            return
          }
        }
        if (y > 200) {
          markOccupied(0, y, w, h)
          result.push({ ...tile, x: 0, y, w, h })

          return
        }
      }
    }

    // ── Mobile mode: interleave charts (full) and KPI pairs (half) ──
    if (forceH) {
      const charts = sorted.filter(t => t.w > 6)   // w>6 → full width (graphiques)
      const kpis = sorted.filter(t => t.w <= 6)    // w<=6 → half width (KPI, paired)

      // If odd KPI count → last one goes full width
      const kpiOdd = kpis.length % 2 !== 0

      let ci = 0 // chart index
      let ki = 0 // kpi index

      // Interleave: 1 chart → up to 2 KPIs → 1 chart → up to 2 KPIs → ...
      while (ci < charts.length || ki < kpis.length) {
        // Place one chart
        if (ci < charts.length) {
          placeTile(charts[ci], viewCols, forceH)
          ci++
        }

        // Place up to 2 KPIs
        for (let pair = 0; pair < 2 && ki < kpis.length; pair++) {
          const isLast = ki === kpis.length - 1
          const w = (kpiOdd && isLast) ? viewCols : halfCols

          placeTile(kpis[ki], w, forceH)
          ki++
        }
      }

      return result
    }

    // ── Desktop (12-col): first-fit placement preserving canonical widths ──
    // Auto-repairs layouts with broken positions (e.g. all tiles at x=0)
    // while preserving correctly positioned layouts.
    if (viewCols >= CANONICAL_COLS) {
      for (const tile of sorted) {
        placeTile(tile, tile.w, tile.h)
      }

      return result
    }

    // ── Tablet only: binary snap with pre-computed widths (ADR-201) ──
    // Pre-compute render widths BEFORE placement:
    //   w ≤ halfCols → halfCols (pair), w > halfCols → viewCols (full).
    //   Orphaned halves (broken by full tiles or at end) → stretched to viewCols.
    // Mobile path (forceH) is UNCHANGED — it uses interleave above.
    const renderWidths = new Array(sorted.length)
    let buffer = null

    for (let i = 0; i < sorted.length; i++) {
      if (sorted[i].w <= halfCols) {
        if (buffer === null) {
          buffer = i
          renderWidths[i] = halfCols
        }
        else {
          renderWidths[i] = halfCols
          buffer = null
        }
      }
      else {
        if (buffer !== null) {
          renderWidths[buffer] = viewCols
          buffer = null
        }
        renderWidths[i] = viewCols
      }
    }

    if (buffer !== null) {
      renderWidths[buffer] = viewCols
    }

    for (let i = 0; i < sorted.length; i++) {
      placeTile(sorted[i], renderWidths[i], sorted[i].h)
    }

    return result
  }

  /**
   * Step 4: Assert no overlap.
   */
  function assertNoOverlap(tiles) {
    for (let i = 0; i < tiles.length; i++) {
      for (let j = i + 1; j < tiles.length; j++) {
        if (overlaps(tiles[i], tiles[j])) return false
      }
    }

    return true
  }

  /**
   * Full pipeline: clamp → resolve → compact → packLeft → compact → reflow → packLeft → compact → assert.
   */
  function applyPipeline(layout, movedKey) {
    const numCols = CANONICAL_COLS

    let tiles = layout.map(t => clampToBounds(t, numCols))

    tiles = resolveOverlaps(tiles, movedKey, numCols)
    tiles = compactLayout(tiles)
    tiles = packRowsLeft(tiles, numCols)
    tiles = compactLayout(tiles)
    tiles = reflowUpward(tiles, numCols)
    tiles = packRowsLeft(tiles, numCols)
    tiles = compactLayout(tiles)

    if (!assertNoOverlap(tiles)) return null
    if (tiles.some(t => t.y + t.h > DASHBOARD_MAX_H)) return null

    return tiles
  }

  // ── Drag (handle-only, 6px threshold) ──

  function startDrag(tile, event) {
    pendingDrag = {
      key: tile.key,
      startMouseX: event.clientX,
      startMouseY: event.clientY,
      origTileX: tile.x,
      origTileY: tile.y,
      w: tile.w,
      h: tile.h,
      activated: false,
    }
  }

  // ── Resize ──

  function startResize(tile, event) {
    resizing.value = {
      key: tile.key,
      startMouseX: event.clientX,
      startMouseY: event.clientY,
      origW: tile.w,
      origH: tile.h,
      origX: tile.x,
      x: tile.x,
      y: tile.y,
    }
    ghostTile.value = { x: tile.x, y: tile.y, w: tile.w, h: tile.h }
    placementError.value = false
  }

  function getConstraints(key) {
    const widget = catalog.value?.find(w => w.key === key)
    const l = widget?.layout ?? {}

    return {
      min_w: Math.max(WIDGET_MIN_W, l.min_w ?? WIDGET_MIN_W),
      max_w: Math.min(CANONICAL_COLS, l.max_w ?? CANONICAL_COLS),
      min_h: Math.max(WIDGET_MIN_H, l.min_h ?? WIDGET_MIN_H),
      max_h: Math.min(WIDGET_MAX_H, l.max_h ?? WIDGET_MAX_H),
    }
  }

  // ── Mouse move (rAF-throttled for ghostTile updates) ──

  let rafId = null

  function onMouseMove(event) {
    // Threshold check is always immediate (no rAF delay)
    if (pendingDrag && !pendingDrag.activated) {
      const dx = Math.abs(event.clientX - pendingDrag.startMouseX)
      const dy = Math.abs(event.clientY - pendingDrag.startMouseY)

      if (dx < DRAG_THRESHOLD && dy < DRAG_THRESHOLD) return

      pendingDrag.activated = true
      dragging.value = {
        key: pendingDrag.key,
        startMouseX: pendingDrag.startMouseX,
        startMouseY: pendingDrag.startMouseY,
        origTileX: pendingDrag.origTileX,
        origTileY: pendingDrag.origTileY,
        w: pendingDrag.w,
        h: pendingDrag.h,
      }
      ghostTile.value = {
        x: pendingDrag.origTileX,
        y: pendingDrag.origTileY,
        w: pendingDrag.w,
        h: pendingDrag.h,
      }

      return
    }

    // Batch ghostTile updates to one per frame (≈60fps)
    // This prevents previewLayout pipeline from running on every pixel
    if (!dragging.value && !resizing.value) return

    const clientX = event.clientX
    const clientY = event.clientY

    if (rafId) return
    rafId = requestAnimationFrame(() => {
      rafId = null
      updateGhostTile(clientX, clientY)
    })
  }

  function updateGhostTile(clientX, clientY) {
    const { cellW, cellH } = getCellSize()

    if (dragging.value) {
      const dx = clientX - dragging.value.startMouseX
      const dy = clientY - dragging.value.startMouseY

      const deltaCols = Math.round(dx / cellW)
      const deltaRows = Math.round(dy / cellH)

      let newX = Math.max(0, dragging.value.origTileX + deltaCols)
      let newY = Math.max(0, dragging.value.origTileY + deltaRows)

      newX = Math.min(newX, CANONICAL_COLS - dragging.value.w)

      ghostTile.value = { x: newX, y: newY, w: dragging.value.w, h: dragging.value.h }
    }

    if (resizing.value) {
      const dx = clientX - resizing.value.startMouseX
      const dy = clientY - resizing.value.startMouseY

      const deltaCols = Math.round(dx / cellW)
      const deltaRows = Math.round(dy / cellH)

      const constraints = getConstraints(resizing.value.key)

      const rawH = resizing.value.origH + deltaRows

      let newW = Math.max(constraints.min_w, Math.min(constraints.max_w, resizing.value.origW + deltaCols))
      let newH = Math.max(constraints.min_h, Math.min(constraints.max_h, rawH))

      // Global height cap
      if (rawH > WIDGET_MAX_H && !placementError.value) {
        placementErrorKey.value = 'dashboardGrid.maxHeightReached'
        placementError.value = true
      }

      // B4: Mobile — vertical resize only (freeze width)
      if (isMobile.value) {
        newW = resizing.value.origW
      }

      newW = Math.min(newW, CANONICAL_COLS)

      let ghostX = resizing.value.x

      if (ghostX + newW > CANONICAL_COLS) {
        ghostX = 0
      }

      ghostTile.value = { x: ghostX, y: resizing.value.y, w: newW, h: newH }
    }
  }

  // ── Mouse up ──

  function onMouseUp() {
    if (rafId) {
      cancelAnimationFrame(rafId)
      rafId = null
    }

    const result = ghostTile.value ? { ...ghostTile.value } : null
    const key = dragging.value?.key || resizing.value?.key

    dragging.value = null
    resizing.value = null
    ghostTile.value = null
    pendingDrag = null

    if (result && key) {
      return { key, ...result }
    }

    return null
  }

  // Global listeners
  let mouseMoveHandler = null
  let mouseUpCleanup = null

  onMounted(() => {
    mouseMoveHandler = e => onMouseMove(e)
    mouseUpCleanup = () => {
      if (pendingDrag && !pendingDrag.activated) {
        pendingDrag = null
      }
    }
    document.addEventListener('mousemove', mouseMoveHandler)
    document.addEventListener('mouseup', mouseUpCleanup)
  })

  onUnmounted(() => {
    if (mouseMoveHandler) document.removeEventListener('mousemove', mouseMoveHandler)
    if (mouseUpCleanup) document.removeEventListener('mouseup', mouseUpCleanup)
  })

  return {
    cols,
    CANONICAL_COLS,
    isMobile,
    dragging,
    resizing,
    ghostTile,
    placementError,
    placementErrorKey,
    startDrag,
    startResize,
    onMouseUp,
    applyPipeline,
    computeVisualLayout,
  }
}
