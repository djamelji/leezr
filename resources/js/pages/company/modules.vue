<script setup>
definePage({ meta: { surface: 'structure' } })

import { useAuthStore } from '@/core/stores/auth'
import { useModuleStore } from '@/core/stores/module'

const auth = useAuthStore()
const moduleStore = useModuleStore()

const isLoading = ref(true)
const togglingKey = ref(null)

const canManage = computed(() => auth.isOwner)

onMounted(async () => {
  try {
    await moduleStore.fetchModules()
  }
  finally {
    isLoading.value = false
  }
})

const toggleModule = async module => {
  togglingKey.value = module.key

  try {
    if (module.is_enabled_for_company) {
      await moduleStore.disableModule(module.key)
    }
    else {
      await moduleStore.enableModule(module.key)
    }
  }
  catch (error) {
    console.error('Failed to toggle module:', error)
  }
  finally {
    togglingKey.value = null
  }
}

const headers = [
  { title: 'Module', key: 'name' },
  { title: 'Description', key: 'description', sortable: false },
  { title: 'Status', key: 'status', align: 'center', width: '140px', sortable: false },
]
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-puzzle"
          class="me-2"
        />
        Modules
      </VCardTitle>
      <VCardSubtitle>
        Manage the modules available for your company.
      </VCardSubtitle>

      <VDataTable
        :headers="headers"
        :items="moduleStore.modules"
        :loading="isLoading"
        item-value="key"
        :items-per-page="-1"
        hide-default-footer
      >
        <!-- Module name -->
        <template #item.name="{ item }">
          <div class="d-flex align-center gap-x-3 py-2">
            <div>
              <span class="text-body-1 font-weight-medium text-high-emphasis">
                {{ item.name }}
              </span>
              <VChip
                v-if="!item.is_enabled_globally"
                size="x-small"
                color="warning"
                class="ms-2"
              >
                Unavailable
              </VChip>
            </div>
          </div>
        </template>

        <!-- Description -->
        <template #item.description="{ item }">
          <span class="text-body-2">
            {{ item.description || '—' }}
          </span>
        </template>

        <!-- Status toggle -->
        <template #item.status="{ item }">
          <VSwitch
            :model-value="item.is_enabled_for_company"
            :disabled="!canManage || !item.is_enabled_globally || togglingKey === item.key"
            :loading="togglingKey === item.key"
            color="primary"
            hide-details
            @update:model-value="toggleModule(item)"
          />
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            No modules available.
          </div>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
