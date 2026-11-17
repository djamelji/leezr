<script setup>
// ADR-819 (Company 2.0) — CATALOGUES de la société : ce qu'elle PEUT collecter (champs de la fiche, types de
// documents), avec l'origine de chaque élément (standard, recommandé par le métier, apporté par un module,
// créé par la société) et les postes qui l'exigent. Disponible ≠ obligatoire : rien ne devient obligatoire
// ici, l'obligation se règle par poste (Organisation › Postes). Tout vient du serveur (/company/catalogs).
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

definePage({ meta: { anyPermission: ['settings.manage', 'documents.configure', 'workforce.manage'] } })

const { t, te } = useI18n()
const route = useRoute()
const router = useRouter()
const { can, canModule } = useCan()
const { toast } = useAppToast()

const tab = computed({
  get: () => (route.params.tab === 'documents' ? 'documents' : 'fields'),
  set: v => router.replace({ params: { tab: v } }),
})
const data = ref(null)
const loading = ref(true)
const search = ref('')
const originFilter = ref(null)
const statusFilter = ref(null)

const load = async () => {
  loading.value = true
  try { data.value = await $api('/company/catalogs') }
  finally { loading.value = false }
}
onMounted(load)

const canFields = computed(() => can('settings.manage'))
const canDocs = computed(() => can('documents.configure'))
const canRequire = computed(() => canModule('workforce') && (can('settings.manage') || can('documents.configure')))
const docLabel = d => (te(`documents.type.${d.code}`) ? t(`documents.type.${d.code}`) : d.label)

const ORIGIN_TONE = { standard: 'secondary', jobdomain: 'info', module: 'primary', custom: 'success' }
const STATUS_TONE = { active: 'success', available: 'secondary', recommended: 'info' }
const originLabel = o => (o.kind === 'module' ? o.name : o.kind === 'jobdomain' && o.recommended ? t('catalogs.origin.jobdomainRecommended') : t(`catalogs.origin.${o.kind}`))

const rows = computed(() => {
  const list = (tab.value === 'fields' ? data.value?.fields : data.value?.documents) || []
  const q = search.value.trim().toLowerCase()

  return list
    .map(r => ({ ...r, label: tab.value === 'documents' ? docLabel(r) : r.label }))
    .filter(r => !q || [r.label, r.code, r.description].join(' ').toLowerCase().includes(q))
    .filter(r => !originFilter.value || r.origins.some(o => o.kind === originFilter.value))
    .filter(r => !statusFilter.value || r.status === statusFilter.value)
})
const counts = computed(() => ({ fields: data.value?.fields?.length || 0, documents: data.value?.documents?.length || 0 }))
const originItems = computed(() => ['standard', 'jobdomain', 'module', 'custom'].map(k => ({ value: k, title: t(`catalogs.origin.${k}`) })))
const statusItems = computed(() => ['active', 'available', 'recommended'].map(k => ({ value: k, title: t(`catalogs.status.${k}`) })))
const headers = computed(() => [
  { title: t('catalogs.col.name'), key: 'label' },
  { title: tab.value === 'fields' ? t('catalogs.col.type') : t('catalogs.col.formats'), key: 'kind', sortable: false },
  { title: t('catalogs.col.origin'), key: 'origins', sortable: false },
  { title: t('catalogs.col.usedBy'), key: 'used_by', sortable: false },
  { title: t('catalogs.col.status'), key: 'status', align: 'end' },
])

// ── Activation (proposé dans les formulaires de la société) — jamais une obligation ──
const toggling = ref(null)
const toggle = async row => {
  toggling.value = row.code
  const enable = row.status !== 'active'
  try {
    if (tab.value === 'fields')
      await $api('/company/field-activations', { method: 'POST', body: { field_definition_id: row.id, enabled: enable } })
    else
      await $api(`/company/document-activations/${row.code}`, { method: 'PUT', body: { enabled: enable, required_override: row.required_for_everyone, order: row.order } })
    toast(enable ? t('catalogs.activated') : t('catalogs.deactivated'), 'success')
    await load()
  }
  catch (e) { toast(e?.data?.message || t('common.error'), 'error') }
  finally { toggling.value = null }
}
const canToggle = computed(() => (tab.value === 'fields' ? canFields.value : canDocs.value))

