<script setup>
const { t } = useI18n()
</script>

<template>
  <VCard>
    <VCardText class="text-center pa-8">
      <VAvatar
        size="64"
        variant="tonal"
        color="primary"
        class="mb-4"
      >
        <VIcon
          icon="tabler-diamond"
          size="32"
        />
      </VAvatar>
      <h5 class="text-h5 mb-2">
        {{ t('companyBilling.noSubscription') }}
      </h5>
      <p class="text-body-2 text-disabled mb-4">
        {{ t('companyBilling.overview.choosePlan') }}
      </p>
      <VBtn
        color="primary"
        :to="{ name: 'company-plan' }"
      >
        {{ t('companyBilling.overview.browsePlans') }}
      </VBtn>
    </VCardText>
  </VCard>
</template>
