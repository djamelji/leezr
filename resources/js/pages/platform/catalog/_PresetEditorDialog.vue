<script setup>
// ADR-798 — éditeurs SPÉCIALISÉS de presets (brouillons uniquement) : RBAC (rôles, lots de permissions,
// propriétés métier), Exigences (sujet personne / entreprise / représentant légal, catalogue, niveau, postes,
// étape, provenance), Planning (modèles d'horaires, semaines types). Pas d'éditeur JSON ; validation serveur ;
// contrôle de concurrence (révision) ; aucune société modifiée. L'Organisation garde son éditeur dédié.
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  isDialogVisible: { type: Boolean, default: false },
  preset: { type: Object, default: null }, // brouillon existant, ou { kind } pour une création
})
const emit = defineEmits(['update:isDialogVisible', 'saved'])
const { t, te } = useI18n()
const { toast } = useAppToast()

const meta = ref(null)
const busy = ref(false)
const errors = ref({})
const conflict = ref(false)
const form = ref({ label: '', family_key: '', description: '' })
const roles = ref([])
const items = ref([])
const library = ref({ fields: [], documents: [] }) // ADR-820 — bibliothèque de départ
const libraryList = ref('fields')
const librarySearch = ref('')
const libraryPick = ref(null)
const planning = ref({ coverage_categories: [], activity_presets: [], schedule_templates: [], week_templates: [] })

const kind = computed(() => props.preset?.kind)
const isNew = computed(() => !props.preset?.id)
const clone = v => JSON.parse(JSON.stringify(v ?? null))
const DAYS = [0, 1, 2, 3, 4, 5, 6]

watch(() => props.isDialogVisible, async v => {
  if (!v) return
  errors.value = {}
  conflict.value = false
  meta.value ??= await $platformApi('/presets/meta')
  const c = clone(props.preset?.content) || {}

  form.value = { label: props.preset?.label || '', family_key: props.preset?.family_key || '', description: props.preset?.description || '' }
  roles.value = (c.roles || []).map(r => ({ ...r, bundles: r.bundles || [] }))
  items.value = (c.items || []).map(i => ({ subject: 'person', job_role_keys: [], ...i }))
  library.value = { fields: (c.fields || []).map(i => ({ subject: 'person', included: true, ...i })), documents: (c.documents || []).map(i => ({ subject: 'person', included: true, ...i })) }
  planning.value = { coverage_categories: c.coverage_categories || [], activity_presets: c.activity_presets || [], schedule_templates: c.schedule_templates || [], week_templates: c.week_templates || [] }
}, { immediate: true })

// ── RBAC ──
const bundleItems = computed(() => (meta.value?.bundles || []).map(b => ({ value: b.key, title: `${b.module} · ${b.label}${b.sensitive || b.is_admin ? ' ⚠' : ''}` })))
const sensitiveBundle = key => (meta.value?.bundles || []).some(b => b.key === key && (b.sensitive || b.is_admin))
const roleIsSensitive = r => r.is_administrative || r.bundles.some(sensitiveBundle)
const addRole = () => roles.value.push({ key: '', name: '', bundles: [], is_administrative: false })

// ── Exigences ──
const SUBJECTS = ['person', 'company', 'legal_representative']
const LEVELS = ['recommended', 'default', 'configurable_required']
const targetItems = i => {
  const scope = i.subject === 'company' ? 'company' : 'person'
  const src = i.kind === 'document' ? meta.value?.documents : meta.value?.fields

  return (src || []).filter(x => x.subject === scope).map(x => ({ value: x.code, title: `${x.label} (${x.code})` }))
}
const positionItems = computed(() => (meta.value?.positions || []).map(p => ({ value: p.key, title: p.label ? `${p.label} (${p.key})` : p.key })))
const addItem = () => items.value.push({ subject: 'person', kind: 'document', code: null, level: 'recommended', job_role_keys: [], origin: 'manual' })
const onSubject = i => { i.code = null; if (i.subject === 'company') i.job_role_keys = [] }

