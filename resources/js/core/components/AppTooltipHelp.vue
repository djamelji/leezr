<script setup>
defineProps({
  text: { type: String, required: true },
  location: { type: String, default: 'top' },
  size: { type: [Number, String], default: 18 },
  icon: { type: String, default: 'tabler-help-circle' },
})
</script>

<template>
  <VIcon
    :icon="icon"
    :size="size"
    class="text-medium-emphasis"
    style="vertical-align: middle;"
  >
    <VTooltip
      activator="parent"
      :location="location"
    >
      {{ text }}
    </VTooltip>
  </VIcon>
</template>
