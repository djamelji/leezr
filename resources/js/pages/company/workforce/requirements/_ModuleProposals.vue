<script setup>
// ADR-794 (L4) — ce que chaque module apporte (champs, documents, exigences PROPOSÉES) et l'adoption
// explicite par l'entreprise. MODE PARALLÈLE : rien n'apparaît dans les fiches, demandes ou conformité.
// Tout statut est calculé par le serveur ; l'interface ne garde que les choix en attente.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const emit = defineEmits(['changed'])
const { t, te } = useI18n()
const { toast } = useAppToast()
const docLabel = (code, fallback) => (code && te(`documents.type.${code}`) ? t(`documents.type.${code}`) : fallback)

const modules = ref([])
const can = ref({ fields: false, documents: false })
const choices = ref({}) // « module|ref » → adopt | decline | keep | replace
const preview = ref(null)
const previewModule = ref(null)
const saving = ref(false)

const load = async () => {
  try {
    const res = await $api('/workforce/requirements/modules')

    modules.value = res.modules
    can.value = res.can
  }
  catch { modules.value = [] }
}
onMounted(load)

const label = (kind, item) => (kind === 'document' ? docLabel(item.code, item.label) : item.label) || t('requirementsCfg.modules.unknownDefinition')
const tone = s => ({ new: 'info', optional: 'info', proposed_required: 'warning', requires_confirmation: 'warning', conflict: 'error', already_configured: 'success', already_available: 'success', not_applicable: 'info' }[s] || 'info')
const scopeText = sc => (!sc || sc.everyone ? t('requirementsCfg.everyone') : sc.positions.join(', ') || t('requirementsCfg.modules.noMatchingPosition'))
const canDecide = (m, p) => m.adoptable && can.value[p.kind === 'field' ? 'fields' : 'documents']
const adoptable = p => ['optional', 'proposed_required'].includes(p.status) || (p.status === 'requires_confirmation' && !p.customized)
const choose = (m, p, decision) => {
  const key = `${m.module_key}|${p.ref}`
  const next = { ...choices.value }

  next[key] === decision ? delete next[key] : (next[key] = decision)
  choices.value = next
}
const chosen = (m, p) => choices.value[`${m.module_key}|${p.ref}`]
const pendingFor = m => Object.entries(choices.value).filter(([k]) => k.startsWith(`${m.module_key}|`)).map(([k, decision]) => ({ ref: k.slice(m.module_key.length + 1), decision }))
const summary = m => [...m.fields.map(f => label('field', f)), ...m.documents.map(d => label('document', d))].join(' · ')

const openPreview = async m => {
  try {
    preview.value = await $api(`/workforce/requirements/modules/${m.module_key}/preview`, { method: 'POST', body: { decisions: pendingFor(m) } })
    previewModule.value = m
  }
  catch (e) { toast(e?.data?.message || t('requirementsCfg.error'), 'error') }
}
const confirm = async () => {
  const m = previewModule.value

  saving.value = true
  try {
    await $api(`/workforce/requirements/modules/${m.module_key}/decisions`, { method: 'POST', body: { decisions: pendingFor(m) } })
    toast(t('requirementsCfg.saved'), 'success')
    choices.value = Object.fromEntries(Object.entries(choices.value).filter(([k]) => !k.startsWith(`${m.module_key}|`)))
    previewModule.value = null
    await load()
    emit('changed')
  }
  catch (e) { toast(e?.data?.message || t('requirementsCfg.error'), 'error') }
  finally { saving.value = false }
}
const refLabel = (m, ref) => {
  const p = m?.proposals.find(x => x.ref === ref)

  return p ? label(p.kind, p) : ''
}
</script>

