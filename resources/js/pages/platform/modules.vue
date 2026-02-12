<script setup>
import { usePlatformStore } from '@/core/stores/platform'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'manage_modules',
  },
})

const platformStore = usePlatformStore()
const { toast } = useAppToast()
const isLoading = ref(true)
const togglingKey = ref(null)

onMounted(async () => {
  try {
    await platformStore.fetchModules()
  }
  finally {
    isLoading.value = false
  }
})

const headers = [
  { title: 'Module', key: 'name' },
  { title: 'Key', key: 'key' },
  { title: 'Description', key: 'description', sortable: false },
  { title: 'Global Status', key: 'status', align: 'center', width: '140px', sortable: false },
]

const toggleModule = async module => {
  togglingKey.value = module.key

  try {
    const data = await platformStore.toggleModule(module.key)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to toggle module.', 'error')
  }
  finally {
    togglingKey.value = null
  }
}
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
        Toggle module availability globally across all companies.
      </VCardSubtitle>

      <VDataTable
        :headers="headers"
        :items="platformStore.modules"
        :loading="isLoading"
        item-value="key"
        :items-per-page="-1"
        hide-default-footer
      >
        <!-- Module name -->
        <template #item.name="{ item }">
          <span class="text-body-1 font-weight-medium text-high-emphasis">
            {{ item.name }}
          </span>
        </template>

        <!-- Key -->
        <template #item.key="{ item }">
          <code class="text-sm">{{ item.key }}</code>
        </template>

        <!-- Description -->
        <template #item.description="{ item }">
          <span class="text-body-2">
            {{ item.description || '—' }}
          </span>
        </template>

        <!-- Global status toggle -->
        <template #item.status="{ item }">
          <VSwitch
            :model-value="item.is_enabled_globally"
            :loading="togglingKey === item.key"
            :disabled="togglingKey === item.key"
            color="primary"
            hide-details
            @update:model-value="toggleModule(item)"
          />
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
