<script setup>
// ADR-792 (L2) — « Informations & documents demandés » par poste. MODE PARALLÈLE : la configuration est
// enregistrée dans le registre des exigences mais n'a encore aucun effet sur les fiches, demandes,
// conformité ou rappels. Tout calcul (état, provenance, aperçu) vient du serveur (résolveur L1) :
// l'interface n'affiche que les choix en attente.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

definePage({ meta: { module: 'workforce', surface: 'structure', anyPermission: ['settings.manage', 'documents.configure'] } })

const { t, te } = useI18n()
// Libellés des types de documents traduits côté interface (convention existante `documents.type.<code>`).
const docLabel = (code, fallback) => (te(`documents.type.${code}`) ? t(`documents.type.${code}`) : fallback)
const { toast } = useAppToast()

const scope = ref('all')
const data = ref(null)
const catalog = ref({ fields: [], documents: [] })
const loading = ref(true)
const pending = ref({}) // « kind:code » → { kind, code, state }
const preview = ref(null)
const previewOpen = ref(false)
const saving = ref(false)
const legacy = ref(null)
const legacyOpen = ref(false)
const search = ref({ field: null, document: null })

const load = async () => {
  loading.value = true
  try {
    data.value = await $api('/workforce/requirements', { query: { scope: scope.value } })
  }
  finally { loading.value = false }
}

onMounted(async () => {
  await load()
  catalog.value = await $api('/workforce/requirements/catalog')
})

watch(scope, () => {
  pending.value = {}
  load()
})

const scopeItems = computed(() => (data.value?.scopes || []).map(s => ({
  value: s.key,
  title: s.key === 'all' ? t('requirementsCfg.everyone') : s.label,
  subtitle: t('requirementsCfg.peopleCount', { n: s.people, a: s.accountless }),
})))
const currentScope = computed(() => (data.value?.scopes || []).find(s => s.key === scope.value))

const STATE_LABEL = { required: 'requirementsCfg.state.required', optional: 'requirementsCfg.state.optional', recommended: 'requirementsCfg.state.recommended', waived: 'requirementsCfg.state.waived', removed: 'requirementsCfg.state.removed' }
const STATE_TONE = { required: 'warning', optional: 'info', recommended: 'info', waived: 'success', removed: 'error' }

// Lignes = état serveur + choix en attente (présentation seulement, aucune résolution côté client).
const rows = kind => {
  const list = (data.value?.state?.[kind === 'field' ? 'fields' : 'documents'] || []).map(r => ({ ...r, kind, label: kind === 'document' ? docLabel(r.code, r.label) : r.label }))
  for (const p of Object.values(pending.value).filter(p => p.kind === kind && !list.some(r => r.code === p.code))) {
    const def = catalog.value[kind === 'field' ? 'fields' : 'documents'].find(d => d.code === p.code)
    list.push({ kind, code: p.code, label: kind === 'document' ? docLabel(p.code, def?.label || p.code) : (def?.label || p.code), state: null, origin: null, locked: false, isNew: true })
  }

  return list.map(r => ({ ...r, pendingState: pending.value[`${kind}:${r.code}`]?.state || null }))
}
const fieldRows = computed(() => rows('field'))
const documentRows = computed(() => rows('document'))
const canEdit = kind => !!data.value?.can?.[kind === 'field' ? 'fields' : 'documents']

const available = kind => {
  const used = new Set(rows(kind).map(r => r.code))

  return (catalog.value[kind === 'field' ? 'fields' : 'documents'] || []).filter(d => !used.has(d.code))
    .map(d => ({ title: kind === 'document' ? docLabel(d.code, d.label) : d.label, value: d.code }))
    .sort((x, y) => x.title.localeCompare(y.title))
}

const setState = (row, state) => {
  const key = `${row.kind}:${row.code}`
  const copy = { ...pending.value }
  if (!row.isNew && row.state === state)
    delete copy[key]
  else
    copy[key] = { kind: row.kind, code: row.code, state }
  pending.value = copy
}
const add = kind => {
  const code = search.value[kind]
  if (!code)
    return
  pending.value = { ...pending.value, [`${kind}:${code}`]: { kind, code, state: 'required' } }
  search.value[kind] = null
}
const pendingCount = computed(() => Object.keys(pending.value).length)

