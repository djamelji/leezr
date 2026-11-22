<script setup>
// Leezr 5.0 DESIGN LAB — Platform cockpit proposal (V35-inspired). Prototype: fictitious data, nothing connected.
// Every block maps to a data source Leezr ALREADY has (see docs/bmad/leezr-5-design/04-dashboards-widgets.md).
import LabBanner from '@/components/lab/LabBanner.vue'
import LzHero from '@/components/lab/LzHero.vue'
import LzKpiTile from '@/components/lab/LzKpiTile.vue'
import LzLivePanel from '@/components/lab/LzLivePanel.vue'
import LzWidget from '@/components/lab/LzWidget.vue'
import { useTheme } from 'vuetify'

definePage({ meta: { layout: 'platform', platform: true, devOnly: true } })

const theme = useTheme()
const viewAs = ref('super_admin')
const canFinance = computed(() => viewAs.value === 'super_admin')
const period = ref('12 mois')

const months = ['déc', 'janv', 'févr', 'mars', 'avr', 'mai', 'juin', 'juil', 'août', 'sept', 'oct', 'nov']
const mrr = [182, 190, 204, 215, 226, 238, 241, 259, 274, 301, 338, 363]
const invoiced = [1.9, 2.1, 2.2, 2.4, 2.5, 2.7, 2.6, 2.9, 3.1, 3.3, 3.6, 3.8]
const collected = [1.8, 2.0, 2.2, 2.3, 2.5, 2.6, 2.6, 2.8, 3.0, 3.2, 3.4, 3.5]

const revenueOptions = computed(() => {
  const c = theme.current.value.colors
  const dark = theme.current.value.dark

  return {
    chart: { toolbar: { show: false }, stacked: false, animations: { enabled: true, speed: 600, dynamicAnimation: { enabled: false } }, fontFamily: 'inherit' },
    stroke: { width: [3, 0, 0], curve: 'smooth' },
    plotOptions: { bar: { columnWidth: '46%', borderRadius: 5 } },
    colors: [c.primary, 'rgba(var(--v-theme-info), .35)', c.success],
    fill: { opacity: [1, .35, .85] },
    dataLabels: { enabled: false },
    legend: { position: 'top', horizontalAlign: 'left', fontSize: '13px', markers: { radius: 12 }, labels: { colors: dark ? '#cfd3ec' : '#5d596c' } },
    grid: { borderColor: dark ? 'rgba(255,255,255,.08)' : 'rgba(47,43,61,.08)', strokeDashArray: 5, padding: { left: 4, right: 8 } },
    xaxis: { categories: months, tickAmount: 6, axisBorder: { show: false }, axisTicks: { show: false }, labels: { rotate: 0, hideOverlappingLabels: true, style: { colors: dark ? '#a3a6c4' : '#8c8798' } } },
    yaxis: [
      { seriesName: 'MRR', title: { text: undefined }, labels: { formatter: v => `${Math.round(v)} €`, style: { colors: dark ? '#a3a6c4' : '#8c8798' } } },
      { seriesName: 'Facturé', opposite: true, labels: { formatter: v => `${v.toFixed(1)} k€`, style: { colors: dark ? '#a3a6c4' : '#8c8798' } } },
      { seriesName: 'Facturé', show: false },
    ],
    tooltip: { shared: true, intersect: false, theme: dark ? 'dark' : 'light' },
    markers: { size: [0, 0, 0], hover: { size: 5 } },
  }
})

const revenueSeries = [
  { name: 'MRR', type: 'line', data: mrr },
  { name: 'Facturé', type: 'column', data: invoiced },
  { name: 'Encaissé', type: 'column', data: collected },
]

const funnel = [
  { step: 'Visites inscription', n: 412, pct: 100 },
  { step: 'Métier choisi', n: 268, pct: 65 },
  { step: 'Offre choisie', n: 171, pct: 41 },
  { step: 'Compte créé', n: 64, pct: 16 },
  { step: 'Société active J+7', n: 41, pct: 10 },
]

