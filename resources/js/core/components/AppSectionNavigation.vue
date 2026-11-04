<script setup>
// SMART RESPONSIVE UX 2026 (ADR-694) — navigation secondaire adaptative. Choisit le MEILLEUR pattern
// selon le nombre de sections et la largeur, sans onglets tronqués et sans tout transformer en dropdown :
//   • desktop            → onglets classiques (VTabs) ;
//   • mobile, ≤ 3 courtes → segmented control (VBtnToggle) ;
//   • mobile, 4–5        → onglets scrollables avec flèches (indication claire) ;
//   • mobile, > 5        → sélecteur de section (VSelect).
// Reste synchronisé avec l'état (v-model) ⇒ routes/deep-links préservés par le parent. Générique.
import { computed } from 'vue'
import { useDisplay } from 'vuetify'

const props = defineProps({
  // [{ value, label, icon?, disabled? }]
  sections: { type: Array, required: true },
  modelValue: { type: [String, Number], default: null },
})
const emit = defineEmits(['update:modelValue'])

const { smAndDown } = useDisplay()

const active = computed({
  get: () => props.modelValue,
  set: v => emit('update:modelValue', v),
})

// Décision de pattern (pas systématiquement un dropdown).
const pattern = computed(() => {
  if (!smAndDown.value) return 'tabs'
  const n = props.sections.length
  if (n <= 3) return 'segmented'
  if (n <= 5) return 'tabs-scroll'

  return 'select'
})

const current = computed(() => props.sections.find(s => s.value === active.value) || props.sections[0])
</script>

<template>
  <!-- Desktop : onglets classiques -->
  <VTabs
    v-if="pattern === 'tabs'"
    v-model="active"
    class="v-tabs-pill"
    show-arrows
  >
    <VTab
      v-for="s in sections"
      :key="s.value"
      :value="s.value"
      :disabled="s.disabled"
      :data-test="`asn-tab-${s.value}`"
    >
      <VIcon v-if="s.icon" :icon="s.icon" size="20" class="me-2" />
      {{ s.label }}
    </VTab>
  </VTabs>

  <!-- Mobile, peu de sections : segmented control -->
  <VBtnToggle
    v-else-if="pattern === 'segmented'"
    v-model="active"
    mandatory
    divided
    density="comfortable"
    color="primary"
    variant="outlined"
    class="w-100 asn-segmented"
  >
    <VBtn
      v-for="s in sections"
      :key="s.value"
      :value="s.value"
      :disabled="s.disabled"
      class="flex-grow-1"
      :data-test="`asn-seg-${s.value}`"
    >
      <VIcon v-if="s.icon" :icon="s.icon" size="18" />
      <span class="ms-1 text-truncate">{{ s.label }}</span>
    </VBtn>
  </VBtnToggle>

  <!-- Mobile, nombre moyen : onglets scrollables -->
  <VTabs
    v-else-if="pattern === 'tabs-scroll'"
    v-model="active"
    class="v-tabs-pill"
    show-arrows
    density="comfortable"
  >
    <VTab
      v-for="s in sections"
      :key="s.value"
      :value="s.value"
      :disabled="s.disabled"
      :data-test="`asn-tab-${s.value}`"
    >
      <VIcon v-if="s.icon" :icon="s.icon" size="18" class="me-1" />
      {{ s.label }}
    </VTab>
  </VTabs>

  <!-- Mobile, nombreuses sections : sélecteur -->
  <VSelect
    v-else
    v-model="active"
    :items="sections"
    item-title="label"
    item-value="value"
    variant="outlined"
    density="comfortable"
    hide-details
    :prepend-inner-icon="current?.icon"
    data-test="asn-select"
  >
    <template #item="{ props: itemProps, item }">
      <VListItem
        v-bind="itemProps"
        :prepend-icon="item.raw.icon"
        :disabled="item.raw.disabled"
        :data-test="`asn-opt-${item.raw.value}`"
      />
    </template>
  </VSelect>
</template>

<style lang="scss">
.asn-segmented .v-btn { min-inline-size: 0; }
</style>
