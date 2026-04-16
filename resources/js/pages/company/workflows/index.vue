<script setup>
import { useWorkflowsStore } from '@/modules/company/workflows/workflows.store'
import { formatDateTime } from '@/utils/datetime'

definePage({
  meta: {
    navActiveKey: 'workflows',
    module: 'core.workflows',
    permission: 'automations.view',
  },
})

const { t } = useI18n()
const router = useRouter()
const store = useWorkflowsStore()
const { can } = useCan()

const isDrawerOpen = ref(false)
const deleteDialog = ref(false)
const ruleToDelete = ref(null)

const headers = computed(() => [
  { title: t('workflows.name'), key: 'name' },
  { title: t('workflows.trigger'), key: 'trigger_topic', width: 180 },
  { title: t('workflows.enabled'), key: 'enabled', width: 120, align: 'center' },
  { title: t('workflows.lastExec'), key: 'last_executed_at', width: 160 },
  { title: t('workflows.execToday'), key: 'executions_today', width: 100, align: 'center' },
  { title: t('common.actions'), key: 'actions', width: 100, sortable: false, align: 'center' },
])

const triggerLabel = topic => {
  return store.triggers[topic]?.label || topic
}


const onToggle = async rule => {
  try {
    await store.toggleEnabled(rule)
  }
  catch {
    // Error handled by store
  }
}

const openDetail = row => {
  router.push({ name: 'company-workflows-id', params: { id: row.id } })
}

const confirmDelete = rule => {
  ruleToDelete.value = rule
  deleteDialog.value = true
}

const onDelete = async () => {
  if (!ruleToDelete.value) return
  try {
    await store.deleteRule(ruleToDelete.value.id)
  }
  catch {
    // Error handled by store
  }
  finally {
    deleteDialog.value = false
    ruleToDelete.value = null
  }
}

const onCreated = () => {
  isDrawerOpen.value = false
  store.fetchRules()
}

onMounted(() => {
  store.fetchRules()
})
</script>

<template>
  <div>
    <!-- Error alert -->
    <VAlert
      v-if="store.error"
      type="error"
      class="mb-4"
      closable
    >
      {{ store.error }}
    </VAlert>

    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between">
        <span>{{ t('workflows.title') }}</span>
        <VBtn
          v-can="'automations.manage'"
          color="primary"
          prepend-icon="tabler-plus"
          @click="isDrawerOpen = true"
        >
          {{ t('workflows.create') }}
        </VBtn>
      </VCardTitle>

      <VCardText class="text-body-2 text-medium-emphasis pb-0">
        {{ t('workflows.subtitle') }}
      </VCardText>

      <VCardText>
        <VDataTable
          :headers="headers"
          :items="store.rules"
          :loading="store.loading.rules"
          item-value="id"
          class="cursor-pointer"
          @click:row="(_, { item }) => openDetail(item)"
        >
          <template #item.trigger_topic="{ item }">
            <VChip
              size="small"
              color="info"
              variant="tonal"
            >
              {{ triggerLabel(item.trigger_topic) }}
            </VChip>
          </template>

          <template #item.enabled="{ item }">
            <VSwitch
              :model-value="item.enabled"
              color="success"
              hide-details
              density="compact"
              :disabled="!can('automations.manage')"
              @click.stop
              @update:model-value="onToggle(item)"
            />
          </template>

          <template #item.last_executed_at="{ item }">
            {{ formatDateTime(item.last_executed_at) }}
          </template>

          <template #item.actions="{ item }">
            <VBtn
              v-can="'automations.manage'"
              icon
              size="small"
              variant="text"
              color="error"
              @click.stop="confirmDelete(item)"
            >
              <VIcon icon="tabler-trash" />
            </VBtn>
          </template>

          <!-- Empty state -->
          <template #no-data>
            <div class="text-center pa-8">
              <VIcon
                icon="tabler-automation"
                size="48"
                class="mb-4 text-medium-emphasis"
              />
              <p class="text-h6 mb-2">
                {{ t('workflows.emptyTitle') }}
              </p>
              <p class="text-body-2 text-medium-emphasis mb-4">
                {{ t('workflows.emptySubtitle') }}
              </p>
              <VBtn
                v-can="'automations.manage'"
                color="primary"
                prepend-icon="tabler-plus"
                @click="isDrawerOpen = true"
              >
                {{ t('workflows.create') }}
              </VBtn>
            </div>
          </template>
        </VDataTable>
      </VCardText>
    </VCard>

    <!-- Create drawer -->
    <WorkflowCreateDrawer
      v-model:is-drawer-open="isDrawerOpen"
      :triggers="store.triggers"
      @created="onCreated"
    />

    <!-- Delete confirmation -->
    <VDialog
      v-model="deleteDialog"
      max-width="400"
    >
      <VCard>
        <VCardTitle>{{ t('workflows.deleteTitle') }}</VCardTitle>
        <VCardText>{{ t('workflows.deleteConfirm', { name: ruleToDelete?.name }) }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="deleteDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            :loading="store.loading.saving"
            @click="onDelete"
          >
            {{ t('common.delete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
