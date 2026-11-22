<script setup>
// ADR-802 — ce que seule l'ancienne liste `/company/members` montrait : le quota de membres (ADR-172) et les
// profils de membres incomplets (ADR-168b). Même source : `GET /company/members` (members.store), même
// garde (`members.view`, posée par le parent). Chaque profil incomplet ouvre la fiche Équipe (le détail
// `/company/members/{id}` redirige déjà vers la fiche personne, ADR-768). Sous-composant.
import { useMembersStore } from '@/modules/company/members/members.store'

const { t } = useI18n()
const store = useMembersStore()
const loaded = ref(false)
const expanded = ref(false)

onMounted(async () => {
  try { await store.fetchMembers(); loaded.value = true }
  catch { loaded.value = false }
})

// ADR-816 : la complétude n'est servie qu'à l'administration des accès ; sans elle, aucune alerte inventée.
// ADR-858 : les profils incomplets sont désormais une tuile et un filtre de l'annuaire (« Dossiers incomplets ») ; reste le quota.
const incomplete = computed(() => [])
const limitReached = computed(() => store.memberLimit !== null && store.memberCount >= store.memberLimit)
</script>

<template>
  <div
    v-if="loaded && (limitReached || incomplete.length)"
    class="d-flex flex-column gap-2 mb-4"
    data-test="member-health"
  >
    <VAlert
      v-if="limitReached"
      type="warning"
      variant="tonal"
      density="compact"
    >
      {{ t('members.memberLimitReached') }}
      ({{ t('members.memberQuota', { current: store.memberCount, limit: store.memberLimit }) }})
    </VAlert>
    <VAlert
      v-if="incomplete.length"
      type="info"
      variant="tonal"
      density="compact"
    >
      <div class="d-flex align-center flex-wrap gap-2">
        <span>{{ t('members.incompleteProfiles', { count: incomplete.length }) }}</span>
        <VBtn
          size="small"
          variant="text"
          data-test="member-health-toggle"
          @click="expanded = !expanded"
        >
          {{ expanded ? t('peopleList.hideIncomplete') : t('peopleList.showIncomplete') }}
        </VBtn>
      </div>
      <div
        v-if="expanded"
        class="d-flex flex-wrap gap-2 mt-2"
      >
        <VChip
          v-for="m in incomplete"
          :key="m.id"
          size="small"
          :to="`/company/members/${m.id}`"
        >
          {{ m.user?.display_name }}
        </VChip>
      </div>
    </VAlert>
  </div>
</template>
