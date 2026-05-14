<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, required: true },
  icon: { type: String, required: true },
  color: { type: String, default: 'primary' },
  to: { type: Object, required: true },
  stats: { type: Array, default: () => [] },
  disabled: { type: Boolean, default: false },
  comingSoon: { type: Boolean, default: false },
})
</script>

<template>
  <VCard
    :class="{ 'card-disabled': disabled || comingSoon }"
    :to="!disabled && !comingSoon ? to : undefined"
    class="cursor-pointer"
  >
    <VCardItem>
      <template #prepend>
        <VAvatar
          :color="color"
          variant="tonal"
          rounded
          size="44"
        >
          <VIcon
            :icon="icon"
            size="28"
          />
        </VAvatar>
      </template>
      <VCardTitle>{{ $t(title) }}</VCardTitle>
      <VCardSubtitle>{{ $t(description) }}</VCardSubtitle>
      <template #append>
        <VChip
          v-if="comingSoon"
          color="secondary"
          size="small"
          variant="tonal"
        >
          {{ $t('workforce.comingSoon') }}
        </VChip>
        <VIcon
          v-else
          icon="tabler-chevron-right"
          color="secondary"
        />
      </template>
    </VCardItem>
    <VCardText
      v-if="stats.length"
      class="d-flex gap-4"
    >
      <div
        v-for="stat in stats"
        :key="stat.label"
        class="d-flex align-center gap-1"
      >
        <span
          class="text-h6 font-weight-bold"
          :class="`text-${stat.color || 'primary'}`"
        >{{ stat.value }}</span>
        <span class="text-body-2 text-medium-emphasis">{{ $t(stat.label) }}</span>
      </div>
    </VCardText>
  </VCard>
</template>

<style scoped>
.card-disabled {
  opacity: 0.5;
  pointer-events: none;
}
</style>
