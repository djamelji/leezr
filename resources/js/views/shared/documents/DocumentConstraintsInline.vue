<script setup>
defineProps({
  maxFileSizeMb: {
    type: Number,
    default: 10,
  },
  acceptedTypes: {
    type: Array,
    default: () => ['pdf', 'jpg', 'png'],
  },
})
</script>

<template>
  <div class="text-body-2 text-disabled">
    Max {{ maxFileSizeMb }} MB · {{ acceptedTypes.join(', ').toUpperCase() }}
  </div>
</template>
