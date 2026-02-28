import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useDisplay } from 'vuetify'

/**
 * Dashboard Grid Engine V5 (ADR-152 V5).
 *
 * Responsive grid with breakpoint contract:
 *   Desktop (≥1280px) → 12 cols
 *   Tablet  (≥960px)  → 8 cols
 *   Mobile  (<960px)  → 4 cols (max w=2)
 *
 * Pipeline applied to EVERY layout mutation:
 *   1. clampToBounds    — enforce bounds + mobile w clamp
 *   2. resolveOverlaps  — eliminate ALL overlaps
 *   3. compactLayout    — gravity up, zero holes
 *   4. assertNoOverlap  — final gate
 *
 * Each widget keeps its own h (free height). No row height unification.
 */
export function useDashboardGrid(gridRef, catalog) {
  const { smAndDown, mdAndDown } = useDisplay()

  // B1: Breakpoint contract
  const cols = computed(() => {
    if (smAndDown.value) return 4
    if (mdAndDown.value) return 8

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
  const WIDGET_MIN_H = 2
  const WIDGET_MAX_H = 6
  const DASHBOARD_MAX_H = 24

  let pendingDrag = null

  function getCellSize() {
    if (!gridRef.value) return { cellW: 80, cellH: ROW_HEIGHT }
    const rect = gridRef.value.getBoundingClientRect()

    return { cellW: rect.width / cols.value, cellH: ROW_HEIGHT }
  }

  // ── Geometry ──

  function overlaps(a, b) {
    return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y
  }

  // ══════════════════════════════════════════════════
  // PIPELINE
  // ══════════════════════════════════════════════════

  /**
   * Step 1: Clamp tile to grid bounds.
   * Mobile (4 cols): w clamped to max 2 (B3).
   */
  function clampToBounds(tile, numCols) {
    let w = Math.max(1, Math.min(tile.w, numCols))

    // Mobile: max 2 widgets per row
    if (numCols === 4) w = Math.min(2, w)

    const h = Math.max(WIDGET_MIN_H, Math.min(WIDGET_MAX_H, tile.h))
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
   * Full pipeline: clamp → resolve → compact → assert.
   */
  function applyPipeline(layout, movedKey) {
    const numCols = cols.value

    let tiles = layout.map(t => clampToBounds(t, numCols))

    tiles = resolveOverlaps(tiles, movedKey, numCols)
    tiles = compactLayout(tiles)

    if (!assertNoOverlap(tiles)) return null
    if (tiles.some(t => t.y + t.h > DASHBOARD_MAX_H)) return null

    return tiles
  }

  /**
   * Breakpoint remap: proportionally remap x/w, then pipeline.
   * Mobile (4 cols): w clamped to max 2 (B3).
   */
  function remapBreakpoint(layout, oldCols, newCols) {
    if (oldCols === newCols || !layout.length) return layout

    const remapped = layout.map(t => {
      let newW = Math.max(1, Math.round(t.w * newCols / oldCols))

      // Mobile: max w = 2
      if (newCols === 4) newW = Math.min(2, newW)

      newW = Math.min(newCols, newW)
      const newX = Math.max(0, Math.min(Math.floor(t.x * newCols / oldCols), newCols - newW))

      return { ...t, x: newX, w: newW }
    })

    return applyPipeline(remapped, null) || remapped
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

    const layout = widget?.layout ?? { min_w: 3, max_w: 12, min_h: 2, max_h: 6 }

    return { ...layout, min_h: Math.max(WIDGET_MIN_H, layout.min_h), max_h: Math.min(WIDGET_MAX_H, layout.max_h) }
  }

  // ── Mouse move ──

  function onMouseMove(event) {
    const { cellW, cellH } = getCellSize()

    // Pending drag threshold
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
    }

    if (dragging.value) {
      const dx = event.clientX - dragging.value.startMouseX
      const dy = event.clientY - dragging.value.startMouseY

      const deltaCols = Math.round(dx / cellW)
      const deltaRows = Math.round(dy / cellH)

      let newX = Math.max(0, dragging.value.origTileX + deltaCols)
      let newY = Math.max(0, dragging.value.origTileY + deltaRows)

      newX = Math.min(newX, cols.value - dragging.value.w)

      ghostTile.value = { x: newX, y: newY, w: dragging.value.w, h: dragging.value.h }
    }

    if (resizing.value) {
      const dx = event.clientX - resizing.value.startMouseX
      const dy = event.clientY - resizing.value.startMouseY

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

      newW = Math.min(newW, cols.value)

      let ghostX = resizing.value.x

      if (ghostX + newW > cols.value) {
        ghostX = 0
      }

      ghostTile.value = { x: ghostX, y: resizing.value.y, w: newW, h: newH }
    }
  }

  // ── Mouse up ──

  function onMouseUp() {
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
    remapBreakpoint,
  }
}
