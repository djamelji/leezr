<script setup>
const { t } = useI18n()

defineProps({
  jobdomains: { type: Array, required: true },
  loading: { type: Boolean, default: false },
})

const selectedJobdomain = defineModel('selectedJobdomain', { type: String })
</script>

<template>
  <h4 class="text-h4 mb-1">
    {{ t('register.whatsYourIndustry') }}
  </h4>
  <p class="text-body-1 mb-6">
    {{ t('register.industryDescription') }}
  </p>

  <VSkeletonLoader
    v-if="loading"
    type="card"
  />

  <template v-else>
    <CustomRadiosWithIcon
      v-model:selected-radio="selectedJobdomain"
      :radio-content="jobdomains.map(jd => ({
        title: jd.label,
        desc: jd.description || '',
        value: jd.key,
        icon: { icon: 'tabler-briefcase', size: '28' },
      }))"
      :grid-column="{ sm: '4', cols: '12' }"
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
          <p class="clamp-text mb-0 text-body-2">
            {{ item.desc }}
          </p>
        </div>
      </template>
    </CustomRadiosWithIcon>

    <p
      v-if="jobdomains.length === 0"
      class="text-body-1 text-disabled"
    >
      {{ t('register.noIndustriesAvailable') }}
    </p>
  </template>
</template>
