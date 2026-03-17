<script setup>
import { useReturnNavigation } from '@/composables/useReturnNavigation'

const { isAuthenticated } = useReturnNavigation()

function supportRoute() {
  return isAuthenticated.value ? { name: 'company-support' } : { name: 'login' }
}
</script>

<template>
  <VContainer>
    <VCardText class="text-center py-10">
      <h4 class="text-h4 mb-2">
        {{ $t('documentation.stillNeedHelp') }}
      </h4>
      <p class="text-body-1 mb-6">
        {{ $t('documentation.stillNeedHelpText') }}
      </p>
      <VBtn
        color="primary"
        :to="supportRoute()"
      >
        <VIcon
          icon="tabler-message-circle"
          class="me-2"
        />
        {{ $t('documentation.contactSupport') }}
      </VBtn>
    </VCardText>
  </VContainer>
</template>
