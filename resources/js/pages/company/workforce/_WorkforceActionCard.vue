<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, required: true },
  icon: { type: String, required: true },
  color: { type: String, default: 'primary' },
  actionLabel: { type: String, required: true },
  to: { type: Object, default: undefined },
  disabled: { type: Boolean, default: false },
})

defineEmits(['action'])
</script>

<template>
  <VCard variant="outlined">
    <VCardText class="d-flex align-center gap-4">
      <VAvatar
        :color="color"
        variant="tonal"
        size="40"
      >
        <VIcon
          :icon="icon"
          size="22"
        />
      </VAvatar>
      <div class="flex-grow-1">
        <div class="text-body-1 font-weight-medium">
          {{ $t(title) }}
        </div>
        <div class="text-body-2 text-medium-emphasis">
          {{ $t(description) }}
        </div>
      </div>
      <VBtn
        :color="color"
        :to="to"
        :disabled="disabled"
        variant="tonal"
        size="small"
        @click="!to && $emit('action')"
      >
        {{ $t(actionLabel) }}
      </VBtn>
    </VCardText>
  </VCard>
</template>
