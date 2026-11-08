<script setup>
// Leezr Premium — en-tête de page/cockpit unifié (Phase 0, ADR-741).
// Titre + sous-titre + sélecteur de période + zone d'actions.
const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  periods: { type: Array, default: () => [] }, // [{ value, label }]
  modelValue: { type: [String, Number], default: null },
})
const emit = defineEmits(['update:modelValue'])
</script>

<template>
  <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5">
    <div class="min-w-0">
      <h1 class="text-h4 font-weight-medium mb-0 text-truncate">{{ title }}</h1>
      <div v-if="subtitle" class="text-body-2 text-medium-emphasis">{{ subtitle }}</div>
    </div>
    <div class="d-flex align-center flex-wrap ga-2">
      <VBtnToggle
        v-if="periods.length"
        :model-value="modelValue"
        density="comfortable"
        variant="outlined"
        divided
        mandatory
        @update:model-value="emit('update:modelValue', $event)"
      >
        <VBtn v-for="p in periods" :key="p.value" :value="p.value" size="small">{{ p.label }}</VBtn>
      </VBtnToggle>
      <slot name="actions" />
    </div>
  </div>
</template>
