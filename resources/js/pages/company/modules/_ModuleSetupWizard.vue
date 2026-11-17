<script setup>
// ADR-803 — assistant commun de configuration d'un module : Accès → Réglages du module → Confirmation.
// Toutes les règles sont serveur (`/modules/{key}/setup`) : aperçu obligatoire avant d'appliquer les accès,
// empreinte de concurrence, révision pour la confirmation. Les réglages réutilisent les composants
// spécialisés du module (registre moduleSetupSteps). Sous-composant — pas de definePage.
import { defineAsyncComponent } from 'vue'
import AppStepper from '@core/components/AppStepper.vue'
import ModuleCapabilityAccess from '@/components/modules/ModuleCapabilityAccess.vue'
import CapabilityChangePreview from '@/components/modules/CapabilityChangePreview.vue'
import { resolveSetupStep } from '@/core/registries/moduleSetupSteps'
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({ moduleKey: { type: String, required: true } })
const emit = defineEmits(['changed'])
const { t, te } = useI18n()
const { capLabel, moduleName } = useCatalogText() // ADR-806
const { toast } = useAppToast()

const data = ref(null)
const step = ref(0)
const changes = ref([])
const preview = ref(null)
const busy = ref(false)
const accessRef = ref(null)

const settingSteps = computed(() => (data.value?.steps || []).filter(s => s !== 'access'))
const stepComponents = computed(() => Object.fromEntries(settingSteps.value.map(s => {
  const loader = resolveSetupStep(s)

  return [s, loader ? defineAsyncComponent(loader) : null]
})))
const items = computed(() => [
  { title: t('moduleSetup.steps.access'), subtitle: t('moduleSetup.steps.accessHint') },
  ...settingSteps.value.map(s => ({ title: t(`moduleSetup.steps.${s}`), subtitle: t(`moduleSetup.steps.${s}Hint`) })),
  { title: t('moduleSetup.steps.confirm'), subtitle: t('moduleSetup.steps.confirmHint') },
])
const lastIndex = computed(() => items.value.length - 1)
const done = s => (data.value?.completed_steps || []).includes(s)

const load = async () => { data.value = await $api(`/modules/${props.moduleKey}/setup`) }
onMounted(async () => {
  await load()
  // Reprise : première étape non terminée.
  const order = ['access', ...settingSteps.value]
  const next = order.findIndex(s => !done(s))
  step.value = next === -1 ? lastIndex.value : next
})

const err = e => e?.data?.message || Object.values(e?.data?.errors || {})[0]?.[0] || t('common.error')

// ── Étape Accès ─────────────────────────────────────────────
const askPreview = async () => {
  busy.value = true
  try { preview.value = await $api(`/modules/${props.moduleKey}/setup/access/preview`, { method: 'POST', body: { changes: changes.value } }) }
  catch (e) { toast(err(e), 'error') }
  finally { busy.value = false }
}
const applyAccess = async (confirmAffected = false) => {
  busy.value = true
  try {
    data.value = await $api(`/modules/${props.moduleKey}/setup/access`, { method: 'PUT', body: { changes: changes.value, fingerprint: preview.value.fingerprint, confirm_affected: confirmAffected } })
    preview.value = null
    changes.value = []
    accessRef.value?.reset()
    toast(t('moduleSetup.accessSaved'), 'success')
    step.value = 1
    emit('changed')
  }
  catch (e) {
    toast(e?.data?.code === 'stale_preview' ? t('moduleSetup.stale') : err(e), 'error')
    if (e?.data?.code === 'stale_preview') { preview.value = null; await load() }
  }
  finally { busy.value = false }
}

// ── Étapes de réglage ───────────────────────────────────────
const markStep = async s => {
  try { data.value = await $api(`/modules/${props.moduleKey}/setup/steps`, { method: 'POST', body: { step: s } }); step.value += 1 }
  catch (e) { toast(err(e), 'error') }
}

