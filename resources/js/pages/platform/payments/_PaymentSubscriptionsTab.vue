<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { useAppToast } from '@/composables/useAppToast'
import { formatDate } from '@/utils/datetime'

const { t } = useI18n()
const store = usePlatformPaymentsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const actionLoading = ref(null)

onMounted(async () => {
  try {
    await store.fetchSubscriptions()
  }
  finally {
    isLoading.value = false
  }
})

const subscriptionHeaders = [
  { title: t('payments.company'), key: 'company.name' },
  { title: t('payments.plan'), key: 'plan_key', width: '120px' },
  { title: t('common.status'), key: 'status', align: 'center', width: '160px' },
  { title: t('payments.paymentMethod'), key: 'provider', width: '140px' },
  { title: t('common.date'), key: 'created_at', width: '140px' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '180px', sortable: false },
]

const statusLabel = status => {
  const key = `subscriptionStatus.${status}`
  const translated = t(key)

  return translated !== key ? translated : status
}

const statusColor = status => {
  const colors = {
    pending: 'warning',
    active: 'success',
    cancelled: 'error',
    expired: 'secondary',
  }

  return colors[status] || 'default'
}

const paymentMethodLabel = provider => {
  if (!provider || provider === 'null')
    return t('payments.internal')

  return provider.charAt(0).toUpperCase() + provider.slice(1)
}

const fmtDate = dateStr => {
  if (!dateStr)
    return '—'

  return formatDate(dateStr)
}

const approveSubscription = async subscription => {
  if (!confirm(t('approveConfirm', { plan: subscription.plan_key, company: subscription.company?.name })))
    return

  actionLoading.value = subscription.id

  try {
    const data = await store.approveSubscription(subscription.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('payments.failedToApprove'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const rejectSubscription = async subscription => {
  if (!confirm(t('rejectConfirm', { company: subscription.company?.name })))
    return

  actionLoading.value = subscription.id

  try {
    const data = await store.rejectSubscription(subscription.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('payments.failedToReject'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const onPageChange = async page => {
  isLoading.value = true

  try {
    await store.fetchSubscriptions(page)
  }
  finally {
    isLoading.value = false
  }
}
</script>

<template>
  <VCard>
    <VCardTitle>
      <VIcon
        icon="tabler-receipt"
        class="me-2"
      />
      {{ t('payments.subscriptionGovernance') }}
    </VCardTitle>

    <VDataTable
      :headers="subscriptionHeaders"
      :items="store.subscriptions"
      :loading="isLoading"
      :items-per-page="-1"
      hide-default-footer
      hover
    >
      <template #item.company.name="{ item }">
        {{ item.company?.name || '—' }}
      </template>

      <template #item.plan_key="{ item }">
        <VChip
          size="small"
          label
        >
          {{ item.plan_key }}
        </VChip>
      </template>

      <template #item.status="{ item }">
        <VChip
          :color="statusColor(item.status)"
          size="small"
        >
          {{ statusLabel(item.status) }}
        </VChip>
      </template>

      <template #item.provider="{ item }">
        {{ paymentMethodLabel(item.provider) }}
      </template>

      <template #item.created_at="{ item }">
        {{ fmtDate(item.created_at) }}
      </template>

      <template #item.actions="{ item }">
        <div
          v-if="item.status === 'pending'"
          class="d-flex gap-1 justify-center"
        >
          <VBtn
            color="success"
            variant="tonal"
            size="small"
            :loading="actionLoading === item.id"
            :disabled="actionLoading !== null && actionLoading !== item.id"
            @click="approveSubscription(item)"
          >
            {{ t('common.approve') }}
          </VBtn>
          <VBtn
            color="error"
            variant="tonal"
            size="small"
            :loading="actionLoading === item.id"
            :disabled="actionLoading !== null && actionLoading !== item.id"
            @click="rejectSubscription(item)"
          >
            {{ t('common.reject') }}
          </VBtn>
        </div>
        <span
          v-else
          class="text-disabled"
        >—</span>
      </template>

      <template #no-data>
        <div class="text-center pa-6 text-disabled">
          <VIcon
            icon="tabler-receipt-off"
            size="48"
            class="mb-2"
          />
          <p class="text-body-1">
            {{ t('payments.noSubscriptions') }}
          </p>
        </div>
      </template>
    </VDataTable>

    <VCardText
      v-if="store.subscriptionsPagination.last_page > 1"
      class="d-flex justify-center"
    >
      <VPagination
        :model-value="store.subscriptionsPagination.current_page"
        :length="store.subscriptionsPagination.last_page"
        @update:model-value="onPageChange"
      />
    </VCardText>
  </VCard>
</template>
