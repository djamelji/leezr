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
    class="mb-6"
    data-test="home-actions"
  >
    <VCardText>
      <div class="d-flex align-center gap-2 mb-3">
        <VIcon
          icon="tabler-rocket"
          size="20"
          color="primary"
        />
        <span class="text-body-1 font-weight-medium">{{ t('dashboard.quickActions.title') }}</span>
      </div>
      <div class="d-flex flex-wrap gap-3">
        <VBtn
          v-for="action in actions"
          :key="action.key"
          :to="action.to"
          color="primary"
          variant="tonal"
          :data-test="`home-action-${action.key}`"
          size="small"
        >
          <VIcon
            start
            :icon="action.icon"
            size="18"
          />
          {{ t(action.label) }}
        </VBtn>
      </div>
    </VCardText>
  </VCard>
</template>