// ── Confirmation ────────────────────────────────────────────
const holders = c => (data.value?.roles || []).filter(r => r.holds?.[c.key] === 'full').map(r => r.name)
const required = computed(() => (data.value?.readiness || []).filter(r => r.required !== false))
const recommended = computed(() => (data.value?.readiness || []).filter(r => r.required === false))
const missing = computed(() => required.value.filter(r => !r.ok))
const confirm = async () => {
  busy.value = true
  try {
    data.value = await $api(`/modules/${props.moduleKey}/setup/confirm`, { method: 'POST', body: { revision: data.value.revision } })
    toast(t('moduleSetup.readyToast'), 'success')
    emit('changed')
  }
  catch (e) {
    if (e?.data?.code === 'stale_revision') { toast(t('moduleSetup.stale'), 'warning'); await load() }
    else toast(err(e), 'error')
  }
  finally { busy.value = false }
}
const statusColor = s => ({ ready: 'success', in_progress: 'info', to_configure: 'warning', needs_review: 'warning', legacy: 'secondary', suspended: 'secondary' }[s] || 'secondary')
</script>

<template>
  <VCard
    v-if="data"
    class="mb-4"
    data-test="module-setup"
  >
    <VCardItem>
      <VCardTitle class="d-flex align-center flex-wrap gap-2">
        {{ t('moduleSetup.title') }}
        <VChip
          size="small"
          :color="statusColor(data.status)"
          data-test="module-setup-status"
        >
          {{ t(`moduleSetup.status.${data.status}`) }}
        </VChip>
      </VCardTitle>
      <VCardSubtitle class="text-wrap">
        {{ data.status === 'legacy' ? t('moduleSetup.legacyHint') : t('moduleSetup.intro') }}
      </VCardSubtitle>
      <VAlert
        v-if="data.status === 'needs_review'"
        type="warning"
        variant="tonal"
        density="compact"
        class="mt-3"
        data-test="module-setup-stale"
      >
        {{ t('moduleSetup.needsReview') }}
        <ul class="mt-1">
          <li
            v-for="r in data.stale_reasons"
            :key="r"
          >
            {{ $te(`moduleSetup.stale_${r.replace(':', '_')}`) ? t(`moduleSetup.stale_${r.replace(':', '_')}`) : r }}
          </li>
        </ul>
      </VAlert>
    </VCardItem>
    <VCardText>
      <AppStepper
        v-model:current-step="step"
        :items="items"
        class="mb-6"
      />

      <!-- 1. Accès -->
      <div
        v-if="step === 0"
        data-test="module-setup-access"
      >
        <VAlert
          v-if="!data.can.configure_access"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ t('moduleSetup.accessNeedsRoles') }}
        </VAlert>
        <ModuleCapabilityAccess
          ref="accessRef"
          :capabilities="data.capabilities"
          :roles="data.roles"
          :suggestion="data.suggestion"
          :readonly="!data.can.configure_access"
          @update:changes="v => { changes = v; preview = null }"
        />
        <!-- ADR-805 : accès décidés dans un AUTRE module (lecture seule, modifiés là où ils vivent) -->
        <div
          v-if="data.related?.length"
          class="mt-4 mb-2"
          data-test="module-setup-related"
        >
          <h6 class="text-h6 mb-1">
            {{ t('moduleSetup.relatedTitle') }}
          </h6>
          <p class="text-body-2 text-medium-emphasis mb-2">
            {{ t('moduleSetup.relatedHint') }}
          </p>
          <div
            v-for="r in data.related"
            :key="r.key"
            class="d-flex flex-wrap align-center gap-2 text-body-2 mb-2"
            :data-test="`related-${r.key}`"
          >
            <span class="font-weight-medium">{{ capLabel(r) }}</span>
            <span class="text-medium-emphasis">({{ moduleName(r.module, r.module_name) }})</span>
            <VChip
              v-for="h in r.holders"
              :key="h.role_id"
              size="x-small"
              :color="h.holds === 'full' ? 'primary' : undefined"
              variant="tonal"
            >
              {{ h.role }}{{ h.holds === 'partial' ? ` · ${t('moduleSetup.partial')}` : '' }}
            </VChip>
            <span
              v-if="!r.holders.length"
              class="text-medium-emphasis"
            >{{ t('moduleSetup.relatedNobody') }}</span>
            <RouterLink
              :to="{ name: 'company-people-roles', query: { module: r.module } }"
              class="text-primary"
            >
              {{ t('moduleSetup.relatedEdit') }}
            </RouterLink>
          </div>
        </div>
        <div
          v-if="data.can.configure_access"
          class="d-flex flex-wrap gap-2 mt-2"
        >
          <VBtn
            color="primary"
            :loading="busy"
            data-test="module-setup-preview"
            @click="askPreview"
          >
            {{ changes.length ? t('moduleSetup.preview') : t('moduleSetup.keepAccess') }}
          </VBtn>
        </div>
        <CapabilityChangePreview
          v-if="preview"
          :preview="preview"
          :busy="busy"
          @apply="applyAccess"
        />
      </div>

      <!-- 2..n-1. Réglages spécialisés du module -->
      <template
        v-for="(s, i) in settingSteps"
        :key="s"
      >
        <div
          v-if="step === i + 1"
          :data-test="`module-setup-step-${s}`"
        >
          <component
            :is="stepComponents[s]"
            v-if="stepComponents[s]"
            :module-key="moduleKey"
            @saved="markStep(s)"
          />
          <div class="d-flex gap-2 mt-4">
            <VBtn
              variant="tonal"
              :data-test="`module-setup-step-${s}-done`"
              @click="markStep(s)"
            >
              {{ t('moduleSetup.stepDone') }}
            </VBtn>
          </div>
        </div>
      </template>

      <!-- Confirmation -->
      <div
        v-if="step === lastIndex"
        data-test="module-setup-confirm"
      >
        <h6 class="text-h6 mb-2">
          {{ t('moduleSetup.summaryAccess') }}
        </h6>
        <div
          v-for="c in data.capabilities.filter(x => holders(x).length)"
          :key="c.key"
          class="text-body-2 mb-1"
        >
          {{ capLabel(c) }} :
          {{ holders(c).join(', ') }}
        </div>
        <div
          v-if="data.module.requires.length"
          class="text-body-2 mt-3"
          data-test="module-setup-dependencies"
        >
          {{ t('moduleSetup.dependencies') }} :
          <template
            v-for="(r, i) in data.module.requires"
            :key="r.key"
          >
            <RouterLink :to="`/company/modules/${r.key}`">{{ moduleName(r.key, r.name) }}</RouterLink>
            ({{ r.active ? (r.setup_status ? t(`moduleSetup.status.${r.setup_status}`) : t('moduleSetup.active')) : t('moduleSetup.inactive') }}){{ i < data.module.requires.length - 1 ? ', ' : '' }}
          </template>
        </div>
        <h6 class="text-h6 mt-4 mb-2">
          {{ t('moduleSetup.summaryReady') }}
        </h6>
        <div
          v-for="r in required"
          :key="r.key"
          class="d-flex align-center gap-2 text-body-2 mb-1"
          :data-test="`readiness-${r.key}`"
        >
          <VIcon
            :icon="r.ok ? 'tabler-circle-check' : 'tabler-alert-circle'"
            :color="r.ok ? 'success' : 'warning'"
            size="18"
          />
          {{ t(`moduleSetup.readiness.${r.key}`) }}
        </div>
        <template v-if="recommended.length">
          <h6 class="text-h6 mt-4 mb-2">
            {{ t('moduleSetup.summaryRecommended') }}
          </h6>
          <div
            v-for="r in recommended"
            :key="r.key"
            class="d-flex align-center gap-2 text-body-2 mb-1"
            :data-test="`recommendation-${r.key}`"
          >
            <VIcon
              :icon="r.ok ? 'tabler-circle-check' : 'tabler-info-circle'"
              :color="r.ok ? 'success' : 'info'"
              size="18"
            />
            {{ t(`moduleSetup.readiness.${r.key}`) }}
          </div>
        </template>
        <VAlert
          v-if="missing.length"
          type="warning"
          variant="tonal"
          density="compact"
          class="mt-3"
        >
          {{ t('moduleSetup.notReady') }}
        </VAlert>
        <VBtn
          class="mt-4"
          color="primary"
          :disabled="!!missing.length || data.status === 'ready'"
          :loading="busy"
          data-test="module-setup-confirm-btn"
          @click="confirm"
        >
          {{ data.status === 'ready' ? t('moduleSetup.isReady') : t('moduleSetup.confirm') }}
        </VBtn>
      </div>
    </VCardText>
  </VCard>
</template>
