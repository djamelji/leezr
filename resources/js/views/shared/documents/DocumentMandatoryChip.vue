<script setup>
defineProps({
  mandatory: {
    type: Boolean,
    default: false,
  },
  required: {
    type: Boolean,
    default: false,
  },
})

const { t } = useI18n()
</script>

<template>
  <VTooltip
    v-if="mandatory"
    :text="t('documents.systemMandatoryTooltip')"
  >
    <template #activator="{ props: tooltipProps }">
      <VChip
        v-bind="tooltipProps"
        size="x-small"
        variant="tonal"
        color="error"
      >
        <VIcon
          icon="tabler-lock"
          size="12"
          start
        />
        {{ t('documents.systemMandatory') }}
      </VChip>
    </template>
  </VTooltip>
  <VChip
    v-else-if="required"
    size="x-small"
    variant="tonal"
    color="warning"
  >
    {{ t('documents.requiredOverride') }}
  </VChip>
  <VChip
    v-else
    size="x-small"
    variant="tonal"
    color="default"
  >
    {{ t('documents.optional') }}
  </VChip>
</template>
