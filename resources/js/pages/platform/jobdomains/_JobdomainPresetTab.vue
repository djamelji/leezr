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
const emit = defineEmits(['changed'])
const { t, te } = useI18n()
const { toast } = useAppToast()

const editor = ref(false)
const editing = ref(null)
const busy = ref(false)

const shown = computed(() => props.state.draft || props.state.published)
const content = computed(() => shown.value?.content || {})

const bundleLabel = key => props.meta?.bundles?.find(b => b.key === key)?.label || key
const targetLabel = i => {
  const list = i.kind === 'document' ? props.meta?.documents : props.meta?.fields
  const hit = list?.find(x => x.code === i.code)

  return hit?.label || (i.kind === 'document' && te(`documents.type.${i.code}`) ? t(`documents.type.${i.code}`) : i.code)
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
const publish = async () => {
  busy.value = true
  try {
    await $platformApi(`/jobdomains/${props.jobdomainId}/presets/${props.kind}/${props.state.draft.id}/publish`, { method: 'POST' })
    toast(t('jobdomainTabs.publishedOk'), 'success')
    emit('changed')
  }
  catch (e) { toast(e?.data?.message || Object.values(e?.data?.errors || {})[0]?.[0] || t('jobdomainProfile.error'), 'error') }
  finally { busy.value = false }
}
const DAY = d => t(`presetEditor.day.${d}`)
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
        @click="publish"
      >
        {{ t('jobdomainTabs.publish') }}
      </VBtn>
    </div>

    <!-- Contenu lisible (brouillon en cours s'il existe, sinon version en vigueur) -->
    <template v-if="kind === 'rbac' && content.roles?.length">
      <div
        v-for="r in content.roles"
        :key="r.key"
        class="jd-row"
      >
        <div class="flex-grow-1">
          <div class="text-body-1">
            {{ r.name }}<span
              v-if="r.is_administrative"
              class="text-body-2 text-warning"
            > · {{ t('presetEditor.administrative') }}</span>
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ (r.bundles || []).map(bundleLabel).join(' · ') || '—' }}
          </div>
        </div>
      </div>
    </template>
    <template v-else-if="kind === 'requirements' && content.items?.length">
      <div
        v-for="s in ['company', 'person', 'legal_representative']"
        :key="s"
      >
        <template v-if="content.items.some(i => (i.subject || 'person') === s)">
          <div class="text-overline mt-2">
            {{ t(`jobdomainTabs.subject.${s}`) }}
          </div>
          <div
            v-for="(i, n) in content.items.filter(x => (x.subject || 'person') === s)"
            :key="n"
            class="jd-row"
          >
            <div class="flex-grow-1 text-body-2">
              {{ targetLabel(i) }} · {{ i.kind === 'document' ? t('presetEditor.document') : t('presetEditor.field') }}<template v-if="i.job_role_keys?.length">
                · {{ i.job_role_keys.join(', ') }}
              </template>
            </div>
            <AppStatusText
              :tone="i.level === 'configurable_required' ? 'warning' : 'info'"
              :text="t(`presetEditor.level.${i.level}`)"
            />
          </div>
        </template>
      </div>
    </template>
    <template v-else-if="kind === 'planning' && (content.schedule_templates?.length || content.week_templates?.length)">
      <div class="text-overline">
        {{ t('presetEditor.schedules', { n: content.schedule_templates?.length || 0 }) }}
      </div>
      <div
        v-for="s in content.schedule_templates || []"
        :key="s.code"
        class="jd-row"
      >
        <div class="flex-grow-1 text-body-2">
          {{ s.name }} · {{ s.start_time }} – {{ s.end_time }}
        </div>
      </div>
      <div class="text-overline mt-3">
        {{ t('presetEditor.weeks', { n: content.week_templates?.length || 0 }) }}
      </div>
      <div
        v-for="w in content.week_templates || []"
        :key="w.code"
        class="jd-row"
      >
        <div class="flex-grow-1 text-body-2">
          {{ w.name }} · {{ [...new Set((w.lines || []).map(l => l.day_index))].map(DAY).join(', ') }}
        </div>
      </div>
      <VAlert
        type="info"
        variant="tonal"
        density="compact"
        class="mt-3"
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

    <PresetEditorDialog
      v-model:is-dialog-visible="editor"
      :preset="editing"
      @saved="emit('changed')"
    />
  </div>
</template>

<style scoped>
.jd-row { display: flex; align-items: center; gap: 12px; padding-block: 8px; flex-wrap: wrap; }
.jd-row + .jd-row { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
</style>
