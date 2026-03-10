<script setup>
defineProps({
  icon: { type: String, required: true },
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
})
</script>

<template>
  <div class="d-flex align-center gap-3 mb-4">
    <VAvatar
      size="40"
      variant="tonal"
      color="primary"
      rounded
    >
      <VIcon
        :icon
        size="20"
      />
    </VAvatar>
    <div>
      <h6 class="text-h6">
        {{ title }}
      </h6>
      <span
        v-if="subtitle"
        class="text-body-2 text-medium-emphasis"
      >{{ subtitle }}</span>
    </div>
    <VSpacer />
    <slot name="actions" />
  </div>
</template>