const originText = o => {
  if (!o)
    return t('requirementsCfg.origin.new')
  const where = o.scope === 'all' ? t('requirementsCfg.origin.allPositions') : t('requirementsCfg.origin.thisPosition')

  return {
    company: t('requirementsCfg.origin.company', { where }),
    preset: t('requirementsCfg.origin.preset'),
    module: t('requirementsCfg.origin.module', { name: o.module || '' }),
    platform: t('requirementsCfg.origin.platform'),
    market: t('requirementsCfg.origin.market'),
    jobdomain: t('requirementsCfg.origin.jobdomain'),
  }[o.kind] || ''
}

const payload = () => ({ scope: scope.value, items: Object.values(pending.value) })
const openPreview = async () => {
  try {
    preview.value = await $api('/workforce/requirements/preview', { method: 'POST', body: payload() })
    previewOpen.value = true
  }
  catch (e) { toast(e?.data?.message || t('requirementsCfg.error'), 'error') }
}
const save = async () => {
  saving.value = true
  try {
    await $api('/workforce/requirements', { method: 'POST', body: payload() })
    toast(t('requirementsCfg.saved'), 'success')
    previewOpen.value = false
    pending.value = {}
    await load()
  }
  catch (e) { toast(e?.data?.message || t('requirementsCfg.error'), 'error') }
  finally { saving.value = false }
}

const labelOf = (kind, code) => {
  const label = (catalog.value[kind === 'field' ? 'fields' : 'documents'].find(d => d.code === code)?.label) || code

  return kind === 'document' ? docLabel(code, label) : label
}
const openLegacy = async () => {
  legacyOpen.value = !legacyOpen.value
  if (legacyOpen.value && !legacy.value)
    legacy.value = (await $api('/workforce/requirements/legacy')).items
}
</script>

