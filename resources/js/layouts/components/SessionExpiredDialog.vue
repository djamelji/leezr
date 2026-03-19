<script setup>
const props = defineProps({
  isDialogVisible: { type: Boolean, required: true },
  loginUrl: { type: String, default: '/login' },
})

function handleLogin() {
  window.location.href = props.loginUrl
}
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
          color="error"
          class="my-4"
          style="block-size: 88px; inline-size: 88px; pointer-events: none;"
        >
          <VIcon
            icon="tabler-clock-off"
            size="40"
          />
        </VBtn>

        <h6 class="text-lg font-weight-medium">
          {{ $t('session.expiredTitle') }}
        </h6>

        <p class="text-body-1 mt-2">
          {{ $t('session.expiredMessage') }}
        </p>
      </VCardText>

      <VCardText class="d-flex align-center justify-center gap-2">
        <VBtn
          variant="elevated"
          color="primary"
          @click="handleLogin"
        >
          {{ $t('session.reconnect') }}
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>
</template>
