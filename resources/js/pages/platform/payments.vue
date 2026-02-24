<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { useAppToast } from '@/composables/useAppToast'
import { formatDate } from '@/utils/datetime'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.billing',
    permission: 'manage_billing',
  },
})

const { t } = useI18n()
const paymentsStore = usePlatformPaymentsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const configLoading = ref(false)
const policiesLoading = ref(false)
const actionLoading = ref(null)

const selectedProvider = ref('null')

// Local policies form
const policiesForm = ref({
  payment_required: false,
  admin_approval_required: true,
  annual_only: false,
  currency: 'usd',
  vat_enabled: false,
  vat_rate: 0,
})

onMounted(async () => {
  try {
    await Promise.all([
      paymentsStore.fetchProviders(),
      paymentsStore.fetchConfig(),
      paymentsStore.fetchPolicies(),
      paymentsStore.fetchSubscriptions(),
    ])
    selectedProvider.value = paymentsStore.config.driver || 'null'
    policiesForm.value = { ...paymentsStore.policies }
  }
  finally {
    isLoading.value = false
  }
})

// Section 1: Payment Modules
const activeProviderKey = computed(() => paymentsStore.config.driver || 'null')

const providerOptions = computed(() =>
  paymentsStore.providers.map(p => ({
    title: p.name,
    value: p.key,
    props: {
      disabled: p.key !== 'null' && !p.installed,
      subtitle: p.key !== 'null' && !p.installed ? `(${t('payments.notInstalled')})` : undefined,
    },
  })),
)

const providerStatusLabel = provider => {
  if (provider.key === activeProviderKey.value)
    return t('payments.statusActive')
  if (provider.installed)
    return t('payments.statusInstalled')

  return t('payments.statusNotInstalled')
}

const providerStatusColor = provider => {
  if (provider.key === activeProviderKey.value)
    return 'success'
  if (provider.installed)
    return 'info'

  return 'secondary'
}

const providerIcon = provider => {
  if (provider.key === 'null')
    return 'tabler-lock'
  if (provider.key === 'stripe')
    return 'tabler-brand-stripe'

  return 'tabler-credit-card'
}

const saveProvider = async () => {
  configLoading.value = true

  try {
    await paymentsStore.updateConfig({
      driver: selectedProvider.value,
      config: paymentsStore.config.config || {},
    })
    toast(t('payments.providerUpdated'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('payments.failedToUpdateProvider'), 'error')
  }
  finally {
    configLoading.value = false
  }
}

// Section 2: Payment Policies
const currencyOptions = [
  { title: 'USD ($)', value: 'usd' },
  { title: 'EUR (\u20AC)', value: 'eur' },
  { title: 'GBP (\u00A3)', value: 'gbp' },
]

const savePolicies = async () => {
  policiesLoading.value = true

  try {
    const data = await paymentsStore.updatePolicies(policiesForm.value)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('payments.failedToUpdatePolicies'), 'error')
  }
  finally {
    policiesLoading.value = false
  }
}

// Section 3: Subscriptions
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
    const data = await paymentsStore.approveSubscription(subscription.id)

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
    const data = await paymentsStore.rejectSubscription(subscription.id)

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
    await paymentsStore.fetchSubscriptions(page)
  }
  finally {
    isLoading.value = false
  }
}
</script>

<template>
  <div>
    <div class="mb-6">
      <h4 class="text-h4">
        {{ t('payments.title') }}
      </h4>
      <p class="text-body-1 mb-0">
        {{ t('payments.subtitle') }}
      </p>
    </div>

    <VSkeletonLoader
      v-if="isLoading"
      type="card, card, card"
    />

    <template v-else>
      <!-- Section 1: Payment Modules -->
      <VCard class="mb-6">
        <VCardTitle>
          <VIcon
            icon="tabler-plug"
            class="me-2"
          />
          {{ t('payments.paymentModules') }}
        </VCardTitle>

        <VCardText>
          <!-- Module cards -->
          <div class="mb-4">
            <VCard
              v-for="provider in paymentsStore.providers"
              :key="provider.key"
              flat
              border
              class="mb-3"
              :style="provider.key === activeProviderKey ? 'border-color: rgb(var(--v-theme-primary))' : ''"
            >
              <div class="d-flex align-center pa-4">
                <VAvatar
                  size="40"
                  variant="tonal"
                  :color="provider.key === activeProviderKey ? 'primary' : 'secondary'"
                  class="me-4"
                >
                  <VIcon :icon="providerIcon(provider)" />
                </VAvatar>

                <div class="flex-grow-1">
                  <h6 class="text-h6">
                    {{ provider.name }}
                  </h6>
                  <p
                    v-if="provider.module_key"
                    class="text-body-2 text-disabled mb-0"
                  >
                    Module: {{ provider.module_key }}
                  </p>
                </div>

                <VChip
                  :color="providerStatusColor(provider)"
                  size="small"
                  variant="tonal"
                >
                  {{ providerStatusLabel(provider) }}
                </VChip>
              </div>
            </VCard>
          </div>

          <!-- Provider selector -->
          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <VSelect
                v-model="selectedProvider"
                :items="providerOptions"
                :label="t('payments.activeProvider')"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
              class="d-flex align-center"
            >
              <VBtn
                :loading="configLoading"
                @click="saveProvider"
              >
                {{ t('payments.saveProvider') }}
              </VBtn>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!-- Section 2: Payment Policies -->
      <VCard class="mb-6">
        <VCardTitle>
          <VIcon
            icon="tabler-shield-check"
            class="me-2"
          />
          {{ t('payments.paymentPolicies') }}
        </VCardTitle>

        <VCardText>
          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <VSwitch
                v-model="policiesForm.payment_required"
                :label="t('payments.paymentRequired')"
                class="mb-4"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <VSwitch
                v-model="policiesForm.admin_approval_required"
                :label="t('payments.adminApproval')"
                class="mb-4"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <VSwitch
                v-model="policiesForm.annual_only"
                :label="t('payments.annualOnly')"
                class="mb-4"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <VSelect
                v-model="policiesForm.currency"
                :items="currencyOptions"
                :label="t('payments.primaryCurrency')"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <VSwitch
                v-model="policiesForm.vat_enabled"
                :label="t('payments.vatApplicable')"
                class="mb-4"
              />
            </VCol>

            <VCol
              v-if="policiesForm.vat_enabled"
              cols="12"
              md="6"
            >
              <AppTextField
                v-model.number="policiesForm.vat_rate"
                :label="t('payments.vatRate')"
                type="number"
                min="0"
                max="100"
                suffix="%"
              />
            </VCol>

            <VCol cols="12">
              <VBtn
                :loading="policiesLoading"
                @click="savePolicies"
              >
                {{ t('payments.savePolicies') }}
              </VBtn>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!-- Section 3: Subscription Governance -->
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
          :items="paymentsStore.subscriptions"
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
          v-if="paymentsStore.subscriptionsPagination.last_page > 1"
          class="d-flex justify-center"
        >
          <VPagination
            :model-value="paymentsStore.subscriptionsPagination.current_page"
            :length="paymentsStore.subscriptionsPagination.last_page"
            @update:model-value="onPageChange"
          />
        </VCardText>
      </VCard>
    </template>
  </div>
</template>