const alerts = [
  { tone: 'error', icon: 'tabler-credit-card-off', title: '2 paiements échoués', meta: 'Transports Morel · Atelier Vidal', action: 'Relancer', finance: true },
  { tone: 'warning', icon: 'tabler-hourglass-high', title: '3 essais se terminent sous 5 jours', meta: 'Sans moyen de paiement enregistré', action: 'Voir' },
  { tone: 'warning', icon: 'tabler-file-certificate', title: '1 abonnement à valider', meta: 'Demande Business · Logistique Sud', action: 'Valider', finance: true },
  { tone: 'info', icon: 'tabler-help-circle', title: '2 tickets non attribués', meta: 'Plus ancien : il y a 3 h', action: 'Attribuer' },
]

const modules = [
  { name: 'Planning', pct: 78, color: 'primary' },
  { name: 'Pointage', pct: 71, color: 'info' },
  { name: 'Expéditions', pct: 64, color: 'success' },
  { name: 'Documents RH', pct: 52, color: 'warning' },
  { name: 'Paie', pct: 23, color: 'error' },
]

const trials = [
  { name: 'Coursiers Express', initials: 'CE', plan: 'Pro', days: 2, ready: 82, card: false },
  { name: 'Atelier Brun', initials: 'AB', plan: 'Starter', days: 4, ready: 45, card: false },
  { name: 'Logistique Sud', initials: 'LS', plan: 'Business', days: 5, ready: 91, card: true },
]

const activity = [
  { icon: 'tabler-building-plus', color: 'success', text: 'Nouvelle société : Coursiers Express (Logistique, Pro)', time: 'il y a 12 min' },
  { icon: 'tabler-receipt', color: 'primary', text: 'Facture F-2026-0412 payée — 290,00 €', time: 'il y a 40 min', finance: true },
  { icon: 'tabler-puzzle', color: 'info', text: 'Module Paie activé chez Atelier Vidal', time: 'il y a 1 h' },
  { icon: 'tabler-user-shield', color: 'warning', text: 'Rôle « Finance » modifié par Claire', time: 'il y a 3 h' },
]

const plans = [
  { name: 'Starter', mrr: '87 €', color: '#00bad1' },
  { name: 'Pro', mrr: '232 €', color: '#7367f0' },
  { name: 'Business', mrr: '44 €', color: '#28c76f' },
]

const planOptions = computed(() => ({
  labels: plans.map(p => p.name),
  colors: plans.map(p => p.color),
  legend: { show: false },
  dataLabels: { enabled: false },
  stroke: { width: 3, colors: [theme.current.value.colors.surface] },
  plotOptions: { pie: { donut: { size: '72%', labels: { show: true, name: { offsetY: 18, color: theme.current.value.dark ? '#a3a6c4' : '#8c8798' }, value: { offsetY: -14, fontSize: '22px', fontWeight: 700, color: theme.current.value.dark ? '#e6e8f5' : '#2f2b3d' }, total: { show: true, label: 'sociétés', fontSize: '12px' } } } } },
  tooltip: { theme: theme.current.value.dark ? 'dark' : 'light' },
}))

const visibleAlerts = computed(() => alerts.filter(a => canFinance.value || !a.finance))
const visibleActivity = computed(() => activity.filter(a => canFinance.value || !a.finance))
</script>

