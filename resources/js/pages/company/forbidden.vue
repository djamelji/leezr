<script setup>
definePage({ meta: { surface: 'operations' } })

import { useAuthStore } from '@/core/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const countdown = ref(5)
let timer = null

const redirectTarget = computed(() => {
  if (auth.hasPermission('shipments.view')) return '/company/shipments'

  return '/'
})

onMounted(() => {
  timer = setInterval(() => {
    countdown.value--
    if (countdown.value <= 0) {
      clearInterval(timer)
      router.replace(redirectTarget.value)
    }
  }, 1000)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <VRow
    class="fill-height"
    align="center"
    justify="center"
  >
    <VCol
      cols="12"
      md="6"
      lg="4"
    >
      <VCard class="text-center pa-6">
        <VIcon
          icon="tabler-lock"
          size="64"
          color="warning"
          class="mb-4"
        />

        <VCardTitle class="text-h5 mb-2">
          Access restricted
        </VCardTitle>

        <VCardText class="text-body-1 mb-4">
          This section is reserved for management roles.
        </VCardText>

        <VCardText class="text-body-2 text-disabled">
          <VProgressCircular
            :size="20"
            :width="2"
            indeterminate
            class="me-2"
          />
          Redirecting in {{ countdown }}s...
        </VCardText>

        <VCardActions class="justify-center">
          <VBtn
            color="primary"
            variant="tonal"
            :to="redirectTarget"
          >
            Go now
          </VBtn>
        </VCardActions>
      </VCard>
    </VCol>
  </VRow>
</template>
