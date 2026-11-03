<script setup>
// PRODUCT-UX-3 / P3-B (ADR-687) — Workforce "À traiter" priorities as a FIXED section
// at the top of the single canonical Company home (/dashboard). Not an optional/hidden
// widget: it is always shown for users with workforce.view (the concerned users), so the
// tasks never disappear behind the widget catalog. Data comes from the authorized,
// manager-scoped, salary-free endpoint /workforce/dashboard/priorities (one source of
// truth, same as P2-A). Renders nothing if the viewer has no workforce access.
import { useWorkforceDashboardStore } from '@/modules/company/workforce/workforce-dashboard.store'

const { t } = useI18n()
const router = useRouter()
const store = useWorkforceDashboardStore()

const loaded = ref(false)
const data = computed(() => store.priorities)
const priorities = computed(() => data.value?.priorities ?? [])
const situation = computed(() => data.value?.situation)
const show = computed(() => loaded.value && !!data.value && (priorities.value.length > 0 || !!situation.value))

onMounted(async () => {
  try { await store.fetchPriorities() } finally { loaded.value = true }
})

const label = key => t(`home.priorities.${key}`)
const severityColor = s => ({ error: 'error', warning: 'warning', info: 'info' }[s] ?? 'primary')
const goTo = to => router.push(to)
</script>

<template>
  <div v-if="show" class="mb-6">
    <div class="d-flex align-center gap-2 mb-3">
      <VIcon icon="tabler-checklist" color="primary" />
      <h6 class="text-h6 font-weight-bold mb-0">{{ $t('home.sections.todo') }}</h6>
    </div>

    <!-- All clear -->
    <VCard v-if="priorities.length === 0" variant="tonal" color="success" class="mb-2">
      <VCardText class="d-flex align-center gap-3 py-4">
        <VIcon icon="tabler-circle-check" size="30" />
        <div>
          <div class="font-weight-bold">{{ $t('home.allClear.title') }}</div>
          <div class="text-body-2">{{ $t('home.allClear.subtitle') }}</div>
        </div>
      </VCardText>
    </VCard>

    <!-- Priority cards -->
    <VRow v-else>
      <VCol
        v-for="p in priorities"
        :key="p.key"
        cols="12"
        sm="6"
        md="3"
      >
        <VCard
          class="h-100"
          :data-test="`home-priority-${p.key}`"
          @click="goTo(p.to)"
        >
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-2">
              <VAvatar :color="severityColor(p.severity)" variant="tonal" rounded size="38">
                <VIcon :icon="p.icon" size="22" />
              </VAvatar>
              <span class="text-h4 font-weight-bold" :class="`text-${severityColor(p.severity)}`">{{ p.count }}</span>
            </div>
            <div class="font-weight-medium mb-2 text-body-2">{{ label(p.key) }}</div>
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

    <!-- Compact situation strip -->
    <div v-if="situation" class="d-flex flex-wrap gap-4 mt-3 text-body-2">
      <span><VIcon icon="tabler-users" size="16" class="me-1" />{{ $t('home.situation.activeEmployees') }}: <strong>{{ situation.active_employees ?? 0 }}</strong></span>
      <span v-if="situation.present_today !== null"><VIcon icon="tabler-user-check" size="16" class="me-1" color="success" />{{ $t('home.situation.presentToday') }}: <strong>{{ situation.present_today ?? 0 }}</strong></span>
      <span v-if="situation.not_clocked_today"><VIcon icon="tabler-user-x" size="16" class="me-1" color="warning" />{{ $t('home.situation.notClocked') }}: <strong>{{ situation.not_clocked_today }}</strong></span>
    </div>
  </div>
</template>
