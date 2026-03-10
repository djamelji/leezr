<script setup>
const { t } = useI18n()

defineProps({
  variant: { type: String, default: 'horizontal' },
  show: { type: Array, default: () => ['secure', 'gdpr', 'cancel'] },
})

const badges = computed(() => [
  { key: 'secure', icon: 'tabler-lock', label: t('trust.secure_payment') },
  { key: 'gdpr', icon: 'tabler-shield-check', label: t('trust.gdpr_compliant') },
  { key: 'cancel', icon: 'tabler-refresh', label: t('trust.cancel_anytime') },
  { key: 'trial', icon: 'tabler-gift', label: t('trust.free_trial') },
])
</script>

<template>
  <div :class="variant === 'horizontal' ? 'd-flex gap-4 flex-wrap' : 'd-flex flex-column gap-2'">
    <div
      v-for="badge in badges.filter(b => show.includes(b.key))"
      :key="badge.key"
      class="d-flex align-center gap-1"
    >
      <VIcon
        :icon="badge.icon"
        size="16"
        color="success"
      />
      <span class="text-caption text-medium-emphasis">{{ badge.label }}</span>
    </div>
  </div>
</template>