// ── Bibliothèque (ADR-820) : disponible ≠ demandé — aucune notion d'obligation ici ──
const libraryKind = computed(() => (libraryList.value === 'fields' ? 'field' : 'document'))
const metaFor = (list, i) => (list === 'fields' ? meta.value?.fields : meta.value?.documents)?.find(x => x.code === i.code && x.subject === i.subject)
const docLabel = code => (te(`documents.type.${code}`) ? t(`documents.type.${code}`) : null)
const libraryRows = computed(() => library.value[libraryList.value].map((i, idx) => ({ ...i, idx, label: (libraryList.value === 'documents' && docLabel(i.code)) || metaFor(libraryList.value, i)?.label || i.code })))
const libraryChoices = computed(() => {
  const taken = new Set(library.value[libraryList.value].map(i => `${i.subject}>${i.code}`))

  return ((libraryList.value === 'fields' ? meta.value?.fields : meta.value?.documents) || [])
    .filter(x => (x.subject === 'person' || x.subject === 'company') && !taken.has(`${x.subject}>${x.code}`))
    .map(x => ({ value: `${x.subject}>${x.code}`, title: (libraryList.value === 'documents' && docLabel(x.code)) || x.label, subtitle: t(`presetEditor.librarySubject.${x.subject}`) }))
})
const addLibraryItem = () => {
  if (!libraryPick.value) return
  const [subject, code] = libraryPick.value.split('>')

  library.value[libraryList.value].push({ code, subject, included: true })
  libraryPick.value = null
}
const LIBRARY_HEADERS = computed(() => [
  { title: t('presetEditor.libraryItem'), key: 'label' },
  { title: t('presetEditor.librarySubjectLabel'), key: 'subject', width: 160 },
  { title: t('presetEditor.libraryIncluded'), key: 'included', width: 190, sortable: false },
  { title: '', key: 'actions', width: 56, sortable: false },
])

// ── Planning ──
const scheduleCodes = computed(() => planning.value.schedule_templates.map(s => ({ value: s.code, title: s.name || s.code })))
const categoryCodes = computed(() => planning.value.coverage_categories.map(s => ({ value: s.code, title: s.name || s.code })))
const slug = s => (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 60)
const addSchedule = () => planning.value.schedule_templates.push({ code: '', name: '', start_time: '08:00', end_time: '16:00', break_duration_minutes: 0 })
const addWeek = () => planning.value.week_templates.push({ code: '', name: '', strategy: 'needs', lines: [] })
const addLine = w => w.lines.push({ kind: 'needs', day_index: 0, headcount: 1, schedule_template_code: scheduleCodes.value[0]?.value || null })

const content = () => {
  if (kind.value === 'rbac') return { roles: roles.value.map(r => ({ ...r, key: r.key || slug(r.name) })) }
  if (kind.value === 'catalog') return { fields: library.value.fields.map(({ code, subject, included, order }) => ({ code, subject, included, ...(order != null ? { order } : {}) })), documents: library.value.documents.map(({ code, subject, included, order }) => ({ code, subject, included, ...(order != null ? { order } : {}) })) }
  if (kind.value === 'requirements') {
    return { items: items.value.map(i => {
      const out = { ...i }
      if (out.subject === 'person') delete out.subject
      if (!out.job_role_keys?.length) delete out.job_role_keys
      if (!out.stage) delete out.stage

      return out
    }) }
  }
  const p = clone(planning.value)
  for (const s of ['coverage_categories', 'activity_presets', 'schedule_templates', 'week_templates']) p[s] = p[s].map(x => ({ ...x, code: x.code || slug(x.name) }))

  return p
}

