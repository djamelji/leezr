<script setup>
// Leezr 5.0 (L0) — Company workspace proposal (V22-inspired). Prototype: fictitious data, nothing connected.
// Composition depends on the JobDomain AND the role; a young company sees a guided start instead of empty widgets.
// Only data Leezr already has (shipments, planning shifts, clock entries, leaves, timesheets, documents) is used;
// the « Services » variant shows where a missing horizontal module (scheduling, ADR-563) would plug in.
import LabBanner from '@/components/design-lab/LabBanner.vue'
import AppPageHero from '@/components/app/AppPageHero.vue'
import AppKpiTile from '@/components/app/AppKpiTile.vue'
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'
import { useTheme } from 'vuetify'

definePage({ meta: { devOnly: true } })

const theme = useTheme()
const domain = ref('logistique') // logistique | services
const role = ref('owner') // owner | manager | driver
const young = ref(false)
const notifTab = ref('Tout')
const week = ref('Cette semaine')

const team = [
  { i: 'KM', name: 'Karim M.', state: 'En tournée', color: 'success' },
  { i: 'SL', name: 'Sarah L.', state: 'En tournée', color: 'success' },
  { i: 'TB', name: 'Tom B.', state: 'Pause', color: 'warning' },
  { i: 'NA', name: 'Nadia A.', state: 'En tournée', color: 'success' },
  { i: 'JP', name: 'Jules P.', state: 'Au dépôt', color: 'info' },
  { i: 'EF', name: 'Emma F.', state: 'Absente', color: 'secondary' },
]

const agenda = [
  { time: '07:30', title: 'Tournée Nord — 6 arrêts', who: 'Karim M.', tone: 'primary' },
  { time: '09:00', title: 'Tournée Centre — 5 arrêts', who: 'Sarah L.', tone: 'primary' },
  { time: '14:00', title: 'Visite médicale', who: 'Tom B.', tone: 'warning' },
  { time: '16:30', title: 'Point dépôt hebdo', who: 'Équipe', tone: 'info' },
]

const actions = computed(() => [
  { icon: 'tabler-clock-check', label: 'Valider les feuilles de temps', badge: 3, color: 'primary', roles: ['owner', 'manager'] },
  { icon: 'tabler-truck-delivery', label: 'Assigner 2 expéditions', badge: 2, color: 'warning', roles: ['owner', 'manager'], domain: 'logistique' },
  { icon: 'tabler-beach', label: 'Répondre à 1 demande de congé', badge: 1, color: 'info', roles: ['owner', 'manager'] },
  { icon: 'tabler-file-upload', label: 'Relancer 4 documents manquants', badge: 4, color: 'error', roles: ['owner'] },
  { icon: 'tabler-user-plus', label: 'Ajouter une personne', color: 'success', roles: ['owner'] },
  { icon: 'tabler-file-export', label: 'Exporter les heures du mois', color: 'secondary', roles: ['owner', 'manager'] },
  { icon: 'tabler-route', label: 'Démarrer ma tournée', color: 'primary', roles: ['driver'], domain: 'logistique' },
  { icon: 'tabler-fingerprint', label: 'Pointer mon arrivée', color: 'success', roles: ['driver'] },
  { icon: 'tabler-beach', label: 'Demander un congé', color: 'info', roles: ['driver'] },
].filter(a => a.roles.includes(role.value) && (!a.domain || a.domain === domain.value)))

const notifications = [
  { i: 'NA', tab: 'Équipe', text: 'Nadia A. a terminé la livraison #2841', time: 'il y a 4 min', tone: 'success' },
  { i: 'TB', tab: 'Équipe', text: 'Tom B. a commencé sa pause', time: 'il y a 12 min', tone: 'warning' },
  { i: 'RH', tab: 'Documents', text: 'Permis de conduire de Jules P. expire dans 20 jours', time: 'il y a 1 h', tone: 'error' },
  { i: 'EF', tab: 'Équipe', text: 'Emma F. a demandé 2 jours de congé', time: 'il y a 2 h', tone: 'info' },
]

const shownNotifs = computed(() => notifications.filter(n => notifTab.value === 'Tout' || n.tab === notifTab.value))

