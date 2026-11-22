<script setup>
// Leezr 5.0 L4 (ADR-856) — visual recipe matrix (devOnly lab). Renders EVERY widget of the user's permissioned
// catalog with its REAL component and REAL data (same catalog + batch data APIs as the dashboard engine), at the
// grid sizes the widget allows (min / default / max useful), plus its empty, error and loading states.
// Not an engine : no layout, no persistence — a test bench. ?only=<widget key> renders one widget (captures).
import { resolveWidgetComponent } from '@/components/dashboard/widgetComponentMap'
import { useCompanyComplianceStore } from '@/modules/company/dashboard/compliance.store'

const props = defineProps({
  apiFn: { type: Function, required: true },
  explicitScope: { type: Boolean, default: false }, // platform batch needs scope per widget
})

const route = useRoute()
const { t } = useI18n()
const catalog = ref([])
const data = ref({})
const loadingAll = ref(true)
const error = ref('')
const only = computed(() => route.query.only || '')
const COL_PX = 79.7 // 12 columns of a ~1130 px grid, 16 px gap (DashboardGrid)
const ROW_PX = 80
const GAP = 16
const px = (n, unit) => n * unit + (n - 1) * GAP

onMounted(async () => {
  try {
    const c = await props.apiFn('/dashboard/widgets/catalog')

    catalog.value = (c.widgets || []).filter(w => !only.value || w.key === only.value)

    // client-resolved compliance widgets read the store the home loads — only when such a widget is in the catalog
    if (!props.explicitScope && catalog.value.some(w => w.key.startsWith('compliance.') || w.key === 'documents.detail')) useCompanyComplianceStore().fetchCompliance()
    for (let i = 0; i < catalog.value.length; i += 20) {
      const chunk = catalog.value.slice(i, i + 20).map(w => (props.explicitScope ? { key: w.key, scope: 'global' } : { key: w.key, period: '30d' }))
      const r = await props.apiFn('/dashboard/widgets/data', { method: 'POST', body: { widgets: chunk } })
      for (const x of r.results || []) data.value[x.key] = x.error ? { error: true, reason: x.error } : x.data
    }
  }
  catch (e) {
    error.value = e?.message || 'load failed'
  }
  finally {
    loadingAll.value = false
  }
})

const sizesOf = w => {
  const l = w.layout || {}

  const list = [
    { label: 'min', w: Math.max(3, l.min_w || 3), h: Math.max(2, l.min_h || 2) },
    { label: 'défaut', w: l.default_w || 4, h: l.default_h || 2 },
    { label: 'max utile', w: Math.min(12, l.max_w || 12), h: Math.min(6, l.max_h || 4) },
  ]

  return list.filter((s, i) => list.findIndex(o => o.w === s.w && o.h === s.h) === i)
}

const STATES = [
  { key: 'empty', label: 'vide', props: () => ({ data: {}, loading: false }) },
  { key: 'error', label: 'erreur', props: () => ({ data: { error: true }, loading: false }) },
  { key: 'loading', label: 'chargement', props: () => ({ data: null, loading: true }) },
]
</script>

<template>
  <div class="wm">
    <VAlert
      v-if="error"
      type="error"
      variant="tonal"
      class="mb-4"
    >
      {{ error }}
    </VAlert>
    <VSkeletonLoader
      v-if="loadingAll"
      type="card@2"
    />
    <section
      v-for="w in catalog"
      :key="w.key"
      class="wm-widget"
      :data-widget="w.key"
    >
      <div class="wm-head">
        <span class="text-h6">{{ t(w.label_key) }}</span>
        <code>{{ w.key }}</code>
        <span class="text-caption text-medium-emphasis">bornes {{ w.layout?.min_w }}×{{ w.layout?.min_h }} → {{ w.layout?.max_w }}×{{ w.layout?.max_h }}</span>
      </div>
      <div class="wm-row">
        <div
          v-for="s in sizesOf(w)"
          :key="s.label"
          class="wm-cell"
        >
          <div class="wm-cap">
            {{ s.label }} · {{ s.w }}×{{ s.h }}
          </div>
          <div
            class="wm-tile"
            :style="{ inlineSize: `${px(s.w, COL_PX)}px`, blockSize: `${px(s.h, ROW_PX)}px` }"
          >
            <component
              :is="resolveWidgetComponent(w.component)"
              :data="data[w.key]"
              :loading="loadingAll"
              scope="company"
              :viewport="{}"
            />
          </div>
        </div>
      </div>
      <div class="wm-row wm-row--states">
        <div
          v-for="st in STATES"
          :key="st.key"
          class="wm-cell"
        >
          <div class="wm-cap">
            {{ st.label }} · {{ Math.min(6, w.layout?.default_w || 4) }}×{{ w.layout?.default_h || 2 }}
          </div>
          <div
            class="wm-tile"
            :style="{ inlineSize: `${px(Math.min(6, w.layout?.default_w || 4), COL_PX)}px`, blockSize: `${px(w.layout?.default_h || 2, ROW_PX)}px` }"
          >
            <component
              :is="resolveWidgetComponent(w.component)"
              v-bind="st.props()"
              scope="company"
              :viewport="{}"
            />
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.wm-widget { padding-block: 1.25rem 1.75rem; border-block-end: 1px dashed rgba(var(--v-border-color), .2); }
.wm-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: .75rem; margin-block-end: .75rem; }
.wm-row { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-start; }
.wm-row--states { margin-block-start: 1rem; }
.wm-cap { font-size: .75rem; color: rgba(var(--v-theme-on-surface), .6); margin-block-end: .25rem; }
.wm-tile {
  max-inline-size: 100%;
  overflow: hidden; /* = the grid tile (DashboardGrid .dashboard-widget) */
  border-radius: 16px;
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-border-color), .07);
  box-shadow: 0 2px 6px rgba(47, 43, 61, .07), 0 1px 2px rgba(47, 43, 61, .05);
}
.wm-tile > :deep(*) { block-size: 100%; }
</style>