<template>
  <div class="req-page">
    <SettingsReturnLink />
    <AppPageHeader
      :title="t('requirementsCfg.title')"
      :description="t('requirementsCfg.subtitle')"
    />

    <!-- Mode parallèle : rien n'est encore actif -->
    <VAlert
      type="info"
      variant="tonal"
      density="compact"
      class="mb-5"
      data-test="requirements-parallel-notice"
    >
      {{ t('requirementsCfg.parallelNotice') }}
    </VAlert>

    <div class="req-toolbar">
      <AppSelect
        v-model="scope"
        :items="scopeItems"
        :label="t('requirementsCfg.position')"
        item-title="title"
        item-value="value"
        class="req-toolbar__select"
        data-test="requirements-scope"
      >
        <template #item="{ props: p, item }">
          <VListItem
            v-bind="p"
            :subtitle="item.raw.subtitle"
          />
        </template>
      </AppSelect>
      <span
        v-if="currentScope"
        class="req-toolbar__meta"
      >{{ t('requirementsCfg.peopleCount', { n: currentScope.people, a: currentScope.accountless }) }}</span>
    </div>

    <VProgressLinear
      v-if="loading"
      indeterminate
      color="primary"
      class="mb-4"
    />

    <template v-else>
      <AppSettingsGroup
        v-for="kind in ['field', 'document']"
        :key="kind"
        :title="t(kind === 'field' ? 'requirementsCfg.fields' : 'requirementsCfg.documents')"
        :description="t(kind === 'field' ? 'requirementsCfg.fieldsHint' : 'requirementsCfg.documentsHint')"
      >
        <div
          v-for="row in (kind === 'field' ? fieldRows : documentRows)"
          :key="row.code"
          class="req-row"
          :class="{ 'req-row--pending': row.pendingState }"
          :data-test="`req-row-${row.kind}-${row.code}`"
        >
          <div class="req-row__main">
            <div class="req-row__title">
              {{ row.label }}
              <VIcon
                v-if="row.locked"
                icon="tabler-lock"
                size="14"
                class="ms-1"
              />
            </div>
            <div class="req-row__sub">
              {{ originText(row.origin) }}<template v-if="row.pendingState">
                · {{ t('requirementsCfg.unsaved') }}
              </template><template v-if="row.locked && row.justification">
                · {{ t('requirementsCfg.justification', { ref: row.justification }) }}
              </template>
            </div>
          </div>
          <!-- Un seul contrôle : l'état courant, modifiable par menu (ou lecture seule si verrouillé / non autorisé) -->
          <VMenu
            v-if="canEdit(row.kind) && !row.locked"
            location="bottom end"
          >
            <template #activator="{ props: menuProps }">
              <VBtn
                v-bind="menuProps"
                variant="text"
                size="small"
                append-icon="tabler-chevron-down"
                class="req-row__state"
                :data-test="`req-state-${row.kind}-${row.code}`"
              >
                <span
                  class="req-dot"
                  :class="`req-dot--${STATE_TONE[row.pendingState || row.state] || 'info'}`"
                />
                {{ t(STATE_LABEL[row.pendingState || row.state] || 'requirementsCfg.state.required') }}
              </VBtn>
            </template>
            <VList density="compact">
              <VListItem
                :title="t('requirementsCfg.state.required')"
                @click="setState(row, 'required')"
              />
              <VListItem
                :title="t('requirementsCfg.state.optional')"
                @click="setState(row, 'optional')"
              />
              <VDivider v-if="row.state !== 'waived'" />
              <VListItem
                v-if="row.state !== 'waived'"
                :title="t('requirementsCfg.remove')"
                class="text-error"
                :data-test="`req-remove-${row.kind}-${row.code}`"
                @click="setState(row, 'removed')"
              />
            </VList>
          </VMenu>
          <AppStatusText
            v-else-if="row.state"
            :tone="STATE_TONE[row.state]"
            :text="t(STATE_LABEL[row.state])"
          />
        </div>
        <p
          v-if="!(kind === 'field' ? fieldRows : documentRows).length"
          class="req-empty"
        >
          {{ t('requirementsCfg.empty') }}
        </p>
        <div
          v-if="canEdit(kind)"
          class="req-add"
        >
          <AppAutocomplete
            v-model="search[kind]"
            :items="available(kind)"
            :placeholder="t(kind === 'field' ? 'requirementsCfg.addField' : 'requirementsCfg.addDocument')"
            density="compact"
            clearable
            class="req-add__input"
            :data-test="`req-add-${kind}`"
          />
          <VBtn
            variant="tonal"
            size="small"
            prepend-icon="tabler-plus"
            :disabled="!search[kind]"
            @click="add(kind)"
          >
            {{ t('requirementsCfg.add') }}
          </VBtn>
        </div>
      </AppSettingsGroup>

      <!-- Reprise de l'existant : lecture seule, aucune conversion -->
      <AppSettingsGroup
        :title="t('requirementsCfg.legacyTitle')"
        :description="t('requirementsCfg.legacyHint')"
        quiet
      >
        <button
          type="button"
          class="req-legacy-toggle"
          data-test="requirements-legacy-toggle"
          @click="openLegacy"
        >
          {{ legacyOpen ? t('requirementsCfg.legacyHide') : t('requirementsCfg.legacyShow') }}
        </button>
        <template v-if="legacyOpen && legacy">
          <div
            v-for="(item, i) in legacy"
            :key="i"
            class="req-row"
          >
            <div class="req-row__main">
              <div class="req-row__title">
                {{ item.kind === 'document' ? docLabel(item.code, item.label) : item.label }} — {{ item.role }}
              </div>
              <div class="req-row__sub">
                {{ t(`requirementsCfg.legacyRule.${item.rule}`) }} · {{ t('requirementsCfg.legacyPeople', { n: item.people, p: item.people_with_position }) }}<template v-if="item.positions.length">
                  · {{ item.positions.map(p => `${p.label} (${p.people})`).join(', ') }}
                </template>
              </div>
            </div>
            <AppStatusText
              :tone="{ certain: 'success', proposed: 'info', ambiguous: 'warning', conflict: 'error', needs_decision: 'warning' }[item.category]"
              :text="t(`requirementsCfg.legacyCategory.${item.category}`)"
            />
          </div>
        </template>
      </AppSettingsGroup>
    </template>

    <!-- Barre des modifications en attente -->
    <div
      v-if="pendingCount"
      class="req-footer"
      data-test="requirements-pending-bar"
    >
      <span>{{ t('requirementsCfg.pendingCount', { n: pendingCount }) }}</span>
      <div class="d-flex gap-2">
        <VBtn
          variant="text"
          @click="pending = {}"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          data-test="requirements-preview"
          @click="openPreview"
        >
          {{ t('requirementsCfg.previewBtn') }}
        </VBtn>
      </div>
    </div>

    <AppDialog
      v-model="previewOpen"
      :title="t('requirementsCfg.previewTitle')"
      :subtitle="t('requirementsCfg.previewSubtitle', { n: preview?.people?.total || 0, a: preview?.people?.accountless || 0 })"
      :max-width="640"
    >
      <template v-if="preview">
        <div
          v-for="group in ['added', 'modified', 'removed']"
          :key="group"
        >
          <template v-if="preview.changes[group].length">
            <div class="text-overline mt-2">
              {{ t(`requirementsCfg.diff.${group}`) }}
            </div>
            <div
              v-for="c in preview.changes[group]"
              :key="c.kind + c.code"
              class="req-diff"
            >
              {{ labelOf(c.kind, c.code) }}
              <span
                v-if="c.to"
                class="text-medium-emphasis"
              > — {{ t(c.to === 'configurable_required' ? 'requirementsCfg.state.required' : 'requirementsCfg.state.optional') }}</span>
            </div>
          </template>
        </div>
        <p
          v-if="!preview.changes.added.length && !preview.changes.modified.length && !preview.changes.removed.length"
          class="text-medium-emphasis mb-0"
        >
          {{ t('requirementsCfg.diff.none') }}
        </p>
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
          @click="previewOpen = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="saving"
          data-test="requirements-save"
          @click="save"
        >
          {{ t('requirementsCfg.saveBtn') }}
        </VBtn>
      </template>
    </AppDialog>
  </div>
