<script setup>
// ADR-834 — step B : ONE activity per company (single radio choice, same Vuexy CustomRadiosWithIcon as sign-up).
const { t } = useI18n()

const props = defineProps({
  jobdomains: { type: Array, required: true },
  modules: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  hasSelectedModules: { type: Boolean, default: false },
})

const selectedJobdomain = defineModel('selectedJobdomain', { type: String })

const radioContent = computed(() => props.jobdomains.map(jd => ({
  title: jd.label,
  desc: jd.description || '',
  value: jd.key,
  offeredForSignup: jd.offered_for_signup,
})))

// what the activity brings at no extra cost (core platform modules are not listed : they are always there)
const includedWithActivity = computed(() => props.modules.filter(m => m.class === 'base' && m.recommended && !m.key.startsWith('core.')))
</script>

<template>
  <h4 class="text-h4 mb-1">
    {{ t('platformCompanyCreation.activity.heading') }}
  </h4>
  <p class="text-body-1 mb-6">
    {{ t('platformCompanyCreation.activity.description') }}
  </p>

  <p
    v-if="jobdomains.length === 0"
    class="text-body-1 text-disabled"
  >
    {{ t('platformCompanyCreation.activity.noActivities') }}
  </p>

  <CustomRadiosWithIcon
    v-else
    v-model:selected-radio="selectedJobdomain"
    :radio-content="radioContent.map(r => ({ ...r, icon: { icon: 'tabler-briefcase', size: '28' } }))"
    :grid-column="{ sm: '6', md: '4', cols: '12' }"
  >
    <template #default="{ item }">
      <div class="text-center">
        <VIcon
          icon="tabler-briefcase"
          size="36"
          class="mb-2 text-primary"
        />
        <h5 class="text-h5 mb-2">
          {{ item.title }}
        </h5>
        <p class="clamp-text mb-2 text-body-2">
          {{ item.desc }}
        </p>
        <VChip
          v-if="!item.offeredForSignup"
          size="small"
          color="secondary"
          variant="tonal"
        >
          {{ t('platformCompanyCreation.activity.notOfferedForSignup') }}
        </VChip>
      </div>
    </template>
  </CustomRadiosWithIcon>

  <VAlert
    v-if="hasSelectedModules"
    type="info"
    variant="tonal"
    density="compact"
    class="mt-4"
  >
    {{ t('platformCompanyCreation.activity.changeWarning') }}
  </VAlert>

  <VCard
    v-if="selectedJobdomain"
    variant="tonal"
    class="mt-6"
  >
    <VCardText>
      <h6 class="text-h6 mb-3">
        {{ t('platformCompanyCreation.activity.includedWithActivity') }}
      </h6>
      <VSkeletonLoader
        v-if="loading"
        type="chip@3"
      />
      <div
        v-else
        class="d-flex flex-wrap gap-2"
      >
        <VChip
          v-for="m in includedWithActivity"
          :key="m.key"
          color="primary"
          variant="tonal"
          :prepend-icon="m.icon || 'tabler-puzzle'"
        >
          {{ m.name }}
        </VChip>
      </div>
    </VCardText>
  </VCard>
</template>
