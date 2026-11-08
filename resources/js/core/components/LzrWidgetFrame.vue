<script setup>
// Leezr Premium — cadre de widget commun (Phase 0, ADR-741).
// États loading / empty / error standardisés + drill-down + refresh.
// Couche Leezr par-dessus Vuetify ; ne touche pas @core/@layouts.
import { computed } from 'vue'

const props = defineProps({
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  icon: { type: String, default: '' },
  iconColor: { type: String, default: 'primary' },
  elevation: { type: String, default: 'raised' }, // flat | raised | active
  loading: { type: Boolean, default: false },
  error: { type: [Boolean, String], default: false },
  empty: { type: Boolean, default: false },
  emptyText: { type: String, default: 'Aucune donnée' },
  to: { type: [String, Object], default: null },
  refreshable: { type: Boolean, default: false },
  minHeight: { type: [Number, String], default: 0 },
})

const emit = defineEmits(['refresh'])

const cardClass = computed(() => `lzr-card lzr-card--${props.elevation}`)
const errorText = computed(() => (typeof props.error === 'string' ? props.error : 'Erreur de chargement'))
</script>

<template>
  <VCard
    :class="cardClass"
    :style="minHeight ? { minBlockSize: typeof minHeight === 'number' ? `${minHeight}px` : minHeight } : {}"
    flat
  >
    <div v-if="title || $slots.actions || icon" class="d-flex align-center px-4 pt-4 pb-2 lzr-widget__header">
      <VAvatar v-if="icon" :color="iconColor" variant="tonal" rounded size="34" class="me-3">
        <VIcon :icon="icon" size="20" />
      </VAvatar>
      <div class="flex-grow-1 overflow-hidden">
        <div class="text-body-1 font-weight-medium text-truncate">{{ title }}</div>
        <div v-if="subtitle" class="text-caption text-medium-emphasis text-truncate">{{ subtitle }}</div>
      </div>
      <div class="d-flex align-center ga-1">
        <slot name="actions" />
        <VBtn v-if="refreshable" icon variant="text" size="x-small" aria-label="Rafraîchir" @click="emit('refresh')">
          <VIcon icon="tabler-refresh" size="18" />
        </VBtn>
      </div>
    </div>

    <VCardText class="pt-0">
      <template v-if="loading">
        <VSkeletonLoader type="image" :height="80" class="bg-transparent" />
      </template>
      <template v-else-if="error">
        <VAlert type="error" variant="tonal" density="compact" :text="errorText" class="mb-0" />
      </template>
      <template v-else-if="empty">
        <div class="d-flex flex-column align-center justify-center text-center py-6 text-medium-emphasis">
          <VIcon icon="tabler-inbox" size="32" class="mb-2 text-disabled" />
          <span class="text-body-2">{{ emptyText }}</span>
        </div>
      </template>
      <template v-else>
        <slot />
      </template>
    </VCardText>

    <VCardText v-if="to && !loading && !error" class="pt-0 pb-3">
      <RouterLink :to="to" class="text-caption font-weight-medium text-decoration-none d-inline-flex align-center lzr-link">
        Voir le détail <VIcon icon="tabler-arrow-right" size="14" class="ms-1" />
      </RouterLink>
    </VCardText>
  </VCard>
</template>