<template>
  <div class="lz-lab">
    <LabBanner
      name="Cockpit Platform (V35)"
      compare="/platform/dashboard/overview"
    />

    <div class="d-flex flex-wrap align-center gap-3 mb-2">
      <VBreadcrumbs
        :items="['Leezr', 'Cockpit']"
        class="pa-0 text-body-2"
      />
      <VSpacer />
      <div class="d-flex align-center gap-2">
        <span class="text-caption text-medium-emphasis">Voir comme</span>
        <div class="lz-seg">
          <button
            v-for="v in [['super_admin', 'Super admin'], ['support', 'Support (sans finance)']]"
            :key="v[0]"
            type="button"
            class="lz-seg__btn"
            :class="{ 'lz-seg__btn--on': viewAs === v[0] }"
            @click="viewAs = v[0]"
          >
            {{ v[1] }}
          </button>
        </div>
      </div>
    </div>

    <VRow class="lz-grid">
      <!-- Hero + KPI -->
      <VCol
        cols="12"
        lg="8"
      >
        <LzHero
          variant="cockpit"
          greeting="Bon retour,"
          name="Djamel"
          subtitle="Leezr tourne normalement. 3 points demandent votre attention aujourd'hui."
          :chips="[{ live: true, text: '26 sociétés actives' }, { icon: 'tabler-calendar', text: 'Dimanche 22 nov. · 17:41', color: 'secondary' }]"
        >
          <template #actions>
            <VBtn
              variant="tonal"
              prepend-icon="tabler-calendar-month"
            >
              Ce mois
            </VBtn>
            <VBtn
              color="primary"
              append-icon="tabler-download"
            >
              Rapport mensuel
            </VBtn>
          </template>
        </LzHero>

        <VRow class="mt-1">
          <VCol
            v-if="canFinance"
            cols="12"
            md="4"
          >
            <LzKpiTile
              accent
              label="MRR · HT"
              icon="tabler-chart-arrows-vertical"
              :value="363"
              format="eur"
              :delta="7.4"
              delta-label="vs octobre"
              :spark="mrr"
            />
          </VCol>
          <VCol
            cols="12"
            :md="canFinance ? 4 : 6"
          >
            <LzKpiTile
              label="Sociétés actives"
              icon="tabler-building-skyscraper"
              :value="26"
              :delta="8.3"
              delta-label="+2 ce mois"
              color="info"
              spark-type="bar"
              :spark="[18, 19, 19, 20, 21, 21, 22, 23, 23, 24, 24, 26]"
            />
          </VCol>
          <VCol
            cols="12"
            :md="canFinance ? 4 : 6"
          >
            <LzKpiTile
              label="Conversion inscriptions"
              icon="tabler-user-check"
              :value="15.6"
              format="pct"
              :decimals="1"
              :delta="-1.2"
              delta-label="conversion 30 j"
              color="success"
              :spark="[12, 14, 13, 16, 18, 17, 15, 16, 17, 16, 15.9, 15.6]"
            />
          </VCol>
        </VRow>
      </VCol>

      <!-- Live platform health -->
      <VCol
        cols="12"
        lg="4"
      >
        <LzLivePanel
          title="Santé de la plateforme"
          subtitle="Files, planificateur, base, disque, e-mail — SystemHealthService"
          status="ok"
          :tabs="['Base', 'Files', 'E-mail']"
          chart-label="Latence base · 60 min"
          chart-note="historique : nouvelle mesure"
          :series="[4, 3, 5, 4, 3, 9, 6, 4, 3, 4, 3, 5, 4, 3, 4]"
          :metrics="[
            { label: 'Planificateur', value: 'il y a 40', unit: ' s', icon: 'tabler-clock-play', tone: 'success' },
            { label: 'E-mails échoués 24 h', value: '0', icon: 'tabler-mail-x', tone: 'success' },
            { label: 'Jobs en file', value: '3', icon: 'tabler-stack-2' },
            { label: 'Jobs échoués 24 h', value: '0', icon: 'tabler-circle-x', tone: 'success' },
          ]"
        >
          <template #footer>
            <VBtn
              block
              variant="tonal"
              color="white"
              prepend-icon="tabler-activity-heartbeat"
            >
              Ouvrir Opérations
            </VBtn>
          </template>
        </LzLivePanel>
      </VCol>

      <!-- Revenue analytics -->
      <VCol
        v-if="canFinance"
        cols="12"
        lg="8"
      >
        <LzWidget
          v-model:period="period"
          title="Revenus et encaissements"
          subtitle="MRR (courbe), facturé et encaissé (barres) — HT"
          icon="tabler-report-money"
          :periods="['30 j', '12 mois', 'Année']"
          link="Facturation"
        >
          <template #default="{ mode }">
            <div class="d-flex flex-wrap gap-6 mb-2 lz-inline-kpis">
              <div><span class="text-medium-emphasis">ARR</span><strong>4 354 €</strong></div>
              <div><span class="text-medium-emphasis">Encaissé ce mois</span><strong>3 512 €</strong></div>
              <div><span class="text-medium-emphasis">Taux d'encaissement</span><strong class="text-success">92,5 %</strong></div>
              <div v-if="mode !== 'compact'">
                <span class="text-medium-emphasis">Impayés</span><strong class="text-error">284 €</strong>
              </div>
            </div>
            <VueApexCharts
              height="290"
              :options="revenueOptions"
              :series="revenueSeries"
            />
          </template>
        </LzWidget>
      </VCol>

      <!-- Priority alerts -->
      <VCol
        cols="12"
        :lg="canFinance ? 4 : 6"
      >
        <LzWidget
          title="À traiter en priorité"
          subtitle="Classé par impact"
          icon="tabler-flag-3"
          color="error"
          link="Tout voir"
        >
          <div class="d-flex flex-column gap-2">
            <TransitionGroup name="lz-list">
              <div
                v-for="a in visibleAlerts"
                :key="a.title"
                class="lz-alert"
                :class="`lz-alert--${a.tone}`"
              >
                <VAvatar
                  :color="a.tone"
                  variant="tonal"
                  size="36"
                  rounded="lg"
                >
                  <VIcon
                    :icon="a.icon"
                    size="19"
                  />
                </VAvatar>
                <div class="min-w-0 flex-grow-1">
                  <div class="font-weight-medium text-truncate">
                    {{ a.title }}
                  </div>
                  <div class="text-caption text-medium-emphasis text-truncate">
                    {{ a.meta }}
                  </div>
                </div>
                <VBtn
                  size="small"
                  variant="text"
                  :color="a.tone"
                  class="flex-shrink-0 px-2"
                >
                  {{ a.action }}
                </VBtn>
              </div>
            </TransitionGroup>
          </div>
        </LzWidget>
      </VCol>

      <!-- Funnel -->
      <VCol
        cols="12"
        md="6"
        :lg="canFinance ? 4 : 6"
      >
        <LzWidget
          title="Parcours d'inscription"
          subtitle="30 derniers jours"
          icon="tabler-filter"
          color="info"
        >
          <div class="d-flex flex-column gap-3 mt-1">
            <div
              v-for="(f, i) in funnel"
              :key="f.step"
              class="lz-funnel"
            >
              <div class="d-flex justify-space-between text-body-2 mb-1">
                <span>{{ f.step }}</span>
                <span><strong>{{ f.n }}</strong> <span class="text-medium-emphasis">· {{ f.pct }} %</span></span>
              </div>
              <div class="lz-funnel__track">
                <div
                  class="lz-funnel__bar"
                  :style="{ inlineSize: `${f.pct}%`, animationDelay: `${i * 70}ms` }"
                />
              </div>
            </div>
          </div>
        </LzWidget>
      </VCol>

      <!-- Trials ending -->
      <VCol
        cols="12"
        md="6"
        lg="4"
      >
        <LzWidget
          title="Essais qui se terminent"
          subtitle="Prochains 7 jours · préparation du compte"
          icon="tabler-hourglass-high"
          color="warning"
          link="Sociétés"
        >
          <div class="d-flex flex-column gap-3">
            <div
              v-for="t in trials"
              :key="t.name"
              class="d-flex align-center gap-3"
            >
              <VAvatar
                color="primary"
                variant="tonal"
                size="38"
              >
                <span class="text-body-2 font-weight-bold">{{ t.initials }}</span>
              </VAvatar>
              <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-center gap-2">
                  <span class="font-weight-medium text-truncate">{{ t.name }}</span>
                  <VChip
                    size="x-small"
                    label
                  >
                    {{ t.plan }}
                  </VChip>
                </div>
                <VProgressLinear
                  :model-value="t.ready"
                  :color="t.ready > 75 ? 'success' : 'warning'"
                  rounded
                  height="6"
                  class="mt-1"
                />
              </div>
              <div class="text-end">
                <div
                  class="font-weight-bold"
                  :class="t.days <= 2 ? 'text-error' : ''"
                >
                  J-{{ t.days }}
                </div>
                <VIcon
                  :icon="t.card ? 'tabler-credit-card' : 'tabler-credit-card-off'"
                  :color="t.card ? 'success' : 'medium-emphasis'"
                  size="16"
                />
              </div>
            </div>
          </div>
        </LzWidget>
      </VCol>

      <!-- Module adoption -->
      <VCol
        cols="12"
        md="6"
        lg="4"
      >
        <LzWidget
          title="Adoption des modules"
          subtitle="% des sociétés actives qui les utilisent"
          icon="tabler-puzzle"
          color="success"
          link="Catalogue"
        >
          <div class="d-flex flex-column gap-3 mt-1">
            <div
              v-for="m in modules"
              :key="m.name"
            >
              <div class="d-flex justify-space-between text-body-2 mb-1">
                <span>{{ m.name }}</span><strong>{{ m.pct }} %</strong>
              </div>
              <VProgressLinear
                :model-value="m.pct"
                :color="m.color"
                rounded
                height="8"
                bg-opacity="0.12"
              />
            </div>
          </div>
        </LzWidget>
      </VCol>

      <!-- Activity -->
      <VCol
        cols="12"
        lg="8"
      >
        <LzWidget
          title="Activité récente"
          subtitle="Journal d'audit Platform"
          icon="tabler-history"
          color="secondary"
          link="Journal"
        >
          <VTimeline
            side="end"
            class="lz-timeline lz-timeline--cols"
            density="compact"
            truncate-line="both"
            line-thickness="1"
          >
            <VTimelineItem
              v-for="a in visibleActivity"
              :key="a.text"
              :dot-color="a.color"
              size="x-small"
            >
              <div class="text-body-2">
                {{ a.text }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ a.time }}
              </div>
            </VTimelineItem>
          </VTimeline>
        </LzWidget>
      </VCol>
      <!-- Plan mix (existing PlanDistribution data) -->
      <VCol
        v-if="canFinance"
        cols="12"
        lg="4"
      >
        <LzWidget
          title="Répartition par offre"
          subtitle="26 sociétés · MRR par offre"
          icon="tabler-chart-donut-3"
          color="primary"
        >
          <div class="d-flex align-center gap-4 flex-wrap">
            <VueApexCharts
              type="donut"
              width="150"
              height="150"
              :options="planOptions"
              :series="[11, 13, 2]"
            />
            <div class="d-flex flex-column gap-3 flex-grow-1">
              <div
                v-for="p in plans"
                :key="p.name"
                class="d-flex align-center gap-2"
              >
                <span
                  class="lz-dot"
                  :style="{ background: p.color }"
                /><span class="flex-grow-1">{{ p.name }}</span><strong>{{ p.mrr }}</strong>
              </div>
            </div>
          </div>
        </LzWidget>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
