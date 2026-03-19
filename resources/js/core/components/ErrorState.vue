<script setup>
const props = defineProps({
  icon: { type: String, default: 'tabler-alert-triangle' },
  title: { type: String, default: '' },
  message: { type: String, default: '' },
  retryLabel: { type: String, default: '' },
  color: { type: String, default: 'error' },
  showRetry: { type: Boolean, default: true },
})

const emit = defineEmits(['retry'])

const { t } = useI18n()

const displayTitle = computed(() => props.title || t('common.error'))
const displayMessage = computed(() => props.message || t('common.loadError'))
const displayRetryLabel = computed(() => props.retryLabel || t('common.retry'))
</script>

<template>
  <div class="text-center pa-8">
    <VAvatar
      size="80"
      variant="tonal"
      :color="color"
      class="mb-4"
    >
      <VIcon
        :icon="icon"
        size="40"
      />
    </VAvatar>

    <h5 class="text-h5 mb-2">
      {{ displayTitle }}
    </h5>

    <p class="text-body-1 text-medium-emphasis mb-4">
      {{ displayMessage }}
    </p>

    <VBtn
      v-if="showRetry"
      :color="color"
      variant="elevated"
      prepend-icon="tabler-refresh"
      @click="emit('retry')"
    >
      {{ displayRetryLabel }}
    </VBtn>

    <slot />
  </div>
</template>