// ── Exiger pour un poste (registre des exigences, même chemin que la fiche du poste) ──
const positions = ref([])
const requireDialog = ref(false)
const requireTarget = ref(null)
const requirePosition = ref(null)
const requiring = ref(false)
const openRequire = async row => {
  requireTarget.value = row
  requirePosition.value = null
  if (!positions.value.length) {
    const list = await $api('/workforce/job-roles').catch(() => [])
    const arr = Array.isArray(list) ? list : Object.values(list || {}).filter(r => r && typeof r === 'object' && r.id)
    positions.value = arr.map(p => ({ title: p.title, value: p.id }))
  }
  requireDialog.value = true
}
const confirmRequire = async () => {
  requiring.value = true
  try {
    const kind = tab.value === 'fields' ? 'field' : 'document'
    await $api('/workforce/requirements', { method: 'POST', body: { scope: `job_role:${requirePosition.value}`, items: [{ kind, code: requireTarget.value.code, state: 'required' }] } })
    toast(t('catalogs.required'), 'success')
    requireDialog.value = false
    await load()
  }
  catch (e) { toast(e?.data?.message || t('common.error'), 'error') }
  finally { requiring.value = false }
}

// ── Création d'un champ ou d'un type de document propre à la société ──
const createDialog = ref(false)
const form = ref({})
const creating = ref(false)
const FORMATS = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']
const openCreate = () => {
  form.value = tab.value === 'fields'
    ? { label: '', type: 'string', options: [] }
    : { label: '', accepted_types: ['pdf', 'jpg', 'png'], max_file_size_mb: 10, requires_expiration: false }
  createDialog.value = true
}
const slug = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'f_$1').slice(0, 40) || 'champ'
const create = async () => {
  creating.value = true
  try {
    if (tab.value === 'fields') {
      const body = { code: `${slug(form.value.label)}_${Date.now().toString(36).slice(-4)}`, label: form.value.label, scope: 'company_user', type: form.value.type }
      if (form.value.type === 'select') body.options = form.value.options
      await $api('/company/field-definitions', { method: 'POST', body }) // créé ET proposé dans les formulaires
    }
    else {
      await $api('/company/document-types/custom', { method: 'POST', body: { ...form.value, scope: 'company_user', required: false } })
    }
    toast(t('catalogs.created'), 'success')
    createDialog.value = false
    await load()
  }
  catch (e) { toast(e?.data?.message || Object.values(e?.data?.errors || {})[0]?.[0] || t('common.error'), 'error') }
  finally { creating.value = false }
}
const canCreate = computed(() => (tab.value === 'fields' ? canFields.value && data.value?.can_create_fields : canDocs.value))
const typeItems = computed(() => (data.value?.field_types || []).map(v => ({ value: v, title: t(`catalogs.fieldType.${v}`) })))
</script>