.lz-grid > [class*='v-col'] { animation: lz-in .45s cubic-bezier(.22, 1, .36, 1) both; }
.lz-grid > [class*='v-col']:nth-child(2) { animation-delay: 60ms; }
.lz-grid > [class*='v-col']:nth-child(3) { animation-delay: 120ms; }
.lz-grid > [class*='v-col']:nth-child(n+4) { animation-delay: 180ms; }
.lz-inline-kpis > div { display: flex; flex-direction: column; font-size: .8125rem; }
.lz-inline-kpis strong { font-size: 1.125rem; letter-spacing: -.01em; }
.lz-alert { display: flex; align-items: center; gap: .75rem; padding: .625rem .75rem; border-radius: 12px; background: rgba(var(--v-theme-on-surface), .03); transition: background .18s, transform .18s; }
.lz-alert:hover { background: rgba(var(--v-theme-on-surface), .06); transform: translateX(2px); }
.lz-funnel__track { block-size: 10px; border-radius: 999px; background: rgba(var(--v-theme-info), .12); overflow: hidden; }
.lz-funnel__bar { block-size: 100%; border-radius: 999px; background: linear-gradient(90deg, rgb(var(--v-theme-info)), rgb(var(--v-theme-primary))); transform-origin: left; animation: lz-grow .7s cubic-bezier(.22, 1, .36, 1) both; }
.lz-timeline :deep(.v-timeline-item__body) { padding-block-end: .75rem; }
.lz-dot { inline-size: 10px; block-size: 10px; border-radius: 50%; display: inline-block; }
.lz-seg { display: inline-flex; padding: 3px; border-radius: 10px; background: rgba(var(--v-theme-on-surface), .06); }
.lz-seg__btn { font-size: .8125rem; padding: .3rem .75rem; border-radius: 8px; color: rgba(var(--v-theme-on-surface), .7); transition: background .18s, color .18s; white-space: nowrap; }
.lz-seg__btn--on { background: rgb(var(--v-theme-surface)); color: rgb(var(--v-theme-on-surface)); font-weight: 600; box-shadow: 0 1px 3px rgba(0, 0, 0, .12); }
.lz-list-enter-active, .lz-list-leave-active { transition: all .25s ease; }
.lz-list-enter-from, .lz-list-leave-to { opacity: 0; transform: translateX(-8px); }
@keyframes lz-in { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
@keyframes lz-grow { from { transform: scaleX(0); } to { transform: scaleX(1); } }
@media (prefers-reduced-motion: reduce) {
  .lz-grid > [class*='v-col'], .lz-funnel__bar { animation: none; }
  .lz-alert:hover { transform: none; }
}
</style>
