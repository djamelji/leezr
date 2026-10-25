<script setup>
import EmptyState from '@/core/components/EmptyState.vue'
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatDate } from '@/utils/datetime'

const emit = defineEmits(['switchTab'])

const { t } = useI18n()
const { toast } = useAppToast()
const store = usePlatformPaymentsStore()

const isLoading = ref(true)
const lastRefreshed = ref(null)
const actionLoading = ref({})

const loadData = async () => {
  isLoading.value = true
  try {
    await store.fetchRecoveryStatus()
    lastRefreshed.value = new Date()
  }
  finally {
    isLoading.value = false
  }
}

onMounted(async () => {
  await loadData()
  if (recovery.value?.dead_letters > 0)
    await store.fetchDeadLetters()
})

const recovery = computed(() => store.recoveryStatus)

const statusIcon = status => {
  return status === 'ok' ? 'tabler-circle-check' : 'tabler-alert-triangle'
}

// ── Recovery actions ──

const handleRecoverCheckouts = async () => {
  actionLoading.value = { ...actionLoading.value, checkouts: true }
  try {
    const res = await store.recoverCheckouts()

    const s = res.stats
    toast(t('platformBilling.recovery.toastCheckouts', {
      activated: s.activated,
      expired: s.expired,
      failed: s.failed,
    }), s.failed > 0 ? 'warning' : 'success')
    await loadData()
  }
  catch {
    toast(t('platformBilling.errorGeneric'), 'error')
  }
  finally {
    actionLoading.value = { ...actionLoading.value, checkouts: false }
  }
}

const handleRecoverWebhooks = async () => {
  actionLoading.value = { ...actionLoading.value, webhooks: true }
  try {
    const res = await store.recoverWebhooks()

    const s = res.stats
    toast(t('platformBilling.recovery.toastWebhooks', {
      recovered: s.recovered,
      expired: s.expired,
      failed: s.failed,
    }), s.failed > 0 ? 'warning' : 'success')
    await loadData()
  }
  catch {
    toast(t('platformBilling.errorGeneric'), 'error')
  }
  finally {
    actionLoading.value = { ...actionLoading.value, webhooks: false }
  }
}

// ── Dead letter replay ──

const isReplayAllDialogOpen = ref(false)
const replayItemLoading = ref({})

const handleReplayAll = async () => {
  isReplayAllDialogOpen.value = false
  actionLoading.value = { ...actionLoading.value, replayAll: true }
  try {
    const res = await store.replayAllDeadLetters()

    const s = res.stats
    toast(t('platformBilling.recovery.toastReplayAll', {
      replayed: s.replayed,
      failed: s.failed,
    }), s.failed > 0 ? 'warning' : 'success')
    await loadData()
    await store.fetchDeadLetters()
  }
  catch {
    toast(t('platformBilling.errorGeneric'), 'error')
  }
  finally {
    actionLoading.value = { ...actionLoading.value, replayAll: false }
  }
}

// ADR-631g: inspect a dead-letter (full error + webhook payload) BEFORE replaying — diagnose
// the incident from the UI, no SQL on billing_webhook_dead_letters required.
const detailDialog = ref(false)
const detailItem = ref(null)

const openDeadLetterDetail = dl => {
  detailItem.value = dl
  detailDialog.value = true
}

const prettyPayload = computed(() => {
  const p = detailItem.value?.payload
  if (p == null) return '—'
  try { return JSON.stringify(p, null, 2) }
  catch { return String(p) }
})

const handleReplayOne = async dl => {
  replayItemLoading.value = { ...replayItemLoading.value, [dl.id]: true }
  try {
    const res = await store.replayDeadLetter(dl.id)

    toast(res.message, res.dead_letter?.status === 'replayed' ? 'success' : 'warning')
    await store.fetchDeadLetters(store.deadLettersPagination.current_page)
    await store.fetchRecoveryStatus()
    lastRefreshed.value = new Date()
  }
  catch {
    toast(t('platformBilling.errorGeneric'), 'error')
  }
  finally {
    delete replayItemLoading.value[dl.id]
  }
}

