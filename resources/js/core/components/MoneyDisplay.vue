<script setup>
import { formatMoney } from '@/utils/money'

const props = defineProps({
  amount: { type: Number, required: true },
  currency: { type: String, default: 'EUR' },
  size: { type: String, default: 'md' },
  color: { type: String, default: '' },
  sign: { type: Boolean, default: false },
})

const sizeClass = computed(() => {
  const map = { sm: 'text-body-2', md: 'text-body-1', lg: 'text-h5' }

  return map[props.size] || 'text-body-1'
})

const formatted = computed(() => {
  const prefix = props.sign && props.amount > 0 ? '+' : ''

  return prefix + formatMoney(props.amount, { currency: props.currency })
})
</script>

<template>
  <span
    :class="[sizeClass, color ? `text-${color}` : '']"
  >{{ formatted }}</span>
</template>
