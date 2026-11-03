<script setup>
// PRODUCT-UX-3 / P2-C (ADR-685) — Work locations managed in the ORGANISATION space
// (moved from Planning, overlap H). A WorkLocation is a shift EXECUTION place
// (internal/client site/remote/mobile) — NOT an administrative establishment (that
// entity is a distinct, not-yet-built effort). Reuses the existing planning.store
// + /workforce/planning/locations endpoints (business rules unchanged).
import { usePlanningStore } from '@/modules/company/workforce/planning.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'

const { t } = useI18n()
const store = usePlanningStore()
const { toast } = useAppToast()
const { can } = useCan()

const canManage = computed(() => can('workforce.planning_manage'))
const loading = ref(false)
const items = computed(() => store.allLocations)

const headers = computed(() => [
  { title: t('organization.locations.columns.name'), key: 'name', sortable: false },
  { title: t('organization.locations.columns.type'), key: 'type', sortable: false, width: 150 },
  { title: t('organization.locations.columns.address'), key: 'address', sortable: false },
  { title: t('organization.locations.columns.active'), key: 'is_active', sortable: false, width: 90 },
  ...(canManage.value ? [{ title: '', key: 'actions', sortable: false, width: 100 }] : []),
])

const typeOptions = [
  { title: t('organization.locations.types.internal'), value: 'internal', icon: 'tabler-building' },
  { title: t('organization.locations.types.client_site'), value: 'client_site', icon: 'tabler-briefcase' },
  { title: t('organization.locations.types.remote'), value: 'remote', icon: 'tabler-home' },
  { title: t('organization.locations.types.mobile'), value: 'mobile', icon: 'tabler-car' },
]
const typeIcon = v => typeOptions.find(o => o.value === v)?.icon ?? 'tabler-map-pin'
const typeLabel = v => typeOptions.find(o => o.value === v)?.title ?? v

const load = async () => {
  loading.value = true
  try { await store.fetchLocations({ all: true, force: true }) }
  finally { loading.value = false }
}
onMounted(load)

// Drawer
const drawer = ref(false)
const editingId = ref(null)
const form = ref({ name: '', type: 'internal', address: '', timezone: '', client_name: '', enabled: true })
const saving = ref(false)

const openCreate = () => {
  editingId.value = null
  form.value = { name: '', type: 'internal', address: '', timezone: '', client_name: '', enabled: true }
  drawer.value = true
}
const openEdit = item => {
  editingId.value = item.id
  form.value = { name: item.name, type: item.type, address: item.address ?? '', timezone: item.timezone ?? '', client_name: item.client_name ?? '', enabled: item.is_active ?? item.enabled ?? true }
  drawer.value = true
}
const submit = async () => {
  saving.value = true
  try {
    const payload = { ...form.value }
    if (editingId.value) await store.updateLocation(editingId.value, payload)
    else await store.createLocation(payload)
    toast(t(editingId.value ? 'organization.locations.updated' : 'organization.locations.created'), 'success')
    drawer.value = false
    await load()
  } catch (e) { toast(e?.response?.data?.message || e.message, 'error') }
  finally { saving.value = false }
}

const confirmDialog = ref(false)
const targetId = ref(null)
const askDelete = item => { targetId.value = item.id; confirmDialog.value = true }
const doDelete = async () => {
  try { await store.deleteLocation(targetId.value); toast(t('organization.locations.deleted'), 'success'); confirmDialog.value = false; await load() }
  catch (e) { toast(e?.response?.data?.message || e.message, 'error'); confirmDialog.value = false }
}
</script>

<template>
  <div>
    <VAlert
      type="info"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      <VIcon icon="tabler-info-circle" size="16" class="me-1" />
      {{ $t('organization.locations.executionNote') }}
    </VAlert>

    <div class="d-flex justify-end mb-3">
      <VBtn
        v-if="canManage"
        color="primary"
        prepend-icon="tabler-plus"
        data-test="org-loc-create"
        @click="openCreate"
      >
        {{ $t('organization.locations.add') }}
      </VBtn>
    </div>

    <VCard>
      <VDataTable
        :headers="headers"
        :items="items"
        :loading="loading"
        class="text-no-wrap"
      >
        <template #item.name="{ item }">
          <div class="d-flex align-center gap-2">
            <VIcon :icon="typeIcon(item.type)" size="18" color="primary" />
            <span class="font-weight-medium">{{ item.name }}</span>
          </div>
          <div v-if="item.client_name" class="text-caption text-medium-emphasis ms-6">{{ item.client_name }}</div>
        </template>
        <template #item.type="{ item }">
          <VChip size="small" variant="tonal">{{ typeLabel(item.type) }}</VChip>
        </template>
        <template #item.address="{ item }">
          <span class="text-body-2">{{ item.address || '—' }}</span>
        </template>
        <template #item.is_active="{ item }">
          <VIcon :icon="(item.is_active ?? item.enabled) ? 'tabler-circle-check' : 'tabler-circle-x'" :color="(item.is_active ?? item.enabled) ? 'success' : 'disabled'" size="20" />
        </template>
        <template #item.actions="{ item }">
          <VBtn icon variant="text" size="small" @click="openEdit(item)"><VIcon icon="tabler-edit" size="18" /></VBtn>
          <VBtn icon variant="text" size="small" color="error" @click="askDelete(item)"><VIcon icon="tabler-trash" size="18" /></VBtn>
        </template>
        <template #no-data>
          <div class="text-center py-8 text-medium-emphasis">{{ $t('organization.locations.empty') }}</div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Drawer -->
    <VNavigationDrawer v-model="drawer" temporary location="end" width="420">
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">{{ editingId ? $t('organization.locations.edit') : $t('organization.locations.add') }}</h5>
        <VBtn icon variant="text" size="small" @click="drawer = false"><VIcon icon="tabler-x" /></VBtn>
      </div>
      <VDivider />
      <div class="pa-4">
        <VForm @submit.prevent="submit">
          <AppTextField v-model="form.name" :label="$t('organization.locations.columns.name')" class="mb-3" :rules="[v => !!v || $t('validation.required')]" />
          <AppSelect v-model="form.type" :items="typeOptions" :label="$t('organization.locations.columns.type')" class="mb-3" />
          <AppTextField v-if="form.type === 'client_site'" v-model="form.client_name" :label="$t('organization.locations.clientName')" class="mb-3" />
          <AppTextField v-model="form.address" :label="$t('organization.locations.columns.address')" class="mb-3" />
          <AppTextField v-model="form.timezone" :label="$t('organization.locations.timezone')" placeholder="Europe/Paris" class="mb-3" />
          <VSwitch v-model="form.enabled" :label="$t('organization.locations.columns.active')" color="primary" class="mb-3" />
          <div class="d-flex gap-3 justify-end">
            <VBtn variant="outlined" @click="drawer = false">{{ $t('common.cancel') }}</VBtn>
            <VBtn type="submit" color="primary" :loading="saving">{{ $t('common.save') }}</VBtn>
          </div>
        </VForm>
      </div>
    </VNavigationDrawer>

    <VDialog v-model="confirmDialog" max-width="420">
      <VCard>
        <VCardTitle class="text-h6 pa-4">{{ $t('organization.locations.deleteTitle') }}</VCardTitle>
        <VCardText>{{ $t('organization.locations.deleteText') }}</VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="outlined" @click="confirmDialog = false">{{ $t('common.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ $t('common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
