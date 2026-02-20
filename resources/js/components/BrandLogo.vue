<script setup>
import { useAppName } from '@/composables/useAppName'

defineProps({
  size: {
    type: String,
    default: 'md',
    validator: v => ['sm', 'md', 'lg'].includes(v),
  },
})

const appName = useAppName()

const sizes = {
  sm: '20px',
  md: '34px',
  lg: '42px',
}
</script>

<template>
  <span
    class="brand-logo d-inline-flex align-center"
    :style="{ fontSize: sizes[size], fontWeight: 700, lineHeight: 1 }"
  >
    <span class="brand-text" style="color: rgb(var(--v-theme-on-surface))">{{ appName.toLowerCase() }}</span>
    <span class="brand-dot" :style="{ color: 'rgb(var(--v-theme-primary))', fontWeight: 700 }">.</span>
  </span>
</template>
