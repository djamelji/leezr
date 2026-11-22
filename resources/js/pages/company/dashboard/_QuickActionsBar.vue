<script setup>
// ADR-775 : the home page offers the everyday actions CONTRIBUTED by the active modules and
// ALLOWED to this user (server-filtered) — never a hard-coded shortcut that could end on a 403.
import { useHomeActions } from '@/composables/useSpaceActions'

// ADR-857 (L3) — V22 action tiles (home workspace) ; `exclude` = keys the hero already offers (never twice)
const props = defineProps({ tiles: { type: Boolean, default: false }, exclude: { type: Array, default: () => [] } })
const { t } = useI18n()
const { actions: all } = useHomeActions(6)
const actions = computed(() => all.value.filter(a => !props.exclude.includes(a.key)))
const TONES = ['primary', 'success', 'info', 'warning', 'secondary', 'error']
</script>

<template>
  <nav
    v-if="tiles && actions.length"
    class="lz-actions lzr-stagger"
    :aria-label="t('home.sections.actions')"
    data-test="home-actions"
  >
    <RouterLink
      v-for="(action, i) in actions"
      :key="action.key"
      :to="action.to"
      class="lz-action lzr-enter"
      :data-test="`home-action-${action.key}`"
    >
      <VAvatar
        :color="TONES[i % TONES.length]"
        variant="tonal"
        rounded="lg"
        size="38"
      >
        <VIcon :icon="action.icon" />
      </VAvatar>
      <span class="lz-action__label">{{ t(action.label) }}</span>
      <VIcon
        icon="tabler-chevron-right"
        size="18"
        class="lz-action__chev"
      />
    </RouterLink>
  </nav>
  <VCard
    v-else-if="actions.length"
    class="h-100"
    data-test="home-actions"
  >
    <VCardItem class="pb-2">
      <VCardTitle class="text-h6">
        {{ t('home.sections.actions') }}
      </VCardTitle>
    </VCardItem>
    <VCardText class="d-flex flex-column gap-2 pt-0">
      <VBtn
        v-for="action in actions"
        :key="action.key"
        :to="action.to"
        color="primary"
        variant="tonal"
        block
        class="justify-start"
        :prepend-icon="action.icon"
        :data-test="`home-action-${action.key}`"
      >
        {{ t(action.label) }}
      </VBtn>
    </VCardText>
  </VCard>
</template>

<style scoped>
/* ADR-857 — V22 action tiles : one row on desktop, wraps on tablet, 1 column on phones ; hover lifts (transform only) */
.lz-actions { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.lz-action {
  display: flex; align-items: center; gap: .75rem; padding: .75rem .875rem; min-inline-size: 0;
  border-radius: 14px; background: rgb(var(--v-theme-surface)); color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity));
  border: 1px solid rgba(var(--v-border-color), .08); box-shadow: 0 2px 6px rgba(47, 43, 61, .05);
  text-decoration: none; transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.lz-action:hover, .lz-action:focus-visible { transform: translateY(-2px); border-color: rgba(var(--v-theme-primary), .35); box-shadow: 0 8px 20px -10px rgba(var(--v-theme-primary), .45); }
.lz-action__label { flex: 1 1 auto; min-inline-size: 0; font-weight: 500; font-size: .9375rem; line-height: 1.25; }
.lz-action__chev { color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); transition: transform .18s ease; }
.lz-action:hover .lz-action__chev { transform: translateX(3px); }
@media (max-width: 599px) { .lz-actions { grid-template-columns: 1fr; } }
@media (prefers-reduced-motion: reduce) { .lz-action, .lz-action__chev { transition: none; } .lz-action:hover { transform: none; } }
</style>
