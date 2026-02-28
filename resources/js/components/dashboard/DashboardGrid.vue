<script setup>
import { useDashboardGrid } from '@/composables/useDashboardGrid'
import { resolveWidgetComponent } from './widgetComponentMap'

const props = defineProps({
  layout: { type: Array, required: true },
  widgetData: { type: Object, default: () => ({}) },
  widgetErrors: { type: Object, default: () => ({}) },
  catalog: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  editable: { type: Boolean, default: false },
})

const emit = defineEmits(['update:layout'])

const { t } = useI18n()

const gridRef = ref(null)
const catalogRef = computed(() => props.catalog)
const {
  cols, isMobile, dragging, resizing, ghostTile,
  placementError, placementErrorKey,
  startDrag, startResize, onMouseUp,
  applyPipeline, remapBreakpoint,
} = useDashboardGrid(gridRef, catalogRef)

const sortedLayout = computed(() =>
  [...props.layout].sort((a, b) => a.y - b.y || a.x - b.x),
)

// ── Live preview: other tiles shift during drag/resize (ADR-152 V5-hotfix-B) ──

const previewLayout = computed(() => {
  if (!ghostTile.value) return null

  const key = dragging.value?.key || resizing.value?.key
  if (!key) return null

  const newLayout = props.layout.map(tile => {
    if (tile.key === key) {
      return { ...tile, x: ghostTile.value.x, y: ghostTile.value.y, w: ghostTile.value.w, h: ghostTile.value.h }
    }

    return tile
  })

  return applyPipeline(newLayout, key)
})

const displayLayout = computed(() => {
  if (previewLayout.value) {
    return [...previewLayout.value].sort((a, b) => a.y - b.y || a.x - b.x)
  }

  return sortedLayout.value
})

// ── Breakpoint remap (A7) ──

watch(cols, (newCols, oldCols) => {
  if (oldCols !== newCols && props.layout.length) {
    const remapped = remapBreakpoint(props.layout, oldCols, newCols)

    if (remapped) emit('update:layout', remapped)
  }
})

// ── Density Engine (ADR-152 — pure height-based) ──

const tileSizes = reactive({})

/**
 * Compute density from tile height (grid rows) only.
 * Pure height-based — monotone scaling guaranteed.
 *
 *   h <= 2 → S
 *   h <= 4 → M
 *   h >= 5 → L
 */
function computeDensity(h) {
  if (h <= 2) return 'S'
  if (h <= 4) return 'M'

  return 'L'
}

function getViewport(tile) {
  const size = tileSizes[tile.key]
  const px = size?.width || 0

  return {
    w: tile.w,
    h: tile.h,
    pxWidth: px,
    pxHeight: size?.height || 0,
    density: computeDensity(tile.h),
  }
}

// ResizeObserver for each tile
const observers = new Map()

function observeTile(key, el) {
  if (!el) {
    const obs = observers.get(key)
    if (obs) {
      obs.disconnect()
      observers.delete(key)
    }

    return
  }

  if (observers.has(key)) return

  const observer = new ResizeObserver(entries => {
    for (const entry of entries) {
      const { width, height } = entry.contentRect

      tileSizes[key] = { width: Math.round(width), height: Math.round(height) }
    }
  })

  observer.observe(el)
  observers.set(key, observer)
}

function setTileRef(key) {
  return el => observeTile(key, el)
}

onUnmounted(() => {
  for (const observer of observers.values()) {
    observer.disconnect()
  }
  observers.clear()
})

// ── Component resolution ──

function getComponent(tile) {
  const catalogEntry = props.catalog.find(w => w.key === tile.key)
  const componentKey = catalogEntry?.component || tile.key

  return resolveWidgetComponent(componentKey)
}

// ── Drag handle only (C1) ──

function handleDragHandleDown(tile, event) {
  if (!props.editable) return
  startDrag(tile, event)
}

function handleRemoveWidget(key) {
  const newLayout = props.layout.filter(tile => tile.key !== key)
  const resolved = applyPipeline(newLayout, null)

  emit('update:layout', resolved || newLayout)
}

function handleResizeDown(tile, event) {
  if (!props.editable) return
  startResize(tile, event)
}

// ── Mouse up → full pipeline (A3) ──

function handleMouseUp() {
  const result = onMouseUp()
  if (!result) return

  const newLayout = props.layout.map(tile => {
    if (tile.key === result.key) {
      return { ...tile, x: result.x, y: result.y, w: result.w, h: result.h }
    }

    return tile
  })

  // Apply full pipeline: clamp → resolve → compact → assert
  const resolved = applyPipeline(newLayout, result.key)

  if (resolved) {
    emit('update:layout', resolved)
  }
  else {
    placementErrorKey.value = 'dashboardGrid.noSpaceToPlaceWidget'
    placementError.value = true
  }
}

onMounted(() => {
  document.addEventListener('mouseup', handleMouseUp)
})

onUnmounted(() => {
  document.removeEventListener('mouseup', handleMouseUp)
})
</script>

