<script setup>
// ADR-775 : the home page offers the everyday actions CONTRIBUTED by the active modules and
// ALLOWED to this user (server-filtered) — never a hard-coded shortcut that could end on a 403.
import { useHomeActions } from '@/composables/useSpaceActions'

const { t } = useI18n()
const { actions } = useHomeActions()
</script>

<template>
  <VCard
    v-if="actions.length"
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
