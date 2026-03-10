<script setup>
import { formatMoney } from '@/utils/money'

defineProps({
  preview: { type: Object, required: true },
  nextBillingDate: { type: String, default: '—' },
})

const { t } = useI18n()
</script>

<template>
  <VCard class="mb-6">
    <VCardItem>
      <template #prepend>
        <VAvatar
          color="primary"
          variant="tonal"
          size="48"
          rounded
        >
          <VIcon
            icon="tabler-file-invoice"
            size="28"
          />
        </VAvatar>
      </template>

      <VCardTitle class="text-h5">
        {{ t('companyBilling.nextInvoice.title') }}
      </VCardTitle>
      <VCardSubtitle>
        {{ nextBillingDate }}
      </VCardSubtitle>

      <template #append>
        <div class="text-right">
          <div class="text-h4 font-weight-bold">
            {{ formatMoney(preview.estimated_amount_due ?? preview.total, { currency: preview.currency }) }}
          </div>
          <span class="text-body-2 text-disabled">
            / {{ preview.plan?.interval === 'yearly' ? t('companyBilling.overview.yearly') : t('companyBilling.overview.monthly') }}
          </span>
        </div>
      </template>
    </VCardItem>

    <VDivider />

    <VCardText class="pa-0">
      <VList>
        <!-- Plan line -->
        <VListItem v-if="preview.plan">
          <template #prepend>
            <VAvatar
              color="primary"
              variant="tonal"
              size="36"
              class="me-3"
            >
              <VIcon
                icon="tabler-diamond"
                size="20"
              />
            </VAvatar>
          </template>

          <VListItemTitle class="font-weight-medium">
            {{ preview.plan.name }}
          </VListItemTitle>
          <VListItemSubtitle>
            {{ t('companyBilling.nextInvoice.planLabel') }}
          </VListItemSubtitle>

          <template #append>
            <span class="font-weight-medium">
              {{ formatMoney(preview.plan.price, { currency: preview.currency }) }}
            </span>
          </template>
        </VListItem>

        <!-- Addon lines -->
        <VListItem
          v-for="addon in preview.addons"
          :key="addon.module_key"
        >
          <template #prepend>
            <VAvatar
              color="secondary"
              variant="tonal"
              size="36"
              class="me-3"
            >
              <VIcon
                icon="tabler-puzzle"
                size="20"
              />
            </VAvatar>
          </template>

          <VListItemTitle class="font-weight-medium">
            {{ addon.name }}
          </VListItemTitle>
          <VListItemSubtitle>
            {{ t('companyBilling.nextInvoice.addonLabel') }}
          </VListItemSubtitle>

          <template #append>
            <span class="font-weight-medium">
              +{{ formatMoney(addon.price, { currency: preview.currency }) }}
            </span>
          </template>
        </VListItem>

        <VDivider />

        <!-- Subtotal line -->
        <VListItem>
          <VListItemTitle class="font-weight-medium">
            {{ t('companyBilling.nextInvoice.subtotal') }}
          </VListItemTitle>

          <template #append>
            <span class="font-weight-medium">
              {{ formatMoney(preview.subtotal ?? preview.total, { currency: preview.currency }) }}
            </span>
          </template>
        </VListItem>

        <!-- Tax line -->
        <VListItem v-if="preview.tax_amount > 0">
          <VListItemTitle class="text-body-2 text-disabled">
            {{ t('companyBilling.nextInvoice.tax') }}
            <span v-if="preview.tax_rate_bps">({{ (preview.tax_rate_bps / 100).toFixed(1) }}%)</span>
          </VListItemTitle>

          <template #append>
            <span class="text-body-2">
              +{{ formatMoney(preview.tax_amount, { currency: preview.currency }) }}
            </span>
          </template>
        </VListItem>

        <!-- Tax exemption -->
        <VListItem v-if="preview.tax_exemption_reason">
          <VListItemTitle>
            <VChip
              color="info"
              variant="tonal"
              size="small"
            >
              {{ t('billing.tax_exemption.' + preview.tax_exemption_reason) }}
            </VChip>
          </VListItemTitle>
        </VListItem>

        <!-- Wallet credit line -->
        <VListItem v-if="preview.estimated_wallet_credit > 0">
          <VListItemTitle class="text-body-2 text-success">
            {{ t('companyBilling.nextInvoice.walletCredit') }}
          </VListItemTitle>

          <template #append>
            <span class="text-body-2 text-success">
              -{{ formatMoney(preview.estimated_wallet_credit, { currency: preview.currency }) }}
            </span>
          </template>
        </VListItem>

        <VDivider />

        <!-- Amount due -->
        <VListItem>
          <VListItemTitle class="text-h6 font-weight-bold">
            {{ t('companyBilling.nextInvoice.estimatedAmountDue') }}
          </VListItemTitle>

          <template #append>
            <span class="text-h6 font-weight-bold">
              {{ formatMoney(preview.estimated_amount_due ?? preview.total, { currency: preview.currency }) }}
            </span>
          </template>
        </VListItem>
      </VList>
    </VCardText>

    <VCardText
      v-if="preview.is_estimate"
      class="pt-0"
    >
      <p class="text-caption text-disabled mb-0">
        {{ t('companyBilling.nextInvoice.estimateDisclaimer') }}
      </p>
    </VCardText>
  </VCard>
</template>
