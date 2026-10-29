<script setup>
// W-CONTRACT-1 (ADR-647) — bibliothèque de modèles de contrat : consultation, duplication d'un
// modèle Platform, édition d'un brouillon Company, publication/archivage. (Éditeur WYSIWYG Tiptap
// complet + palette d'insertion visuelle = sous-lot 2 ; sous-lot 1 = édition structurée fiable.)
import { useContractStore } from '@/modules/company/workforce/contract.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'

definePage({ meta: { module: 'workforce_contract', permission: 'workforce_contract.view' } })

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
const legalColor = s => (s === 'validated' ? 'success' : 'warning')

const busyId = ref(null)
const act = async (fn, id, okMsg) => {
  busyId.value = id
  try { await fn(); toast(okMsg, 'success'); await load() }
  catch (e) { toast(e.response?.data?.message || t('common.error'), 'error') }
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
const insertVar = key => { editForm.value.body_html += ` {{ ${key} }}` }
const saveEdit = async () => {
  editSaving.value = true
  try {
    await store.updateTemplate(editTpl.value.id, editForm.value)
    toast(t('common.saved'), 'success')
    editOpen.value = false
    await load()
  } catch (e) { toast(e.response?.data?.message || t('common.error'), 'error') }
  finally { editSaving.value = false }
}
</script>

<template>
  <div>
    <div class="mb-6">
      <h4 class="text-h4 font-weight-bold">
        {{ t('contractTpl.title') }}
      </h4>
      <p class="text-body-1 text-medium-emphasis mb-0">
        {{ t('contractTpl.subtitle') }}
      </p>
    </div>

    <VAlert
      type="info"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      {{ t('contractTpl.legalDisclaimer') }}
    </VAlert>

    <VCard>
      <VCardText v-if="loading" class="text-center py-8">
        <VProgressCircular indeterminate color="primary" />
      </VCardText>
      <template v-else>
        <VTable class="text-no-wrap">
          <thead>
            <tr>
              <th>{{ t('contractTpl.col.name') }}</th>
              <th>{{ t('contractTpl.col.type') }}</th>
              <th>{{ t('contractTpl.col.source') }}</th>
              <th>{{ t('contractTpl.col.version') }}</th>
              <th>{{ t('contractTpl.col.status') }}</th>
              <th>{{ t('contractTpl.col.legal') }}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="tpl in store.templates" :key="tpl.id">
              <td class="font-weight-medium">
                {{ tpl.name }}
              </td>
              <td>{{ tpl.contract_type || '—' }}</td>
              <td>
                <VChip :color="sourceColor(tpl.source)" size="x-small" variant="tonal">
                  {{ t('contractTpl.source.' + tpl.source) }}
                </VChip>
              </td>
              <td>v{{ tpl.version }}</td>
              <td>
                <VChip :color="statusColor(tpl.status)" size="x-small" variant="tonal">
                  {{ t('contractTpl.status.' + tpl.status, tpl.status) }}
                </VChip>
              </td>
              <td>
                <VChip :color="legalColor(tpl.legal_status)" size="x-small" variant="tonal">
                  {{ t('contractTpl.legal.' + tpl.legal_status, tpl.legal_status) }}
                </VChip>
              </td>
              <td>
                <div class="d-flex gap-1 justify-end">
                  <VBtn
                    v-if="tpl.source === 'platform' && can('workforce_contract.manage_templates')"
                    size="x-small"
                    variant="tonal"
                    prepend-icon="tabler-copy"
                    :loading="busyId === tpl.id"
                    @click="onDuplicate(tpl)"
                  >
                    {{ t('contractTpl.duplicate') }}
                  </VBtn>
                  <template v-if="tpl.source === 'company' && can('workforce_contract.manage_templates')">
                    <VBtn
                      v-if="tpl.status === 'draft'"
                      size="x-small"
                      variant="tonal"
                      prepend-icon="tabler-edit"
                      @click="openEdit(tpl)"
                    >
                      {{ t('common.edit') }}
                    </VBtn>
                    <VBtn
                      v-if="tpl.status === 'draft'"
                      size="x-small"
                      color="success"
                      variant="tonal"
                      prepend-icon="tabler-circle-check"
                      :loading="busyId === tpl.id"
                      @click="onPublish(tpl)"
                    >
                      {{ t('contractTpl.publish') }}
                    </VBtn>
                    <VBtn
                      v-if="tpl.status === 'published'"
                      size="x-small"
                      variant="tonal"
                      prepend-icon="tabler-versions"
                      :loading="busyId === tpl.id"
                      @click="onNewVersion(tpl)"
                    >
                      {{ t('contractTpl.newVersion') }}
                    </VBtn>
                    <VBtn
                      v-if="tpl.status !== 'archived'"
                      size="x-small"
                      variant="text"
                      color="error"
                      :loading="busyId === tpl.id"
                      @click="onArchive(tpl)"
                    >
                      {{ t('contractTpl.archive') }}
                    </VBtn>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </VTable>
        <div v-if="!store.templates.length" class="text-center text-disabled py-8">
          {{ t('contractTpl.empty') }}
        </div>
      </template>
    </VCard>

    <!-- Édition brouillon -->
    <VNavigationDrawer v-model="editOpen" temporary location="end" width="640">
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ t('contractTpl.editTitle') }}
        </h5>
        <VBtn icon variant="text" size="small" @click="editOpen = false">
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
        <div class="text-caption text-medium-emphasis mb-1">
          {{ t('contractTpl.variables') }}
        </div>
        <div class="d-flex gap-1 flex-wrap mb-3" style="max-block-size: 120px; overflow-y: auto;">
          <VChip
            v-for="v in variables"
            :key="v.key"
            size="x-small"
            variant="outlined"
            class="cursor-pointer"
            @click="insertVar(v.key)"
          >
            {{ v.key }}
          </VChip>
        </div>
        <VTextarea
          v-model="editForm.body_html"
          :label="t('contractTpl.body')"
          rows="12"
          auto-grow
          class="mb-3"
        />
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
