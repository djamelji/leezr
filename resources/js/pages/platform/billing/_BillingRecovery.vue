<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'

const { t } = useI18n()
const store = usePlatformPaymentsStore()

const isLoading = ref(true)

onMounted(async () => {
  try {
    await store.fetchRecoveryStatus()
  }
  finally {
    isLoading.value = false
  }
})

const recovery = computed(() => store.recoveryStatus)

const statusIcon = status => {
  return status === 'ok' ? 'tabler-circle-check' : 'tabler-alert-triangle'
}

const statusColor = status => {
  return status === 'ok' ? 'success' : 'error'
}

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>

<template>
  <div>
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
      </VAlert>

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
              <p class="text-body-2 text-disabled mb-0">
                {{ t('platformBilling.recovery.deadLettersDesc') }}
              </p>
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
              <p class="text-body-2 text-disabled mb-0">
                {{ t('platformBilling.recovery.stuckCheckoutsDesc') }}
              </p>
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
              <p class="text-body-2 text-disabled mb-0">
                {{ t('platformBilling.recovery.overdueConfirmationsDesc') }}
              </p>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

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
            class="text-center pa-6 text-disabled"
          >
            {{ t('platformBilling.recovery.noHeartbeats') }}
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
                </div>
              </div>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </template>
  </div>
</template>
