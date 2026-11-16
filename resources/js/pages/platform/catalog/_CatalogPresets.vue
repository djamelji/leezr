<script setup>
// ADR-797 (L7) — administration des presets versionnés (RBAC, Exigences, Planning ; l'Organisation garde son
// éditeur dédié). Consulter, filtrer par famille, versions, contenu (aperçu par élément), comparer deux
// versions, publier un brouillon, créer une nouvelle version, archiver, prévisualiser sur une société
// (lecture seule). Pas d'éditeur JSON : un brouillon naît d'une version existante ou de la configuration
// historique d'un JobDomain. Sub-component — hub : platform/catalog/[tab].vue.
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import PresetEditorDialog from './_PresetEditorDialog.vue'

const { t } = useI18n()
const route = useRoute()
const { toast } = useAppToast()
const auth = usePlatformAuthStore()
// ADR-798 : actions affichées selon les droits réels (l'API les vérifie de toute façon).
const can = p => auth.hasPermission('manage_jobdomains') || auth.hasPermission(p)
const editor = ref(false)
const editing = ref(null)
const blocked = ref(null)

const KINDS = ['rbac', 'requirements', 'planning']
const kind = ref(KINDS.includes(route.query.kind) ? route.query.kind : null)
const presets = ref([])
const loading = ref(false)
const drawer = ref(false)
const detail = ref(null)
const busy = ref(false)
const diff = ref(null)
const companyId = ref('')
const preview = ref(null)

const load = async () => {
  loading.value = true
  try { presets.value = (await $platformApi('/presets')).presets || [] }
  finally { loading.value = false }
}
onMounted(load)

const rows = computed(() => presets.value.filter(p => !kind.value || p.kind === kind.value))
const headers = computed(() => [
  { title: t('presetAdmin.preset'), key: 'label' },
  { title: t('presetAdmin.family'), key: 'kind' },
  { title: t('presetAdmin.version'), key: 'version', align: 'center' },
  { title: t('presetAdmin.status'), key: 'status' },
  { title: t('presetAdmin.jobdomain'), key: 'jobdomain_key' },
])
const tone = s => ({ published: 'success', draft: 'warning', archived: 'info' }[s] || 'info')

const open = async p => {
  drawer.value = true
  diff.value = null
  blocked.value = null
  preview.value = null
  detail.value = await $platformApi(`/presets/${p.kind}/${p.id}`)
}
const reopen = async id => {
  await load()
  const p = presets.value.find(x => x.id === id)
  if (p) await open(p)
}

const act = async (fn, msg) => {
  busy.value = true
  try {
    const res = await fn()

    toast(msg, 'success')

    return res
  }
  catch (e) { toast(e?.data?.message || Object.values(e?.data?.errors || {})[0]?.[0] || t('presetAdmin.error'), 'error') }
  finally { busy.value = false }
}
const publish = async () => {
  const p = detail.value.preset
  if (await act(() => $platformApi(`/presets/${p.kind}/${p.id}/publish`, { method: 'POST' }), t('presetAdmin.published'))) await reopen(p.id)
}
const newVersion = async () => {
  const res = await act(() => $platformApi(`/presets/${detail.value.preset.id}/new-version`, { method: 'POST' }), t('presetAdmin.versioned'))
  if (res) await reopen(res.id)
}
const archive = async () => {
  const p = detail.value.preset

  blocked.value = null
  busy.value = true
  try {
    await $platformApi(`/presets/${p.id}/archive`, { method: 'POST' })
    toast(t('presetAdmin.archived'), 'success')
    await reopen(p.id)
  }
  catch (e) {
    if (e?.data?.code === 'recommendation_would_break') blocked.value = e.data
    else toast(e?.data?.message || t('presetAdmin.error'), 'error')
  }
  finally { busy.value = false }
}
const openEditor = preset => {
  editing.value = preset
  editor.value = true
}
const onSaved = async saved => {
  await reopen(saved.id)
  // Prévisualisation des changements : comparaison avec la version précédente de la famille.
  const prev = detail.value?.versions.find(v => v.version === saved.version - 1)
  if (prev) diff.value = await $platformApi(`/presets/${saved.kind}/${prev.id}/diff/${saved.id}`)
}
const compare = async other => {
  const p = detail.value.preset
  const [from, to] = other.version < p.version ? [other.id, p.id] : [p.id, other.id]

  diff.value = await $platformApi(`/presets/${p.kind}/${from}/diff/${to}`)
}
const runPreview = async () => {
  const p = detail.value.preset
  try { preview.value = await $platformApi(`/presets/${p.kind}/${p.id}/companies/${companyId.value}/preview`) }
  catch { toast(t('presetAdmin.companyNotFound'), 'error') }
}
</script>

