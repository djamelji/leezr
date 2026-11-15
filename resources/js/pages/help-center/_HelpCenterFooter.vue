<script setup>
import { useReturnNavigation } from '@/composables/useReturnNavigation'

const props = defineProps({
  // ADR-787 : décision du serveur (module support actif + permission) pour l'utilisateur connecté.
  canContactSupport: { type: Boolean, default: false },
})

const { isAuthenticated } = useReturnNavigation()

// Visiteur : se connecter. Utilisateur : le support seulement s'il y a accès, sinon rien.
const showSupport = computed(() => !isAuthenticated.value || props.canContactSupport)

function supportRoute() {
  return isAuthenticated.value ? { name: 'company-support' } : { name: 'login' }
}
</script>

<template>
  <VContainer>
    <VCardText
      v-if="showSupport"
      class="text-center py-10"
    >
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
