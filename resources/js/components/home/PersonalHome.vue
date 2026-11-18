<script setup>
import { displayLocale } from '@/utils/datetime'
// ADR-751 (P3) — Accueil OPÉRATIONNEL de l'employé `/home`. PAS une copie du dashboard de
// gestion `/dashboard` : orienté ACTION (« que dois-je faire maintenant ? »). Composition
// ADAPTATIVE par modules + capacités + données RÉELLES (self-service, tenant-scoped). La
// grille de widgets personnalisable est conservée en section secondaire. Aucune fiction,
// aucun élargissement RBAC : chaque section n'apparaît que si son module/sa donnée existe.
import { useAuthStore } from '@/core/stores/auth'
import { useCan } from '@/composables/useCan'
import { useWorkforceClockStore } from '@/core/stores/workforce-clock'
import { useEmployeeHomeStore } from '@/modules/company/home/employee-home.store'
import { useOperationalHomeStore } from '@/modules/company/home/home.store'
import { usePersonalSpaceStore } from '@/modules/company/me/personal-space.store'
import DashboardGrid from '@/components/dashboard/DashboardGrid.vue'
import DashboardHostContainer from '@/components/dashboard/DashboardHostContainer.vue'

// ADR-818 (chantier D) : composant du SEUL accueil `/home`. `embedded` = bloc « Moi » d'un gestionnaire
// (l'en-tête et la grille de widgets sont alors portés par l'accueil de gestion — jamais deux grilles).
const props = defineProps({ embedded: { type: Boolean, default: false } })

const { t } = useI18n()
const auth = useAuthStore()
const { can, canModule } = useCan()
const clock = useWorkforceClockStore()
const emp = useEmployeeHomeStore()
const homeStore = useOperationalHomeStore()
const space = usePersonalSpaceStore()

// ── Capacités (déduites des modules + permissions, jamais de rôle codé en dur) ──
// ADR-769 (M7 LOT 3) : les capacités PERSONNELLES viennent du serveur (`/profile/space`) — mes
// documents et mes signatures ne dépendent d'aucune permission de gestion (`documents.view` n'a rien
// à voir avec MES documents), et le bloc Workforce n'existe que si une fiche salarié m'est liée.
const canWorkforce = computed(() => space.can('time_clock'))
const canDocuments = computed(() => space.can('documents'))
const canSignatures = computed(() => space.can('signatures'))
const canDeliveries = computed(() => canModule('logistics_shipments') && can('shipments.view_own'))
const isEmployee = computed(() => !!clock.employee)

const firstName = computed(() => auth.user?.first_name || auth.user?.name?.split(' ')?.[0] || '')
const todayLabel = computed(() => new Date().toLocaleDateString(displayLocale(), { weekday: 'long', day: 'numeric', month: 'long' }))

// ── Pointage (réutilise le store self-service — serveur = source de vérité) ──
const busy = ref(false)
const snack = ref('')
async function act(fn) {
  if (busy.value) return
  busy.value = true
  try { await fn() }
  catch (e) { snack.value = e?.data?.message || e?.message || t('employeeHome.actionError') }
  finally { busy.value = false }
}
const statusMeta = computed(() => ({
  working: { color: 'success', icon: 'tabler-player-play', label: t('employeeHome.clock.working') },
  on_break: { color: 'warning', icon: 'tabler-coffee', label: t('employeeHome.clock.onBreak') },
  completed: { color: 'secondary', icon: 'tabler-check', label: t('employeeHome.clock.completed') },
  not_started: { color: 'secondary', icon: 'tabler-clock', label: t('employeeHome.clock.notStarted') },
  idle: { color: 'secondary', icon: 'tabler-clock', label: t('employeeHome.clock.notStarted') },
}[clock.clockStatus] || { color: 'secondary', icon: 'tabler-clock', label: '' }))

