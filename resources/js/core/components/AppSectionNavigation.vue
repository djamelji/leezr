<script setup>
// SMART RESPONSIVE UX 2026 (ADR-695) — navigation secondaire, pensée produit (pas « overflow technique »).
// Respecte la hiérarchie Vuexy : la barre d'onglets est la NAVIGATION SUPÉRIEURE de la page, placée
// AU-DESSUS du contenu (le parent met le panneau en dessous), visuellement distincte du contenu.
//   • desktop → onglets Vuexy icône + libellé (états actifs/bordures du thème) ;
//   • mobile  → onglets icône + libellé court (ADR-824 : les icônes seules n'étaient pas explicites),
//              répartis sur la largeur, SANS scroll ;
//              si trop nombreux → regroupement explicite « Plus » (jamais de compression/scroll).
// Libellés conservés pour lecteurs d'écran (aria-label) + tooltip si l'icône peut être ambiguë.
// Sync état (v-model) ⇒ routes/deep-links préservés par le parent. Générique, aucune logique métier.
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { useContainerWidth } from '@/composables/useContainerWidth'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  // [{ value, label, icon, short?, disabled? }] — `short` (ADR-862) : the phone label, whole, never truncated
  sections: { type: Array, required: true },
  modelValue: { type: [String, Number], default: null },
})
const emit = defineEmits(['update:modelValue'])

const { smAndDown } = useDisplay()
const { t } = useI18n()
const isMobile = computed(() => smAndDown.value)

const active = computed({
  get: () => props.modelValue,
  set: v => emit('update:modelValue', v),
})

// Répartition mobile : ~64px par onglet (icône + libellé court, tronqué au besoin) ; « Plus » si débordement.
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
      min-width="0"
      :variant="active === s.value ? 'tonal' : 'text'"
      :color="active === s.value ? 'primary' : 'medium-emphasis'"
      :disabled="s.disabled"
      :aria-label="s.label"
      :aria-selected="active === s.value"
      role="tab"
      :data-test="`asn-mtab-${s.value}`"
      @click="active = s.value"
    >
      <span class="asn-mtab__inner">
        <VIcon
          :icon="s.icon"
          size="20"
        />
        <span class="asn-mtab__label">{{ s.short || s.label }}</span>
      </span>
    </VBtn>

    <VBtn
      v-if="overflowSections.length"
      class="asn-mtab flex-grow-1"
      min-width="0"
      :variant="overflowActive ? 'tonal' : 'text'"
      :color="overflowActive ? 'primary' : 'medium-emphasis'"
      :aria-label="t('appSectionNav.more')"
      data-test="asn-more"
    >
      <span class="asn-mtab__inner">
        <VIcon
          icon="tabler-dots"
          size="20"
        />
        <span class="asn-mtab__label">{{ t('appSectionNav.moreShort') }}</span>
      </span>
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
    flex: 1 1 0; // ADR-824 : largeur répartie, jamais dictée par le libellé (pas de débordement)
    min-block-size: 56px;
    block-size: 56px;
    min-inline-size: 0; // laisse le flex répartir sans débordement
    padding-inline: 1px; // ADR-862 : every pixel for the whole short label
    border-radius: 0;
    text-transform: none;
    letter-spacing: normal;
  }

  .asn-mtab .v-btn__content {
    min-inline-size: 0;
    max-inline-size: 100%;
    overflow: hidden;
  }

  .asn-mtab__inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    min-inline-size: 0;
    max-inline-size: 100%;
  }

  .asn-mtab__label {
    max-inline-size: 100%;
    overflow: hidden;
    font-size: 0.6875rem;
    letter-spacing: -0.01em;
    line-height: 1.1;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}
</style>