const deadLetterHeaders = computed(() => [
  { title: t('platformBilling.recovery.dlEventType'), key: 'event_type' },
  { title: t('platformBilling.recovery.dlError'), key: 'error_message', sortable: false },
  { title: t('platformBilling.recovery.dlFailedAt'), key: 'failed_at' },
  { title: t('platformBilling.recovery.dlAttempts'), key: 'replay_attempts', align: 'center', width: '100px' },
  { title: t('platformBilling.actions'), key: 'actions', sortable: false, align: 'center', width: '100px' },
])
</script>

<template>
  <div>
    <VAlert
      type="info"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      <VAlertTitle>
        <VIcon
          icon="tabler-first-aid-kit"
          size="20"
          class="me-2"
        />
        {{ t('platformBilling.recovery.headerTitle') }}
      </VAlertTitle>
      {{ t('platformBilling.recovery.headerDesc') }}
    </VAlert>

    <div class="d-flex align-center justify-end gap-2 mb-4">
      <span
        v-if="lastRefreshed"
        class="text-body-2 text-disabled"
      >
        {{ t('platformBilling.recovery.lastCheckedAt', { time: lastRefreshed.toLocaleTimeString() }) }}
      </span>
      <VBtn
        variant="tonal"
        prepend-icon="tabler-refresh"
        :loading="isLoading"
        @click="loadData"
      >
        {{ t('common.refresh') }}
      </VBtn>
    </div>

    <VSkeletonLoader
      v-if="isLoading"
      type="card, card"
    />

    <template v-else-if="recovery">
      <!-- Overall Status -->
      <VAlert
        :type="recovery.status === 'ok' ? 'success' : 'warning'"
        variant="tonal"
        :icon="statusIcon(recovery.status)"
        class="mb-6"
      >
        <VAlertTitle>
          {{ recovery.status === 'ok'
            ? t('platformBilling.recovery.allOk')
            : t('platformBilling.recovery.anomaliesDetected', { count: recovery.anomalies })
          }}
        </VAlertTitle>
        <div
          v-if="recovery.status !== 'ok'"
          class="mt-1 text-body-2"
        >
          <span
            v-if="recovery.stuck_checkouts > 0"
            class="me-3"
          >{{ t('platformBilling.recovery.stuckCheckoutDetail', { count: recovery.stuck_checkouts }) }}</span>
          <span
            v-if="recovery.overdue_confirmations > 0"
            class="me-3"
          >{{ t('platformBilling.recovery.overdueConfirmationDetail', { count: recovery.overdue_confirmations }) }}</span>
          <span v-if="recovery.dead_letters > 0">{{ t('platformBilling.recovery.deadLetterDetail', { count: recovery.dead_letters }) }}</span>
        </div>
      </VAlert>

      <!-- Counter Cards with Actions -->
      <VRow>
        <!-- Dead Letters -->
        <VCol
          cols="12"
          md="4"
        >
          <VCard>
            <VCardItem>
              <template #prepend>
                <VAvatar
                  :color="recovery.dead_letters > 0 ? 'error' : 'success'"
                  variant="tonal"
                  size="40"
                  rounded
                >
                  <VIcon icon="tabler-mail-off" />
                </VAvatar>
              </template>
              <VCardTitle>{{ t('platformBilling.recovery.deadLetters') }}</VCardTitle>
            </VCardItem>
            <VCardText>
              <h4 class="text-h4 mb-2">
                {{ recovery.dead_letters }}
              </h4>
              <p class="text-body-2 text-disabled mb-3">
                {{ t('platformBilling.recovery.deadLettersDesc') }}
              </p>
              <VBtn
                size="small"
                variant="tonal"
                color="error"
                prepend-icon="tabler-player-play"
                :loading="actionLoading.replayAll"
                :disabled="recovery.dead_letters === 0"
                @click="isReplayAllDialogOpen = true"
              >
                {{ t('platformBilling.recovery.replayAll') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Stuck Checkouts -->
        <VCol
          cols="12"
          md="4"
        >
          <VCard>
            <VCardItem>
              <template #prepend>
                <VAvatar
                  :color="recovery.stuck_checkouts > 0 ? 'warning' : 'success'"
                  variant="tonal"
                  size="40"
                  rounded
                >
                  <VIcon icon="tabler-shopping-cart-off" />
                </VAvatar>
              </template>
              <VCardTitle>{{ t('platformBilling.recovery.stuckCheckouts') }}</VCardTitle>
            </VCardItem>
            <VCardText>
              <h4 class="text-h4 mb-2">
                {{ recovery.stuck_checkouts }}
              </h4>
              <p class="text-body-2 text-disabled mb-3">
                {{ t('platformBilling.recovery.stuckCheckoutsDesc') }}
              </p>
              <VBtn
                size="small"
                variant="tonal"
                color="warning"
                prepend-icon="tabler-refresh"
                :loading="actionLoading.checkouts"
                :disabled="recovery.stuck_checkouts === 0"
                @click="handleRecoverCheckouts"
              >
                {{ t('platformBilling.recovery.recoverCheckouts') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Overdue Confirmations -->
        <VCol
          cols="12"
          md="4"
        >
          <VCard>
            <VCardItem>
              <template #prepend>
                <VAvatar
                  :color="recovery.overdue_confirmations > 0 ? 'warning' : 'success'"
                  variant="tonal"
                  size="40"
                  rounded
                >
                  <VIcon icon="tabler-clock-off" />
                </VAvatar>
              </template>
              <VCardTitle>{{ t('platformBilling.recovery.overdueConfirmations') }}</VCardTitle>
            </VCardItem>
            <VCardText>
              <h4 class="text-h4 mb-2">
                {{ recovery.overdue_confirmations }}
              </h4>
              <p class="text-body-2 text-disabled mb-3">
                {{ t('platformBilling.recovery.overdueConfirmationsDesc') }}
              </p>
              <VBtn
                size="small"
                variant="tonal"
                color="warning"
                prepend-icon="tabler-refresh"
                :loading="actionLoading.webhooks"
                :disabled="recovery.overdue_confirmations === 0"
                @click="handleRecoverWebhooks"
              >
                {{ t('platformBilling.recovery.recoverWebhooks') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- Dead Letters Table -->
      <VCard
        v-if="recovery.dead_letters > 0"
        class="mt-6"
      >
        <VCardTitle>
          <VIcon
            icon="tabler-mail-off"
            class="me-2"
          />
          {{ t('platformBilling.recovery.deadLettersTable') }}
        </VCardTitle>
        <VCardText class="pa-0">
          <VSkeletonLoader
            v-if="store.deadLettersLoading && store.deadLetters.length === 0"
            type="table"
          />

          <EmptyState
            v-else-if="store.deadLetters.length === 0"
            icon="tabler-mood-happy"
            :title="t('platformBilling.recovery.noDeadLetters')"
          />

          <VDataTable
            v-else
            :headers="deadLetterHeaders"
            :items="store.deadLetters"
            :loading="store.deadLettersLoading"
            :items-per-page="store.deadLettersPagination.per_page"
            hide-default-footer
          >
            <template #item.event_type="{ item }">
              <VChip
                size="small"
                variant="tonal"
                color="secondary"
              >
                {{ item.event_type }}
              </VChip>
            </template>

            <template #item.error_message="{ item }">
              <span
                class="text-body-2 text-truncate d-inline-block"
                style="max-inline-size: 300px;"
                :title="item.error_message"
              >
                {{ item.error_message || '—' }}
              </span>
            </template>

            <template #item.failed_at="{ item }">
              {{ formatDate(item.failed_at) }}
            </template>

            <template #item.replay_attempts="{ item }">
              <VChip
                size="small"
                :color="item.replay_attempts >= 2 ? 'error' : 'warning'"
                variant="tonal"
              >
                {{ item.replay_attempts }}/3
              </VChip>
            </template>

            <template #item.actions="{ item }">
              <div class="d-flex gap-1 justify-center">
                <VBtn
                  size="x-small"
                  variant="text"
                  icon="tabler-eye"
                  :aria-label="t('platformBilling.recovery.dlInspect')"
                  data-test="dl-inspect"
                  @click="openDeadLetterDetail(item)"
                >
                  <VIcon icon="tabler-eye" />
                  <VTooltip activator="parent" location="top">{{ t('platformBilling.recovery.dlInspect') }}</VTooltip>
                </VBtn>
                <VBtn
                  size="x-small"
                  color="primary"
                  variant="tonal"
                  icon="tabler-player-play"
                  :aria-label="t('platformBilling.recovery.dlReplay')"
                  :loading="replayItemLoading[item.id]"
                  :disabled="!!replayItemLoading[item.id]"
                  @click="handleReplayOne(item)"
                >
                  <VIcon icon="tabler-player-play" />
                  <VTooltip activator="parent" location="top">{{ t('platformBilling.recovery.dlReplay') }}</VTooltip>
                </VBtn>
              </div>
            </template>

            <template #bottom>
              <VDivider />
              <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
                <span class="text-body-2 text-disabled">
                  {{ t('platformBilling.recovery.deadLetterCount', { count: store.deadLettersPagination.total }) }}
                </span>
                <VPagination
                  v-if="store.deadLettersPagination.last_page > 1"
                  :model-value="store.deadLettersPagination.current_page"
                  :length="store.deadLettersPagination.last_page"
                  :total-visible="5"
                  @update:model-value="page => store.fetchDeadLetters(page)"
                />
              </div>
            </template>
          </VDataTable>
        </VCardText>
      </VCard>

      <!-- Job Heartbeats -->
      <VCard class="mt-6">
        <VCardTitle>
          <VIcon
            icon="tabler-heartbeat"
            class="me-2"
          />
          {{ t('platformBilling.recovery.heartbeats') }}
        </VCardTitle>
        <VCardText>
          <div
            v-if="!recovery.heartbeats?.length"
            class="text-center pa-6"
          >
            <VIcon
              icon="tabler-heartbeat"
              size="40"
              color="disabled"
              class="mb-3"
            />
            <p class="text-body-1 text-disabled mb-1">
              {{ t('platformBilling.recovery.noHeartbeats') }}
            </p>
            <p class="text-body-2 text-disabled mb-0">
              {{ t('platformBilling.recovery.heartbeatsExplain') }}
            </p>
          </div>

          <VList v-else>
            <VListItem
              v-for="hb in recovery.heartbeats"
              :key="hb.job_key"
            >
              <template #prepend>
                <VAvatar
                  :color="hb.last_status === 'ok' ? 'success' : 'error'"
                  variant="tonal"
                  size="40"
                  class="me-3"
                >
                  <VIcon :icon="hb.last_status === 'ok' ? 'tabler-circle-check' : 'tabler-circle-x'" />
                </VAvatar>
              </template>

              <VListItemTitle class="font-weight-medium">
                {{ hb.job_key }}
              </VListItemTitle>
              <VListItemSubtitle>
                {{ t('platformBilling.recovery.lastRun') }}: {{ formatDate(hb.last_finished_at) }}
              </VListItemSubtitle>

              <template #append>
                <VChip
                  :color="hb.last_status === 'ok' ? 'success' : 'error'"
                  size="small"
                >
                  {{ hb.last_status || 'unknown' }}
                </VChip>
              </template>
            </VListItem>
          </VList>
        </VCardText>
      </VCard>

      <!-- Additional Counters -->
      <VCard class="mt-6">
        <VCardTitle>
          <VIcon
            icon="tabler-chart-dots-3"
            class="me-2"
          />
          {{ t('platformBilling.recovery.additionalCounters') }}
        </VCardTitle>
        <VCardText>
          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <div class="d-flex gap-x-4 align-center">
                <VAvatar
                  :color="recovery.overdue_invoices > 0 ? 'error' : 'success'"
                  variant="tonal"
                  size="40"
                  rounded
                >
                  <VIcon icon="tabler-file-invoice" />
                </VAvatar>
                <div>
                  <h5 class="text-h5">
                    {{ recovery.overdue_invoices }}
                  </h5>
                  <div class="text-body-2 text-disabled">
                    {{ t('platformBilling.recovery.overdueInvoices') }}
                  </div>
                  <VBtn
                    v-if="recovery.overdue_invoices > 0"
                    size="small"
                    variant="tonal"
                    color="error"
                    class="mt-2"
                    @click="emit('switchTab', 'dunning')"
                  >
                    {{ t('platformBilling.recovery.viewDunning') }}
                  </VBtn>
                </div>
              </div>
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <div class="d-flex gap-x-4 align-center">
                <VAvatar
                  :color="recovery.past_due_subscriptions > 0 ? 'error' : 'success'"
                  variant="tonal"
                  size="40"
                  rounded
                >
                  <VIcon icon="tabler-alert-triangle" />
                </VAvatar>
                <div>
                  <h5 class="text-h5">
                    {{ recovery.past_due_subscriptions }}
                  </h5>
                  <div class="text-body-2 text-disabled">
                    {{ t('platformBilling.recovery.pastDueSubs') }}
                  </div>
                  <VBtn
                    v-if="recovery.past_due_subscriptions > 0"
                    size="small"
                    variant="tonal"
                    color="warning"
                    class="mt-2"
                    @click="emit('switchTab', 'subscriptions')"
                  >
                    {{ t('platformBilling.subscriptions.viewInvoices') }}
                  </VBtn>
                </div>
              </div>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </template>

    <!-- Replay All Confirmation Dialog -->
    <VDialog
      v-model="isReplayAllDialogOpen"
      max-width="460"
    >
      <VCard>
        <VCardTitle class="text-h5 pa-5">
          {{ t('platformBilling.recovery.replayAllTitle') }}
        </VCardTitle>
        <VCardText>
          {{ t('platformBilling.recovery.replayAllConfirm', { count: recovery?.dead_letters || 0 }) }}
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="isReplayAllDialogOpen = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            variant="elevated"
            prepend-icon="tabler-player-play"
            :loading="actionLoading.replayAll"
            @click="handleReplayAll"
          >
            {{ t('platformBilling.recovery.replayAll') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ADR-631g: dead-letter inspection (full error + webhook payload) -->
    <VDialog
      v-model="detailDialog"
      max-width="720"
      data-test="dl-detail-dialog"
    >
      <VCard v-if="detailItem">
        <VCardItem>
          <VCardTitle class="d-flex align-center gap-2">
            <VIcon icon="tabler-search" size="20" />
            {{ t('platformBilling.recovery.dlDetailTitle') }}
          </VCardTitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VRow class="mb-2">
            <VCol cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">{{ t('platformBilling.recovery.dlEventType') }}</div>
              <div class="font-weight-medium">{{ detailItem.event_type }}</div>
            </VCol>
            <VCol cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">{{ t('platformBilling.recovery.dlEventId') }}</div>
              <div class="font-weight-medium"><code>{{ detailItem.event_id || '—' }}</code></div>
            </VCol>
            <VCol cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">{{ t('platformBilling.recovery.dlFailedAt') }}</div>
              <div class="font-weight-medium">{{ formatDate(detailItem.failed_at) }}</div>
            </VCol>
            <VCol cols="12" sm="6">
              <div class="text-caption text-medium-emphasis">{{ t('platformBilling.recovery.dlAttempts') }}</div>
              <div class="font-weight-medium">{{ detailItem.replay_attempts }}/3</div>
            </VCol>
          </VRow>

          <div class="text-caption text-medium-emphasis mb-1">{{ t('platformBilling.recovery.dlError') }}</div>
          <VAlert type="error" variant="tonal" density="compact" class="mb-4" data-test="dl-detail-error">
            {{ detailItem.error_message || '—' }}
          </VAlert>

          <div class="text-caption text-medium-emphasis mb-1">{{ t('platformBilling.recovery.dlPayload') }}</div>
          <pre
            class="pa-3 rounded text-caption overflow-auto"
            style="background: rgb(var(--v-theme-surface)); max-block-size: 320px;"
            data-test="dl-detail-payload"
          >{{ prettyPayload }}</pre>
        </VCardText>
        <VDivider />
        <VCardActions class="justify-end">
          <VBtn variant="text" @click="detailDialog = false">{{ t('common.close') }}</VBtn>
          <VBtn
            color="primary"
            variant="elevated"
            prepend-icon="tabler-player-play"
            :loading="replayItemLoading[detailItem.id]"
            :disabled="!!replayItemLoading[detailItem.id]"
            @click="() => { const it = detailItem; detailDialog = false; handleReplayOne(it) }"
          >
            {{ t('platformBilling.recovery.dlReplay') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
