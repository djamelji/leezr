<script setup>
// PRODUCT-UX-3 / P2-A (ADR-682) — action-oriented Company home.
// Replaces the former module-cards HR hub (anti-pattern, ADR-673) with a clear
// hierarchy: P1 "À traiter" (what needs my action) → P2 "Situation" → P3 access.
// All data is AUTHORIZED + manager-scoped SERVER-SIDE (no salary); the frontend
// only renders what the backend returned for this viewer.
import { useWorkforceDashboardStore } from '@/modules/company/workforce/workforce-dashboard.store'
import { useCan } from '@/composables/useCan'

definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const { t } = useI18n()
const router = useRouter()
const store = useWorkforceDashboardStore()
const { can, canModule } = useCan()

const loading = ref(true)
const data = computed(() => store.priorities ?? { priorities: [], all_clear: true, situation: null, scope: 'team' })
const priorities = computed(() => data.value.priorities ?? [])
const situation = computed(() => data.value.situation)
const isTeamScope = computed(() => data.value.scope === 'team')

onMounted(async () => {
  try { await store.fetchPriorities() } finally { loading.value = false }
})

// Priority presentation (label + action verb) keyed by the server item key.
const label = key => t(`home.priorities.${key}`)
const severityColor = s => ({ error: 'error', warning: 'warning', info: 'info' }[s] ?? 'primary')

function goTo(to) {
  router.push(to)
}

// P3 — frequent operations (short, permission/module gated). Not the full module list.
const quickLinks = computed(() => [
  { key: 'employees', icon: 'tabler-users', to: { name: 'company-workforce-employees' }, show: can('workforce.view') },
  { key: 'time', icon: 'tabler-clock', to: { name: 'company-workforce-time' }, show: can('workforce.timesheet_view') },
  { key: 'leave', icon: 'tabler-calendar', to: { name: 'company-workforce-leave' }, show: can('workforce.leave_request') || can('workforce.leave_approve') },
  { key: 'payroll', icon: 'tabler-receipt', to: { name: 'company-workforce-payroll' }, show: canModule('workforce_payroll') && can('workforce.payroll_prepare') },
  { key: 'documents', icon: 'tabler-file-certificate', to: { name: 'company-workforce-documents' }, show: canModule('workforce_documents') && can('workforce_documents.view') },
].filter(l => l.show))
</script>

<template>
  <div>
    <!-- Header -->
    <div class="mb-6">
      <h4 class="text-h4 font-weight-bold mb-1">
        {{ $t('home.title') }}
      </h4>
      <p class="text-body-1 text-medium-emphasis mb-0">
        {{ isTeamScope ? $t('home.subtitleTeam') : $t('home.subtitleAll') }}
      </p>
    </div>

    <VProgressLinear
      v-if="loading"
      indeterminate
      color="primary"
      class="mb-6"
    />

    <template v-else>
      <!-- ── P1 — À TRAITER ─────────────────────────────────── -->
      <div class="d-flex align-center gap-2 mb-3">
        <VIcon icon="tabler-checklist" color="primary" />
        <h6 class="text-h6 font-weight-bold mb-0">{{ $t('home.sections.todo') }}</h6>
      </div>

      <!-- All clear -->
      <VCard
        v-if="data.all_clear"
        variant="tonal"
        color="success"
        class="mb-6"
      >
        <VCardText class="d-flex align-center gap-3 py-6">
          <VIcon icon="tabler-circle-check" size="36" />
          <div>
            <div class="text-h6 font-weight-bold">{{ $t('home.allClear.title') }}</div>
            <div class="text-body-2">{{ $t('home.allClear.subtitle') }}</div>
          </div>
        </VCardText>
      </VCard>

      <!-- Priority cards -->
      <VRow v-else class="mb-6">
        <VCol
          v-for="p in priorities"
          :key="p.key"
          cols="12"
          sm="6"
          md="4"
        >
          <VCard
            class="h-100"
            :data-test="`home-priority-${p.key}`"
            @click="goTo(p.to)"
          >
            <VCardText>
              <div class="d-flex align-center justify-space-between mb-2">
                <VAvatar
                  :color="severityColor(p.severity)"
                  variant="tonal"
                  rounded
                  size="42"
                >
                  <VIcon :icon="p.icon" size="24" />
                </VAvatar>
                <span
                  class="text-h4 font-weight-bold"
                  :class="`text-${severityColor(p.severity)}`"
                >{{ p.count }}</span>
              </div>
              <div class="font-weight-medium mb-3">{{ label(p.key) }}</div>
              <VBtn
                :color="severityColor(p.severity)"
                variant="tonal"
                size="small"
                block
                append-icon="tabler-arrow-right"
                @click.stop="goTo(p.to)"
              >
                {{ $t('home.process') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- ── P2 — SITUATION ─────────────────────────────────── -->
      <template v-if="situation">
        <div class="d-flex align-center gap-2 mb-3">
          <VIcon icon="tabler-chart-dots" color="secondary" />
          <h6 class="text-h6 font-weight-bold mb-0">{{ $t('home.sections.situation') }}</h6>
        </div>
        <VRow class="card-grid card-grid-xs mb-6">
          <VCol cols="6" sm="4">
            <VCard>
              <VCardText class="d-flex align-center gap-3">
                <VAvatar color="primary" variant="tonal" rounded size="40"><VIcon icon="tabler-users" /></VAvatar>
                <div>
                  <div class="text-body-2 text-medium-emphasis">{{ $t('home.situation.activeEmployees') }}</div>
                  <div class="text-h5 font-weight-bold">{{ situation.active_employees ?? 0 }}</div>
                </div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol v-if="situation.present_today !== null" cols="6" sm="4">
            <VCard>
              <VCardText class="d-flex align-center gap-3">
                <VAvatar color="success" variant="tonal" rounded size="40"><VIcon icon="tabler-user-check" /></VAvatar>
                <div>
                  <div class="text-body-2 text-medium-emphasis">{{ $t('home.situation.presentToday') }}</div>
                  <div class="text-h5 font-weight-bold">{{ situation.present_today ?? 0 }}</div>
                </div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol v-if="situation.not_clocked_today" cols="6" sm="4">
            <VCard>
              <VCardText class="d-flex align-center gap-3">
                <VAvatar color="warning" variant="tonal" rounded size="40"><VIcon icon="tabler-user-x" /></VAvatar>
                <div>
                  <div class="text-body-2 text-medium-emphasis">{{ $t('home.situation.notClocked') }}</div>
                  <div class="text-h5 font-weight-bold">{{ situation.not_clocked_today ?? 0 }}</div>
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </template>

      <!-- ── P3 — ACCÈS RAPIDES ─────────────────────────────── -->
      <template v-if="quickLinks.length">
        <div class="d-flex align-center gap-2 mb-3">
          <VIcon icon="tabler-bolt" color="secondary" />
          <h6 class="text-h6 font-weight-bold mb-0">{{ $t('home.sections.quickAccess') }}</h6>
        </div>
        <div class="d-flex flex-wrap gap-3">
          <VBtn
            v-for="l in quickLinks"
            :key="l.key"
            variant="outlined"
            :prepend-icon="l.icon"
            @click="goTo(l.to)"
          >
            {{ $t(`home.quick.${l.key}`) }}
          </VBtn>
        </div>
      </template>
    </template>
  </div>
</template>
