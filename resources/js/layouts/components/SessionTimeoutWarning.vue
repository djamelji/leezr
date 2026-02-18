<script setup>
const props = defineProps({
  isDialogVisible: { type: Boolean, required: true },
  remainingSeconds: { type: Number, required: true },
})

const emit = defineEmits(['extend'])
</script>

<template>
  <VDialog
    max-width="500"
    :model-value="props.isDialogVisible"
    persistent
  >
    <VCard class="text-center px-10 py-6">
      <VCardText>
        <VBtn
          icon
          variant="outlined"
          color="warning"
          class="my-4"
          style="block-size: 88px; inline-size: 88px; pointer-events: none;"
        >
          <span class="text-5xl">!</span>
        </VBtn>

        <h6 class="text-lg font-weight-medium">
          Session Expiring
        </h6>

        <p class="text-body-1 mt-2">
          Your session will expire in
          <strong>{{ props.remainingSeconds }}</strong> seconds
          due to inactivity.
        </p>
      </VCardText>

      <VCardText class="d-flex align-center justify-center gap-2">
        <VBtn
          variant="elevated"
          @click="emit('extend')"
        >
          Stay Connected
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>
</template>
