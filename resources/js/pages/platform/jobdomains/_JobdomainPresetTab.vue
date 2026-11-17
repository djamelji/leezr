<script setup>
// ADR-799 — un onglet = le preset DU domaine pour une famille (Rôles, Champs & documents, Modèles de
// planning). Lecture claire du contenu en vigueur ; « Modifier » ouvre un brouillon (créé si besoin) dans
// l'éditeur spécialisé ; « Publier » publie et l'associe au domaine. Versions, provenance, historique et
// concurrence restent gérés en interne (affichés discrètement). Aucune société n'est modifiée.
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'
import PresetEditorDialog from '../catalog/_PresetEditorDialog.vue'

const props = defineProps({
  jobdomainId: { type: Number, required: true },
  kind: { type: String, required: true },
  state: { type: Object, required: true },
  meta: { type: Object, default: null },
})
const emit = defineEmits(['changed', 'link'])
const { t, te } = useI18n()
const { toast } = useAppToast()

const editor = ref(false)
const editing = ref(null)
const busy = ref(false)

const shown = computed(() => props.state.draft || props.state.published)
const content = computed(() => shown.value?.content || {})

const targetLabel = i => {
  const list = i.kind === 'document' ? props.meta?.documents : props.meta?.fields
  const hit = list?.find(x => x.code === i.code)

  return (i.kind === 'document' && te(`documents.type.${i.code}`) ? t(`documents.type.${i.code}`) : null) || hit?.label || i.code // ADR-820 : libellé traduit d'abord
}

const edit = async () => {
  busy.value = true
  try {
    const res = await $platformApi(`/jobdomains/${props.jobdomainId}/presets/${props.kind}/draft`, { method: 'POST' })

    editing.value = res.draft || { kind: props.kind, family_key: props.state.family_key }
    editor.value = true
  }
  catch (e) { toast(e?.data?.message || t('jobdomainProfile.error'), 'error') }
  finally { busy.value = false }
}
// ADR-803 — un preset SUIVI par d'autres domaines n'est jamais modifié pour eux en silence : le serveur
// refuse (409 `shared_preset`) et l'administrateur choisit explicitement.
const sharedWith = ref(null)
const publish = async (mode = null) => {
  busy.value = true
  try {
    await $platformApi(`/jobdomains/${props.jobdomainId}/presets/${props.kind}/${props.state.draft.id}/publish`, { method: 'POST', body: mode ? { mode } : {} })
    sharedWith.value = null
    toast(t('jobdomainTabs.publishedOk'), 'success')
    emit('changed')
  }
  catch (e) {
    if (e?.data?.code === 'shared_preset') sharedWith.value = e.data.shared_with
    else toast(e?.data?.message || Object.values(e?.data?.errors || {})[0]?.[0] || t('jobdomainProfile.error'), 'error')
  }
  finally { busy.value = false }
}
// ADR-820 — résumé de la bibliothèque (jamais la liste complète sur l'écran principal)
const catalogItems = computed(() => [...(content.value.fields || []).map(i => ({ ...i, kind: 'field', subject: i.subject || 'person', included: i.included !== false })),
  ...(content.value.documents || []).map(i => ({ ...i, kind: 'document', subject: i.subject || 'person', included: i.included !== false }))])
const catalogSummary = computed(() => [
  { key: 'fieldsActive', n: catalogItems.value.filter(i => i.kind === 'field' && i.included).length },
  { key: 'fieldsSuggested', n: catalogItems.value.filter(i => i.kind === 'field' && !i.included).length },
  { key: 'documentsActive', n: catalogItems.value.filter(i => i.kind === 'document' && i.included).length },
  { key: 'documentsSuggested', n: catalogItems.value.filter(i => i.kind === 'document' && !i.included).length },
])
const catalogSample = computed(() => catalogItems.value.filter(i => i.included).slice(0, 10))
const catalogMore = computed(() => catalogItems.value.length - catalogSample.value.length)

// ADR-802 — partager un preset entre domaines depuis la fiche (avant : seulement par l'API). Liste des
// familles PUBLIÉES de la même famille (`GET /platform/presets?kind=`), enregistrement par le parent via
// `preset-recommendations` (endpoint existant). Rien n'est publié ni appliqué.
const currentFamily = computed(() => props.state.published?.family_key || props.state.draft?.family_key || null)
const shareable = ref([])
const shareChoice = ref(null)
onMounted(async () => {
  try {
    const all = (await $platformApi(`/presets?kind=${props.kind}`)).presets || []
    const seen = new Map()
    for (const p of all) if (p.status === 'published' && p.family_key !== currentFamily.value && !seen.has(p.family_key)) seen.set(p.family_key, { value: p.family_key, title: p.label || p.family_key })
    shareable.value = [...seen.values()]
  }
  catch { shareable.value = [] }
})
</script>

