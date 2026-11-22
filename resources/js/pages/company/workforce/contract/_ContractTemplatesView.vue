<script setup>
// W-CONTRACT-1 (ADR-647) — bibliothèque de modèles de contrat : consultation, duplication d'un
// modèle Platform, édition d'un brouillon Company, publication/archivage. (Éditeur WYSIWYG Tiptap
// complet + palette d'insertion visuelle = sous-lot 2 ; sous-lot 1 = édition structurée fiable.)
import { useContractStore } from '@/modules/company/workforce/contract.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'
import ContractEditor from './_ContractEditor.vue'
import { useDisplay } from 'vuetify'
import AppEmptyState from '@/components/app/AppEmptyState.vue'

// ADR-802 — vue embarquée par l'espace Documents (`/company/documents/templates`) ; l'ancienne route redirige.

// PRODUCT-UX-2 (LOT A) — `embedded` : masque l'en-tête de page quand monté comme onglet « Modèles »
// de l'espace Documents. Logique 100 % réutilisée.
defineProps({ embedded: { type: Boolean, default: false } })

const { t } = useI18n()
const { toast } = useAppToast()
const { can } = useCan()
const store = useContractStore()

const loading = ref(false)
const variables = ref([])

const load = async () => {
  loading.value = true
  try { await store.fetchTemplates() } finally { loading.value = false }
}
onMounted(async () => { load(); variables.value = await store.fetchVariables().catch(() => []) })

const sourceColor = s => (s === 'company' ? 'primary' : 'secondary')
const statusColor = s => ({ draft: 'warning', published: 'success', archived: 'secondary' }[s] || 'secondary')

const busyId = ref(null)

