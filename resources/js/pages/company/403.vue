<script setup>
definePage({})

import { useAuthStore } from '@/core/stores/auth'
import { useCompanyNav } from '@/composables/useCompanyNav'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const { firstAccessibleRoute } = useCompanyNav()

// ADR-357: Fallback uses workspace-appropriate landing
const fallbackRoute = computed(() =>
  firstAccessibleRoute.value !== '/'
    ? firstAccessibleRoute.value
    : auth.workspace === 'home' ? '/home' : '/dashboard',
)

const countdown = ref(5)
let timer = null

onMounted(() => {
  timer = setInterval(() => {
    countdown.value--
    if (countdown.value <= 0) {
      clearInterval(timer)
      router.replace(fallbackRoute.value)
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
          {{ t('forbidden.title') }}
        </VCardTitle>

        <VCardText class="text-body-1 mb-4">
          {{ t('forbidden.message') }}
        </VCardText>

        <VCardText class="text-body-2 text-disabled">
          <VProgressCircular
            :size="20"
            :width="2"
            indeterminate
            class="me-2"
          />
          {{ t('forbidden.redirecting', { seconds: countdown }) }}
        </VCardText>

        <VCardActions class="justify-center gap-2">
          <VBtn
            variant="tonal"
            @click="router.back()"
          >
            {{ t('forbidden.goBack') }}
          </VBtn>
          <VBtn
            color="primary"
            :to="fallbackRoute"
          >
            {{ t('forbidden.goToDashboard') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VCol>
  </VRow>
</template>
