<script setup>
// SMART RESPONSIVE UX 2026 (ADR-695) — vue de données avec PRIORITÉ & DENSITÉ (décisions de design) :
//   • desktop (≥960) → table riche, toutes les colonnes ;
//   • tablette (600-959) → table RÉDUITE aux colonnes qui ont de la valeur (essential + important) ;
//   • mobile (<600) → LISTE TACTILE COMPACTE (slot #mobile-item, 2-3 infos utiles), détail au toucher.
// Priorité déclarée par colonne : header.priority = 'essential' | 'important' | 'secondary' | 'detail'.
// Distingue la DISPONIBILITÉ d'une donnée de sa PRÉSENCE à l'écran : l'e-mail/téléphone restent dans
// la fiche, pas dans la liste mobile. La confidentialité SERVEUR reste la règle (rien n'est exposé ici).
// Logique (données/tri/pagination/filtres/permissions) PARTAGÉE ; seule la présentation varie.
import { computed } from 'vue'
import { useDisplay } from 'vuetify'

const props = defineProps({
  headers: { type: Array, required: true },
  items: { type: Array, default: () => [] },
  itemsLength: { type: Number, default: 0 },
  loading: { type: Boolean, default: false },
  options: { type: Object, default: null },
  modelValue: { type: Array, default: () => [] },
  itemValue: { type: String, default: 'id' },
  showSelect: { type: Boolean, default: false },
  itemsPerPageOptions: { type: Array, default: () => [15, 30, 50] },
})
const emit = defineEmits(['update:options', 'update:modelValue', 'row-click'])

const { xs, smAndDown } = useDisplay()
const isList = computed(() => xs.value) // < 600 : liste tactile

// Filtrage des colonnes par priorité (tablette = essential + important).
const RANK = { essential: 0, important: 1, secondary: 2, detail: 3 }
const maxRank = computed(() => (smAndDown.value ? 1 : 3))
const visibleHeaders = computed(() => props.headers.filter(h => {
  if (!h.key || h.key === 'actions') return true
  const r = RANK[h.priority] ?? 0 // sans priorité → essentiel (toujours affiché)

  return r <= maxRank.value
}))

const localOptions = computed({ get: () => props.options, set: v => emit('update:options', v) })
const selection = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })

const page = computed({
  get: () => props.options?.page || 1,
  set: v => emit('update:options', { ...props.options, page: v }),
})
const perPage = computed(() => props.options?.itemsPerPage || 15)
const pageCount = computed(() => Math.max(1, Math.ceil((props.itemsLength || 0) / perPage.value)))

const keyOf = item => item?.[props.itemValue]
const isSelected = item => selection.value.includes(keyOf(item))
const toggleSelect = item => {
  const k = keyOf(item)
  const set = new Set(selection.value)
  set.has(k) ? set.delete(k) : set.add(k)
  emit('update:modelValue', [...set])
}
</script>

<template>
  <!-- ═══ TABLE (desktop / tablette) — colonnes filtrées par priorité ═══ -->
  <VDataTableServer
    v-if="!isList"
    v-model="selection"
    v-model:options="localOptions"
    :headers="visibleHeaders"
    :items="items"
    :items-length="itemsLength"
    :loading="loading"
    :item-value="itemValue"
    :show-select="showSelect"
    class="text-no-wrap"
    @click:row="(_, { item }) => emit('row-click', item)"
  >
    <template
      v-for="(_, name) in $slots"
      :key="name"
      #[name]="scope"
    >
      <slot :name="name" v-bind="scope || {}" />
    </template>
  </VDataTableServer>

  <!-- ═══ LISTE TACTILE COMPACTE (mobile) ═══ -->
  <div v-else class="arv-mobile">
    <div v-if="loading && !items.length" class="pa-2">
      <VSkeletonLoader
        v-for="n in 6"
        :key="n"
        type="list-item-avatar"
        class="mb-1"
      />
    </div>

    <div v-else-if="!items.length">
      <slot name="no-data">
        <div class="text-center text-medium-emphasis py-8">
          {{ $t('common.noData', 'Aucune donnée') }}
        </div>
      </slot>
    </div>

    <VList v-else class="py-0" role="list">
      <template v-for="(item, idx) in items" :key="keyOf(item)">
        <VListItem
          class="arv-row px-3"
          role="listitem"
          :data-test="`arv-row-${keyOf(item)}`"
          @click="emit('row-click', item)"
        >
          <template v-if="showSelect" #prepend>
            <VCheckboxBtn
              :model-value="isSelected(item)"
              density="compact"
              class="me-1"
              @click.stop="toggleSelect(item)"
              @update:model-value="toggleSelect(item)"
            />
          </template>
          <slot name="mobile-item" :item="item" />
          <template #append>
            <slot name="mobile-append" :item="item" />
          </template>
        </VListItem>
        <VDivider v-if="idx < items.length - 1" />
      </template>
    </VList>

    <div
      v-if="pageCount > 1"
      class="d-flex align-center justify-space-between px-3 py-2 flex-wrap gap-2"
    >
      <span class="text-caption text-medium-emphasis">
        {{ $t('common.pageOf', { page, total: pageCount }) }}
      </span>
      <VPagination
        v-model="page"
        :length="pageCount"
        :total-visible="3"
        density="comfortable"
        rounded
      />
    </div>
  </div>
</template>

<style lang="scss">
.arv-mobile {
  .arv-row {
    cursor: pointer;
    min-block-size: 52px; // ligne compacte mais cible tactile confortable
  }
}
</style>