<template>
  <div :data-test="`jd-tab-${kind}`">
    <div class="d-flex flex-wrap align-center gap-2 mb-4">
      <div class="flex-grow-1 text-body-2 text-medium-emphasis">
        <template v-if="state.draft">
          <span class="text-warning">{{ t('jobdomainTabs.draftPending') }}</span>
        </template>
        <template v-else-if="state.published">
          {{ t('jobdomainTabs.inEffect', { date: state.history.find(h => h.status === 'published')?.published_at ? new Date(state.history.find(h => h.status === 'published').published_at).toLocaleDateString() : '' }) }}
        </template>
        <template v-else>
          {{ state.legacy_available ? t('jobdomainTabs.fromLegacyHint') : t('jobdomainTabs.empty') }}
        </template>
        <span
          v-if="state.shared_with.length"
          class="d-block text-info"
        >{{ t('jobdomainTabs.shared', { list: state.shared_with.join(', ') }) }}</span>
      </div>
      <VBtn
        variant="tonal"
        prepend-icon="tabler-edit"
        :loading="busy"
        :data-test="`jd-edit-${kind}`"
        @click="edit"
      >
        {{ state.draft ? t('jobdomainTabs.continue') : t('jobdomainTabs.edit') }}
      </VBtn>
      <VBtn
        v-if="state.draft"
        color="primary"
        :loading="busy"
        :data-test="`jd-publish-${kind}`"
        @click="publish()"
      >
        {{ t('jobdomainTabs.publish') }}
      </VBtn>
    </div>

    <div
      v-if="!state.draft && shareable.length"
      class="d-flex flex-wrap align-center gap-2 mb-4"
      :data-test="`jd-share-${kind}`"
    >
      <AppSelect
        v-model="shareChoice"
        :items="shareable"
        :label="t('jobdomainTabs.useShared')"
        clearable
        density="compact"
        style="flex: 1 1 260px"
      />
      <VBtn
        variant="tonal"
        :disabled="!shareChoice"
        @click="emit('link', shareChoice)"
      >
        {{ t('jobdomainTabs.useSharedApply') }}
      </VBtn>
    </div>

    <!-- ADR-820 : ce que le preset FOURNIT, en résumé ; le détail s'ouvre dans l'éditeur -->
    <template v-if="kind === 'catalog' && (content.fields?.length || content.documents?.length)">
      <VRow class="card-grid card-grid-xs mb-2">
        <VCol
          v-for="b in catalogSummary"
          :key="b.key"
          cols="6"
          md="3"
        >
          <div class="text-h5">
            {{ b.n }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ t(`jobdomainTabs.summary.${b.key}`) }}
          </div>
        </VCol>
      </VRow>
      <div class="d-flex flex-wrap gap-2">
        <VChip
          v-for="i in catalogSample"
          :key="`${i.kind}:${i.code}`"
          size="small"
          :variant="i.included ? 'tonal' : 'outlined'"
        >
          {{ targetLabel(i) }}
        </VChip>
        <span
          v-if="catalogMore > 0"
          class="text-body-2 text-medium-emphasis align-self-center"
        >{{ t('jobdomainTabs.andMore', { n: catalogMore }) }}</span>
      </div>
    </template>
    <VList
      v-else-if="kind === 'rbac' && content.roles?.length"
      density="compact"
      class="py-0"
    >
      <VListItem
        v-for="r in content.roles"
        :key="r.key"
        :title="r.name"
        :subtitle="t('jobdomainTabs.bundleCount', { n: (r.bundles || []).length })"
      >
        <template #append>
          <VChip
            v-if="r.is_administrative"
            size="small"
            color="warning"
            variant="tonal"
          >
            {{ t('presetEditor.administrative') }}
          </VChip>
        </template>
      </VListItem>
    </VList>
    <template v-else-if="kind === 'requirements' && content.items?.length">
      <p class="text-body-2 mb-2">
        {{ t('jobdomainTabs.requirementsSummary', { n: content.items.length, positions: content.items.filter(i => i.job_role_keys?.length).length }) }}
      </p>
      <VAlert
        type="info"
        variant="tonal"
        density="compact"
      >
        {{ t('jobdomainTabs.requirementsNotApplied') }}
      </VAlert>
    </template>
    <template v-else-if="kind === 'planning' && (content.schedule_templates?.length || content.week_templates?.length)">
      <p class="text-body-2 mb-2">
        {{ t('jobdomainTabs.planningSummary', { schedules: content.schedule_templates?.length || 0, weeks: content.week_templates?.length || 0 }) }}
      </p>
      <VAlert
        type="info"
        variant="tonal"
        density="compact"
      >
        {{ t('jobdomainTabs.planningNotPolicy') }}
      </VAlert>
    </template>
    <p
      v-else
      class="text-body-2 text-medium-emphasis"
    >
      {{ t('jobdomainTabs.nothingYet') }}
    </p>

    <div
      v-if="state.history.length > 1"
      class="text-caption text-medium-emphasis mt-4"
    >
      {{ t('jobdomainTabs.history', { n: state.history.length }) }}
    </div>

    <VDialog
      :model-value="!!sharedWith"
      max-width="520"
      @update:model-value="v => { if (!v) sharedWith = null }"
    >
      <VCard
        :title="t('jobdomainTabs.sharedPublishTitle')"
        data-test="jd-shared-publish"
      >
        <VCardText>{{ t('jobdomainTabs.sharedPublishText', { list: (sharedWith || []).join(', ') }) }}</VCardText>
        <VCardActions class="flex-wrap gap-2">
          <VBtn
            variant="text"
            @click="sharedWith = null"
          >
            {{ t('jobdomainTabs.cancel') }}
          </VBtn>
          <VSpacer />
          <VBtn
            variant="tonal"
            :loading="busy"
            data-test="jd-publish-variant"
            @click="publish('variant')"
          >
            {{ t('jobdomainTabs.publishVariant') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="busy"
            data-test="jd-publish-shared"
            @click="publish('shared')"
          >
            {{ t('jobdomainTabs.publishShared') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
    <PresetEditorDialog
      v-model:is-dialog-visible="editor"
      :preset="editing"
      @saved="emit('changed')"
    />
  </div>
</template>
