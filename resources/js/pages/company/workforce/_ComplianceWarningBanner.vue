<script setup>
defineProps({
  warnings: { type: Array, required: true },
})
</script>

<template>
  <div
    v-if="warnings.length"
    class="d-flex flex-column gap-2"
  >
    <VAlert
      v-for="warning in warnings"
      :key="warning.key"
      :type="warning.severity"
      variant="tonal"
      closable
      density="compact"
    >
      <template #text>
        <div class="d-flex align-center justify-space-between">
          <span>{{ $t(warning.message) }}</span>
          <VBtn
            v-if="warning.actionLabel"
            :to="warning.actionTo"
            variant="text"
            :color="warning.severity"
            size="small"
            class="ms-2"
          >
            {{ $t(warning.actionLabel) }}
          </VBtn>
        </div>
      </template>
    </VAlert>
  </div>
</template>
