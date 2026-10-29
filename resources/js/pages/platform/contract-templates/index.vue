<script setup>
// W-CONTRACT-3B (ADR-650) — catalogue PLATFORM des modèles de contrat : liste filtrable, cycle de
// vie (brouillon/publié/archivé), éditeur Tiptap partagé avec la Company (_ContractEditor), aperçu
// PDF fidèle, et GOUVERNANCE JURIDIQUE distincte de la publication technique.
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'
import ContractEditor from '@/pages/company/workforce/contract/_ContractEditor.vue'

definePage({ meta: { layout: 'platform', platform: true, module: 'platform.jobdomains', permission: 'manage_jobdomains' } })

const { t } = useI18n()
const { toast } = useAppToast()

const items = ref([])
const variables = ref([])
const loading = ref(true)
const marketFilter = ref(null)
const typeFilter = ref(null)
const statusFilter = ref(null)

const statusOptions = [
  { title: t('contractTpl.status.draft'), value: 'draft' },
  { title: t('contractTpl.status.published'), value: 'published' },
  { title: t('contractTpl.status.archived'), value: 'archived' },
]
const legalOptions = [
  { title: t('contractTpl.legal.example_unvalidated'), value: 'example_unvalidated' },
  { title: t('platformContract.legal.in_review'), value: 'in_review' },
  { title: t('platformContract.legal.validated'), value: 'validated' },
  { title: t('platformContract.legal.obsolete'), value: 'obsolete' },
]

const statusColor = s => ({ draft: 'warning', published: 'success', archived: 'secondary' }[s] || 'secondary')
const legalColor = s => ({ validated: 'success', in_review: 'info', example_unvalidated: 'warning', obsolete: 'secondary' }[s] || 'warning')

const load = async () => {
  loading.value = true
  try {
    const params = new URLSearchParams()
    if (marketFilter.value) params.set('market_key', marketFilter.value)
    if (typeFilter.value) params.set('contract_type', typeFilter.value)
    if (statusFilter.value) params.set('status', statusFilter.value)
    const res = await $platformApi(`/contract-templates${params.toString() ? `?${params}` : ''}`)
    items.value = res.data ?? []
  } finally { loading.value = false }
}
watch([marketFilter, typeFilter, statusFilter], load)
onMounted(async () => {
  load()
  variables.value = (await $platformApi('/contract-templates/variables').catch(() => ({ data: [] }))).data ?? []
})

const busyId = ref(null)
const act = async (fn, id, msg) => {
  busyId.value = id
  try { await fn(); toast(msg, 'success'); await load() }
  catch (e) { toast(e.response?.data?.message || t('common.error'), 'error') }
  finally { busyId.value = null }
}
const onPublish = tpl => act(() => $platformApi(`/contract-templates/${tpl.id}/publish`, { method: 'POST' }), tpl.id, t('contractTpl.published'))
const onArchive = tpl => act(() => $platformApi(`/contract-templates/${tpl.id}/archive`, { method: 'POST' }), tpl.id, t('contractTpl.archived'))
const onVersion = tpl => act(() => $platformApi(`/contract-templates/${tpl.id}/version`, { method: 'POST' }), tpl.id, t('contractTpl.versioned'))
const onLegalStatus = (tpl, status) => act(() => $platformApi(`/contract-templates/${tpl.id}/legal-status`, { method: 'POST', body: { legal_status: status } }), tpl.id, t('platformContract.legalUpdated'))

// ── Création ──
const createOpen = ref(false)
const createForm = ref({ name: '', contract_type: '', market_key: '', jurisdiction: '' })
const creating = ref(false)
const createTpl = async () => {
  creating.value = true
  try {
    const t2 = await $platformApi('/contract-templates', { method: 'POST', body: createForm.value })
    createOpen.value = false
    createForm.value = { name: '', contract_type: '', market_key: '', jurisdiction: '' }
    await load()
    openEdit(t2)
  } catch (e) { toast(e.response?.data?.message || t('common.error'), 'error') }
  finally { creating.value = false }
}