<template>
  <AppSettingsGroup
    v-for="m in modules"
    :key="m.module_key"
    :title="t('requirementsCfg.modules.title', { name: m.name })"
    :description="t(`requirementsCfg.modules.state.${m.state}`)"
    :data-test="`module-proposals-${m.module_key}`"
  >
    <p
      v-if="summary(m)"
      class="req-empty"
    >
      {{ t('requirementsCfg.modules.brings', { name: m.name }) }} {{ summary(m) }}
    </p>
    <div
      v-for="p in m.proposals"
      :key="p.ref"
      class="req-row"
      :class="{ 'req-row--pending': chosen(m, p) }"
      :data-test="`module-proposal-${m.module_key}-${p.kind}-${p.code}`"
    >
      <div class="req-row__main">
        <div class="req-row__title">
          {{ label(p.kind, p) }}<span
            v-if="p.sensitive"
            class="req-row__sub ms-2"
          >· {{ t('requirementsCfg.modules.sensitive') }}</span>
        </div>
        <div class="req-row__sub">
          {{ t(`requirementsCfg.modules.level.${p.level}`) }} · {{ scopeText(p.scope) }}<template v-if="p.reason">
            · {{ p.reason }}
          </template><template v-if="p.update">
            · {{ t('requirementsCfg.modules.update', { now: t(`requirementsCfg.modules.level.${p.update.level_now}`), next: t(`requirementsCfg.modules.level.${p.update.level_proposed}`) }) }}
          </template><template v-if="p.previous_decision === 'declined'">
            · {{ t('requirementsCfg.modules.declinedBefore') }}
          </template>
        </div>
      </div>
      <AppStatusText
        :tone="tone(p.status)"
        :text="t(`requirementsCfg.modules.status.${p.customized && p.status === 'requires_confirmation' ? 'customized_update' : p.status}`)"
      />
      <template v-if="canDecide(m, p)">
        <template v-if="p.customized && p.status === 'requires_confirmation'">
          <VBtn
            size="small"
            :variant="chosen(m, p) === 'keep' ? 'tonal' : 'text'"
            @click="choose(m, p, 'keep')"
          >
            {{ t('requirementsCfg.modules.keep') }}
          </VBtn>
          <VBtn
            size="small"
            :variant="chosen(m, p) === 'replace' ? 'tonal' : 'text'"
            @click="choose(m, p, 'replace')"
          >
            {{ t('requirementsCfg.modules.replace') }}
          </VBtn>
        </template>
        <template v-else-if="adoptable(p)">
          <VBtn
            size="small"
            :variant="chosen(m, p) === 'adopt' ? 'tonal' : 'text'"
            :color="chosen(m, p) === 'adopt' ? 'primary' : undefined"
            :data-test="`module-adopt-${m.module_key}-${p.kind}-${p.code}`"
            @click="choose(m, p, 'adopt')"
          >
            {{ t(p.status === 'requires_confirmation' ? 'requirementsCfg.modules.update_btn' : 'requirementsCfg.modules.adopt') }}
          </VBtn>
          <VBtn
            size="small"
            :variant="chosen(m, p) === 'decline' ? 'tonal' : 'text'"
            @click="choose(m, p, 'decline')"
          >
            {{ t('requirementsCfg.modules.decline') }}
          </VBtn>
        </template>
      </template>
    </div>
    <div
      v-for="(s, i) in m.suspended_now"
      :key="`s${i}`"
      class="req-row"
    >
      <div class="req-row__main">
        <div class="req-row__title">
          {{ s.label }}
        </div>
        <div class="req-row__sub">
          {{ scopeText(s.scope) }}
        </div>
      </div>
      <AppStatusText
        :tone="s.outcome === 'suspended' ? 'warning' : 'success'"
        :text="t(`requirementsCfg.modules.impact.${s.outcome}`)"
      />
    </div>
    <div
      v-if="pendingFor(m).length"
      class="req-add"
    >
      <VBtn
        color="primary"
        size="small"
        :data-test="`module-preview-${m.module_key}`"
        @click="openPreview(m)"
      >
        {{ t('requirementsCfg.previewBtn') }}
      </VBtn>
    </div>
  </AppSettingsGroup>

  <AppDialog
    :model-value="!!previewModule"
    :title="t('requirementsCfg.modules.previewTitle', { name: previewModule?.name || '' })"
    :max-width="640"
    @update:model-value="v => { if (!v) previewModule = null }"
  >
    <template v-if="preview && previewModule">
      <div
        v-for="group in ['adopted', 'updated', 'replaced', 'kept', 'declined', 'skipped']"
        :key="group"
      >
        <template v-if="preview.changes[group].length">
          <div class="text-overline mt-2">
            {{ t(`requirementsCfg.modules.diff.${group}`) }}
          </div>
          <div
            v-for="c in preview.changes[group]"
            :key="c.ref"
            class="req-diff"
          >
            {{ refLabel(previewModule, c.ref) }}<span
              v-if="preview.people[c.ref]"
              class="text-medium-emphasis"
            > — {{ t('requirementsCfg.previewSubtitle', { n: preview.people[c.ref].total, a: preview.people[c.ref].accountless }) }}</span>
          </div>
        </template>
      </div>
      <VAlert
        type="info"
        variant="tonal"
        density="compact"
        class="mt-4"
      >
        {{ t('requirementsCfg.previewParallel') }}
      </VAlert>
    </template>
    <template #actions>
      <VBtn
        variant="text"
        @click="previewModule = null"
      >
        {{ t('common.cancel') }}
      </VBtn>
      <VBtn
        color="primary"
        :loading="saving"
        :data-test="`module-confirm`"
        @click="confirm"
      >
        {{ t('requirementsCfg.saveBtn') }}
      </VBtn>
    </template>
  </AppDialog>
</template>

<style scoped>
/* ADR-794 — mêmes lignes compactes que la page ADR-792 (composition ADR-782). */
.req-row { display: flex; align-items: center; gap: 12px; min-block-size: 56px; padding: 8px 12px 8px 20px; flex-wrap: wrap; }
.req-row + .req-row { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.req-row--pending { background: rgba(var(--v-theme-primary), 0.04); }
.req-row__main { flex: 1; min-inline-size: 220px; }
.req-row__title { font-size: 0.9375rem; font-weight: 500; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.req-row__sub { font-size: 0.8125rem; font-weight: 400; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.req-empty { margin: 0; padding: 12px 20px; font-size: 0.875rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.req-add { display: flex; justify-content: flex-end; padding: 12px 20px; border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.req-diff { padding: 4px 0; font-size: 0.9375rem; }
@media (max-width: 599px) {
  .req-row { padding-inline-start: 16px; }
  .req-row__main { flex-basis: 100%; }
}
</style>
