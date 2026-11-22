<script setup>
// Leezr 5.0 cockpit (ADR-855) — « Activité des modules » for platform.catalog_overview (manage_modules).
// Source unchanged : distinct companies per module (CompanyModule) ; business names added to the payload.
//   larger sizes → + companies per business domain (JobDomain).
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const mods = computed(() => d.value.top_modules || [])
const total = computed(() => d.value.companies_total || Math.max(1, ...mods.value.map(m => m.companies)))
const COLORS = ['primary', 'info', 'success', 'warning', 'secondary', 'primary', 'info', 'success']
</script>

<template>
  <AppWidgetShell
    bare
    :title="t('cockpit.modules.title')"
    :subtitle="t('cockpit.modules.subtitle', { n: d.modules_tracked ?? 0 })"
    icon="tabler-puzzle"
    color="success"
    :link="t('cockpit.modules.link')"
    :to="{ name: 'platform-catalog-tab', params: { tab: 'modules' } }"
    :state="loading && !data ? 'loading' : d.error ? 'error' : !mods.length ? 'empty' : 'ready'"
    :empty-title="t('cockpit.modules.emptyTitle')"
  >
    <template #default="{ mode, h }">
      <div class="d-flex gap-6 flex-grow-1 min-w-0">
        <div class="d-flex flex-column gap-3 flex-grow-1 min-w-0">
          <div
            v-for="(m, i) in mods.slice(0, Math.max(2, Math.floor((h - 80) / 46)))"
            :key="m.key"
          >
            <div class="d-flex justify-space-between text-body-2 mb-1 gap-2">
              <span class="text-truncate">{{ m.label || m.key }}</span>
              <span class="flex-shrink-0"><strong>{{ m.companies }}</strong> <span class="text-medium-emphasis">· {{ Math.round((m.companies / total) * 100) }} %</span></span>
            </div>
            <div class="cm-track">
              <div
                class="cm-fill lzr-grow"
                :class="`bg-${COLORS[i]}`"
                :style="{ inlineSize: `${Math.min(100, (m.companies / total) * 100)}%`, animationDelay: `${i * 60}ms` }"
              />
            </div>
          </div>
        </div>
        <div
          v-if="mode === 'expanded' || mode === 'wide'"
          class="cm-aside"
        >
          <div class="text-overline text-medium-emphasis mb-1">
            {{ t('cockpit.modules.byDomain') }}
          </div>
          <div
            v-for="j in d.jobdomains || []"
            :key="j.key"
            class="d-flex justify-space-between py-1 text-body-2"
          >
            <span class="text-truncate">{{ j.label }}</span><strong>{{ j.companies }}</strong>
          </div>
        </div>
      </div>
    </template>
  </AppWidgetShell>
</template>

<style scoped>
.cm-track { block-size: 8px; border-radius: 999px; background: rgba(var(--v-theme-on-surface), .07); overflow: hidden; }
.cm-fill { block-size: 100%; border-radius: 999px; }
.cm-aside { flex: 0 0 200px; border-inline-start: 1px solid rgba(var(--v-border-color), .12); padding-inline-start: 1.25rem; }
</style>
