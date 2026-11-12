<script setup>
// M7 LOT 2 — page d'acceptation d'une invitation (lien de l'e-mail). Publique côté routeur car la
// personne invitée n'a PAS encore d'accès à l'entreprise (éventuellement aucune entreprise active) :
// l'identité est vérifiée par le serveur (session), jamais par l'URL.
import PendingInvitationsCard from '@/components/PendingInvitationsCard.vue'

definePage({ meta: { layout: 'blank', public: true } })

usePublicTheme()

const { t } = useI18n()

// Après acceptation : rechargement complet pour démarrer l'application dans le nouveau contexte.
const goToApp = () => { window.location.href = '/' }
</script>

<template>
  <div class="d-flex justify-center pa-4 pa-sm-8">
    <div style="inline-size: 100%; max-inline-size: 720px">
      <h4 class="text-h4 mb-1">
        {{ t('invitations.pageTitle') }}
      </h4>
      <p class="text-body-1 text-medium-emphasis mb-6">
        {{ t('invitations.pageSubtitle') }}
      </p>

      <PendingInvitationsCard
        standalone
        @accepted="goToApp"
      />

      <VBtn
        variant="text"
        prepend-icon="tabler-arrow-left"
        class="mt-2"
        href="/"
      >
        {{ t('invitations.backToApp') }}
      </VBtn>
    </div>
  </div>
</template>