const hoursOptions = computed(() => {
  const c = theme.current.value.colors
  const dark = theme.current.value.dark

  return {
    chart: { toolbar: { show: false }, animations: { enabled: true, speed: 500, dynamicAnimation: { enabled: false } }, fontFamily: 'inherit' },
    plotOptions: { bar: { borderRadius: 8, columnWidth: '42%', borderRadiusApplication: 'end' } },
    colors: [c.primary, dark ? '#4a4f6e' : '#d6d9e6'],
    dataLabels: { enabled: false },
    legend: { position: 'top', horizontalAlign: 'left', labels: { colors: dark ? '#cfd3ec' : '#5d596c' } },
    grid: { borderColor: dark ? 'rgba(255,255,255,.08)' : 'rgba(47,43,61,.08)', strokeDashArray: 5 },
    xaxis: { categories: ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'], axisBorder: { show: false }, axisTicks: { show: false }, labels: { style: { colors: dark ? '#a3a6c4' : '#8c8798' } } },
    yaxis: { labels: { formatter: v => `${Math.round(v)} h`, style: { colors: dark ? '#a3a6c4' : '#8c8798' } } },
    tooltip: { shared: true, intersect: false, theme: dark ? 'dark' : 'light', y: { formatter: v => `${v} h` } },
  }
})

const statusOptions = computed(() => ({
  labels: ['Terminées', 'En cours', 'En retard', 'Non assignées'],
  colors: ['#28c76f', '#7367f0', '#ff4c51', '#ff9f43'],
  legend: { show: false },
  dataLabels: { enabled: false },
  stroke: { width: 3, colors: [theme.current.value.colors.surface] },
  plotOptions: { pie: { donut: { size: '74%', labels: { show: true, value: { fontSize: '24px', fontWeight: 700, offsetY: -12, color: theme.current.value.dark ? '#e6e8f5' : '#2f2b3d' }, name: { offsetY: 18, color: theme.current.value.dark ? '#a3a6c4' : '#8c8798' }, total: { show: true, label: 'aujourd\'hui', fontSize: '12px' } } } } },
  tooltip: { theme: theme.current.value.dark ? 'dark' : 'light' },
}))

const isDriver = computed(() => role.value === 'driver')
</script>

<template>
  <div class="lz-lab">
    <LabBanner
      name="Espace de travail Company (V22)"
      compare="/dashboard"
    />

    <div class="d-flex flex-wrap gap-3 mb-4 align-center">
      <div
        v-for="ctl in [
          { label: 'Métier', model: 'domain', opts: [['logistique', 'Logistique'], ['services', 'Services']] },
          { label: 'Rôle', model: 'role', opts: [['owner', 'Dirigeant'], ['manager', 'Manager'], ['driver', 'Chauffeur']] },
          { label: 'Données', model: 'young', opts: [[false, 'Société active'], [true, 'Nouvelle société']] },
        ]"
        :key="ctl.label"
        class="d-flex align-center gap-2"
      >
        <span class="text-caption text-medium-emphasis">{{ ctl.label }}</span>
        <div class="lz-seg">
          <button
            v-for="o in ctl.opts"
            :key="String(o[0])"
            type="button"
            class="lz-seg__btn"
            :class="{ 'lz-seg__btn--on': (ctl.model === 'domain' ? domain : ctl.model === 'role' ? role : young) === o[0] }"
            @click="ctl.model === 'domain' ? (domain = o[0]) : ctl.model === 'role' ? (role = o[0]) : (young = o[0])"
          >
            {{ o[1] }}
          </button>
        </div>
      </div>
    </div>

    <VRow class="lz-grid">
      <!-- LEFT: hero, KPIs, work -->
      <VCol
        cols="12"
        lg="8"
      >
        <AppPageHero
          variant="workspace"
          :greeting="isDriver ? 'Bonne tournée,' : 'Bonjour,'"
          :name="isDriver ? 'Karim' : 'Marie'"
          :subtitle="young
            ? 'Bienvenue sur Leezr. Trois étapes pour que votre espace travaille pour vous.'
            : isDriver
              ? '6 arrêts aujourd\'hui · prise de poste 07:30 au dépôt'
              : domain === 'logistique'
                ? '14 livraisons prévues aujourd\'hui, 9 déjà terminées. 2 retards à surveiller.'
                : '11 personnes au travail aujourd\'hui. 3 validations vous attendent.'"
          :chips="young ? [{ icon: 'tabler-sparkles', text: 'Démarrage' }] : [{ live: true, text: '6 en activité' }, { icon: 'tabler-calendar', text: 'Lundi 23 novembre' }]"
          :people="young ? [] : team.map(t => t.i)"
          :progress="young ? 33 : domain === 'logistique' ? 64 : 82"
          :progress-label="young ? 'mise en route' : domain === 'logistique' ? 'livraisons terminées' : 'présences'"
        >
          <template #actions>
            <VBtn
              class="lzr-on-brand"
              :prepend-icon="isDriver ? 'tabler-route' : domain === 'logistique' ? 'tabler-truck' : 'tabler-calendar-plus'"
            >
              {{ isDriver ? 'Démarrer ma tournée' : domain === 'logistique' ? 'Nouvelle expédition' : 'Planifier' }}
            </VBtn>
            <VBtn
              variant="outlined"
              color="white"
              prepend-icon="tabler-calendar-week"
            >
              Planning du jour
            </VBtn>
          </template>
        </AppPageHero>

        <!-- young company: guided start instead of empty widgets -->
        <VRow
          v-if="young"
          class="mt-1"
        >
          <VCol
            v-for="(s, i) in [
              { icon: 'tabler-users-plus', title: 'Ajoutez votre équipe', text: 'Importez ou invitez vos collaborateurs. Leezr crée leurs fiches et leurs accès.', done: true, cta: 'Ajouter' },
              { icon: 'tabler-calendar-cog', title: 'Réglez le temps de travail', text: 'Convention, horaires, pauses : l\'assistant Workforce pré-remplit selon votre métier.', done: false, cta: 'Ouvrir l\'assistant' },
              { icon: domain === 'logistique' ? 'tabler-truck' : 'tabler-calendar-event', title: domain === 'logistique' ? 'Créez votre première expédition' : 'Publiez votre premier planning', text: 'Vos indicateurs du jour apparaîtront ici dès la première opération.', done: false, cta: 'Commencer' },
            ]"
            :key="s.title"
            cols="12"
            md="4"
          >
            <VCard
              class="lzr-card lzr-card--raised h-100 pa-5 lz-step"
              :class="{ 'lz-step--done': s.done }"
            >
              <div class="d-flex align-center justify-space-between mb-3">
                <VAvatar
                  :color="s.done ? 'success' : 'primary'"
                  variant="tonal"
                  rounded="lg"
                >
                  <VIcon :icon="s.done ? 'tabler-check' : s.icon" />
                </VAvatar>
                <span class="text-caption text-medium-emphasis">Étape {{ i + 1 }}/3</span>
              </div>
              <div class="text-h6 mb-1">
                {{ s.title }}
              </div>
              <p class="text-body-2 text-medium-emphasis mb-4">
                {{ s.text }}
              </p>
              <VBtn
                :variant="s.done ? 'text' : 'tonal'"
                :color="s.done ? 'success' : 'primary'"
                size="small"
                :prepend-icon="s.done ? 'tabler-circle-check' : undefined"
              >
                {{ s.done ? 'Fait' : s.cta }}
              </VBtn>
            </VCard>
          </VCol>
        </VRow>

        <!-- KPIs -->
        <VRow
          v-else
          class="mt-1"
        >
          <template v-if="domain === 'logistique' && !isDriver">
            <VCol
              cols="6"
              md="3"
            >
              <AppKpiTile
                label="Livraisons du jour"
                icon="tabler-truck-delivery"
                :value="14"
                :delta="12"
                delta-label="vs lundi dernier"
                :spark="[9, 11, 10, 12, 13, 12, 14]"
                spark-type="bar"
              />
            </VCol>
            <VCol
              cols="6"
              md="3"
            >
              <AppKpiTile
                label="En retard"
                icon="tabler-clock-exclamation"
                :value="2"
                color="error"
                :delta="-33"
                delta-label="vs hier"
              />
            </VCol>
            <VCol
              cols="6"
              md="3"
            >
              <AppKpiTile
                label="Chauffeurs actifs"
                icon="tabler-steering-wheel"
                :value="6"
                color="success"
                :spark="[4, 5, 6, 6, 5, 6, 6]"
              />
            </VCol>
            <VCol
              cols="6"
              md="3"
            >
              <AppKpiTile
                label="Non assignées"
                icon="tabler-package"
                :value="2"
                color="warning"
              />
            </VCol>
          </template>
          <template v-else-if="isDriver">
            <VCol
              v-for="k in [
                { label: 'Mes arrêts', icon: 'tabler-map-pin', value: 6, color: 'primary' },
                { label: 'Terminés', icon: 'tabler-circle-check', value: 2, color: 'success' },
                { label: 'Heures cette semaine', icon: 'tabler-clock', value: 28.5, color: 'info', decimals: 1 },
                { label: 'Congés restants', icon: 'tabler-beach', value: 12, color: 'warning' },
              ]"
              :key="k.label"
              cols="6"
              md="3"
            >
              <AppKpiTile v-bind="k" />
            </VCol>
          </template>
          <template v-else>
            <VCol
              v-for="k in [
                { label: 'Présents', icon: 'tabler-user-check', value: 11, color: 'success', spark: [9, 10, 11, 10, 11, 11, 11] },
                { label: 'Absents', icon: 'tabler-user-off', value: 2, color: 'secondary' },
                { label: 'Pointages en anomalie', icon: 'tabler-alert-triangle', value: 1, color: 'error' },
                { label: 'Heures à valider', icon: 'tabler-clock-check', value: 3, color: 'warning' },
              ]"
              :key="k.label"
              cols="6"
              md="3"
            >
              <AppKpiTile v-bind="k" />
            </VCol>
          </template>
        </VRow>
      </VCol>

      <!-- RIGHT: day agenda -->
      <VCol
        cols="12"
        lg="4"
      >
        <AppWidgetShell
          title="Lundi 23 novembre"
          subtitle="Planning du jour"
          icon="tabler-calendar"
          link="Planning"
        >
          <div class="lz-days mb-4">
            <button
              v-for="(d, i) in [['L', 23], ['M', 24], ['M', 25], ['J', 26], ['V', 27], ['S', 28]]"
              :key="i"
              type="button"
              class="lz-day"
              :class="{ 'lz-day--on': i === 0 }"
            >
              <span>{{ d[0] }}</span><strong>{{ d[1] }}</strong>
            </button>
          </div>
          <div
            v-if="young"
            class="lz-empty"
          >
            <VIcon
              icon="tabler-calendar-plus"
              size="36"
              color="primary"
            />
            <div class="font-weight-medium mt-2">
              Aucun créneau planifié
            </div>
            <div class="text-body-2 text-medium-emphasis mb-3">
              Publiez un planning : votre équipe le reçoit sur son téléphone.
            </div>
            <VBtn
              size="small"
              variant="tonal"
            >
              Créer le planning
            </VBtn>
          </div>
          <div
            v-else
            class="d-flex flex-column gap-2"
          >
            <div
              v-for="a in agenda"
              :key="a.time"
              class="lz-agenda"
              :class="`lz-agenda--${a.tone}`"
            >
              <span class="lz-agenda__time">{{ a.time }}</span>
              <div class="min-w-0">
                <div class="font-weight-medium text-truncate">
                  {{ a.title }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ a.who }}
                </div>
              </div>
            </div>
          </div>
        </AppWidgetShell>
      </VCol>

      <!-- Quick actions -->
      <VCol cols="12">
        <div class="lz-actions">
          <button
            v-for="a in actions"
            :key="a.label"
            type="button"
            class="lz-action"
          >
            <VAvatar
              :color="a.color"
              variant="tonal"
              rounded="lg"
              size="38"
            >
              <VIcon :icon="a.icon" />
            </VAvatar>
            <span class="text-start flex-grow-1">{{ a.label }}</span>
            <VBadge
              v-if="a.badge"
              :content="a.badge"
              :color="a.color"
              inline
            />
            <VIcon
              icon="tabler-chevron-right"
              size="18"
              class="text-medium-emphasis"
            />
          </button>
        </div>
      </VCol>

      <template v-if="!young">
        <!-- Deliveries status (logistics) / hours (others) -->
        <VCol
          v-if="domain === 'logistique' && !isDriver"
          cols="12"
          md="6"
          lg="4"
        >
          <AppWidgetShell
            title="Livraisons — état du jour"
            subtitle="Mis à jour en continu"
            icon="tabler-chart-donut"
            link="Expéditions"
          >
            <div class="d-flex align-center gap-4 flex-wrap">
              <VueApexCharts
                type="donut"
                width="160"
                height="160"
                :options="statusOptions"
                :series="[9, 1, 2, 2]"
              />
              <div class="d-flex flex-column gap-2 flex-grow-1">
                <div
                  v-for="(s, i) in [['Terminées', 9, '#28c76f'], ['En cours', 1, '#7367f0'], ['En retard', 2, '#ff4c51'], ['Non assignées', 2, '#ff9f43']]"
                  :key="i"
                  class="d-flex align-center gap-2 text-body-2"
                >
                  <span
                    class="lz-dot"
                    :style="{ background: s[2] }"
                  /><span class="flex-grow-1">{{ s[0] }}</span><strong>{{ s[1] }}</strong>
                </div>
              </div>
            </div>
          </AppWidgetShell>
        </VCol>

        <VCol
          cols="12"
          md="6"
          :lg="domain === 'logistique' && !isDriver ? 4 : 6"
        >
          <AppWidgetShell
            v-model:period="week"
            :title="isDriver ? 'Mes heures' : 'Heures pointées vs planifiées'"
            subtitle="Source : pointages et planning publiés"
            icon="tabler-clock-hour-4"
            color="info"
            :periods="['Cette semaine', 'Mois']"
          >
            <VueApexCharts
              type="bar"
              height="210"
              :options="hoursOptions"
              :series="[{ name: 'Pointées', data: [62, 71, 68, 74, 70, 22] }, { name: 'Planifiées', data: [64, 70, 70, 72, 72, 24] }]"
            />
          </AppWidgetShell>
        </VCol>

        <!-- Team -->
        <VCol
          v-if="!isDriver"
          cols="12"
          md="6"
          :lg="domain === 'logistique' ? 4 : 6"
        >
          <AppWidgetShell
            title="Équipe en ce moment"
            :subtitle="`${team.filter(t => t.color === 'success').length} en activité · 1 absente`"
            icon="tabler-users"
            color="success"
            link="Personnes"
          >
            <div class="lz-team">
              <div
                v-for="m in team"
                :key="m.i"
                class="lz-team__m"
              >
                <VBadge
                  dot
                  location="bottom end"
                  :color="m.color"
                  offset-x="4"
                  offset-y="4"
                >
                  <VAvatar
                    size="46"
                    color="primary"
                    variant="tonal"
                  >
                    <span class="font-weight-bold">{{ m.i }}</span>
                  </VAvatar>
                </VBadge>
                <div class="text-body-2 font-weight-medium mt-1 text-truncate">
                  {{ m.name }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ m.state }}
                </div>
              </div>
            </div>
          </AppWidgetShell>
        </VCol>

        <!-- Services: missing module, proposed with its data source -->
        <VCol
          v-if="domain === 'services' && !isDriver"
          cols="12"
          md="6"
        >
          <VCard class="lzr-card lzr-card--flat h-100 pa-5 lz-proposal">
            <div class="d-flex align-center gap-2 mb-2">
              <VChip
                size="small"
                color="primary"
                variant="tonal"
                label
              >
                Module proposé
              </VChip>
              <span class="text-caption text-medium-emphasis">pas encore dans Leezr</span>
            </div>
            <div class="text-h6 mb-1">
              Rendez-vous et missions du jour
            </div>
            <p class="text-body-2 text-medium-emphasis mb-3">
              Pour un métier de services, l'accueil montrerait les rendez-vous et missions par intervenant. Ce widget
              demande le module horizontal <strong>scheduling</strong> (ADR-563), qui n'existe pas encore : il n'est pas
              affiché tant que la donnée n'existe pas.
            </p>
            <div class="text-caption">
              Source : <code>scheduling.appointments</code> · Dépendances : contacts, planning
            </div>
          </VCard>
        </VCol>

        <!-- Notifications -->
        <VCol
          cols="12"
          :md="isDriver ? 6 : 6"
          :lg="isDriver ? 6 : (domain === 'logistique' ? 12 : 6)"
        >
          <AppWidgetShell
            title="Fil d'activité"
            subtitle="Ce qui vient de se passer dans votre société"
            icon="tabler-bell"
            color="warning"
          >
            <template #head-actions>
              <div class="lz-seg">
                <button
                  v-for="t in ['Tout', 'Équipe', 'Documents']"
                  :key="t"
                  type="button"
                  class="lz-seg__btn"
                  :class="{ 'lz-seg__btn--on': notifTab === t }"
                  @click="notifTab = t"
                >
                  {{ t }}
                </button>
              </div>
            </template>
            <TransitionGroup
              name="lz-list"
              tag="div"
              class="lz-feed"
            >
              <div
                v-for="n in shownNotifs"
                :key="n.text"
                class="lz-feed__item"
              >
                <VAvatar
                  size="36"
                  :color="n.tone"
                  variant="tonal"
                >
                  <span class="text-caption font-weight-bold">{{ n.i }}</span>
                </VAvatar>
                <div class="min-w-0 flex-grow-1">
                  <div class="text-body-2">
                    {{ n.text }}
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    {{ n.time }}
                  </div>
                </div>
                <VBtn
                  size="small"
                  variant="text"
                  icon="tabler-chevron-right"
                />
              </div>
            </TransitionGroup>
          </AppWidgetShell>
        </VCol>
      </template>
    </VRow>
  </div>
