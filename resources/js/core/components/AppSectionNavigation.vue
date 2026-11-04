<script setup>
// SMART RESPONSIVE UX 2026 (ADR-695) — navigation secondaire, pensée produit (pas « overflow technique »).
// Respecte la hiérarchie Vuexy : la barre d'onglets est la NAVIGATION SUPÉRIEURE de la page, placée
// AU-DESSUS du contenu (le parent met le panneau en dessous), visuellement distincte du contenu.
//   • desktop → onglets Vuexy icône + libellé (états actifs/bordures du thème) ;
//   • mobile  → onglets ICÔNES SEULES, répartis sur la largeur, SANS scroll ni troncature ;
//              si trop nombreux → regroupement explicite « Plus » (jamais de compression/scroll).
// Libellés conservés pour lecteurs d'écran (aria-label) + tooltip si l'icône peut être ambiguë.
// Sync état (v-model) ⇒ routes/deep-links préservés par le parent. Générique, aucune logique métier.
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { useContainerWidth } from '@/composables/useContainerWidth'

const props = defineProps({
  // [{ value, label, icon, disabled? }]
  sections: { type: Array, required: true },
  modelValue: { type: [String, Number], default: null },
})
const emit = defineEmits(['update:modelValue'])

const { smAndDown } = useDisplay()
const isMobile = computed(() => smAndDown.value)

const active = computed({
  get: () => props.modelValue,
  set: v => emit('update:modelValue', v),
})

// Répartition mobile : cible tactile ~56px ; on réserve une place pour « Plus » si débordement.
const barRef = ref(null)
const { width } = useContainerWidth(barRef)
const maxVisible = computed(() => Math.max(2, Math.floor((width.value || 360) / 64)))
const needsOverflow = computed(() => props.sections.length > maxVisible.value)
const visibleSections = computed(() =>
  needsOverflow.value ? props.sections.slice(0, maxVisible.value - 1) : props.sections)
const overflowSections = computed(() =>
  needsOverflow.value ? props.sections.slice(maxVisible.value - 1) : [])
const overflowActive = computed(() => overflowSections.value.some(s => s.value === active.value))
</script>

<template>
  <!-- Desktop : onglets Vuexy (icône + libellé), navigation supérieure distincte du contenu -->
  <VTabs
    v-if="!isMobile"
    v-model="active"
    class="app-section-nav"
    color="primary"
  >
    <VTab
      v-for="s in sections"
      :key="s.value"
      :value="s.value"
      :disabled="s.disabled"
      :data-test="`asn-tab-${s.value}`"
    >
      <VIcon
        v-if="s.icon"
        :icon="s.icon"
        start
      />
      {{ s.label }}
    </VTab>
  </VTabs>

  <!-- Mobile : icônes seules réparties, sans scroll ; « Plus » si débordement -->
  <div
    v-else
    ref="barRef"
    class="app-section-nav app-section-nav--mobile d-flex align-stretch"
    role="tablist"
  >
    <VBtn
      v-for="s in visibleSections"
      :key="s.value"
      class="asn-mtab flex-grow-1"
      :variant="active === s.value ? 'tonal' : 'text'"
      :color="active === s.value ? 'primary' : 'medium-emphasis'"
      :disabled="s.disabled"
      :aria-label="s.label"
      :aria-selected="active === s.value"
      role="tab"
      :data-test="`asn-mtab-${s.value}`"
      @click="active = s.value"
    >
      <VIcon :icon="s.icon" size="22" />
      <VTooltip
        activator="parent"
        location="bottom"
        open-delay="400"
      >
        {{ s.label }}
      </VTooltip>
    </VBtn>

    <VBtn
      v-if="overflowSections.length"
      class="asn-mtab flex-grow-1"
      :variant="overflowActive ? 'tonal' : 'text'"
      :color="overflowActive ? 'primary' : 'medium-emphasis'"
      aria-label="Plus de sections"
      data-test="asn-more"
    >
      <VIcon icon="tabler-dots" size="22" />
      <VMenu
        activator="parent"
        location="bottom end"
      >
        <VList density="comfortable" min-width="200">
          <VListItem
            v-for="s in overflowSections"
            :key="s.value"
            :prepend-icon="s.icon"
            :active="active === s.value"
            :disabled="s.disabled"
            :data-test="`asn-mopt-${s.value}`"
            @click="active = s.value"
          >
            <VListItemTitle>{{ s.label }}</VListItemTitle>
          </VListItem>
        </VList>
      </VMenu>
    </VBtn>
  </div>
</template>

<style lang="scss">
.app-section-nav--mobile {
  border-block-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  gap: 2px;

  .asn-mtab {
    min-block-size: 48px;
    block-size: 48px;
    min-inline-size: 0; // laisse le flex répartir sans débordement
    border-radius: 0;
  }
}
</style>
