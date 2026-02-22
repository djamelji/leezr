<script setup>
import { usePlatformSettingsStore } from '@/modules/platform-admin/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'manage_modules',
  },
})

const router = useRouter()
const settingsStore = usePlatformSettingsStore()
const { toast } = useAppToast()
const isLoading = ref(true)
const togglingKey = ref(null)

// Filters
const filterType = ref(null)
const filterPlan = ref(null)
const filterEnabled = ref(null)

onMounted(async () => {
  try {
    await settingsStore.fetchModules()
  }
  finally {
    isLoading.value = false
  }
})

const filteredModules = computed(() => {
  return settingsStore.modules.filter(m => {
    if (filterType.value && m.type !== filterType.value) return false
    if (filterPlan.value === 'none' && m.min_plan !== null) return false
    if (filterPlan.value && filterPlan.value !== 'none' && m.min_plan !== filterPlan.value) return false
    if (filterEnabled.value === true && !m.is_enabled_globally) return false
    if (filterEnabled.value === false && m.is_enabled_globally) return false

    return true
  })
})

const headers = [
  { title: 'Module', key: 'name' },
  { title: 'Key', key: 'key', width: '180px' },
  { title: 'Type', key: 'type', align: 'center', width: '100px', sortable: false },
  { title: 'Min Plan', key: 'min_plan', align: 'center', width: '120px', sortable: false },
  { title: 'Jobdomains', key: 'compatible_jobdomains', align: 'center', width: '140px', sortable: false },
  { title: 'Global', key: 'status', align: 'center', width: '100px', sortable: false },
]

const onRowClick = (_event, { item }) => {
  router.push({ name: 'platform-modules-key', params: { key: item.key } })
}

const toggleModule = async module => {
  togglingKey.value = module.key

  try {
    const data = await settingsStore.toggleModule(module.key)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to toggle module.', 'error')
  }
  finally {
    togglingKey.value = null
  }
}

const planLabel = planKey => {
  const labels = { pro: 'Pro', business: 'Business' }

  return labels[planKey] || planKey
}

const clearFilters = () => {
  filterType.value = null
  filterPlan.value = null
  filterEnabled.value = null
}

const hasFilters = computed(() =>
  filterType.value !== null || filterPlan.value !== null || filterEnabled.value !== null,
)
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-puzzle"
          class="me-2"
        />
        Platform Modules
      </VCardTitle>
      <VCardSubtitle>
        Manage module availability globally. Click a module for details.
      </VCardSubtitle>

      <!-- Filters -->
      <VCardText class="pb-0">
        <VRow>
          <VCol
            cols="12"
            sm="3"
          >
            <VSelect
              v-model="filterType"
              :items="[{ title: 'Core', value: 'core' }, { title: 'Addon', value: 'addon' }]"
              label="Type"
              clearable
              density="compact"
              hide-details
            />
          </VCol>
          <VCol
            cols="12"
            sm="3"
          >
            <VSelect
              v-model="filterPlan"
              :items="[{ title: 'No requirement', value: 'none' }, { title: 'Pro', value: 'pro' }, { title: 'Business', value: 'business' }]"
              label="Min Plan"
              clearable
              density="compact"
              hide-details
            />
          </VCol>
          <VCol
            cols="12"
            sm="3"
          >
            <VSelect
              v-model="filterEnabled"
              :items="[{ title: 'Enabled', value: true }, { title: 'Disabled', value: false }]"
              label="Global Status"
              clearable
              density="compact"
              hide-details
            />
          </VCol>
          <VCol
            cols="12"
            sm="3"
            class="d-flex align-center"
          >
            <VBtn
              v-if="hasFilters"
              variant="text"
              size="small"
              color="secondary"
              @click="clearFilters"
            >
              Clear filters
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>

      <VDataTable
        :headers="headers"
        :items="filteredModules"
        :loading="isLoading"
        item-value="key"
        :items-per-page="-1"
        hide-default-footer
        hover
        class="cursor-pointer"
        @click:row="onRowClick"
      >
        <!-- Module name with icon -->
        <template #item.name="{ item }">
          <div class="d-flex align-center gap-x-3 py-2">
            <VAvatar
              size="32"
              :color="item.type === 'core' ? 'primary' : 'info'"
              variant="tonal"
            >
              <VIcon
                :icon="item.icon_name || 'tabler-puzzle'"
                size="18"
              />
            </VAvatar>
            <span class="text-body-1 font-weight-medium text-high-emphasis">
              {{ item.name }}
            </span>
          </div>
        </template>

        <!-- Key -->
        <template #item.key="{ item }">
          <code class="text-sm">{{ item.key }}</code>
        </template>

        <!-- Type -->
        <template #item.type="{ item }">
          <VChip
            :color="item.type === 'core' ? 'primary' : 'info'"
            size="small"
            variant="tonal"
          >
            {{ item.type === 'core' ? 'Core' : 'Addon' }}
          </VChip>
        </template>

        <!-- Min plan -->
        <template #item.min_plan="{ item }">
          <VChip
            v-if="item.min_plan"
            color="warning"
            size="small"
            variant="tonal"
          >
            {{ planLabel(item.min_plan) }}
          </VChip>
          <span
            v-else
            class="text-disabled"
          >—</span>
        </template>

        <!-- Compatible jobdomains -->
        <template #item.compatible_jobdomains="{ item }">
          <template v-if="item.compatible_jobdomains">
            <VChip
              v-for="jd in item.compatible_jobdomains"
              :key="jd"
              size="small"
              variant="tonal"
              class="me-1"
            >
              {{ jd }}
            </VChip>
          </template>
          <span
            v-else
            class="text-disabled"
          >All</span>
        </template>

        <!-- Global status toggle -->
        <template #item.status="{ item }">
          <div @click.stop>
            <VSwitch
              :model-value="item.is_enabled_globally"
              :loading="togglingKey === item.key"
              :disabled="togglingKey === item.key"
              color="primary"
              hide-details
              @update:model-value="toggleModule(item)"
            />
          </div>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            No modules found.
          </div>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