</template>

<style scoped>
.lz-grid > [class*='v-col'] { animation: lz-in .45s cubic-bezier(.22, 1, .36, 1) both; }
.lz-grid > [class*='v-col']:nth-child(2) { animation-delay: 70ms; }
.lz-grid > [class*='v-col']:nth-child(n+3) { animation-delay: 140ms; }
.lz-seg { display: inline-flex; padding: 3px; border-radius: 10px; background: rgba(var(--v-theme-on-surface), .06); }
.lz-seg__btn { font-size: .8125rem; padding: .3rem .75rem; border-radius: 8px; color: rgba(var(--v-theme-on-surface), .7); transition: background .18s, color .18s; white-space: nowrap; }
.lz-seg__btn--on { background: rgb(var(--v-theme-surface)); color: rgb(var(--v-theme-on-surface)); font-weight: 600; box-shadow: 0 1px 3px rgba(0, 0, 0, .12); }
.lz-days { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: .375rem; }
.lz-day { display: flex; flex-direction: column; align-items: center; padding: .5rem 0; border-radius: 12px; font-size: .75rem; color: rgba(var(--v-theme-on-surface), .6); transition: background .18s; }
.lz-day strong { font-size: 1rem; color: rgb(var(--v-theme-on-surface)); }
.lz-day:hover { background: rgba(var(--v-theme-on-surface), .05); }
.lz-day--on { background: rgb(var(--v-theme-primary)); color: rgba(255, 255, 255, .85); box-shadow: 0 8px 18px -8px rgba(var(--v-theme-primary), .8); }
.lz-day--on strong { color: #fff; }
.lz-agenda { display: flex; gap: .75rem; align-items: center; padding: .625rem .75rem; border-radius: 12px; border-inline-start: 3px solid; background: rgba(var(--v-theme-on-surface), .03); }
.lz-agenda--primary { border-color: rgb(var(--v-theme-primary)); }
.lz-agenda--warning { border-color: rgb(var(--v-theme-warning)); }
.lz-agenda--info { border-color: rgb(var(--v-theme-info)); }
.lz-agenda__time { font-weight: 600; font-variant-numeric: tabular-nums; min-inline-size: 44px; }
.lz-actions { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: .75rem; }
.lz-action { display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem; border-radius: 14px; background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-border-color), .1); font-size: .9rem; font-weight: 500; color: rgb(var(--v-theme-on-surface)); transition: transform .18s, box-shadow .18s, border-color .18s; }
.lz-action:hover { transform: translateY(-2px); box-shadow: 0 10px 22px -12px rgba(47, 43, 61, .35); border-color: rgba(var(--v-theme-primary), .35); }
.lz-dot { inline-size: 10px; block-size: 10px; border-radius: 50%; display: inline-block; }
.lz-team { display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 1rem .5rem; text-align: center; }
.lz-team__m { display: flex; flex-direction: column; align-items: center; min-inline-size: 0; }
.lz-feed { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: .5rem 1rem; }
.lz-feed__item { display: flex; align-items: center; gap: .75rem; padding: .5rem; border-radius: 12px; transition: background .18s; }
.lz-feed__item:hover { background: rgba(var(--v-theme-on-surface), .04); }
.lz-empty { text-align: center; padding: 1.5rem .5rem; border-radius: 14px; border: 1px dashed rgba(var(--v-border-color), .3); }
.lz-step--done { opacity: .75; }
.lz-proposal { border-style: dashed !important; }
.lz-list-enter-active, .lz-list-leave-active { transition: all .22s ease; }
.lz-list-enter-from, .lz-list-leave-to { opacity: 0; transform: translateY(6px); }
@keyframes lz-in { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) {
  .lz-grid > [class*='v-col'] { animation: none; }
  .lz-action:hover { transform: none; }
}
</style>
