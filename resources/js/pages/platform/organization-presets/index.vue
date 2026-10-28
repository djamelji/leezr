<script setup>
// ADR-638 (W-E2) — catalogue Platform des presets organisationnels. Liste réelle (DB), filtres, cycle
// de vie (créer brouillon, publier, dupliquer, archiver). Édition visuelle sur la page [id].
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'

definePage({ meta: { layout: 'platform', platform: true, module: 'platform.jobdomains', permission: 'manage_jobdomains' } })

const { t } = useI18n()
const router = useRouter()
const { toast } = useAppToast()

const items = ref([])
const meta = ref({ jobdomains: [], markets: [], profiles_by_jobdomain: {} })
const loading = ref(true)
const search = ref('')
const jobdomainFilter = ref(null)
const statusFilter = ref(null)
const createDialog = ref(false)
const archiveDialog = ref(false)
const archiveTarget = ref(null)
const form = ref({ key: '', label: '', jobdomain_key: null, market_key: null, org_profile_key: null })
const saving = ref(false)

const load = async () => {
  loading.value = true
  try {
    const [list, m] = await Promise.all([
      $platformApi('/organization-presets?per_page=100'),
      $platformApi('/organization-presets/meta'),
    ])
    items.value = list.data || list
    meta.value = m
  }
  finally { loading.value = false }
}

const filtered = computed(() => items.value.filter(p => {
  if (jobdomainFilter.value && p.jobdomain_key !== jobdomainFilter.value) return false
  if (statusFilter.value && p.status !== statusFilter.value) return false
  if (search.value && !(`${p.key} ${p.label}`.toLowerCase().includes(search.value.toLowerCase()))) return false

  return true
}))

const headers = [
  { title: t('orgPresetAdmin.label'), key: 'label' },
  { title: t('orgPresetAdmin.target'), key: 'target', sortable: false },
  { title: t('orgPresetAdmin.version'), key: 'version', align: 'center' },
  { title: t('orgPresetAdmin.status'), key: 'status' },
  { title: t('orgPresetAdmin.updated'), key: 'updated_at' },
  { title: '', key: 'actions', sortable: false, align: 'end' },
]

const statusColor = s => ({ draft: 'secondary', published: 'success', archived: 'warning' }[s] || 'secondary')
const profileItems = computed(() => (meta.value.profiles_by_jobdomain[form.value.jobdomain_key] || []).map(p => ({ title: p.label, value: p.key })))

const create = async () => {
  saving.value = true
  try {
    const created = await $platformApi('/organization-presets', {
      method: 'POST',
      body: { ...form.value, structure: { departments: [], job_roles: [] } },
    })
    createDialog.value = false
    router.push(`/platform/organization-presets/${created.id}`)
  }
  catch { /* $platformApi handles */ }
  finally { saving.value = false }
}

const publish = async p => { try { await $platformApi(`/organization-presets/${p.id}/publish`, { method: 'POST', body: {} }); toast(t('orgPresetAdmin.published'), 'success'); await load() } catch { /* */ } }
const duplicate = async p => { try { const d = await $platformApi(`/organization-presets/${p.id}/duplicate`, { method: 'POST', body: {} }); toast(t('orgPresetAdmin.duplicated'), 'success'); router.push(`/platform/organization-presets/${d.id}`) } catch { /* */ } }
const askArchive = p => { archiveTarget.value = p; archiveDialog.value = true }
const archive = async () => { try { await $platformApi(`/organization-presets/${archiveTarget.value.id}/archive`, { method: 'POST', body: {} }); toast(t('orgPresetAdmin.archived'), 'success'); archiveDialog.value = false; await load() } catch { /* */ } }

onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ t('orgPresetAdmin.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ t('orgPresetAdmin.subtitle') }}
        </p>
      </div>
      <VBtn
        color="primary"
        prepend-icon="tabler-plus"
        @click="createDialog = true"
      >
        {{ t('orgPresetAdmin.create') }}
      </VBtn>
    </div>

    <VCard>
      <VCardText class="d-flex flex-wrap gap-3">
        <AppTextField
          v-model="search"
          :placeholder="t('orgPresetAdmin.search')"
          density="compact"
          style="max-inline-size: 240px;"
          clearable
        />
        <AppSelect
          v-model="jobdomainFilter"
          :items="meta.jobdomains.map(j => ({ title: j.label, value: j.key }))"
          :label="t('orgPresetAdmin.jobdomain')"
          density="compact"
          style="max-inline-size: 200px;"
          clearable
        />
        <AppSelect
          v-model="statusFilter"
          :items="[{ title: t('orgPresetAdmin.statuses.draft'), value: 'draft' }, { title: t('orgPresetAdmin.statuses.published'), value: 'published' }, { title: t('orgPresetAdmin.statuses.archived'), value: 'archived' }]"
          :label="t('orgPresetAdmin.status')"
          density="compact"
          style="max-inline-size: 180px;"
          clearable
        />
      </VCardText>

      <VDataTable
        :headers="headers"
        :items="filtered"
        :loading="loading"
        item-value="id"
        class="text-no-wrap"
      >
        <template #item.target="{ item }">
          <div class="text-body-2">
            {{ item.jobdomain_key }}
            <span v-if="item.market_key"> · {{ item.market_key }}</span>
            <span v-if="item.org_profile_key" class="text-primary"> · {{ item.org_profile_key }}</span>
          </div>
        </template>
        <template #item.status="{ item }">
          <VChip
            :color="statusColor(item.status)"
            size="small"
          >
            {{ t('orgPresetAdmin.statuses.' + item.status) }}
          </VChip>
        </template>
        <template #item.updated_at="{ item }">
          {{ new Date(item.updated_at).toLocaleDateString() }}
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex gap-1 justify-end">
            <IconBtn
              size="small"
              :title="t('orgPresetAdmin.edit')"
              @click="router.push(`/platform/organization-presets/${item.id}`)"
            >
              <VIcon :icon="item.status === 'published' ? 'tabler-eye' : 'tabler-edit'" />
            </IconBtn>
            <IconBtn
              v-if="item.status === 'draft'"
              size="small"
              :title="t('orgPresetAdmin.publish')"
              @click="publish(item)"
            >
              <VIcon icon="tabler-rocket" />
            </IconBtn>
            <IconBtn
              size="small"
              :title="t('orgPresetAdmin.duplicate')"
              @click="duplicate(item)"
            >
              <VIcon icon="tabler-copy" />
            </IconBtn>
            <IconBtn
              v-if="item.status !== 'archived'"
              size="small"
              :title="t('orgPresetAdmin.archive')"
              @click="askArchive(item)"
            >
              <VIcon icon="tabler-archive" />
            </IconBtn>
          </div>
        </template>
        <template #no-data>
          <div class="text-center text-disabled py-6">
            {{ t('orgPresetAdmin.empty') }}
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Create dialog -->
    <VDialog
      v-model="createDialog"
      max-width="560"
    >
      <VCard>
        <VCardTitle>{{ t('orgPresetAdmin.create') }}</VCardTitle>
        <VCardText class="d-flex flex-column gap-3">
          <AppTextField
            v-model="form.key"
            :label="t('orgPresetAdmin.key')"
            :hint="t('orgPresetAdmin.keyHint')"
            persistent-hint
          />
          <AppTextField
            v-model="form.label"
            :label="t('orgPresetAdmin.label')"
          />
          <AppSelect
            v-model="form.jobdomain_key"
            :items="meta.jobdomains.map(j => ({ title: j.label, value: j.key }))"
            :label="t('orgPresetAdmin.jobdomain')"
          />
          <AppSelect
            v-model="form.market_key"
            :items="meta.markets.map(m => ({ title: m.name, value: m.key }))"
            :label="t('orgPresetAdmin.market')"
            clearable
          />
          <AppSelect
            v-model="form.org_profile_key"
            :items="profileItems"
            :label="t('orgPresetAdmin.profile')"
            :disabled="!form.jobdomain_key"
            clearable
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            @click="createDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="saving"
            :disabled="!form.key || !form.label || !form.jobdomain_key"
            @click="create"
          >
            {{ t('orgPresetAdmin.createAndEdit') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Archive confirm -->
    <VDialog
      v-model="archiveDialog"
      max-width="460"
    >
      <VCard>
        <VCardTitle>{{ t('orgPresetAdmin.archive') }}</VCardTitle>
        <VCardText>{{ t('orgPresetAdmin.archiveConfirm') }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            @click="archiveDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="warning"
            @click="archive"
          >
            {{ t('orgPresetAdmin.archive') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
