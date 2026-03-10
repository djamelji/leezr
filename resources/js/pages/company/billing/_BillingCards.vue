<script setup>
import { formatMoney } from '@/utils/money'

const props = defineProps({
  overview: { type: Object, required: true },
  planName: { type: String, default: '—' },
  planPrice: { type: String, default: '—' },
  planInterval: { type: String, default: '' },
  subscriptionStatus: { type: String, default: null },
  statusColor: { type: String, default: 'secondary' },
  canCancel: { type: Boolean, default: false },
  paymentMethod: { type: Object, default: null },
  paymentMethodLabel: { type: String, default: null },
  walletBalance: { type: String, default: '—' },
  billingDayOptions: { type: Array, required: true },
})

const emit = defineEmits(['open-cancel-dialog', 'update-billing-day'])

const { t } = useI18n()
</script>

<template>
  <VRow>
    <!-- Card — Current Plan -->
    <VCol
      cols="12"
      sm="6"
      md="4"
      lg="3"
    >
      <VCard class="h-100">
        <VCardItem>
          <template #prepend>
            <VAvatar
              color="primary"
              variant="tonal"
              size="40"
              rounded
            >
              <VIcon icon="tabler-diamond" />
            </VAvatar>
          </template>

          <VCardTitle>{{ t('companyBilling.overview.currentPlan') }}</VCardTitle>
        </VCardItem>

        <VCardText>
          <div class="d-flex align-center gap-2 mb-2">
            <h4 class="text-h4">
              {{ planName }}
            </h4>
            <VChip
              v-if="subscriptionStatus"
              :color="statusColor"
              size="small"
              label
            >
              {{ t(`subscriptionStatus.${subscriptionStatus}`) }}
            </VChip>
          </div>

          <p class="text-body-2 text-disabled mb-4">
            {{ planPrice }} / {{ planInterval }}
          </p>

          <div class="d-flex gap-2">
            <VBtn
              variant="tonal"
              color="primary"
              size="small"
              :to="{ name: 'company-plan' }"
            >
              {{ t('companyBilling.overview.changePlan') }}
            </VBtn>
            <VBtn
              v-if="canCancel"
              variant="outlined"
              color="error"
              size="small"
              @click="emit('open-cancel-dialog')"
            >
              {{ t('companyBilling.overview.cancelSubscription') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Card — Payment Method -->
    <VCol
      cols="12"
      sm="6"
      md="4"
      lg="3"
    >
      <VCard class="h-100">
        <VCardItem>
          <template #prepend>
            <VAvatar
              color="info"
              variant="tonal"
              size="40"
              rounded
            >
              <VIcon icon="tabler-credit-card" />
            </VAvatar>
          </template>

          <VCardTitle>{{ t('companyBilling.overview.paymentMethod') }}</VCardTitle>
        </VCardItem>

        <VCardText>
          <template v-if="paymentMethod">
            <p class="text-body-1 font-weight-medium mb-1">
              {{ paymentMethodLabel }}
            </p>
            <p class="text-body-2 text-disabled mb-4">
              {{ t('companyBilling.cardExpiry', { month: paymentMethod.exp_month, year: paymentMethod.exp_year }) }}
            </p>
          </template>

          <p
            v-else
            class="text-body-2 text-disabled mb-4"
          >
            {{ t('companyBilling.overview.noPaymentMethod') }}
          </p>

          <VBtn
            variant="tonal"
            color="info"
            size="small"
            :to="{ name: 'company-billing-tab', params: { tab: 'payment-methods' } }"
          >
            {{ t('companyBilling.overview.manage') }}
          </VBtn>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Card — Wallet -->
    <VCol
      cols="12"
      sm="6"
      md="4"
      lg="3"
    >
      <VCard class="h-100">
        <VCardItem>
          <template #prepend>
            <VAvatar
              color="success"
              variant="tonal"
              size="40"
              rounded
            >
              <VIcon icon="tabler-wallet" />
            </VAvatar>
          </template>

          <VCardTitle>{{ t('companyBilling.walletTitle') }}</VCardTitle>
        </VCardItem>

        <VCardText>
          <h4 class="text-h4 mb-4">
            {{ walletBalance }}
          </h4>

          <VBtn
            variant="tonal"
            color="success"
            size="small"
            :to="{ name: 'company-billing-tab', params: { tab: 'invoices' } }"
          >
            {{ t('companyBilling.overview.viewInvoices') }}
          </VBtn>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Card — Outstanding Invoices -->
    <VCol
      cols="12"
      sm="6"
      md="4"
      lg="3"
    >
      <VCard class="h-100">
        <VCardItem>
          <template #prepend>
            <VAvatar
              :color="overview.outstanding_invoices > 0 ? 'error' : 'secondary'"
              variant="tonal"
              size="40"
              rounded
            >
              <VIcon icon="tabler-file-invoice" />
            </VAvatar>
          </template>

          <VCardTitle>{{ t('companyBilling.invoices') }}</VCardTitle>
        </VCardItem>

        <VCardText>
          <template v-if="overview.outstanding_invoices > 0">
            <p class="text-body-1 font-weight-medium text-error mb-1">
              {{ overview.outstanding_invoices }} {{ t('companyBilling.overview.unpaid') }}
            </p>
            <p class="text-body-2 text-disabled mb-4">
              {{ t('companyBilling.overview.totalDue') }}:
              {{ formatMoney(overview.outstanding_amount, { currency: overview.currency }) }}
            </p>
          </template>

          <p
            v-else
            class="text-body-2 text-disabled mb-4"
          >
            {{ t('companyBilling.overview.allPaid') }}
          </p>

          <VBtn
            variant="tonal"
            :color="overview.outstanding_invoices > 0 ? 'error' : 'secondary'"
            size="small"
            :to="{ name: 'company-billing-tab', params: { tab: 'invoices' } }"
          >
            {{ t('companyBilling.overview.viewInvoices') }}
          </VBtn>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Card — Billing Settings -->
    <VCol
      cols="12"
      sm="6"
      md="4"
      lg="3"
    >
      <VCard class="h-100">
        <VCardItem>
          <template #prepend>
            <VAvatar
              color="warning"
              variant="tonal"
              size="40"
              rounded
            >
              <VIcon icon="tabler-calendar" />
            </VAvatar>
          </template>

          <VCardTitle>{{ t('companyBilling.overview.billingSettings') }}</VCardTitle>
        </VCardItem>

        <VCardText>
          <p class="text-body-2 text-disabled mb-3">
            {{ t('companyBilling.overview.billingDayDesc') }}
          </p>

          <AppSelect
            :model-value="overview?.subscription?.billing_anchor_day || ''"
            :items="billingDayOptions"
            :label="t('companyBilling.overview.billingDay')"
            @update:model-value="emit('update-billing-day', $event)"
          />
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>
