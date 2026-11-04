<script setup>
// SMART RESPONSIVE UX 2026 (ADR-696) — barre de filtres adaptative. Recherche DIRECTE toujours visible ;
// filtres avancés dans :
//   • desktop → panneau repliable inline ;
//   • mobile  → BOTTOM SHEET (ouvert par « Filtres »).
// Les VALEURS sont possédées par le parent (v-model search + slot #filters) ⇒ préservées lors d'un
// changement de présentation (rotation, desktop↔mobile) : rien n'est perdu. Compteur + réinitialiser.
// Natif Vuetify (VBottomSheet + VExpandTransition). Générique, aucune logique métier.
import { ref } from 'vue'
import { useDisplay } from 'vuetify'

const props = defineProps({
  search: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  activeCount: { type: Number, default: 0 },
  toggleLabel: { type: String, default: '' },
  resetLabel: { type: String, default: '' },
})
const emit = defineEmits(['update:search', 'reset'])

const { t } = useI18n()
const { smAndDown } = useDisplay()
const open = ref(false)

const localSearch = computed({ get: () => props.search, set: v => emit('update:search', v) })
const tLabel = computed(() => props.toggleLabel || t('employees.filters.toggle', 'Filtres'))
const rLabel = computed(() => props.resetLabel || t('employees.filters.reset', 'Réinitialiser'))
</script>

<template>
  <div class="app-filter-panel">
    <div class="d-flex align-center gap-2 flex-wrap">
      <AppTextField
        v-model="localSearch"
        :placeholder="placeholder"
        prepend-inner-icon="tabler-search"
        clearable
        density="compact"
        data-test="fp-search"
        style="min-inline-size: 220px; flex: 1 1 220px;"
      />
      <VBtn
        variant="tonal"
        :color="open || activeCount ? 'primary' : 'secondary'"
        prepend-icon="tabler-adjustments-horizontal"
        data-test="fp-toggle"
        @click="open = !open"
      >
        {{ tLabel }}
        <VChip
          v-if="activeCount"
          size="x-small"
          color="primary"
          class="ms-2"
        >
          {{ activeCount }}
        </VChip>
      </VBtn>
      <VBtn
        v-if="activeCount"
        variant="text"
        size="small"
        prepend-icon="tabler-filter-off"
        data-test="fp-reset"
        @click="emit('reset')"
      >
        {{ rLabel }}
      </VBtn>
    </div>

    <!-- Desktop : panneau repliable inline -->
    <VExpandTransition v-if="!smAndDown">
      <div v-if="open" class="mt-3">
        <slot name="filters" />
      </div>
    </VExpandTransition>

    <!-- Mobile : bottom sheet -->
    <VBottomSheet v-else v-model="open">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ tLabel }}</VCardTitle>
          <template #append>
            <VBtn icon="tabler-x" variant="text" size="small" aria-label="Fermer" @click="open = false" />
          </template>
        </VCardItem>
        <VDivider />
        <VCardText>
          <slot name="filters" />
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 py-3">
          <VBtn
            v-if="activeCount"
            variant="text"
            prepend-icon="tabler-filter-off"
            data-test="fp-reset-sheet"
            @click="emit('reset')"
          >
            {{ rLabel }}
          </VBtn>
          <VSpacer />
          <VBtn color="primary" data-test="fp-apply" @click="open = false">
            {{ $t('common.apply', 'Appliquer') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VBottomSheet>
  </div>
</template>
