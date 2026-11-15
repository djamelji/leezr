<script setup>
// ADR-796 (L6) — intégrations en cours, dans Équipe : qui est invité, a commencé, ce qui manque, qui a
// soumis, ce qui attend une validation, les liens expirés. Aucun nouveau menu, aucune seconde liste.
import { $api } from '@/utils/api'
import OnboardingInviteDialog from './_OnboardingInviteDialog.vue'
import OnboardingReviewDialog from './_OnboardingReviewDialog.vue'

const { t } = useI18n()
const items = ref([])
const inviteOpen = ref(false)
const reviewId = ref(null)

const load = async () => {
  try { items.value = (await $api('/workforce/onboarding-files')).invitations }
  catch { items.value = [] }
}
onMounted(load)

const open = computed(() => items.value.filter(i => !['validated', 'revoked'].includes(i.status)))
const tone = s => ({ submitted: 'warning', changes_requested: 'info', expired: 'error', validated: 'success' }[s] || 'info')
</script>

<template>
  <AppSettingsGroup
    :title="t('onboardingFile.panelTitle')"
    :description="t('onboardingFile.panelHint')"
    class="mb-6"
    data-test="onboarding-panel"
  >
    <div
      v-for="i in open"
      :key="i.id"
      class="onb-row"
    >
      <div class="onb-row__main">
        <div class="text-body-1 font-weight-medium">
          {{ i.name }}
        </div>
        <div class="text-body-2 text-medium-emphasis">
          {{ t('onboardingFile.rowProgress', { filled: i.fields_filled, total: i.fields_total, docs: i.documents_total }) }}
        </div>
      </div>
      <AppStatusText
        :tone="tone(i.status)"
        :text="t(`onboardingFile.status.${i.status}`)"
      />
      <VBtn
        variant="text"
        size="small"
        :data-test="`onb-open-${i.id}`"
        @click="reviewId = i.id"
      >
        {{ i.status === 'submitted' ? t('onboardingFile.review') : t('onboardingFile.details') }}
      </VBtn>
    </div>
    <p
      v-if="!open.length"
      class="onb-empty"
    >
      {{ t('onboardingFile.empty') }}
    </p>
    <div class="onb-add">
      <VBtn
        variant="tonal"
        prepend-icon="tabler-mail-plus"
        data-test="onboarding-invite"
        @click="inviteOpen = true"
      >
        {{ t('onboardingFile.inviteAction') }}
      </VBtn>
    </div>
  </AppSettingsGroup>

  <OnboardingInviteDialog
    v-model:is-dialog-visible="inviteOpen"
    @created="load"
  />
  <OnboardingReviewDialog
    :is-dialog-visible="reviewId !== null"
    :invitation-id="reviewId"
    @update:is-dialog-visible="v => { if (!v) reviewId = null }"
    @changed="load"
  />
</template>

<style scoped>
.onb-row { display: flex; align-items: center; gap: 12px; min-block-size: 56px; padding: 8px 12px 8px 20px; flex-wrap: wrap; }
.onb-row + .onb-row { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.onb-row__main { flex: 1; min-inline-size: 200px; }
.onb-empty { margin: 0; padding: 16px 20px; font-size: 0.875rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.onb-add { display: flex; justify-content: flex-end; padding: 12px 20px; border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
</style>
