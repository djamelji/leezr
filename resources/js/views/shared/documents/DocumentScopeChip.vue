<script setup>
defineProps({
  scope: {
    type: String,
    required: true,
    validator: v => ['company_user', 'company'].includes(v),
  },
})

const { t } = useI18n()
</script>

<template>
  <VChip
    size="small"
    variant="tonal"
    :color="scope === 'company_user' ? 'warning' : 'primary'"
  >
    <VIcon
      :icon="scope === 'company_user' ? 'tabler-user' : 'tabler-building'"
      size="14"
      start
    />
    {{ scope === 'company_user' ? t('documents.scopeMember') : t('documents.scopeCompany') }}
  </VChip>
</template>
