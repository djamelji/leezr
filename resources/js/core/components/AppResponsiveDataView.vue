<script setup>
// SMART RESPONSIVE UX 2026 (ADR-694) — vue de données adaptative. MÊME dataset :
//   • desktop → VDataTableServer riche (colonnes, tri, pagination, sélection, actions) ;
//   • mobile  → liste tactile compacte (slot #mobile-item), détail au toucher, actions en overflow.
// La LOGIQUE (données, tri, pagination, filtres, permissions) reste PARTAGÉE et inchangée — seule la
// PRÉSENTATION varie. Générique : aucune dépendance métier (pas d'Employee/Shift/JobDomain).
// Ne pas utiliser pour les grilles complexes (planning) : elles ont leur propre adaptation.
import { computed } from 'vue'
import { useDisplay } from 'vuetify'

const props = defineProps({
  headers: { type: Array, required: true },
  items: { type: Array, default: () => [] },
  itemsLength: { type: Number, default: 0 },
  loading: { type: Boolean, default: false },
  // v-model:options (pagination/tri serveur, comme VDataTableServer)
  options: { type: Object, default: null },
  // v-model (sélection) — tableau d'item-value
  modelValue: { type: Array, default: () => [] },
  itemValue: { type: String, default: 'id' },
  showSelect: { type: Boolean, default: false },
  // Bascule liste mobile : par défaut smAndDown (le viewport est le bon signal pour une vue PLEINE page).
  mobile: { type: Boolean, default: null },
  itemsPerPageOptions: { type: Array, default: () => [15, 30, 50] },
})

const emit = defineEmits(['update:options', 'update:modelValue', 'row-click'])

const { smAndDown } = useDisplay()
const isMobile = computed(() => (props.mobile === null ? smAndDown.value : props.mobile))

const localOptions = computed({
  get: () => props.options,
  set: v => emit('update:options', v),
})
const selection = computed({
  get: () => props.modelValue,
  set: v => emit('update:modelValue', v),
})

// ── Pagination mobile (réutilise les mêmes `options` que la table) ────────────
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
  <!-- ═══ DESKTOP : table riche (slots de colonnes transmis tels quels) ═══ -->
  <VDataTableServer
    v-if="!isMobile"
    v-model="selection"
    v-model:options="localOptions"
    :headers="headers"
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

  <!-- ═══ MOBILE : liste tactile compacte ═══ -->
  <div v-else class="arv-mobile">
    <!-- Chargement -->
    <div v-if="loading && !items.length" class="pa-2">
      <VSkeletonLoader
        v-for="n in 5"
        :key="n"
        type="list-item-avatar-two-line"
        class="mb-1"
      />
    </div>

    <!-- Vide -->
    <div v-else-if="!items.length">
      <slot name="no-data">
        <div class="text-center text-medium-emphasis py-8">
          {{ $t('common.noData', 'Aucune donnée') }}
        </div>
      </slot>
    </div>

    <!-- Liste -->
    <VList
      v-else
      lines="two"
      class="py-0"
      role="list"
    >
      <template
        v-for="item in items"
        :key="keyOf(item)"
      >
        <VListItem
          class="arv-row"
          :active="isSelected(item)"
          role="listitem"
          :data-test="`arv-row-${keyOf(item)}`"
          @click="emit('row-click', item)"
        >
          <template v-if="showSelect" #prepend>
            <VCheckboxBtn
              :model-value="isSelected(item)"
              density="compact"
              @click.stop="toggleSelect(item)"
              @update:model-value="toggleSelect(item)"
            />
          </template>
          <!-- Contenu de carte fourni par le consommateur -->
          <slot name="mobile-item" :item="item" />
          <template #append>
            <slot name="mobile-append" :item="item">
              <VIcon icon="tabler-chevron-right" size="18" class="text-disabled" />
            </slot>
          </template>
        </VListItem>
        <VDivider />
      </template>
    </VList>

    <!-- Pagination mobile (mêmes options serveur) -->
    <div
      v-if="pageCount > 1"
      class="d-flex align-center justify-space-between pa-3 flex-wrap gap-2"
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
    min-block-size: 56px; // cible tactile confortable
  }
}
</style>