</template>

<style scoped>
/* ADR-792 — composition ADR-782 : en-tête, barre d'outils, groupes « titre + liste », lignes compactes. */
.req-page { padding-block-end: 72px; }
.req-toolbar { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-block-end: 24px; }
.req-toolbar__select { flex: 0 1 360px; min-inline-size: 240px; }
.req-toolbar__meta { font-size: 0.8125rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.req-row { display: flex; align-items: center; gap: 12px; min-block-size: 56px; padding: 8px 12px 8px 20px; }
.req-row + .req-row { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.req-row--pending { background: rgba(var(--v-theme-primary), 0.04); }
.req-row__main { flex: 1; min-inline-size: 0; }
.req-row__title { display: flex; align-items: center; font-size: 0.9375rem; font-weight: 500; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.req-row__sub { font-size: 0.8125rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.req-row__state { flex: none; text-transform: none; letter-spacing: normal; }
.req-dot { display: inline-block; inline-size: 8px; block-size: 8px; margin-inline-end: 8px; border-radius: 50%; }
.req-dot--warning { background: rgb(var(--v-theme-warning)); }
.req-dot--info { background: rgb(var(--v-theme-info)); }
.req-dot--success { background: rgb(var(--v-theme-success)); }
.req-dot--error { background: rgb(var(--v-theme-error)); }
.req-empty { margin: 0; padding: 16px 20px; font-size: 0.875rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.req-add { display: flex; align-items: center; gap: 8px; padding: 12px 20px; border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.req-add__input { flex: 1; max-inline-size: 420px; }
.req-legacy-toggle { padding: 14px 20px; font-size: 0.875rem; color: rgb(var(--v-theme-primary)); background: none; border: 0; cursor: pointer; text-align: start; inline-size: 100%; }
.req-legacy-toggle:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: -2px; }
.req-footer { position: sticky; inset-block-end: 16px; z-index: 2; display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-block-start: 24px; padding: 10px 16px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 10px; background: rgb(var(--v-theme-surface)); box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); }
.req-diff { padding: 4px 0; font-size: 0.9375rem; }
@media (max-width: 599px) {
  .req-row { flex-wrap: wrap; padding-inline-start: 16px; }
  .req-row__main { flex-basis: 100%; }
  .req-add { flex-wrap: wrap; padding-inline: 16px; }
  .req-add__input { max-inline-size: none; flex-basis: 100%; }
}
</style>
