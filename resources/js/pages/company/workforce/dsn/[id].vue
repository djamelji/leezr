<script setup>
import { useAppToast } from '@/composables/useAppToast'
import StatusChip from '@/core/components/StatusChip.vue'
import { useDsnStore } from '@/modules/company/workforce/dsn.store'

definePage({ meta: { module: 'workforce_payroll', permission: 'workforce.payroll_prepare' } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const route = useRoute()
const store = useDsnStore()

const pollResult = ref(null)
const declaration = computed(() => store.currentDeclaration)
const validation = computed(() => store.currentValidation)
const history = computed(() => store.currentHistory)

// ── Workflow steps ───────────────────────────────────────────
const workflowSteps = computed(() => {
  if (!declaration.value) return []
  const status = declaration.value.status
  const isFinalRejected = status === 'rejected'
  const steps = [
    { key: 'draft', label: t('dsn.workflow.draft'), icon: 'tabler-edit', color: 'secondary' },
    { key: 'validated', label: t('dsn.workflow.validated'), icon: 'tabler-check', color: 'info' },
    { key: 'exported', label: t('dsn.workflow.exported'), icon: 'tabler-download', color: 'warning' },
    { key: 'submitted', label: t('dsn.workflow.submitted'), icon: 'tabler-send', color: 'primary' },
  ]

  if (isFinalRejected)
    steps.push({ key: 'rejected', label: t('dsn.workflow.rejected'), icon: 'tabler-circle-x', color: 'error' })
  else
    steps.push({ key: 'accepted', label: t('dsn.workflow.accepted'), icon: 'tabler-circle-check', color: 'success' })

  const statusOrder = ['draft', 'validated', 'exported', 'submitted', isFinalRejected ? 'rejected' : 'accepted']
  const currentIndex = statusOrder.indexOf(status)

  return steps.map((step, i) => ({ ...step, reached: i <= currentIndex, active: i === currentIndex }))
})

// ── Helpers ──────────────────────────────────────────────────
function formatDate(dateStr) {
  if (!dateStr) return '\u2014'
  return new Date(dateStr).toLocaleDateString(locale.value, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function truncateHash(hash) {
  if (!hash) return '\u2014'
  return hash.length > 20 ? `${hash.substring(0, 20)}\u2026` : hash
}

const gwColors = { pending: 'warning', processing: 'info', success: 'success', error: 'error', timeout: 'secondary' }
function gatewayColor(status) { return gwColors[status] || 'secondary' }

const auditIcons = { created: 'tabler-file-plus', validated: 'tabler-check', exported: 'tabler-download', submitted: 'tabler-send', polled: 'tabler-refresh', accepted: 'tabler-circle-check', rejected: 'tabler-circle-x' }
const auditColors = { created: 'secondary', validated: 'info', exported: 'warning', submitted: 'primary', polled: 'secondary', accepted: 'success', rejected: 'error' }

// ── Data loading & actions ───────────────────────────────────
async function reload() {
  await store.fetchDeclaration(route.params.id)
  await Promise.all([
    store.fetchValidation(route.params.id).catch(() => {}),
    store.fetchHistory(route.params.id).catch(() => {}),
  ])
}

async function handleSubmit() {
  try {
    await store.submitDeclaration(route.params.id)
    toast(t('dsn.submitted'), 'success')
    await reload()
  } catch (e) {
    toast(e?.response?.data?.message || e.message, 'error')
  }
}

async function handlePoll() {
  try {
    const result = await store.pollDeclaration(route.params.id)
    pollResult.value = result
    toast(t('dsn.polled'), 'success')
    await reload()
  } catch (e) {
    toast(e?.response?.data?.message || e.message, 'error')
  }
}

onMounted(() => reload())
onBeforeUnmount(() => store.clearCurrentDeclaration())
</script>

<template>
  <div v-if="declaration">
    <!-- A. Back button -->
    <VBtn
      variant="text"
      :to="{ name: 'company-workforce-dsn' }"
      prepend-icon="tabler-arrow-left"
      class="mb-4"
    >
      {{ $t('dsn.backToList') }}
    </VBtn>

    <!-- B. Header card -->
    <VCard class="mb-6">
      <VCardText>
        <div class="d-flex align-center justify-space-between flex-wrap gap-4">
          <div>
            <h4 class="text-h4 font-weight-bold mb-2">
              {{ $t('dsn.detail.periodLabel') }} {{ declaration.period_month }}
            </h4>
            <div class="d-flex align-center gap-3 flex-wrap">
              <StatusChip domain="dsn" :status="declaration.status">
                {{ $t(`dsn.statuses.${declaration.status}`) }}
              </StatusChip>
              <VChip
                v-if="declaration.gateway_status"
                size="small" variant="tonal" :color="gatewayColor(declaration.gateway_status)"
              >
                {{ declaration.gateway_status }}
              </VChip>
            </div>
            <div v-if="declaration.payload_hash" class="mt-2">
              <span class="text-body-2 text-medium-emphasis">{{ $t('dsn.detail.payloadHash') }} </span>
              <code class="text-body-2">{{ truncateHash(declaration.payload_hash) }}</code>
            </div>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <VBtn
              v-if="declaration.status === 'exported'"
              v-can="'workforce.payroll_export'"
              color="primary" :loading="store.loading" @click="handleSubmit"
            >
              <VIcon start icon="tabler-send" />
              {{ $t('dsn.actions.submit') }}
            </VBtn>
            <VBtn
              v-if="declaration.status === 'submitted'"
              v-can="'workforce.payroll_export'"
              color="info" variant="outlined" :loading="store.loading" @click="handlePoll"
            >
              <VIcon start icon="tabler-refresh" />
              {{ $t('dsn.actions.poll') }}
            </VBtn>
          </div>
        </div>

        <VDivider class="my-4" />

        <VRow dense>
          <VCol v-if="declaration.generated_by_name" cols="12" sm="4">
            <div class="text-body-2 text-medium-emphasis">{{ $t('dsn.detail.generatedBy') }}</div>
            <div class="text-body-1 font-weight-medium">{{ declaration.generated_by_name }}</div>
            <div v-if="declaration.created_at" class="text-body-2 text-medium-emphasis">{{ formatDate(declaration.created_at) }}</div>
          </VCol>
          <VCol v-if="declaration.exported_by_name" cols="12" sm="4">
            <div class="text-body-2 text-medium-emphasis">{{ $t('dsn.detail.exportedBy') }}</div>
            <div class="text-body-1 font-weight-medium">{{ declaration.exported_by_name }}</div>
            <div v-if="declaration.exported_at" class="text-body-2 text-medium-emphasis">{{ formatDate(declaration.exported_at) }}</div>
          </VCol>
          <VCol v-if="declaration.submitted_by_name" cols="12" sm="4">
            <div class="text-body-2 text-medium-emphasis">{{ $t('dsn.detail.submittedBy') }}</div>
            <div class="text-body-1 font-weight-medium">{{ declaration.submitted_by_name }}</div>
            <div v-if="declaration.submitted_at" class="text-body-2 text-medium-emphasis">{{ formatDate(declaration.submitted_at) }}</div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- C. Workflow timeline -->
    <VCard class="mb-6">
      <VCardTitle>{{ $t('dsn.detail.workflow') }}</VCardTitle>
      <VCardText>
        <div class="d-flex align-center justify-space-between">
          <template v-for="(step, i) in workflowSteps" :key="step.key">
            <div class="d-flex align-center gap-2">
              <VAvatar
                :color="step.reached ? step.color : 'grey-lighten-2'"
                :variant="step.active ? 'elevated' : 'tonal'" size="36"
              >
                <VIcon :icon="step.icon" size="20" />
              </VAvatar>
              <span :class="step.reached ? 'font-weight-bold' : 'text-medium-emphasis'" class="text-body-2">
                {{ step.label }}
              </span>
            </div>
            <VDivider v-if="i < workflowSteps.length - 1" class="mx-2" />
          </template>
        </div>
      </VCardText>
    </VCard>

    <!-- Poll result alert -->
    <VAlert
      v-if="pollResult" :type="pollResult.changed ? 'success' : 'info'"
      variant="tonal" class="mb-6" closable @click:close="pollResult = null"
    >
      {{ pollResult.changed ? $t('dsn.poll.statusChanged') : $t('dsn.poll.noChange') }}
      <span v-if="pollResult.gateway_status"> &mdash; {{ pollResult.gateway_status }}</span>
    </VAlert>

    <!-- D. Validation errors card -->
    <VCard v-if="validation?.validation_errors?.length" class="mb-6">
      <VCardTitle class="d-flex align-center gap-2">
        <VIcon icon="tabler-alert-triangle" color="error" size="22" />
        {{ $t('dsn.detail.validationErrors') }}
      </VCardTitle>
      <VCardText>
        <VAlert type="error" variant="tonal" class="mb-4">
          {{ $t('dsn.detail.validationErrorsDescription', { count: validation.validation_errors.length }) }}
        </VAlert>
        <div
          v-for="(error, idx) in validation.validation_errors" :key="idx"
          class="d-flex align-start gap-2 mb-2"
        >
          <VIcon icon="tabler-point-filled" color="error" size="16" class="mt-1" />
          <div>
            <span v-if="error.category" class="font-weight-medium">[{{ error.category }}]</span>
            {{ error.message }}
            <span v-if="error.employee_name" class="text-medium-emphasis"> &mdash; {{ error.employee_name }}</span>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- E. Gateway tracking card -->
    <VCard
      v-if="declaration.status === 'submitted' || declaration.status === 'accepted' || declaration.status === 'rejected'"
      class="mb-6"
    >
      <VCardTitle class="d-flex align-center gap-2">
        <VIcon icon="tabler-cloud" size="22" />
        {{ $t('dsn.detail.gatewayTracking') }}
      </VCardTitle>
      <VCardText>
        <VRow dense>
          <VCol cols="12" sm="6" md="3">
            <div class="text-body-2 text-medium-emphasis">{{ $t('dsn.detail.gatewayDriver') }}</div>
            <div class="text-body-1 font-weight-medium">{{ declaration.gateway_driver ?? '\u2014' }}</div>
          </VCol>
          <VCol cols="12" sm="6" md="3">
            <div class="text-body-2 text-medium-emphasis">{{ $t('dsn.detail.gatewayEnvironment') }}</div>
            <div class="text-body-1 font-weight-medium">{{ declaration.gateway_environment ?? '\u2014' }}</div>
          </VCol>
          <VCol cols="12" sm="6" md="3">
            <div class="text-body-2 text-medium-emphasis">{{ $t('dsn.detail.gatewayStatusLabel') }}</div>
            <VChip v-if="declaration.gateway_status" size="small" variant="tonal" :color="gatewayColor(declaration.gateway_status)">
              {{ declaration.gateway_status }}
            </VChip>
            <span v-else class="text-medium-emphasis">&mdash;</span>
          </VCol>
          <VCol cols="12" sm="6" md="3">
            <div class="text-body-2 text-medium-emphasis">{{ $t('dsn.detail.attemptCount') }}</div>
            <div class="text-body-1 font-weight-medium">{{ declaration.attempt_count ?? 0 }}</div>
          </VCol>
        </VRow>

        <VAlert
          v-if="declaration.gateway_error_code || declaration.gateway_error_message"
          type="error" variant="tonal" class="mt-4"
        >
          <div v-if="declaration.gateway_error_code">
            <span class="font-weight-medium">{{ $t('dsn.detail.errorCode') }}:</span> {{ declaration.gateway_error_code }}
          </div>
          <div v-if="declaration.gateway_error_message">{{ declaration.gateway_error_message }}</div>
        </VAlert>

        <VRow dense class="mt-4">
          <VCol cols="12" sm="6">
            <div class="text-body-2 text-medium-emphasis">{{ $t('dsn.detail.lastPolledAt') }}</div>
            <div class="text-body-1">{{ formatDate(declaration.last_polled_at) }}</div>
          </VCol>
          <VCol cols="12" sm="6">
            <div class="text-body-2 text-medium-emphasis">{{ $t('dsn.detail.nextPollAt') }}</div>
            <div class="text-body-1">{{ formatDate(declaration.next_poll_at) }}</div>
          </VCol>
        </VRow>

        <VRow v-if="declaration.technical_receipt_path || declaration.business_report_path" dense class="mt-4">
          <VCol v-if="declaration.technical_receipt_path" cols="12" sm="6">
            <div class="text-body-2 text-medium-emphasis">{{ $t('dsn.detail.technicalReceipt') }}</div>
            <code class="text-body-2">{{ declaration.technical_receipt_path }}</code>
          </VCol>
          <VCol v-if="declaration.business_report_path" cols="12" sm="6">
            <div class="text-body-2 text-medium-emphasis">{{ $t('dsn.detail.businessReport') }}</div>
            <code class="text-body-2">{{ declaration.business_report_path }}</code>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- F. Audit history card -->
    <VCard v-if="history?.length">
      <VCardTitle class="d-flex align-center gap-2">
        <VIcon icon="tabler-history" size="22" />
        {{ $t('dsn.detail.auditHistory') }}
      </VCardTitle>
      <VCardText>
        <VTimeline density="compact" side="end" truncate-line="both">
          <VTimelineItem
            v-for="entry in history" :key="entry.id"
            :dot-color="auditColors[entry.action] || 'secondary'"
            :icon="auditIcons[entry.action] || 'tabler-point'" size="small"
          >
            <div class="d-flex align-center justify-space-between flex-wrap gap-2">
              <div>
                <span class="font-weight-medium">{{ $t(`dsn.audit.${entry.action}`, entry.action) }}</span>
                <span v-if="entry.actor_name" class="text-medium-emphasis"> &mdash; {{ entry.actor_name }}</span>
              </div>
              <span class="text-body-2 text-medium-emphasis">{{ formatDate(entry.created_at) }}</span>
            </div>
            <div v-if="entry.metadata" class="text-body-2 text-medium-emphasis mt-1">
              {{ typeof entry.metadata === 'string' ? entry.metadata : JSON.stringify(entry.metadata).substring(0, 120) }}
            </div>
          </VTimelineItem>
        </VTimeline>
      </VCardText>
    </VCard>
  </div>

  <!-- Loading state -->
  <div v-else-if="store.loading" class="d-flex justify-center align-center py-16">
    <VProgressCircular indeterminate size="48" color="primary" />
  </div>
</template>
