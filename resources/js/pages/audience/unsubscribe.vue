<script setup>
import { useGenerateImageVariant } from '@core/composable/useGenerateImageVariant'
import miscUnderMaintenance from '@images/pages/misc-under-maintenance.png'
import miscMaskDark from '@images/pages/misc-mask-dark.png'
import miscMaskLight from '@images/pages/misc-mask-light.png'

definePage({
  meta: {
    layout: 'blank',
    public: true,
  },
})

const authThemeMask = useGenerateImageVariant(miscMaskLight, miscMaskDark)
const route = useRoute()

const loading = ref(true)
const result = ref(null)

onMounted(async () => {
  const token = route.query.token

  if (!token) {
    result.value = { status: 'invalid' }
    loading.value = false

    return
  }

  try {
    const res = await fetch('/api/audience/unsubscribe', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({ token }),
    })

    result.value = await res.json()
  }
  catch {
    result.value = { status: 'invalid' }
  }
  finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="misc-wrapper">
    <div class="text-center mb-15">
      <template v-if="loading">
        <h4 class="text-h4 font-weight-medium mb-2">
          Processing your request...
        </h4>
        <VProgressCircular
          indeterminate
          class="mt-4"
        />
      </template>

      <template v-else-if="result?.status === 'unsubscribed'">
        <h4 class="text-h4 font-weight-medium mb-2">
          You've been unsubscribed
        </h4>
        <p class="text-body-1 mb-6">
          Your email <strong>{{ result.email }}</strong> has been removed from <strong>{{ result.list_name }}</strong>.
        </p>
        <VBtn to="/">
          Back to Home
        </VBtn>
      </template>

      <template v-else>
        <h4 class="text-h4 font-weight-medium mb-2">
          Invalid or expired link
        </h4>
        <p class="text-body-1 mb-6">
          This unsubscribe link is no longer valid.
        </p>
        <VBtn to="/">
          Back to Home
        </VBtn>
      </template>
    </div>

    <!-- Image -->
    <div class="misc-avatar w-100 text-center">
      <VImg
        :src="miscUnderMaintenance"
        alt="Unsubscribe"
        :max-width="550"
        :min-height="300"
        class="mx-auto"
      />
    </div>

    <img
      class="misc-footer-img d-none d-md-block"
      :src="authThemeMask"
      alt="misc-footer-img"
      height="320"
    >
  </div>
</template>

<style lang="scss" scoped>
@use "@core-scss/template/pages/misc.scss";
</style>