const save = async () => {
  busy.value = true
  errors.value = {}
  try {
    const saved = isNew.value
      ? await $platformApi('/presets', { method: 'POST', body: { kind: kind.value, family_key: form.value.family_key || slug(form.value.label), label: form.value.label, description: form.value.description || null, content: content() } })
      : await $platformApi(`/presets/${props.preset.id}`, { method: 'PUT', body: { label: form.value.label, description: form.value.description || null, content: content(), revision: props.preset.revision ?? 0 } })

    toast(t('presetEditor.saved'), 'success')
    emit('saved', saved)
    emit('update:isDialogVisible', false)
  }
  catch (e) {
    if (e?.status === 409 || e?.data?.code === 'stale_revision') conflict.value = true
    errors.value = e?.data?.errors || {}
    toast(e?.data?.message || t('presetEditor.invalid'), 'error')
  }
  finally { busy.value = false }
}
const err = path => Object.entries(errors.value).filter(([k]) => k === path || k.startsWith(`${path}.`)).map(([, v]) => v[0]).join(' ')
</script>

<template>
  <AppDialog
    :model-value="isDialogVisible"
    :title="isNew ? t('presetEditor.newTitle', { family: t(`jobdomainProfile.family.${kind}`) }) : t('presetEditor.editTitle', { name: preset?.label, version: preset?.version })"
    :subtitle="t('presetEditor.subtitle')"
    :max-width="980"
    @update:model-value="v => emit('update:isDialogVisible', v)"
  >
    <div
      v-if="meta"
      data-test="preset-editor"
    >
      <VAlert
        v-if="conflict"
        type="warning"
        variant="tonal"
        class="mb-4"
        data-test="preset-editor-conflict"
      >
        {{ t('presetEditor.conflict') }}
      </VAlert>
      <VRow>
        <VCol
          cols="12"
          md="6"
        >
          <AppTextField
            v-model="form.label"
            :label="t('presetEditor.label')"
            data-test="preset-editor-label"
          />
        </VCol>
        <VCol
          v-if="isNew"
          cols="12"
          md="6"
        >
          <AppTextField
            v-model="form.family_key"
            :label="t('presetEditor.familyKey')"
            :placeholder="slug(form.label)"
            :hint="t('presetEditor.familyKeyHint')"
            persistent-hint
          />
        </VCol>
        <VCol cols="12">
          <AppTextarea
            v-model="form.description"
            rows="2"
            :label="t('presetEditor.description')"
          />
        </VCol>
      </VRow>

      <!-- ── RBAC ── -->
      <template v-if="kind === 'rbac'">
        <div class="d-flex align-center mt-4 mb-2">
          <div class="text-overline flex-grow-1">
            {{ t('presetEditor.roles', { n: roles.length }) }}
          </div>
          <VBtn
            size="small"
            variant="tonal"
            prepend-icon="tabler-plus"
            data-test="preset-add-role"
            @click="addRole"
          >
            {{ t('presetEditor.addRole') }}
          </VBtn>
        </div>
        <div
          v-for="(r, i) in roles"
          :key="i"
          class="pe-block"
          :data-test="`preset-role-${i}`"
        >
          <VRow dense>
            <VCol
              cols="12"
              md="4"
            >
              <AppTextField
                v-model="r.name"
                :label="t('presetEditor.roleName')"
                :error-messages="err(`roles.${i}.name`)"
              />
            </VCol>
            <VCol
              cols="12"
              md="3"
            >
              <AppTextField
                v-model="r.key"
                :label="t('presetEditor.roleKey')"
                :placeholder="slug(r.name)"
                :error-messages="err(`roles.${i}.key`)"
              />
            </VCol>
            <VCol
              cols="12"
              md="3"
            >
              <AppSelect
                v-model="r.archetype"
                :items="meta.archetypes.map(a => ({ value: a, title: t(`presetEditor.archetype.${a}`, a) }))"
                clearable
                :label="t('presetEditor.archetypeLabel')"
              />
            </VCol>
            <VCol
              cols="12"
              md="2"
              class="d-flex align-center"
            >
              <VSwitch
                v-model="r.is_administrative"
                hide-details
                density="compact"
                :label="t('presetEditor.administrative')"
              />
            </VCol>
            <VCol cols="12">
              <AppAutocomplete
                v-model="r.bundles"
                :items="bundleItems"
                multiple
                chips
                closable-chips
                :label="t('presetEditor.bundles')"
                :error-messages="err(`roles.${i}.bundles`)"
              />
            </VCol>
          </VRow>
          <div class="d-flex align-center gap-2">
            <span
              v-if="roleIsSensitive(r)"
              class="text-body-2 text-warning flex-grow-1"
            >{{ t('presetEditor.sensitiveRole') }}</span>
            <span
              v-else
              class="flex-grow-1"
            />
            <VBtn
              size="small"
              variant="text"
              color="error"
              @click="roles.splice(i, 1)"
            >
              {{ t('presetEditor.remove') }}
            </VBtn>
          </div>
        </div>
      </template>

      <!-- ── Exigences ── -->
      <template v-else-if="kind === 'catalog'">
        <p class="text-body-2 text-medium-emphasis mt-4 mb-2">
          {{ t('presetEditor.libraryHint') }}
        </p>
        <VTabs
          v-model="libraryList"
          class="border-b mb-3"
          density="compact"
        >
          <VTab value="fields">
            {{ t('presetEditor.libraryFields', { n: library.fields.length }) }}
          </VTab>
          <VTab value="documents">
            {{ t('presetEditor.libraryDocuments', { n: library.documents.length }) }}
          </VTab>
        </VTabs>
        <div class="d-flex flex-wrap gap-2 align-center mb-3">
          <AppAutocomplete
            v-model="libraryPick"
            :items="libraryChoices"
            item-props
            :placeholder="t('presetEditor.libraryAdd')"
            style="flex: 1 1 280px"
            data-test="library-pick"
          />
          <VBtn
            variant="tonal"
            prepend-icon="tabler-plus"
            :disabled="!libraryPick"
            data-test="library-add"
            @click="addLibraryItem"
          >
            {{ t('presetEditor.libraryAddButton') }}
          </VBtn>
          <AppTextField
            v-model="librarySearch"
            prepend-inner-icon="tabler-search"
            :placeholder="t('presetEditor.librarySearch')"
            style="flex: 0 1 220px"
          />
        </div>
        <VDataTable
          :headers="LIBRARY_HEADERS"
          :items="libraryRows"
          :search="librarySearch"
          :items-per-page="8"
          density="compact"
          class="text-no-wrap"
          data-test="library-table"
        >
          <template #item.subject="{ item }">
            {{ t(`presetEditor.librarySubject.${item.subject}`) }}
          </template>
          <template #item.included="{ item }">
            <VSwitch
              :model-value="item.included"
              :label="item.included ? t('presetEditor.libraryActive') : t('presetEditor.librarySuggested')"
              density="compact"
              hide-details
              @update:model-value="v => (library[libraryList][item.idx].included = !!v)"
            />
          </template>
          <template #item.actions="{ item }">
            <IconBtn
              :aria-label="t('presetEditor.remove')"
              @click="library[libraryList].splice(item.idx, 1)"
            >
              <VIcon icon="tabler-trash" />
            </IconBtn>
          </template>
        </VDataTable>
        <div
          v-if="err('fields') || err('documents')"
          class="text-error text-body-2 mt-2"
        >
          {{ err('fields') }} {{ err('documents') }}
        </div>
      </template>

      <template v-else-if="kind === 'requirements'">
        <VAlert
          type="info"
          variant="tonal"
          density="compact"
          class="mt-4"
        >
          {{ t('presetEditor.requirementsHint') }}
        </VAlert>
        <div class="d-flex align-center mt-4 mb-2">
          <div class="text-overline flex-grow-1">
            {{ t('presetEditor.items', { n: items.length }) }}
          </div>
          <VBtn
            size="small"
            variant="tonal"
            prepend-icon="tabler-plus"
            data-test="preset-add-item"
            @click="addItem"
          >
            {{ t('presetEditor.addItem') }}
          </VBtn>
        </div>
        <div
          v-for="(it, i) in items"
          :key="i"
          class="pe-block"
          :data-test="`preset-item-${i}`"
        >
          <VRow dense>
            <VCol
              cols="12"
              sm="6"
              md="3"
            >
              <AppSelect
                v-model="it.subject"
                :items="SUBJECTS.map(s => ({ value: s, title: t(`presetEditor.subject.${s}`) }))"
                :label="t('presetEditor.subjectLabel')"
                @update:model-value="onSubject(it)"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
              md="2"
            >
              <AppSelect
                v-model="it.kind"
                :items="[{ value: 'document', title: t('presetEditor.document') }, { value: 'field', title: t('presetEditor.field') }]"
                :label="t('presetEditor.kind')"
                @update:model-value="it.code = null"
              />
            </VCol>
            <VCol
              cols="12"
              md="4"
            >
              <AppAutocomplete
                v-model="it.code"
                :items="targetItems(it)"
                :label="t('presetEditor.target')"
                :error-messages="err(`items.${i}.code`)"
              />
            </VCol>
            <VCol
              cols="12"
              md="3"
            >
              <AppSelect
                v-model="it.level"
                :items="LEVELS.map(l => ({ value: l, title: t(`presetEditor.level.${l}`) }))"
                :label="t('presetEditor.levelLabel')"
                :error-messages="err(`items.${i}.level`)"
              />
            </VCol>
            <VCol
              v-if="it.subject === 'person'"
              cols="12"
              md="6"
            >
              <AppAutocomplete
                v-model="it.job_role_keys"
                :items="positionItems"
                multiple
                chips
                closable-chips
                :label="t('presetEditor.positions')"
                :hint="t('presetEditor.positionsHint')"
                persistent-hint
              />
            </VCol>
            <VCol
              cols="12"
              md="3"
            >
              <AppSelect
                v-model="it.stage"
                :items="meta.stages.map(s => ({ value: s, title: t(`presetEditor.stage.${s}`) }))"
                clearable
                :label="t('presetEditor.stageLabel')"
              />
            </VCol>
          </VRow>
          <div class="d-flex align-center gap-2">
            <span class="text-body-2 text-medium-emphasis flex-grow-1">{{ t('presetEditor.origin') }} : {{ it.origin?.startsWith('legacy:') ? t('presetEditor.originLegacy', { jd: it.origin.slice(7) }) : t('presetEditor.originManual') }}</span>
            <VBtn
              size="small"
              variant="text"
              color="error"
              @click="items.splice(i, 1)"
            >
              {{ t('presetEditor.remove') }}
            </VBtn>
          </div>
        </div>
      </template>

      <!-- ── Planning ── -->
      <template v-else-if="kind === 'planning'">
        <VAlert
          type="info"
          variant="tonal"
          density="compact"
          class="mt-4"
        >
          {{ t('presetEditor.planningHint', { categories: planning.coverage_categories.length, activities: planning.activity_presets.length }) }}
        </VAlert>
        <div class="d-flex align-center mt-4 mb-2">
          <div class="text-overline flex-grow-1">
            {{ t('presetEditor.schedules', { n: planning.schedule_templates.length }) }}
          </div>
          <VBtn
            size="small"
            variant="tonal"
            prepend-icon="tabler-plus"
            data-test="preset-add-schedule"
            @click="addSchedule"
          >
            {{ t('presetEditor.addSchedule') }}
          </VBtn>
        </div>
        <div
          v-for="(s, i) in planning.schedule_templates"
          :key="`s${i}`"
          class="pe-block"
          :data-test="`preset-schedule-${i}`"
        >
          <VRow dense>
            <VCol
              cols="12"
              md="4"
            >
              <AppTextField
                v-model="s.name"
                :label="t('presetEditor.scheduleName')"
                :error-messages="err(`schedule_templates.${i}`)"
              />
            </VCol>
            <VCol
              cols="6"
              md="2"
            >
              <AppTextField
                v-model="s.start_time"
                type="time"
                :label="t('presetEditor.start')"
              />
            </VCol>
            <VCol
              cols="6"
              md="2"
            >
              <AppTextField
                v-model="s.end_time"
                type="time"
                :label="t('presetEditor.end')"
              />
            </VCol>
            <VCol
              cols="12"
              md="3"
            >
              <AppSelect
                v-model="s.coverage_category_code"
                :items="categoryCodes"
                clearable
                :label="t('presetEditor.category')"
              />
            </VCol>
            <VCol
              cols="12"
              md="1"
              class="d-flex align-center"
            >
              <IconBtn
                :aria-label="t('presetEditor.remove')"
                @click="planning.schedule_templates.splice(i, 1)"
              >
                <VIcon icon="tabler-trash" />
              </IconBtn>
            </VCol>
          </VRow>
          <div
            v-if="s.composition?.activities?.length"
            class="text-body-2 text-medium-emphasis"
          >
            {{ t('presetEditor.composition', { n: s.composition.activities.length }) }}
          </div>
        </div>

        <div class="d-flex align-center mt-4 mb-2">
          <div class="text-overline flex-grow-1">
            {{ t('presetEditor.weeks', { n: planning.week_templates.length }) }}
          </div>
          <VBtn
            size="small"
            variant="tonal"
            prepend-icon="tabler-plus"
            @click="addWeek"
          >
            {{ t('presetEditor.addWeek') }}
          </VBtn>
        </div>
        <div
          v-for="(w, wi) in planning.week_templates"
          :key="`w${wi}`"
          class="pe-block"
        >
          <div class="d-flex gap-2 align-center">
            <AppTextField
              v-model="w.name"
              :label="t('presetEditor.weekName')"
              class="flex-grow-1"
            />
            <IconBtn
              :aria-label="t('presetEditor.remove')"
              @click="planning.week_templates.splice(wi, 1)"
            >
              <VIcon icon="tabler-trash" />
            </IconBtn>
          </div>
          <div
            v-for="(l, li) in w.lines"
            :key="li"
            class="d-flex flex-wrap gap-2 align-center mt-2"
          >
            <AppSelect
              v-model="l.day_index"
              :items="DAYS.map(d => ({ value: d, title: t(`presetEditor.day.${d}`) }))"
              density="compact"
              style="flex: 1 1 140px"
              :label="t('presetEditor.dayLabel')"
            />
            <AppSelect
              v-model="l.schedule_template_code"
              :items="scheduleCodes"
              density="compact"
              style="flex: 2 1 200px"
              :label="t('presetEditor.schedule')"
            />
            <AppTextField
              v-model.number="l.headcount"
              type="number"
              min="1"
              density="compact"
              style="flex: 0 1 110px"
              :label="t('presetEditor.headcount')"
            />
            <IconBtn
              :aria-label="t('presetEditor.remove')"
              @click="w.lines.splice(li, 1)"
            >
              <VIcon icon="tabler-x" />
            </IconBtn>
          </div>
          <VBtn
            size="small"
            variant="text"
            class="mt-1"
            @click="addLine(w)"
          >
            {{ t('presetEditor.addLine') }}
          </VBtn>
        </div>
      </template>

      <p
        v-if="err('content') || err('roles') || err('items')"
        class="text-error text-body-2 mt-3"
      >
        {{ err('content') || err('roles') || err('items') }}
      </p>
    </div>

    <template #actions>
      <VBtn
        variant="text"
        @click="emit('update:isDialogVisible', false)"
      >
        {{ t('common.cancel') }}
      </VBtn>
      <VBtn
        color="primary"
        :loading="busy"
        :disabled="!form.label"
        data-test="preset-editor-save"
        @click="save"
      >
        {{ isNew ? t('presetEditor.create') : t('presetEditor.save') }}
      </VBtn>
    </template>
  </AppDialog>
</template>

<style scoped>
.pe-block { padding-block: 12px; }
.pe-block + .pe-block { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
</style>
