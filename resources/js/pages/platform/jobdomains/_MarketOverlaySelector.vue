<script setup>
const props = defineProps({
  markets: { type: Array, default: () => [] },
  overlays: { type: Object, default: () => ({}) },
  modelValue: { type: [String, null], default: null },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const items = computed(() => {
  const list = [{ key: null, label: t('platformJobdomains.global'), flagCode: null, hasOverlay: false }]

  for (const market of props.markets) {
    list.push({
      key: market.key,
      label: market.name,
      flagCode: market.flag_code,
      hasOverlay: !!props.overlays[market.key],
    })
  }

  return list
})
</script>

<template>
  <div class="d-flex align-center gap-2">
    <VIcon
      icon="tabler-world"
      size="20"
      color="primary"
    />
    <AppSelect
      :model-value="modelValue"
      :items="items"
      item-value="key"
      item-title="label"
      density="compact"
      hide-details
      style="max-inline-size: 220px;"
      @update:model-value="emit('update:modelValue', $event)"
    >
      <template #selection="{ item }">
        <span
          v-if="item.raw.flagCode"
          class="me-1"
        >{{ item.raw.flagCode }}</span>
        {{ item.title }}
        <VChip
          v-if="item.raw.hasOverlay"
          size="x-small"
          color="info"
          variant="tonal"
          class="ms-1"
        >
          {{ t('platformJobdomains.overlay') }}
        </VChip>
      </template>
      <template #item="{ item, props: itemProps }">
        <VListItem v-bind="itemProps">
          <template #prepend>
            <span
              v-if="item.raw.flagCode"
              class="me-2 text-body-1"
            >{{ item.raw.flagCode }}</span>
            <VIcon
              v-else
              icon="tabler-world"
              size="18"
              class="me-2"
            />
          </template>
          <template #append>
            <VChip
              v-if="item.raw.hasOverlay"
              size="x-small"
              color="info"
              variant="tonal"
            >
              {{ t('platformJobdomains.overlay') }}
            </VChip>
          </template>
        </VListItem>
      </template>
    </AppSelect>
  </div>
</template>