<template>
  <div>
    <AppPageHeader
      :title="t('catalogs.title')"
      :description="t('catalogs.subtitle')"
    >
      <template #actions>
        <VBtn
          v-if="canCreate"
          prepend-icon="tabler-plus"
          data-test="catalog-create"
          @click="openCreate"
        >
          {{ tab === 'fields' ? t('catalogs.createField') : t('catalogs.createDocument') }}
        </VBtn>
      </template>
    </AppPageHeader>

    <VTabs
      v-model="tab"
      class="mb-4"
    >
      <VTab value="fields">
        <VIcon
          icon="tabler-forms"
          start
          size="18"
        />{{ t('catalogs.tabs.fields', { n: counts.fields }) }}
      </VTab>
      <VTab value="documents">
        <VIcon
          icon="tabler-file-description"
          start
          size="18"
        />{{ t('catalogs.tabs.documents', { n: counts.documents }) }}
      </VTab>
    </VTabs>

    <VAlert
      type="info"
      variant="tonal"
      density="compact"
      class="mb-4"
      :text="t('catalogs.availabilityHint')"
    />

    <VCard>
      <VCardText class="d-flex flex-wrap gap-3">
        <AppTextField
          v-model="search"
          :placeholder="t('catalogs.search')"
          prepend-inner-icon="tabler-search"
          clearable
          style="min-inline-size: 220px; flex: 1 1 220px;"
          data-test="catalog-search"
        />
        <AppSelect
          v-model="originFilter"
          :items="originItems"
          :placeholder="t('catalogs.col.origin')"
          clearable
          style="min-inline-size: 180px; flex: 0 1 200px;"
        />
        <AppSelect
          v-model="statusFilter"
          :items="statusItems"
          :placeholder="t('catalogs.col.status')"
          clearable
          style="min-inline-size: 180px; flex: 0 1 200px;"
        />
      </VCardText>
      <VDivider />
      <VDataTable
        :headers="headers"
        :items="rows"
        :loading="loading"
        :items-per-page="25"
        item-value="code"
        class="text-no-wrap"
        :data-test="`catalog-table-${tab}`"
      >
        <template #item.label="{ item }">
          <div class="py-2 text-wrap">
            <div class="font-weight-medium">
              {{ item.label }}
              <VIcon
                v-if="item.sensitive || item.confidentiality === 'highly_confidential'"
                icon="tabler-lock"
                size="14"
                class="ms-1 text-medium-emphasis"
                :title="t('catalogs.sensitive')"
              />
            </div>
            <div
              v-if="item.description"
              class="text-caption text-medium-emphasis"
            >
              {{ item.description }}
            </div>
            <div
              v-if="item.sources?.includes('module_proof')"
              class="text-caption text-success"
            >
              {{ t('catalogs.moduleProof') }}
            </div>
          </div>
        </template>
        <template #item.kind="{ item }">
          <span v-if="tab === 'fields'">{{ te(`catalogs.fieldType.${item.type}`) ? t(`catalogs.fieldType.${item.type}`) : item.type }}</span>
          <span
            v-else
            class="text-caption"
          >{{ item.accepted_types.join(', ').toUpperCase() }} · {{ item.max_file_size_mb }} Mo<template v-if="item.requires_expiration"> · {{ t('catalogs.expires') }}</template></span>
        </template>
        <template #item.origins="{ item }">
          <div class="d-flex flex-wrap gap-1">
            <VChip
              v-for="(o, i) in item.origins"
              :key="i"
              size="small"
              label
              :color="ORIGIN_TONE[o.kind]"
              :variant="o.kind === 'module' && !o.active ? 'outlined' : 'tonal'"
              :title="o.kind === 'module' && !o.active ? t('catalogs.moduleInactive') : ''"
            >
              {{ originLabel(o) }}
            </VChip>
          </div>
        </template>
        <template #item.used_by="{ item }">
          <div class="d-flex flex-wrap gap-1 text-wrap">
            <VChip
              v-if="item.used_by.everyone || item.required_for_everyone"
              size="small"
              label
              color="warning"
              variant="tonal"
            >
              {{ t('catalogs.everyone') }}
            </VChip>
            <VChip
              v-for="p in item.used_by.positions"
              :key="p.id"
              size="small"
              label
              :to="`/company/workforce/organization/positions/${p.id}?tab=${tab}`"
            >
              {{ p.title }}
            </VChip>
            <span
              v-if="!item.used_by.everyone && !item.required_for_everyone && !item.used_by.positions.length"
              class="text-caption text-disabled"
            >{{ t('catalogs.notRequired') }}</span>
          </div>
        </template>
        <template #item.status="{ item }">
          <div class="d-flex align-center justify-end gap-2">
            <VChip
              size="small"
              label
              :color="STATUS_TONE[item.status]"
            >
              {{ t(`catalogs.status.${item.status}`) }}
            </VChip>
            <VMenu v-if="canToggle || canRequire">
              <template #activator="{ props }">
                <IconBtn
                  v-bind="props"
                  size="small"
                  :loading="toggling === item.code"
                  :aria-label="t('common.actions')"
                  :data-test="`catalog-actions-${item.code}`"
                >
                  <VIcon icon="tabler-dots-vertical" />
                </IconBtn>
              </template>
              <VList density="compact">
                <VListItem
                  v-if="canToggle"
                  :prepend-icon="item.status === 'active' ? 'tabler-eye-off' : 'tabler-eye'"
                  :title="item.status === 'active' ? t('catalogs.deactivate') : t('catalogs.activate')"
                  @click="toggle(item)"
                />
                <VListItem
                  v-if="canRequire"
                  prepend-icon="tabler-briefcase"
                  :title="t('catalogs.requireForPosition')"
                  :data-test="`catalog-require-${item.code}`"
                  @click="openRequire(item)"
                />
              </VList>
            </VMenu>
          </div>
        </template>
        <template #no-data>
          <div class="text-medium-emphasis py-6">
            {{ t('catalogs.empty') }}
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Exiger pour un poste -->
    <VDialog
      v-model="requireDialog"
      max-width="480"
    >
      <VCard :title="t('catalogs.requireForPosition')">
        <VCardText>
          <p class="text-body-2 mb-4">
            {{ t('catalogs.requireHint', { name: requireTarget?.label }) }}
          </p>
          <AppSelect
            v-model="requirePosition"
            :items="positions"
            :label="t('catalogs.position')"
            :no-data-text="t('catalogs.noPosition')"
            data-test="catalog-require-position"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="requireDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :disabled="!requirePosition"
            :loading="requiring"
            data-test="catalog-require-confirm"
            @click="confirmRequire"
          >
            {{ t('catalogs.requireConfirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Créer un champ / un type de document -->
    <VDialog
      v-model="createDialog"
      max-width="520"
    >
      <VCard :title="tab === 'fields' ? t('catalogs.createField') : t('catalogs.createDocument')">
        <VCardText class="d-flex flex-column gap-4">
          <AppTextField
            v-model="form.label"
            :label="`${t('catalogs.col.name')} *`"
            autofocus
            data-test="catalog-create-label"
          />
          <template v-if="tab === 'fields'">
            <AppSelect
              v-model="form.type"
              :items="typeItems"
              :label="t('catalogs.col.type')"
            />
            <VCombobox
              v-if="form.type === 'select'"
              v-model="form.options"
              :label="t('catalogs.options')"
              multiple
              chips
              closable-chips
              :hint="t('catalogs.optionsHint')"
              persistent-hint
            />
          </template>
          <template v-else>
            <AppSelect
              v-model="form.accepted_types"
              :items="FORMATS.map(f => ({ value: f, title: f.toUpperCase() }))"
              :label="t('catalogs.col.formats')"
              multiple
              chips
            />
            <AppTextField
              v-model.number="form.max_file_size_mb"
              type="number"
              min="1"
              max="50"
              :label="t('catalogs.maxSize')"
              suffix="Mo"
            />
            <VSwitch
              v-model="form.requires_expiration"
              :label="t('catalogs.requiresExpiration')"
              hide-details
            />
          </template>
          <p class="text-caption text-medium-emphasis mb-0">
            {{ t('catalogs.createHint') }}
          </p>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="createDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="creating"
            :disabled="!form.label?.trim() || (form.type === 'select' && !form.options?.length) || (tab === 'documents' && !form.accepted_types?.length)"
            data-test="catalog-create-confirm"
            @click="create"
          >
            {{ t('common.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