// ADR-862 (L7 closure) — the contract type in words (« CDI », not « cdi ») ; one visible action per template, the others
// in a named menu (was up to four buttons of different weights per line) ; a list on a phone.
const { xs } = useDisplay()
const typeLabel = code => (code ? t(`employees.contractTypes.${code}`, String(code).toUpperCase()) : '—')
const canManage = computed(() => can('workforce_contract.manage_templates'))
const actionsOf = tpl => {
  if (!canManage.value) return { main: null, more: [] }
  if (tpl.source === 'platform') return { main: { key: 'duplicate', icon: 'tabler-copy', label: t('contractTpl.duplicate'), run: () => onDuplicate(tpl) }, more: [] }
  if (tpl.status === 'draft') return {
    main: { key: 'publish', icon: 'tabler-circle-check', color: 'success', label: t('contractTpl.publish'), run: () => onPublish(tpl) },
    more: [
      { key: 'edit', icon: 'tabler-edit', label: t('common.edit'), run: () => openEdit(tpl) },
      { key: 'archive', icon: 'tabler-archive', label: t('contractTpl.archive'), run: () => onArchive(tpl), danger: true },
    ],
  }
  if (tpl.status === 'published') return {
    main: { key: 'version', icon: 'tabler-versions', label: t('contractTpl.newVersion'), run: () => onNewVersion(tpl) },
    more: [
      ...(!tpl.is_default ? [{ key: 'default', icon: 'tabler-star', label: t('contractTpl.setDefault'), run: () => onSetDefault(tpl) }] : []),
      { key: 'archive', icon: 'tabler-archive', label: t('contractTpl.archive'), run: () => onArchive(tpl), danger: true },
    ],
  }

  return { main: null, more: [] }
}
const act = async (fn, id, okMsg) => {
  busyId.value = id
  try { await fn(); toast(okMsg, 'success'); await load() }
  catch (e) { toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error') }
  finally { busyId.value = null }
}
const onDuplicate = tpl => act(() => store.duplicate(tpl.id), tpl.id, t('contractTpl.duplicated'))
const onPublish = tpl => act(() => store.publish(tpl.id), tpl.id, t('contractTpl.published'))
const onArchive = tpl => act(() => store.archive(tpl.id), tpl.id, t('contractTpl.archived'))
const onNewVersion = tpl => act(() => store.createVersion(tpl.id), tpl.id, t('contractTpl.versioned'))

// ── Édition brouillon ──
const editOpen = ref(false)
const editTpl = ref(null)
const editForm = ref({ name: '', body_html: '', contract_type: '' })
const editSaving = ref(false)
const openEdit = async tpl => {
  const full = await store.fetchTemplate(tpl.id)
  editTpl.value = full
  editForm.value = { name: full.name, body_html: full.body_html || '', contract_type: full.contract_type || '' }
  editOpen.value = true
}
const editorRef = ref()
// W-CONTRACT-3 : insertion AU CURSEUR (pas en fin) via l'éditeur exposé.
const insertVar = key => { editorRef.value?.insertAtCursor(`{{ ${key} }}`) }
const onSetDefault = tpl => act(() => store.setDefault(tpl.id), tpl.id, t('contractTpl.defaultSet'))

// Palette catégorisée (par préfixe de variable : employee/contract/company/compensation…).
const variablesByCategory = computed(() => {
  const groups = {}
  for (const v of variables.value) {
    const cat = (v.key || '').split('.')[0] || 'autre'
    ;(groups[cat] ||= []).push(v)
  }

  return groups
})

// Aperçu PDF FIDÈLE du contenu EN COURS D'ÉDITION (non enregistré) — même pipeline sécurisé.
const previewing = ref(false)
const previewPdf = async () => {
  previewing.value = true
  try {
    const blob = await store.previewPdf({ templateId: editTpl.value.id, bodyHtml: editForm.value.body_html })
    const url = URL.createObjectURL(blob)

    window.open(url, '_blank')
    setTimeout(() => URL.revokeObjectURL(url), 20000)
  } catch (e) { toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error') }
  finally { previewing.value = false }
}
const saveEdit = async () => {
  editSaving.value = true
  try {
    await store.updateTemplate(editTpl.value.id, editForm.value)
    toast(t('common.saved'), 'success')
    editOpen.value = false
    await load()
  } catch (e) { toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error') }
  finally { editSaving.value = false }
}
</script>

<template>
  <div>
    <div v-if="!embedded" class="mb-6">
      <h4 class="text-h4 font-weight-bold">
        {{ t('contractTpl.title') }}
      </h4>
      <p class="text-body-1 text-medium-emphasis mb-0">
        {{ t('contractTpl.subtitle') }}
      </p>
    </div>

    <VCard data-test="contract-templates">
      <VCardItem>
        <VCardTitle>{{ t('l7.docs.templates.title') }}</VCardTitle>
        <VCardSubtitle class="text-wrap">
          <VIcon
            icon="tabler-info-circle"
            size="16"
            class="me-1"
          />{{ t('l7.docs.templates.hint') }}
        </VCardSubtitle>
      </VCardItem>
      <VCardText
        v-if="loading"
        class="text-center py-8"
      >
        <VProgressCircular
          indeterminate
          color="primary"
        />
      </VCardText>
      <AppEmptyState
        v-else-if="!store.templates.length"
        compact
        icon="tabler-file-text"
        :title="t('l7.docs.templates.emptyTitle')"
        :text="t('l7.docs.templates.emptyText')"
      />
      <template v-else>
        <VList
          v-if="xs"
          class="py-0"
        >
          <template
            v-for="(tpl, i) in store.templates"
            :key="tpl.id"
          >
            <VDivider v-if="i" />
            <VListItem class="py-3">
              <div class="d-flex align-center justify-space-between gap-2">
                <span class="font-weight-medium text-truncate">
                  <VIcon
                    v-if="tpl.is_default"
                    icon="tabler-star-filled"
                    color="primary"
                    size="14"
                    class="me-1"
                    :aria-label="t('contractTpl.default')"
                  />{{ tpl.name }}
                </span>
                <VChip
                  :color="statusColor(tpl.status)"
                  size="small"
                  label
                  variant="tonal"
                >
                  {{ t('contractTpl.status.' + tpl.status, tpl.status) }}
                </VChip>
              </div>
              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ t('l7.docs.templates.meta', { type: typeLabel(tpl.contract_type), version: tpl.version }) }} · {{ t('contractTpl.source.' + tpl.source) }} · {{ t('contractTpl.legal.' + tpl.legal_status, tpl.legal_status) }}
              </div>
              <div
                v-if="actionsOf(tpl).main"
                class="d-flex flex-wrap gap-2 mt-2"
              >
                <VBtn
                  size="small"
                  variant="tonal"
                  :color="actionsOf(tpl).main.color || 'primary'"
                  :prepend-icon="actionsOf(tpl).main.icon"
                  :loading="busyId === tpl.id"
                  @click="actionsOf(tpl).main.run()"
                >
                  {{ actionsOf(tpl).main.label }}
                </VBtn>
                <VBtn
                  v-for="a in actionsOf(tpl).more"
                  :key="a.key"
                  size="small"
                  variant="text"
                  :color="a.danger ? 'error' : 'default'"
                  @click="a.run()"
                >
                  {{ a.label }}
                </VBtn>
              </div>
            </VListItem>
          </template>
        </VList>

        <VTable
          v-else
          class="text-no-wrap"
        >
          <thead>
            <tr>
              <th>{{ t('contractTpl.col.name') }}</th>
              <th>{{ t('contractTpl.col.type') }}</th>
              <th>{{ t('contractTpl.col.source') }}</th>
              <th>{{ t('contractTpl.col.status') }}</th>
              <th>{{ t('contractTpl.col.legal') }}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="tpl in store.templates"
              :key="tpl.id"
              :class="{ 'text-disabled': tpl.status === 'archived' }"
            >
              <td>
                <span class="font-weight-medium">{{ tpl.name }}</span>
                <span class="text-medium-emphasis ms-2">v{{ tpl.version }}</span>
                <VChip
                  v-if="tpl.is_default"
                  size="x-small"
                  color="primary"
                  variant="tonal"
                  prepend-icon="tabler-star-filled"
                  class="ms-2"
                >
                  {{ t('contractTpl.default') }}
                </VChip>
              </td>
              <td>{{ typeLabel(tpl.contract_type) }}</td>
              <td>
                <VChip
                  :color="sourceColor(tpl.source)"
                  size="small"
                  label
                  variant="tonal"
                >
                  {{ t('contractTpl.source.' + tpl.source) }}
                </VChip>
              </td>
              <td>
                <VChip
                  :color="statusColor(tpl.status)"
                  size="small"
                  label
                  variant="tonal"
                >
                  {{ t('contractTpl.status.' + tpl.status, tpl.status) }}
                </VChip>
              </td>
              <td class="text-body-2 text-medium-emphasis">
                {{ t('contractTpl.legal.' + tpl.legal_status, tpl.legal_status) }}
              </td>
              <td>
                <div class="d-flex gap-1 justify-end align-center">
                  <VBtn
                    v-if="actionsOf(tpl).main"
                    size="small"
                    variant="tonal"
                    :color="actionsOf(tpl).main.color || 'primary'"
                    :prepend-icon="actionsOf(tpl).main.icon"
                    :loading="busyId === tpl.id"
                    :data-test="`tpl-${actionsOf(tpl).main.key}-${tpl.id}`"
                    @click="actionsOf(tpl).main.run()"
                  >
                    {{ actionsOf(tpl).main.label }}
                  </VBtn>
                  <VMenu v-if="actionsOf(tpl).more.length">
                    <template #activator="{ props: menu }">
                      <IconBtn
                        v-bind="menu"
                        :aria-label="t('l7.docs.templates.more', { name: tpl.name })"
                        :data-test="`tpl-more-${tpl.id}`"
                      >
                        <VIcon icon="tabler-dots-vertical" />
                      </IconBtn>
                    </template>
                    <VList density="compact">
                      <VListItem
                        v-for="a in actionsOf(tpl).more"
                        :key="a.key"
                        :prepend-icon="a.icon"
                        :base-color="a.danger ? 'error' : undefined"
                        @click="a.run()"
                      >
                        {{ a.label }}
                      </VListItem>
                    </VList>
                  </VMenu>
                </div>
              </td>
            </tr>
          </tbody>
        </VTable>
      </template>
    </VCard>

    <!-- Édition brouillon -->
    <VNavigationDrawer class="wf-scroll-drawer" v-model="editOpen" temporary location="end" width="640">
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ t('contractTpl.editTitle') }}
        </h5>
        <VBtn :aria-label="$t('common.close')" icon variant="text" size="small" @click="editOpen = false">
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>
      <VDivider />
      <div class="pa-4">
        <AppTextField
          v-model="editForm.name"
          :label="t('contractTpl.col.name')"
          class="mb-3"
        />
        <AppTextField
          v-model="editForm.contract_type"
          :label="t('contractTpl.col.type')"
          class="mb-3"
        />
        <!-- Palette de variables catégorisée (cliquer pour insérer) -->
        <div class="text-caption text-medium-emphasis mb-1">
          {{ t('contractTpl.variables') }}
        </div>
        <div class="mb-3" style="max-block-size: 160px; overflow-y: auto;">
          <div v-for="(vars, cat) in variablesByCategory" :key="cat" class="mb-1">
            <span class="text-caption font-weight-medium me-1">{{ t('contractVars.' + cat, cat) }}:</span>
            <VChip
              v-for="v in vars"
              :key="v.key"
              size="x-small"
              variant="outlined"
              class="cursor-pointer me-1 mb-1"
              @click="insertVar(v.key)"
            >
              {{ v.label || v.key }}
            </VChip>
          </div>
        </div>

        <!-- Éditeur WYSIWYG (Tiptap) — aucun HTML à écrire ; variables insérées au curseur -->
        <div class="text-caption text-medium-emphasis mb-1">
          {{ t('contractTpl.body') }}
        </div>
        <ContractEditor
          ref="editorRef"
          v-model="editForm.body_html"
          :placeholder="t('contractTpl.body')"
          class="mb-3"
        />

        <VBtn
          variant="tonal"
          size="small"
          prepend-icon="tabler-file-type-pdf"
          :loading="previewing"
          class="mb-3"
          @click="previewPdf"
        >
          {{ t('contractTpl.previewPdf') }}
        </VBtn>

        <div class="d-flex gap-3 justify-end">
          <VBtn variant="outlined" @click="editOpen = false">
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn color="primary" :loading="editSaving" @click="saveEdit">
            {{ t('common.save') }}
          </VBtn>
        </div>
      </div>
    </VNavigationDrawer>
  </div>
</template>
