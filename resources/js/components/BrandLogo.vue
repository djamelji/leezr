<script setup>
const props = defineProps({
  size: {
    type: String,
    default: 'md',
    validator: v => ['sm', 'md', 'lg'].includes(v),
  },
})

const sizes = {
  sm: { fontSize: '20px', dot: '6px' },
  md: { fontSize: '28px', dot: '8px' },
  lg: { fontSize: '42px', dot: '10px' },
}

const s = computed(() => sizes[props.size])
</script>

<template>
  <span
    class="d-inline-flex align-center"
    :style="{ fontSize: s.fontSize, fontWeight: 700, lineHeight: 1 }"
  >
    <span style="color: rgb(var(--v-theme-on-surface))">leezr</span>
    <span
      :style="{
        color: 'rgb(var(--v-theme-primary))',
        fontWeight: 700,
      }"
    >.</span>
  </span>
</template>