<template>
  <div>
    <div class="d-flex flex-wrap align-center gap-2 mb-4">
      <VChip
        :color="!kind ? 'primary' : undefined"
        variant="tonal"
        @click="kind = null"
      >
        {{ t('presetAdmin.all') }}
      </VChip>
      <VChip
        v-for="k in KINDS"
        :key="k"
        :color="kind === k ? 'primary' : undefined"
        variant="tonal"
        :data-test="`preset-filter-${k}`"
        @click="kind = k"
      >
        {{ t(`jobdomainProfile.family.${k}`) }}
      </VChip>
      <VSpacer />
      <VMenu v-if="can('presets.edit')">
        <template #activator="{ props: menu }">
          <VBtn
            v-bind="menu"
            size="small"
            color="primary"
            prepend-icon="tabler-plus"
            data-test="preset-new"
          >
            {{ t('presetEditor.newPreset') }}
          </VBtn>
        </template>
        <VList density="compact">
          <VListItem
            v-for="k in KINDS"
            :key="k"
            :title="t(`jobdomainProfile.family.${k}`)"
            :data-test="`preset-new-${k}`"
            @click="openEditor({ kind: k })"
          />
        </VList>
      </VMenu>
      <RouterLink
        to="/platform/organization-presets"
        class="text-body-2"
      >
        {{ t('presetAdmin.organizationEditor') }}
      </RouterLink>
    </div>
    <VAlert
      type="info"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      {{ t('presetAdmin.hint') }}
    </VAlert>

    <VCard>
      <VDataTable
        :headers="headers"
        :items="rows"
        :loading="loading"
        :items-per-page="25"
        hover
        data-test="preset-table"
        @click:row="(_, { item }) => open(item)"
      >
        <template #item.label="{ item }">
          <div class="text-body-1">
            {{ item.label }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ item.family_key }}
          </div>
        </template>
        <template #item.kind="{ item }">
          {{ t(`jobdomainProfile.family.${item.kind}`) }}
        </template>
        <template #item.version="{ item }">
          v{{ item.version }}
        </template>
        <template #item.status="{ item }">
          <AppStatusText
            :tone="tone(item.status)"
            :text="t(`jobdomainProfile.status.${item.status}`)"
          />
        </template>
        <template #item.jobdomain_key="{ item }">
          {{ item.jobdomain_key || '—' }}
        </template>
        <template #no-data>
          <div class="pa-6 text-body-2 text-medium-emphasis">
            {{ t('presetAdmin.empty') }}
          </div>
        </template>
      </VDataTable>
    </VCard>

    <VNavigationDrawer
      v-model="drawer"
      location="end"
      temporary
      width="520"
      class="preset-drawer"
    >
      <div
        v-if="detail"
        class="pa-5"
        data-test="preset-detail"
      >
        <div class="d-flex align-start gap-2 mb-1">
          <div class="flex-grow-1">
            <h2 class="text-h5">
              {{ detail.preset.label }}
            </h2>
            <div class="text-body-2 text-medium-emphasis">
              {{ t(`jobdomainProfile.family.${detail.preset.kind}`) }} · {{ detail.preset.family_key }} · v{{ detail.preset.version }}
            </div>
          </div>
          <IconBtn
            :aria-label="t('presetAdmin.close')"
            @click="drawer = false"
          >
            <VIcon icon="tabler-x" />
          </IconBtn>
        </div>
        <AppStatusText
          :tone="tone(detail.preset.status)"
          :text="t(`jobdomainProfile.status.${detail.preset.status}`)"
        />
        <p
          v-if="detail.preset.description"
          class="text-body-2 mt-2"
        >
          {{ detail.preset.description }}
        </p>
        <div class="text-body-2 text-medium-emphasis mt-2">
          {{ detail.recommended_by.length ? t('presetAdmin.recommendedBy', { list: detail.recommended_by.map(r => r.jobdomain_key + (r.pinned_version ? ` (v${r.pinned_version})` : '')).join(', ') }) : t('presetAdmin.notRecommended') }}
        </div>

        <div class="d-flex flex-wrap gap-2 my-4">
          <VBtn
            v-if="detail.preset.status === 'draft' && can('presets.edit')"
            variant="tonal"
            size="small"
            prepend-icon="tabler-edit"
            data-test="preset-edit"
            @click="openEditor(detail.preset)"
          >
            {{ t('presetEditor.edit') }}
          </VBtn>
          <VBtn
            v-if="detail.preset.status === 'draft' && can('presets.publish')"
            color="primary"
            size="small"
            :loading="busy"
            data-test="preset-publish"
            @click="publish"
          >
            {{ t('presetAdmin.publish') }}
          </VBtn>
          <VBtn
            v-if="detail.preset.status !== 'draft' && can('presets.edit')"
            variant="tonal"
            size="small"
            :loading="busy"
            @click="newVersion"
          >
            {{ t('presetAdmin.newVersion') }}
          </VBtn>
          <VBtn
            v-if="detail.preset.status !== 'archived' && can('presets.publish')"
            variant="text"
            size="small"
            :loading="busy"
            data-test="preset-archive"
            @click="archive"
          >
            {{ t('presetAdmin.archive') }}
          </VBtn>
        </div>
        <VAlert
          v-if="blocked"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-4"
          data-test="preset-archive-blocked"
        >
          {{ t('presetEditor.archiveBlocked', { list: [...blocked.pinned_by, ...blocked.latest_for].join(', ') }) }}
        </VAlert>

        <div class="text-overline">
          {{ t('presetAdmin.versions') }}
        </div>
        <div
          v-for="v in detail.versions"
          :key="v.id"
          class="preset-row"
        >
          <span class="flex-grow-1">v{{ v.version }} · {{ t(`jobdomainProfile.status.${v.status}`) }}</span>
          <VBtn
            v-if="v.id !== detail.preset.id"
            size="small"
            variant="text"
            @click="compare(v)"
          >
            {{ t('presetAdmin.compare') }}
          </VBtn>
        </div>
        <VAlert
          v-if="diff"
          type="info"
          variant="tonal"
          density="compact"
          class="mt-2"
          data-test="preset-diff"
        >
          v{{ diff.from }} → v{{ diff.to }} :
          {{ t('presetAdmin.diff', { added: diff.added.length, removed: diff.removed.length, changed: diff.changed.length }) }}
          <div
            v-if="diff.added.length"
            class="text-body-2"
          >
            + {{ diff.added.join(', ') }}
          </div>
          <div
            v-if="diff.removed.length"
            class="text-body-2"
          >
            − {{ diff.removed.join(', ') }}
          </div>
        </VAlert>

        <div class="text-overline mt-4">
          {{ t('presetAdmin.items', { n: detail.items.length }) }}
        </div>
        <div
          v-for="i in detail.items"
          :key="i.key"
          class="preset-row"
        >
          <div class="flex-grow-1">
            <div class="text-body-1">
              {{ i.label }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ i.key }}<template v-if="i.detail">
                · {{ i.detail }}
              </template>
            </div>
          </div>
        </div>

        <div
          v-if="can('presets.preview_company')"
          class="text-overline mt-4"
        >
          {{ t('presetAdmin.previewTitle') }}
        </div>
        <div
          v-if="can('presets.preview_company')"
          class="d-flex gap-2 align-center"
        >
          <AppTextField
            v-model="companyId"
            type="number"
            density="compact"
            :label="t('presetAdmin.companyId')"
          />
          <VBtn
            size="small"
            variant="tonal"
            :disabled="!companyId"
            @click="runPreview"
          >
            {{ t('presetAdmin.preview') }}
          </VBtn>
        </div>
        <div
          v-if="preview"
          class="mt-2"
          data-test="preset-preview"
        >
          <div class="text-body-2 text-medium-emphasis">
            {{ t('presetAdmin.previewHint') }}<template v-if="preview.last_applied_version">
              · {{ t('presetAdmin.lastApplied', { v: preview.last_applied_version }) }}
            </template>
          </div>
          <div
            v-for="i in preview.items"
            :key="i.key"
            class="preset-row"
          >
            <span class="flex-grow-1 text-body-2">{{ i.label }}</span>
            <AppStatusText
              :tone="i.status === 'identical' ? 'success' : (i.status === 'new' ? 'info' : 'warning')"
              :text="t(`presetAdmin.itemStatus.${i.status}`)"
            />
          </div>
        </div>
      </div>
    </VNavigationDrawer>
    <PresetEditorDialog
      v-model:is-dialog-visible="editor"
      :preset="editing"
      @saved="onSaved"
    />
  </div>
</template>

<style scoped>
.preset-row { display: flex; align-items: center; gap: 8px; padding-block: 6px; }
.preset-row + .preset-row { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
</style>