// ── Édition (Tiptap + aperçu PDF) ──
const editOpen = ref(false)
const editTpl = ref(null)
const editForm = ref({ name: '', body_html: '', contract_type: '', market_key: '', jurisdiction: '' })
const editSaving = ref(false)
const editorRef = ref()
const openEdit = async tpl => {
  const full = await $platformApi(`/contract-templates/${tpl.id}`)
  editTpl.value = full
  editForm.value = { name: full.name, body_html: full.body_html || '', contract_type: full.contract_type || '', market_key: full.market_key || '', jurisdiction: full.jurisdiction || '' }
  editOpen.value = true
}
const insertVar = key => editorRef.value?.insertAtCursor(`{{ ${key} }}`)
const variablesByCategory = computed(() => {
  const g = {}
  for (const v of variables.value) (g[(v.key || '').split('.')[0] || 'autre'] ||= []).push(v)

  return g
})
const previewing = ref(false)
const previewPdf = async () => {
  previewing.value = true
  try {
    const blob = await $platformApi('/contract-templates/preview-pdf', { method: 'POST', body: { template_id: editTpl.value.id, body_html: editForm.value.body_html }, responseType: 'blob' })
    const url = URL.createObjectURL(blob)

    window.open(url, '_blank')
    setTimeout(() => URL.revokeObjectURL(url), 20000)
  } catch (e) { toast(e.response?.data?.message || t('common.error'), 'error') }
  finally { previewing.value = false }
}
const saveEdit = async () => {
  editSaving.value = true
  try {
    await $platformApi(`/contract-templates/${editTpl.value.id}`, { method: 'PUT', body: editForm.value })
    toast(t('common.saved'), 'success')
    editOpen.value = false
    await load()
  } catch (e) { toast(e.response?.data?.message || t('common.error'), 'error') }
  finally { editSaving.value = false }
}
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ t('platformContract.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ t('platformContract.subtitle') }}
        </p>
      </div>
      <VBtn
        color="primary"
        prepend-icon="tabler-plus"
        @click="createOpen = true"
      >
        {{ t('platformContract.create') }}
      </VBtn>
    </div>

    <VAlert
      type="info"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      {{ t('platformContract.governanceNote') }}
    </VAlert>

    <VCard>
      <VCardText>
        <VRow>
          <VCol cols="12" sm="4" md="3">
            <AppTextField
              v-model="marketFilter"
              :placeholder="t('contractTpl.col.name') && 'Market'"
              clearable
              density="compact"
            />
          </VCol>
          <VCol cols="12" sm="4" md="3">
            <AppTextField
              v-model="typeFilter"
              :placeholder="t('contractTpl.col.type')"
              clearable
              density="compact"
            />
          </VCol>
          <VCol cols="12" sm="4" md="3">
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :placeholder="t('contractTpl.col.status')"
              clearable
              density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>
      <VDivider />
      <VCardText v-if="loading" class="text-center py-8">
        <VProgressCircular indeterminate color="primary" />
      </VCardText>
      <VTable v-else class="text-no-wrap">
        <thead>
          <tr>
            <th>{{ t('contractTpl.col.name') }}</th>
            <th>{{ t('contractTpl.col.type') }}</th>
            <th>Market</th>
            <th>{{ t('contractTpl.col.version') }}</th>
            <th>{{ t('contractTpl.col.status') }}</th>
            <th>{{ t('platformContract.legalCol') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="tpl in items" :key="tpl.id">
            <td class="font-weight-medium">{{ tpl.name }}</td>
            <td>{{ tpl.contract_type || '—' }}</td>
            <td>{{ tpl.market_key || '—' }}</td>
            <td>v{{ tpl.version }}</td>
            <td><VChip :color="statusColor(tpl.status)" size="x-small" variant="tonal">{{ t('contractTpl.status.' + tpl.status, tpl.status) }}</VChip></td>
            <td>
              <AppSelect
                :model-value="tpl.legal_status"
                :items="legalOptions"
                density="compact"
                hide-details
                style="min-inline-size: 180px;"
                @update:model-value="s => onLegalStatus(tpl, s)"
              />
            </td>
            <td>
              <div class="d-flex gap-1 justify-end">
                <VBtn v-if="tpl.status === 'draft'" size="x-small" variant="tonal" prepend-icon="tabler-edit" @click="openEdit(tpl)">{{ t('common.edit') }}</VBtn>
                <VBtn v-if="tpl.status === 'draft'" size="x-small" color="success" variant="tonal" :loading="busyId === tpl.id" @click="onPublish(tpl)">{{ t('contractTpl.publish') }}</VBtn>
                <VBtn v-if="tpl.status === 'published'" size="x-small" variant="tonal" prepend-icon="tabler-versions" :loading="busyId === tpl.id" @click="onVersion(tpl)">{{ t('contractTpl.newVersion') }}</VBtn>
                <VBtn v-if="tpl.status !== 'archived'" size="x-small" variant="text" color="error" :loading="busyId === tpl.id" @click="onArchive(tpl)">{{ t('contractTpl.archive') }}</VBtn>
              </div>
            </td>
          </tr>
        </tbody>
      </VTable>
      <div v-if="!loading && !items.length" class="text-center text-disabled py-8">{{ t('contractTpl.empty') }}</div>
    </VCard>

    <!-- Création -->
    <VDialog v-model="createOpen" max-width="480">
      <VCard>
        <VCardItem><VCardTitle>{{ t('platformContract.create') }}</VCardTitle></VCardItem>
        <VCardText>
          <AppTextField v-model="createForm.name" :label="t('contractTpl.col.name')" class="mb-3" />
          <AppTextField v-model="createForm.contract_type" :label="t('contractTpl.col.type')" class="mb-3" />
          <AppTextField v-model="createForm.market_key" label="Market" class="mb-3" />
          <AppTextField v-model="createForm.jurisdiction" :label="t('platformContract.jurisdiction')" />
        </VCardText>
        <VCardText class="d-flex justify-end gap-3 pt-0">
          <VBtn variant="outlined" color="secondary" @click="createOpen = false">{{ t('common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="creating" :disabled="!createForm.name" @click="createTpl">{{ t('common.create') }}</VBtn>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- Éditeur (partagé avec la Company) -->
    <VNavigationDrawer v-model="editOpen" temporary location="end" width="640">
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">{{ t('contractTpl.editTitle') }}</h5>
        <VBtn icon variant="text" size="small" @click="editOpen = false"><VIcon icon="tabler-x" /></VBtn>
      </div>
      <VDivider />
      <div class="pa-4">
        <AppTextField v-model="editForm.name" :label="t('contractTpl.col.name')" class="mb-3" />
        <VRow class="mb-1">
          <VCol cols="6"><AppTextField v-model="editForm.contract_type" :label="t('contractTpl.col.type')" density="compact" /></VCol>
          <VCol cols="6"><AppTextField v-model="editForm.market_key" label="Market" density="compact" /></VCol>
        </VRow>
        <div class="text-caption text-medium-emphasis mb-1">{{ t('contractTpl.variables') }}</div>
        <div class="mb-3" style="max-block-size: 140px; overflow-y: auto;">
          <div v-for="(vars, cat) in variablesByCategory" :key="cat" class="mb-1">
            <span class="text-caption font-weight-medium me-1">{{ t('contractVars.' + cat, cat) }}:</span>
            <VChip v-for="v in vars" :key="v.key" size="x-small" variant="outlined" class="cursor-pointer me-1 mb-1" @click="insertVar(v.key)">{{ v.label || v.key }}</VChip>
          </div>
        </div>
        <div class="text-caption text-medium-emphasis mb-1">{{ t('contractTpl.body') }}</div>
        <ContractEditor ref="editorRef" v-model="editForm.body_html" :placeholder="t('contractTpl.body')" class="mb-3" />
        <VBtn variant="tonal" size="small" prepend-icon="tabler-file-type-pdf" :loading="previewing" class="mb-3" @click="previewPdf">{{ t('contractTpl.previewPdf') }}</VBtn>
        <div class="d-flex gap-3 justify-end">
          <VBtn variant="outlined" @click="editOpen = false">{{ t('common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="editSaving" @click="saveEdit">{{ t('common.save') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>
  </div>
</template>
