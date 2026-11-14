<script setup>
// ADR-781 (UX5) — revenir là d'où l'on vient : au hub « Réglages » (`?from=settings`) ou à l'opération
// métier qui a ouvert ce réglage (`?back=/chemin`, chemin interne uniquement — safeRedirect).
// Rien n'est affiché quand on arrive autrement (menu, lien direct).
import { safeRedirect } from '@/utils/safeRedirect'

const route = useRoute()
const { t } = useI18n()

const target = computed(() => {
  const back = typeof route.query.back === 'string' ? safeRedirect(route.query.back, '') : ''
  if (back) return { to: back, label: t('settingsHub.back') }
  if (route.query.from === 'settings') return { to: { name: 'company-settings' }, label: t('settingsHub.backToSettings') }

  return null
})
</script>

<template>
  <VBtn
    v-if="target"
    :to="target.to"
    variant="text"
    size="small"
    class="px-1 mb-2"
    prepend-icon="tabler-arrow-left"
    data-test="settings-return"
  >
    {{ target.label }}
  </VBtn>
</template>
