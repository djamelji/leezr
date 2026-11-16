<script setup>
// ADR-803.1 — Équipe → Rôles « par capacité » : le MÊME composant (ModuleCapabilityAccess), le MÊME aperçu
// (CapabilityChangePreview) et le MÊME moteur serveur (/company/role-capabilities → CapabilityAccessService +
// MembershipSecurityPolicy) que l'assistant de module. La matrice par permission reste la vue avancée.
// Sous-composant — pas de definePage.
import ModuleCapabilityAccess from '@/components/modules/ModuleCapabilityAccess.vue'
import CapabilityChangePreview from '@/components/modules/CapabilityChangePreview.vue'
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const { toast } = useAppToast()
const modules = ref([])
const moduleKey = ref(null)
const data = ref(null)
const changes = ref([])
const preview = ref(null)
const busy = ref(false)
const accessRef = ref(null)

const { moduleName } = useCatalogText() // ADR-806 : nom traduit (pas le nom anglais du manifeste)
const err = e => e?.data?.message || Object.values(e?.data?.errors || {})[0]?.[0] || t('common.error')
const load = async () => {
  if (!moduleKey.value) return
  data.value = await $api(`/company/role-capabilities/${moduleKey.value}`)
  preview.value = null
  changes.value = []
}
const route = useRoute()
onMounted(async () => {
  modules.value = (await $api('/company/role-capabilities')).modules || []
  // `?module=` ouvre directement un module (lien depuis l'assistant ou un lien profond) ; sinon le premier.
  moduleKey.value = modules.value.find(m => m.key === route.query.module)?.key || modules.value[0]?.key || null
})
watch(moduleKey, load)

const pendingRoles = computed(() => (data.value?.roles || []).filter(r => r.editable && (r.pending || []).length))
const confirmPending = async r => {
  busy.value = true
  try {
    const out = await $api(`/company/roles/${r.id}/confirm-pending`, { method: 'POST', body: { module: moduleKey.value } })
    toast(out.refused?.length ? t('roles.pendingPartly') : t('roles.pendingConfirmed'), out.refused?.length ? 'warning' : 'success')
    await load()
  }
  catch (e) { toast(err(e), 'error') }
  finally { busy.value = false }
}

const askPreview = async () => {
  busy.value = true
  try { preview.value = await $api(`/company/role-capabilities/${moduleKey.value}/preview`, { method: 'POST', body: { changes: changes.value } }) }
  catch (e) { toast(err(e), 'error') }
  finally { busy.value = false }
}
const apply = async confirmAffected => {
  busy.value = true
  try {
    data.value = await $api(`/company/role-capabilities/${moduleKey.value}`, { method: 'PUT', body: { changes: changes.value, fingerprint: preview.value.fingerprint, confirm_affected: confirmAffected } })
    preview.value = null
    changes.value = []
    accessRef.value?.reset()
    toast(t('moduleSetup.accessSaved'), 'success')
  }
  catch (e) {
    toast(e?.data?.code === 'stale_preview' ? t('moduleSetup.stale') : err(e), 'error')
    if (e?.data?.code === 'stale_preview') await load()
  }
  finally { busy.value = false }
}
</script>

<template>
  <div data-test="role-capabilities">
    <AppSelect
      v-model="moduleKey"
      :items="modules.map(m => ({ value: m.key, title: moduleName(m.key, m.name) }))"
      :label="t('moduleSetup.module')"
      class="mb-4"
      style="max-inline-size: 360px;"
      data-test="role-capabilities-module"
    />
    <template v-if="data">
      <VAlert
        v-if="!data.can_change"
        type="info"
        variant="tonal"
        density="compact"
        class="mb-4"
      >
        {{ t('moduleSetup.accessNeedsRoles') }}
      </VAlert>
      <ModuleCapabilityAccess
        ref="accessRef"
        :key="moduleKey"
        :capabilities="data.capabilities"
        :roles="data.roles"
        :readonly="!data.can_change"
        @update:changes="v => { changes = v; preview = null }"
      />
      <!-- ADR-808.1 : confirmation par rôle des droits en attente (seul point pour un module sans assistant) -->
      <div
        v-if="data.can_change && pendingRoles.length"
        class="d-flex flex-wrap gap-2 mb-4"
      >
        <VBtn
          v-for="r in pendingRoles"
          :key="r.id"
          size="small"
          variant="tonal"
          color="warning"
          :loading="busy"
          :data-test="`role-capabilities-confirm-${r.id}`"
          @click="confirmPending(r)"
        >
          {{ t('moduleSetup.pendingConfirmRole', { role: r.name }) }}
        </VBtn>
      </div>
      <VBtn
        v-if="data.can_change && changes.length"
        color="primary"
        :loading="busy"
        data-test="role-capabilities-preview"
        @click="askPreview"
      >
        {{ t('moduleSetup.preview') }}
      </VBtn>
      <CapabilityChangePreview
        v-if="preview"
        :preview="preview"
        :busy="busy"
        @apply="apply"
      />
    </template>
  </div>
</template>
