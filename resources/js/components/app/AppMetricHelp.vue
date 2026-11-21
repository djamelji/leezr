<script setup>
const props = defineProps({
  text: { type: String, required: true },
  label: { type: String, default: '' },
})

// ADR-841 (Leezr 4.2 M4) — « what is counted » help of a financial indicator : composition of VTooltip + VIcon,
// reachable by keyboard (focusable button with an accessible name).
const { t } = useI18n()
</script>

<template>
  <VTooltip
    location="top"
    max-width="340"
  >
    <template #activator="{ props: tip }">
      <VBtn
        v-bind="tip"
        icon
        variant="text"
        size="x-small"
        density="comfortable"
        class="ms-1 metric-help"
        :aria-label="props.label ? t('metricHelp.aria', { name: props.label }) : t('metricHelp.ariaGeneric')"
        data-testid="metric-help"
      >
        <VIcon
          icon="tabler-info-circle"
          size="16"
        />
      </VBtn>
    </template>
    <span class="text-body-2">{{ props.text }}</span>
  </VTooltip>
</template>
