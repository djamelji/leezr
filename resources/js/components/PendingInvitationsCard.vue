<script setup>
// M7 LOT 2 — invitations en attente de l'utilisateur CONNECTÉ (compte pré-existant invité par une
// entreprise). Rien n'est activé tant que la personne n'a pas accepté elle-même. Endpoints hors
// contexte company (`/my-invitations`). Ne rend rien s'il n'y a aucune invitation (mode bandeau).
import { $api } from '@/utils/api'
import { useAuthStore } from '@/core/stores/auth'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  // Bandeau discret (layout) vs page dédiée (affiche aussi l'état vide / non connecté).
  standalone: { type: Boolean, default: false },
})

const emit = defineEmits(['accepted', 'declined'])

const { t } = useI18n()
const auth = useAuthStore()
const { toast } = useAppToast()

const invitations = ref([])
const loading = ref(true)
const unauthenticated = ref(false)
const busyId = ref(null)

async function load() {
  loading.value = true
  try {
    const data = await $api('/my-invitations', { _authCheck: true })

    invitations.value = data?.invitations || []
    unauthenticated.value = !data
  }
  catch (e) {
    invitations.value = []
    unauthenticated.value = e?.status === 401 || e?.response?.status === 401
  }
  finally { loading.value = false }
}

async function answer(inv, action) {
  if (busyId.value) return
  busyId.value = inv.id
  try {
    await $api(`/my-invitations/${inv.id}/${action}`, { method: 'POST' })
    invitations.value = invitations.value.filter(i => i.id !== inv.id)
    if (action === 'accept') {
      await auth.fetchMyCompanies().catch(() => {})
      toast(t('invitations.accepted', { company: inv.company?.name }), 'success')
      emit('accepted', inv)
    }
    else {
      toast(t('invitations.declined', { company: inv.company?.name }), 'info')
      emit('declined', inv)
    }
  }
  catch (e) {
    // M7 LOT 3 : invitation expirée entre l'affichage et le clic → message clair, liste rafraîchie.
    if ((e?.status ?? e?.response?.status) === 410 || e?.data?.code === 'invitation_expired') {
      toast(t('invitations.expired', { company: inv.company?.name }), 'warning')
      await load()
    }
    else { toast(e?.data?.message || t('invitations.error'), 'error') }
  }
  finally { busyId.value = null }
}

onMounted(load)
</script>

<template>
  <div
    v-if="standalone || invitations.length"
    data-test="pending-invitations"
  >
    <VProgressLinear
      v-if="standalone && loading"
      indeterminate
      color="primary"
    />

    <VAlert
      v-else-if="standalone && unauthenticated"
      type="info"
      variant="tonal"
    >
      {{ t('invitations.signInFirst') }}
      <template #append>
        <VBtn
          variant="text"
          to="/login"
        >
          {{ t('invitations.signIn') }}
        </VBtn>
      </template>
    </VAlert>

    <VAlert
      v-else-if="standalone && !invitations.length"
      type="success"
      variant="tonal"
    >
      {{ t('invitations.none') }}
    </VAlert>

    <VCard
      v-for="inv in invitations"
      :key="inv.id"
      variant="outlined"
      class="mb-4 border-s-lg border-primary"
      :data-test="`invitation-${inv.id}`"
    >
      <VCardText class="d-flex align-center flex-wrap gap-4">
        <VAvatar
          color="primary"
          variant="tonal"
          size="42"
        >
          <VIcon icon="tabler-building" />
        </VAvatar>
        <div
          class="flex-grow-1"
          style="min-inline-size: 0"
        >
          <div class="text-body-1 font-weight-medium text-high-emphasis">
            {{ t('invitations.title', { company: inv.company?.name }) }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            <span v-if="inv.invited_by">{{ t('invitations.invitedBy', { name: inv.invited_by }) }}</span>
            <span v-if="inv.invited_by && inv.role"> · </span>
            <span v-if="inv.role">{{ t('invitations.role', { role: inv.role }) }}</span>
          </div>
          <div class="text-caption text-medium-emphasis">
            {{ t('invitations.noAccessUntilAccepted') }}
            <span v-if="inv.expires_at">{{ t('invitations.validUntil', { date: new Date(inv.expires_at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }) }) }}</span>
          </div>
        </div>
        <div class="d-flex gap-2">
          <VBtn
            color="primary"
            prepend-icon="tabler-check"
            :loading="busyId === inv.id"
            data-test="invitation-accept"
            @click="answer(inv, 'accept')"
          >
            {{ t('invitations.accept') }}
          </VBtn>
          <VBtn
            color="secondary"
            variant="tonal"
            :disabled="busyId === inv.id"
            data-test="invitation-decline"
            @click="answer(inv, 'decline')"
          >
            {{ t('invitations.decline') }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>
  </div>
</template>