const fmtHM = sec => {
  const m = Math.floor((Number(sec) || 0) / 60)

  return m >= 60 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}` : `${m} min`
}
const fmtTime = iso => iso ? new Date(iso).toLocaleTimeString(displayLocale(), { hour: '2-digit', minute: '2-digit' }) : ''
const relDay = iso => {
  if (!iso) return ''
  const d = new Date(iso); const today = new Date()
  const diff = Math.round((new Date(d.getFullYear(), d.getMonth(), d.getDate()) - new Date(today.getFullYear(), today.getMonth(), today.getDate())) / 86400000)
  if (diff === 0) return t('employeeHome.today')
  if (diff === 1) return t('employeeHome.tomorrow')

  return d.toLocaleDateString(displayLocale(), { weekday: 'long', day: 'numeric', month: 'short' })
}

// ── Cartes d'action (compteurs réels, liens vers l'outil concerné) ──
const actionCards = computed(() => {
  const out = []
  const mySpace = tab => ({ name: 'company-my-space-tab', params: { tab } })
  if (canSignatures.value && space.counts.signatures_to_sign > 0)
    out.push({ key: 'signatures', icon: 'tabler-writing-sign', color: 'info', value: space.counts.signatures_to_sign, label: t('employeeHome.cards.signaturesToSign'), to: mySpace('signatures'), loading: false })
  if (canDocuments.value)
    out.push({ key: 'docs', icon: 'tabler-file-text', color: 'warning', value: space.counts.document_requests ?? 0, label: t('employeeHome.cards.docsToProvide'), to: mySpace('documents'), loading: space.loading })
  if (space.can('leave') && isEmployee.value)
    out.push({ key: 'leaves', icon: 'tabler-beach', color: 'info', value: emp.pendingLeaves, label: t('employeeHome.cards.leavesPending'), to: '/company/workforce/me', loading: emp.loadingWorkforce })
  if (canDeliveries.value)
    out.push({ key: 'deliveries', icon: 'tabler-truck-delivery', color: 'primary', value: emp.deliveriesCount, label: t('employeeHome.cards.myDeliveries'), to: '/company/my-deliveries', loading: emp.loadingDeliveries })

  return out
})

const hasOperational = computed(() => (canWorkforce.value && isEmployee.value) || actionCards.value.length > 0)

// ── Widgets personnalisables (grille conservée en secondaire) ──
const canEdit = computed(() => auth.hasPermission('manage-own-dashboard'))
const showCatalogDrawer = ref(false)
const hasWidgets = computed(() => homeStore.layout.length > 0)
const availableWidgets = computed(() => {
  const keys = new Set(homeStore.layout.map(i => i.key))

  return homeStore.catalog.filter(w => !keys.has(w.key))
})
const addWidgetFromCatalog = w => { homeStore.addWidget(w); showCatalogDrawer.value = false }
const saveAndResolve = async () => {
  try { await homeStore.saveLayout(); await homeStore.resolveWidgets() }
  catch { /* saveError → snackbar */ }
}

onMounted(async () => {
  if (!props.embedded)
    homeStore.loadDashboard()
  if (canDeliveries.value) emp.loadDeliveries()

  // Une seule réponse serveur décide de ce qui est chargé : aucune API Workforce n'est appelée
  // sans module actif ET fiche salarié liée.
  await space.load()
  if (canWorkforce.value) {
    await clock.fetchProfile()
    if (clock.employee) emp.loadWorkforce({ planning: space.can('planning'), leave: space.can('leave') })
  }
})
</script>

<template>
  <div class="employee-home">
    <!-- ═══ En-tête ═══ -->
    <div
      v-if="!embedded"
      class="mb-6"
    >
      <div
        class="text-overline text-medium-emphasis"
        data-test="personal-home-label"
      >
        {{ t('nav.company.personal-home') }}
      </div>
      <h4 class="text-h4 mb-1">
        {{ firstName ? t('employeeHome.greeting', { name: firstName }) : t('employeeHome.greetingNoName') }}
      </h4>
      <div class="text-body-1 text-medium-emphasis text-capitalize">{{ todayLabel }}</div>
    </div>

    <!-- ═══ AUJOURD'HUI : prochain shift + pointage (employé Workforce) ═══ -->
    <VRow v-if="canWorkforce && isEmployee" class="mb-2">
      <!-- Prochain shift -->
      <VCol v-if="space.can('planning')" cols="12" md="7">
        <VCard class="h-100" border flat>
          <VCardText class="d-flex flex-column h-100">
            <div class="d-flex align-center gap-2 mb-3">
              <VIcon icon="tabler-calendar-event" color="primary" size="20" />
              <span class="text-body-1 font-weight-medium text-high-emphasis">{{ t('employeeHome.nextShift') }}</span>
            </div>
            <template v-if="emp.nextShift">
              <div class="d-flex align-baseline gap-2 flex-wrap">
                <span class="text-h5 font-weight-medium text-high-emphasis text-capitalize">{{ relDay(emp.nextShift.start_at) }}</span>
                <span class="text-h6 text-medium-emphasis">{{ fmtTime(emp.nextShift.start_at) }}<span v-if="emp.nextShift.end_at"> – {{ fmtTime(emp.nextShift.end_at) }}</span></span>
              </div>
              <div v-if="emp.nextShift.notes" class="text-body-2 text-medium-emphasis mt-1">{{ emp.nextShift.notes }}</div>
              <div v-if="emp.upcomingShifts.length > 1" class="text-caption text-medium-emphasis mt-2">
                {{ t('employeeHome.moreShifts', { n: emp.upcomingShifts.length - 1 }) }}
              </div>
            </template>
            <div v-else class="d-flex flex-column align-start text-medium-emphasis py-2">
              <VIcon icon="tabler-calendar-off" size="26" class="mb-1 text-disabled" />
              <span class="text-body-2">{{ t('employeeHome.noShift') }}</span>
            </div>
            <VSpacer />
            <div class="mt-3">
              <VBtn variant="text" size="small" color="primary" to="/company/workforce/me" append-icon="tabler-chevron-right">{{ t('employeeHome.viewPlanning') }}</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Pointage -->
      <VCol cols="12" :md="space.can('planning') ? 5 : 12">
        <VCard class="h-100" border flat>
          <VCardText class="d-flex flex-column h-100">
            <div class="d-flex align-center justify-space-between gap-2 mb-3">
              <span class="text-body-1 font-weight-medium text-high-emphasis">{{ t('employeeHome.clock.title') }}</span>
              <VChip :color="statusMeta.color" size="small" variant="tonal" label :prepend-icon="statusMeta.icon">{{ statusMeta.label }}</VChip>
            </div>
            <div class="d-flex align-baseline gap-2">
              <span class="text-h4 font-weight-medium text-high-emphasis">{{ fmtHM(clock.workedSeconds) }}</span>
              <span class="text-caption text-medium-emphasis">{{ t('employeeHome.clock.workedToday') }}</span>
            </div>
            <div v-if="clock.breakMinutesToday > 0" class="text-caption text-medium-emphasis">{{ t('employeeHome.clock.breakToday', { n: clock.breakMinutesToday }) }}</div>
            <VSpacer />
            <div class="d-flex gap-2 flex-wrap mt-3">
              <VBtn v-if="clock.clockStatus === 'not_started' || clock.clockStatus === 'idle'" color="success" :loading="busy" prepend-icon="tabler-login-2" @click="act(clock.clockIn)">{{ t('employeeHome.clock.clockIn') }}</VBtn>
              <template v-else-if="clock.isWorking">
                <VBtn color="warning" variant="tonal" :loading="busy" prepend-icon="tabler-coffee" @click="act(clock.startBreak)">{{ t('employeeHome.clock.break') }}</VBtn>
                <VBtn color="error" variant="tonal" :loading="busy" prepend-icon="tabler-logout-2" @click="act(clock.clockOut)">{{ t('employeeHome.clock.clockOut') }}</VBtn>
              </template>
              <VBtn v-else-if="clock.isOnBreak" color="success" :loading="busy" prepend-icon="tabler-player-play" @click="act(clock.endBreak)">{{ t('employeeHome.clock.resume') }}</VBtn>
              <span v-else-if="clock.isCompleted" class="text-body-2 text-medium-emphasis d-flex align-center gap-1"><VIcon icon="tabler-circle-check" color="success" size="18" />{{ t('employeeHome.clock.doneForToday') }}</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ═══ MES DEMANDES / ACTIVITÉS : cartes d'action compactes ═══ -->
    <VRow v-if="actionCards.length" class="mb-2">
      <VCol v-for="c in actionCards" :key="c.key" cols="12" sm="6" md="4">
        <VCard border flat :to="c.to" class="action-card">
          <VCardText class="d-flex align-center gap-4">
            <VAvatar :color="c.color" variant="tonal" size="44" rounded><VIcon :icon="c.icon" size="24" /></VAvatar>
            <div class="flex-grow-1">
              <div class="text-h5 font-weight-medium text-high-emphasis">{{ c.loading ? '—' : c.value }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ c.label }}</div>
            </div>
            <VIcon icon="tabler-chevron-right" size="18" class="text-disabled" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ═══ Mes soldes de congés ═══ -->
    <VCard v-if="space.can('leave') && isEmployee && clock.leaveBalances.length" border flat class="mb-2">
      <VCardText>
        <div class="d-flex align-center gap-2 mb-3">
          <VIcon icon="tabler-sun" color="info" size="20" />
          <span class="text-body-1 font-weight-medium text-high-emphasis">{{ t('employeeHome.leaveBalances') }}</span>
        </div>
        <div class="d-flex flex-wrap gap-6">
          <div v-for="b in clock.leaveBalances" :key="b.leave_type_id || b.label || b.name" class="d-flex flex-column">
            <span class="text-h6 font-weight-medium text-high-emphasis">{{ ((b.available ?? b.balance_hundredths ?? 0) / 100).toLocaleString(displayLocale(), { maximumFractionDigits: 2 }) }} <span class="text-caption text-medium-emphasis">{{ t('employeeHome.days') }}</span></span>
            <span class="text-caption text-medium-emphasis">{{ b.leave_type_name || b.name || b.label }}</span>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- ═══ État vide intelligent (ni salarié, ni activité) ═══ -->
    <VCard v-if="!hasOperational && !homeStore.isLoading && space.ready" border flat class="mb-2">
      <VCardText class="text-center py-8">
        <VAvatar color="primary" variant="tonal" size="64" class="mb-3"><VIcon icon="tabler-coffee" size="32" /></VAvatar>
        <div class="text-h6 mb-1">{{ t('employeeHome.empty.title') }}</div>
        <div class="text-body-2 text-medium-emphasis">{{ t('employeeHome.empty.hint') }}</div>
        <VBtn class="mt-4" variant="tonal" :to="{ name: 'company-my-space-tab', params: { tab: 'overview' } }" prepend-icon="tabler-user-circle" data-test="home-my-space">{{ t('mySpace.title') }}</VBtn>
      </VCardText>
    </VCard>

    <!-- ═══ Widgets personnalisables (secondaire, conservé ; un gestionnaire a sa grille d'entreprise) ═══ -->
    <h5
      v-if="embedded"
      class="text-h5 mt-2 mb-4"
      data-test="home-me-title"
    >
      {{ t('home.meTitle') }}
    </h5>
    <DashboardHostContainer v-if="!embedded">
      <template #toolbar>
        <div v-if="hasWidgets || canEdit" class="d-flex align-center mt-8 mb-4">
          <h5 class="text-h5">{{ t('home.widgetsTitle') }}</h5>
          <VSpacer />
          <VBtn v-if="canEdit && homeStore.isDirty" variant="tonal" color="success" size="small" class="me-2" @click="saveAndResolve">
            <VIcon start icon="tabler-device-floppy" size="18" />{{ t('home.saveLayout') }}
          </VBtn>
          <VBtn v-if="canEdit" variant="tonal" size="small" @click="showCatalogDrawer = true">
            <VIcon start icon="tabler-plus" size="18" />{{ t('home.addWidget') }}
          </VBtn>
        </div>
      </template>

      <DashboardGrid
        v-if="hasWidgets"
        :layout="homeStore.layout"
        :widget-data="homeStore.widgetData"
        :widget-errors="homeStore.widgetErrors"
        :catalog="homeStore.catalog"
        :loading="homeStore.dataLoading"
        :editable="canEdit"
        @update:layout="homeStore.updateLayout($event)"
      />
    </DashboardHostContainer>

    <VNavigationDrawer v-model="showCatalogDrawer" temporary location="end" width="360">
      <VCardTitle class="pa-4">{{ t('home.catalogTitle') }}</VCardTitle>
      <VDivider />
      <VList v-if="availableWidgets.length">
        <VListItem v-for="widget in availableWidgets" :key="widget.key" @click="addWidgetFromCatalog(widget)">
          <VListItemTitle>{{ t(widget.label_key) }}</VListItemTitle>
          <VListItemSubtitle>{{ t(widget.description_key) }}</VListItemSubtitle>
        </VListItem>
      </VList>
      <VCardText v-else class="text-center text-disabled">{{ t('home.allWidgetsAdded') }}</VCardText>
    </VNavigationDrawer>

    <VSnackbar :model-value="!!snack || !!homeStore.saveError" color="error" :timeout="5000" @update:model-value="snack = ''">
      {{ snack || homeStore.saveError }}
    </VSnackbar>
  </div>
</template>

<style scoped>
.action-card { transition: border-color 0.15s ease; }
.action-card:hover { border-color: rgba(var(--v-theme-primary), 0.5); }
</style>
