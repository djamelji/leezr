<script setup>
// ADR-809 — revue GROUPÉE des accès en attente (création de l'entreprise ou première activation d'un module) :
// rôles concernés, capacités principales (libellés métier), droits sensibles à part, une seule décision.
// Moteur existant : PendingModuleAccess::confirm (politique de délégation, audit). L'empreinte lie la
// confirmation exactement à ce qui est affiché (aucun droit non prévisualisé).
import { useAppToast } from '@/composables/useAppToast'

const emit = defineEmits(['confirmed'])
const { t } = useI18n()
const { toast } = useAppToast()
const { capLabel, moduleName } = useCatalogText()
const { can } = useCan()
const data = ref(null)
const busy = ref(false)

const load = async () => {
  try { data.value = await $api('/company/pending-access') }
  catch { data.value = null }
}
onMounted(load)

const roles = computed(() => data.value?.roles || [])
const confirmable = computed(() => roles.value.filter(r => !r.blocked))
const capText = c => `${capLabel(c)} (${moduleName(c.module)})`

const confirmAll = async () => {
  busy.value = true
  try {
    const r = await $api('/company/pending-access/confirm', { method: 'POST', body: { fingerprint: data.value.fingerprint } })
    data.value = r
    toast(r.refused?.length ? t('roles.pendingPartly') : t('pendingAccess.confirmed'), r.refused?.length ? 'warning' : 'success')
    emit('confirmed')
  }
  catch (e) {
    if (e?.data?.code === 'stale_review') { toast(t('pendingAccess.stale'), 'warning'); await load() }
    else toast(e?.data?.message || t('common.error'), 'error')
  }
  finally { busy.value = false }
}

defineExpose({ load })
</script>

<template>
  <VAlert
    v-if="roles.length"
    type="warning"
    variant="tonal"
    class="mb-4"
    data-test="pending-access-review"
  >
    <div class="text-body-1 font-weight-medium mb-1">
      {{ t('pendingAccess.title', { n: roles.length }) }}
    </div>
    <div class="text-body-2 mb-3">
      {{ t('pendingAccess.intro') }}
    </div>
    <div
      v-for="r in roles"
      :key="r.id"
      class="mb-3"
      :data-test="`pending-access-role-${r.id}`"
    >
      <div class="font-weight-medium">
        {{ r.name }}
        <span
          v-if="r.blocked"
          class="text-caption ms-2"
          :data-test="`pending-access-blocked-${r.id}`"
        >— {{ t('pendingAccess.blocked') }}</span>
      </div>
      <div class="d-flex flex-wrap gap-1 mt-1">
        <VChip
          v-for="c in r.capabilities.filter(x => !x.sensitive)"
          :key="c.key"
          size="small"
          variant="tonal"
        >
          {{ capText(c) }}
        </VChip>
      </div>
      <div
        v-if="r.capabilities.some(x => x.sensitive)"
        class="d-flex flex-wrap align-center gap-1 mt-1"
        :data-test="`pending-access-sensitive-${r.id}`"
      >
        <span class="text-caption me-1">{{ t('pendingAccess.sensitive') }}</span>
        <VChip
          v-for="c in r.capabilities.filter(x => x.sensitive)"
          :key="c.key"
          size="small"
          color="error"
          variant="tonal"
        >
          {{ capText(c) }}
        </VChip>
      </div>
      <div
        v-if="r.other_rights"
        class="text-caption mt-1"
      >
        {{ t('pendingAccess.otherRights', { n: r.other_rights }) }}
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-2">
      <VBtn
        v-if="can('roles.manage') && confirmable.length"
        color="warning"
        :loading="busy"
        data-test="pending-access-confirm"
        @click="confirmAll"
      >
        {{ t('pendingAccess.confirm', { n: confirmable.length }) }}
      </VBtn>
      <span class="text-caption align-self-center">{{ t('pendingAccess.adjustHint') }}</span>
    </div>
  </VAlert>
</template>
