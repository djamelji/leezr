<script setup>
definePage({ meta: { surface: 'structure' } })

import { useAuthStore } from '@/core/stores/auth'
import { useModuleStore } from '@/core/stores/module'

const auth = useAuthStore()
const moduleStore = useModuleStore()

const isLoading = ref(true)
const togglingKey = ref(null)
const errorMessage = ref('')

const canManage = computed(() => auth.roleLevel === 'management')

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
  errorMessage.value = ''

  try {
    if (module.is_enabled_for_company) {
      await moduleStore.disableModule(module.key)
    }
    else {
      await moduleStore.enableModule(module.key)
    }
  }
  catch (error) {
    errorMessage.value = error?.data?.message || 'Failed to toggle module.'
  }
  finally {
    togglingKey.value = null
  }
}

const isToggleDisabled = module => {
  if (!canManage.value)
    return true
  if (togglingKey.value === module.key)
    return true
  if (!module.is_enabled_globally)
    return true
  if (module.type === 'core')
    return true
  if (!module.is_entitled)
    return true

  return false
}

const entitlementChip = module => {
  if (module.type === 'core')
    return { text: 'Core', color: 'primary' }
  if (!module.is_entitled) {
    if (module.entitlement_reason === 'plan_required')
      return { text: `Requires ${module.min_plan}`, color: 'warning' }
    if (module.entitlement_reason === 'incompatible_jobdomain')
      return { text: 'Not available', color: 'error' }

    return { text: 'Not available', color: 'secondary' }
  }
  if (module.entitlement_source === 'jobdomain')
    return { text: 'Included', color: 'success' }

  return null
}

const headers = [
  { title: 'Module', key: 'name' },
  { title: 'Description', key: 'description', sortable: false },
  { title: '', key: 'entitlement', align: 'center', width: '140px', sortable: false },
  { title: 'Status', key: 'status', align: 'center', width: '100px', sortable: false },
]
</script>

<template>
  <div>
    <VAlert
      v-if="errorMessage"
      type="error"
      class="mb-4"
      closable
      @click:close="errorMessage = ''"
    >
      {{ errorMessage }}
    </VAlert>

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
              <span
                class="text-body-1 font-weight-medium"
                :class="item.is_entitled ? 'text-high-emphasis' : 'text-disabled'"
              >
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
          <span
            class="text-body-2"
            :class="{ 'text-disabled': !item.is_entitled }"
          >
            {{ item.description || '—' }}
          </span>
        </template>

        <!-- Entitlement badge -->
        <template #item.entitlement="{ item }">
          <VChip
            v-if="entitlementChip(item)"
            :color="entitlementChip(item).color"
            size="small"
            variant="tonal"
          >
            {{ entitlementChip(item).text }}
          </VChip>
        </template>

        <!-- Status toggle -->
        <template #item.status="{ item }">
          <VSwitch
            :model-value="item.type === 'core' ? true : item.is_enabled_for_company"
            :disabled="isToggleDisabled(item)"
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
