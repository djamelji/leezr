<script setup>
// Leezr 5.0 (L0) — trend widget (prototype) with a layout PER presentation mode, measured on the real
// container by the existing engine (through AppWidgetShell). Content is recomposed, never scaled down:
//   micro    → value + delta + sparkline
//   compact  → value, delta, small chart
//   standard → header KPIs + full chart
//   wide     → chart dominant, KPIs in a band
//   expanded → chart + breakdown aside + action
import { useTheme } from 'vuetify'
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'

const props = defineProps({
  title: { type: String, default: 'Revenu récurrent (MRR)' },
  state: { type: String, default: 'ready' }, // ready | loading | empty | error
})

const theme = useTheme()
const data = [182, 190, 204, 215, 226, 238, 241, 259, 274, 301, 338, 363]
const months = ['déc', 'janv', 'févr', 'mars', 'avr', 'mai', 'juin', 'juil', 'août', 'sept', 'oct', 'nov']

const opts = (spark, h) => {
  const c = theme.current.value.colors
  const dark = theme.current.value.dark

  return {
    chart: { sparkline: { enabled: spark }, toolbar: { show: false }, animations: { enabled: true, speed: 500, dynamicAnimation: { enabled: false } }, fontFamily: 'inherit' },
    stroke: { curve: 'smooth', width: 3 },
    fill: { type: 'gradient', gradient: { opacityFrom: .3, opacityTo: 0 } },
    colors: [c.primary],
    dataLabels: { enabled: false },
    grid: { borderColor: dark ? 'rgba(255,255,255,.08)' : 'rgba(47,43,61,.08)', strokeDashArray: 5, padding: { left: 0, right: 4 } },
    xaxis: { categories: months, labels: { show: !spark, style: { colors: dark ? '#a3a6c4' : '#8c8798' } }, axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { labels: { show: h > 160, formatter: v => `${Math.round(v)} €`, style: { colors: dark ? '#a3a6c4' : '#8c8798' } } },
    tooltip: { theme: dark ? 'dark' : 'light', y: { formatter: v => `${v} €` } },
  }
}
</script>

<template>
  <AppWidgetShell
    :title="title"
    subtitle="HT · 12 derniers mois"
    icon="tabler-chart-arrows-vertical"
    :periods="['30 j', '12 mois']"
    link="Détail"
  >
    <template #default="{ mode }">
      <div
        v-if="state === 'loading'"
        class="flex-grow-1 d-flex flex-column gap-3 pt-2"
      >
        <VSkeletonLoader
          type="heading"
          class="bg-transparent"
        />
        <VSkeletonLoader
          v-if="mode !== 'micro'"
          type="image"
          class="bg-transparent flex-grow-1"
        />
      </div>
      <div
        v-else-if="state === 'empty'"
        class="lz-state"
      >
        <VIcon
          icon="tabler-chart-line"
          size="30"
          color="primary"
        />
        <div class="font-weight-medium">
          Pas encore de revenu récurrent
        </div>
        <div
          v-if="mode !== 'micro'"
          class="text-body-2 text-medium-emphasis"
        >
          Il apparaîtra dès le premier abonnement payant.
        </div>
      </div>
      <div
        v-else-if="state === 'error'"
        class="lz-state"
      >
        <VIcon
          icon="tabler-plug-connected-x"
          size="30"
          color="error"
        />
        <div class="font-weight-medium">
          Données indisponibles
        </div>
        <VBtn
          size="small"
          variant="tonal"
          color="error"
          prepend-icon="tabler-refresh"
        >
          Réessayer
        </VBtn>
      </div>

      <template v-else>
        <!-- micro -->
        <div
          v-if="mode === 'micro'"
          class="d-flex align-end gap-3 flex-grow-1 lz-micro"
        >
          <div>
            <div class="lz-big">
              363 €
            </div>
            <div class="text-success text-body-2 font-weight-semibold">
              +7,4 %
            </div>
          </div>
          <VueApexCharts
            :key="`m-${mode}`"
            class="flex-grow-1"
            type="area"
            height="56"
            :options="opts(true, 56)"
            :series="[{ name: 'MRR', data }]"
          />
        </div>

        <!-- expanded: chart + aside -->
        <div
          v-else-if="mode === 'expanded'"
          class="d-flex gap-5 flex-grow-1"
        >
          <div class="flex-grow-1 min-w-0 d-flex flex-column">
            <div class="d-flex align-end gap-3 mb-1">
              <span class="lz-big">363 €</span><span class="text-success font-weight-semibold mb-1">+7,4 % vs octobre</span>
            </div>
            <VueApexCharts
              :key="`x-${mode}`"
              class="flex-grow-1"
              type="area"
              height="250"
              :options="opts(false, 300)"
              :series="[{ name: 'MRR', data }]"
            />
          </div>
          <div class="lz-aside">
            <div class="text-overline text-medium-emphasis">
              Variation du mois
            </div>
            <div
              v-for="r in [['Nouveaux', '+38 €', 'success'], ['Montées d\'offre', '+12 €', 'success'], ['Résiliations', '-21 €', 'error'], ['Remises', '-4 €', 'warning']]"
              :key="r[0]"
              class="d-flex justify-space-between py-2 lz-aside__row"
            >
              <span>{{ r[0] }}</span><strong :class="`text-${r[2]}`">{{ r[1] }}</strong>
            </div>
            <VBtn
              block
              variant="tonal"
              class="mt-3"
              size="small"
            >
              Voir les abonnements
            </VBtn>
          </div>
        </div>

        <!-- compact / standard / wide -->
        <div
          v-else
          class="d-flex flex-column flex-grow-1"
        >
          <div
            class="d-flex align-end gap-3"
            :class="mode === 'wide' ? 'mb-0' : 'mb-1'"
          >
            <span class="lz-big">363 €</span>
            <span class="text-success font-weight-semibold mb-1">+7,4 %</span>
            <template v-if="mode === 'wide'">
              <VDivider
                vertical
                class="mx-2"
              />
              <span class="text-body-2 text-medium-emphasis mb-1">ARR <strong class="text-high-emphasis">4 354 €</strong></span>
              <span class="text-body-2 text-medium-emphasis mb-1 ms-3">Abonnements <strong class="text-high-emphasis">10</strong></span>
            </template>
          </div>
          <VueApexCharts
            :key="`c-${mode}`"
            class="flex-grow-1"
            type="area"
            :height="mode === 'compact' ? 150 : mode === 'wide' ? 150 : 190"
            :options="opts(mode === 'compact', mode === 'standard' ? 190 : 150)"
            :series="[{ name: 'MRR', data }]"
          />
        </div>
      </template>
    </template>
  </AppWidgetShell>
</template>

<style scoped>
.lz-big { white-space: nowrap; font-size: 1.75rem; font-weight: 700; letter-spacing: -.03em; line-height: 1; }
.lz-micro .lz-big { font-size: 1.5rem; }
.lz-state { flex: 1 1 auto; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: .375rem; padding: .5rem; }
.lz-aside { flex: 0 0 220px; border-inline-start: 1px solid rgba(var(--v-border-color), .12); padding-inline-start: 1.25rem; }
.lz-aside__row { border-block-end: 1px dashed rgba(var(--v-border-color), .16); font-size: .875rem; }
</style>