<template>
  <div
    ref="gridRef"
    class="dashboard-grid"
    :style="{ '--dashboard-cols': cols, '--dashboard-gap': cols <= 4 ? '8px' : cols <= 6 ? '12px' : '16px' }"
  >
    <div
      v-for="tile in displayLayout"
      :key="tile.key"
      :ref="setTileRef(tile.key)"
      class="dashboard-tile"
      :class="{
        'dashboard-tile--dragging': dragging?.key === tile.key,
        'dashboard-tile--resizing': resizing?.key === tile.key,
      }"
      :style="{
        gridColumn: `${tile.x + 1} / span ${Math.min(tile.w, cols)}`,
        gridRow: `${tile.y + 1} / span ${tile.h}`,
      }"
    >
      <VCard
        variant="flat"
        class="dashboard-widget"
      >
        <div class="widget-shell">
          <!-- Editable toolbar — drag handle is ONLY the grip icon -->
          <div
            v-if="editable"
            class="widget-toolbar"
          >
            <VIcon
              icon="tabler-grip-vertical"
              size="16"
              class="drag-handle"
              @mousedown.stop.prevent="handleDragHandleDown(tile, $event)"
            />
            <VSpacer />
            <VTooltip
              v-if="layout.length <= 1"
              location="bottom"
            >
              <template #activator="{ props: tooltipProps }">
                <VBtn
                  icon
                  variant="text"
                  size="x-small"
                  disabled
                  class="toolbar-action"
                  v-bind="tooltipProps"
                >
                  <VIcon
                    icon="tabler-x"
                    size="16"
                  />
                </VBtn>
              </template>
              {{ t('dashboardGrid.minWidgetRequired') }}
            </VTooltip>
            <VBtn
              v-else
              icon
              variant="text"
              size="x-small"
              color="error"
              class="toolbar-action"
              @click.stop="handleRemoveWidget(tile.key)"
            >
              <VIcon
                icon="tabler-x"
                size="16"
              />
            </VBtn>
          </div>

          <!-- Widget content -->
          <component
            :is="getComponent(tile)"
            v-if="getComponent(tile)"
            :data="widgetData[tile.key]"
            :loading="loading"
            :scope="tile.scope"
            :viewport="getViewport(tile)"
          />
          <VCardText
            v-else
            class="text-center text-disabled"
          >
            {{ tile.key }}
          </VCardText>
        </div>

        <!-- Resize handle — visible on card hover only -->
        <div
          v-if="editable"
          class="dashboard-resize-handle"
          :style="{ cursor: isMobile ? 's-resize' : 'se-resize' }"
          @mousedown.stop.prevent="handleResizeDown(tile, $event)"
        >
          <VIcon
            icon="tabler-arrows-diagonal-2"
            size="14"
            class="resize-icon"
          />
        </div>
      </VCard>
    </div>

    <!-- Ghost preview -->
    <div
      v-if="ghostTile"
      class="dashboard-ghost"
      :style="{
        gridColumn: `${ghostTile.x + 1} / span ${Math.min(ghostTile.w, cols)}`,
        gridRow: `${ghostTile.y + 1} / span ${ghostTile.h}`,
      }"
    />
  </div>

  <!-- Placement error snackbar -->
  <VSnackbar
    v-model="placementError"
    color="error"
    :timeout="3000"
  >
    {{ placementErrorKey ? t(placementErrorKey) : '' }}
  </VSnackbar>
</template>

<style scoped>
.dashboard-grid {
  display: grid;
  grid-template-columns: repeat(var(--dashboard-cols, 12), 1fr);
  grid-auto-rows: 80px;
  gap: var(--dashboard-gap, 16px);
  min-height: 200px;
  position: relative;
}

.dashboard-tile {
  position: relative;
  min-height: 0;
  transition: grid-column 0.15s ease, grid-row 0.15s ease;
}

.dashboard-tile--dragging,
.dashboard-tile--resizing {
  opacity: 0.5;
  z-index: 10;
}

.dashboard-widget {
  background-color: rgb(var(--v-theme-surface));
  border-radius: 12px;
  height: 100%;
  overflow: hidden;
}

.widget-shell {
  height: 100%;
  position: relative;
}

.widget-toolbar {
  position: absolute;
  inset: 0 0 auto 0;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px;
  z-index: 2;
  pointer-events: none;
}

.widget-toolbar > * {
  pointer-events: auto;
}

.widget-shell > :last-child {
  height: 100%;
  min-height: 0;
  overflow: hidden;
}

.drag-handle,
.toolbar-action {
  opacity: 0;
  transition: opacity 0.15s;
}

.drag-handle {
  cursor: grab;
}

.dashboard-widget:hover .drag-handle,
.dashboard-widget:hover .toolbar-action {
  opacity: 0.5;
}

.drag-handle:hover,
.toolbar-action:hover {
  opacity: 1;
}

.drag-handle:active {
  cursor: grabbing;
}

.dashboard-resize-handle {
  position: absolute;
  right: 4px;
  bottom: 4px;
  width: 24px;
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  transition: opacity 0.15s;
}

.resize-icon {
  color: rgba(var(--v-theme-on-surface), 0.4);
  transition: color 0.15s;
}

.dashboard-resize-handle:hover .resize-icon {
  color: rgb(var(--v-theme-primary));
}

.dashboard-widget:hover .dashboard-resize-handle {
  opacity: 1;
}

.dashboard-ghost {
  background: rgba(var(--v-theme-primary), 0.08);
  border: 2px dashed rgba(var(--v-theme-primary), 0.3);
  border-radius: 8px;
  pointer-events: none;
}
</style>
