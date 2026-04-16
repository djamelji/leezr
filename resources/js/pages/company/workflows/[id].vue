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
const route = useRoute()
const router = useRouter()
const store = useWorkflowsStore()
const { can } = useCan()

const ruleId = computed(() => Number(route.params.id))
const isEditing = ref(false)
const editForm = ref({})
const deleteDialog = ref(false)
const logsPage = ref(1)

const statusColors = {
  success: 'success',
  partial: 'warning',
  skipped: 'secondary',
  failed: 'error',
}

const statusIcons = {
  success: 'tabler-check',
  partial: 'tabler-alert-triangle',
  skipped: 'tabler-player-skip-forward',
  failed: 'tabler-x',
}

const rule = computed(() => store.currentRule)

const triggerLabel = computed(() => {
  if (!rule.value) return ''

  return store.triggers[rule.value.trigger_topic]?.label || rule.value.trigger_topic
})

const startEdit = () => {
  if (!rule.value) return
  editForm.value = {
    name: rule.value.name,
    enabled: rule.value.enabled,
    max_executions_per_day: rule.value.max_executions_per_day,
    cooldown_minutes: rule.value.cooldown_minutes,
  }
  isEditing.value = true
}

const saveEdit = async () => {
  try {
    await store.updateRule(ruleId.value, editForm.value)
    isEditing.value = false
  }
  catch {
    // Error handled by store
  }
}

const onDelete = async () => {
  try {
    await store.deleteRule(ruleId.value)
    router.push({ name: 'company-workflows' })
  }
  catch {
    // Error handled by store
  }
  finally {
    deleteDialog.value = false
  }
}

const onToggle = async () => {
  if (!rule.value) return
  await store.toggleEnabled(rule.value)
}

const loadLogs = () => {
  store.fetchLogs(ruleId.value, { page: logsPage.value })
}

watch(logsPage, loadLogs)

onMounted(async () => {
  await Promise.all([
    store.fetchRule(ruleId.value),
    store.fetchTriggers(),
    store.fetchLogs(ruleId.value),
  ])
})
</script>

<template>
  <div>
    <!-- Loading -->
    <VCard
      v-if="store.loading.currentRule"
      class="mb-4"
    >
      <VCardText class="text-center pa-8">
        <VProgressCircular
          indeterminate
          color="primary"
        />
      </VCardText>
    </VCard>

    <!-- Error -->
    <VAlert
      v-else-if="store.error && !rule"
      type="error"
      class="mb-4"
    >
      {{ store.error }}
      <template #append>
        <VBtn
          variant="text"
          @click="router.push({ name: 'company-workflows' })"
        >
          {{ t('common.back') }}
        </VBtn>
      </template>
    </VAlert>

    <template v-else-if="rule">
      <!-- Header -->
      <div class="d-flex align-center mb-4">
        <VBtn
          icon
          variant="text"
          size="small"
          class="me-2"
          @click="router.push({ name: 'company-workflows' })"
        >
          <VIcon icon="tabler-arrow-left" />
        </VBtn>
        <h4 class="text-h4 flex-grow-1">
          {{ rule.name }}
        </h4>
        <VSwitch
          v-can="'automations.manage'"
          :model-value="rule.enabled"
          color="success"
          hide-details
          class="me-4"
          @update:model-value="onToggle"
        />
        <VBtn
          v-can="'automations.manage'"
          variant="tonal"
          class="me-2"
          @click="startEdit"
        >
          {{ t('common.edit') }}
        </VBtn>
        <VBtn
          v-can="'automations.manage'"
          variant="tonal"
          color="error"
          @click="deleteDialog = true"
        >
          {{ t('common.delete') }}
        </VBtn>
      </div>

      <VRow>
        <!-- Rule details -->
        <VCol
          cols="12"
          md="5"
        >
          <VCard class="mb-4">
            <VCardTitle>{{ t('workflows.details') }}</VCardTitle>
            <VCardText>
              <VList density="compact">
                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-bolt"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workflows.trigger') }}
                  </VListItemTitle>
                  <VListItemSubtitle>
                    <VChip
                      size="small"
                      color="info"
                      variant="tonal"
                    >
                      {{ triggerLabel }}
                    </VChip>
                  </VListItemSubtitle>
                </VListItem>

                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-hash"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workflows.execToday') }}
                  </VListItemTitle>
                  <VListItemSubtitle>
                    {{ rule.executions_today }} / {{ rule.max_executions_per_day }}
                  </VListItemSubtitle>
                </VListItem>

                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-clock"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workflows.cooldown') }}
                  </VListItemTitle>
                  <VListItemSubtitle>
                    {{ rule.cooldown_minutes }} min
                  </VListItemSubtitle>
                </VListItem>

                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-calendar"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workflows.lastExec') }}
                  </VListItemTitle>
                  <VListItemSubtitle>
                    {{ rule.last_executed_at ? formatDateTime(rule.last_executed_at) : '—' }}
                  </VListItemSubtitle>
                </VListItem>
              </VList>
            </VCardText>
          </VCard>

          <!-- Conditions -->
          <VCard class="mb-4">
            <VCardTitle>{{ t('workflows.conditions') }}</VCardTitle>
            <VCardText>
              <div
                v-if="rule.conditions && rule.conditions.length > 0"
              >
                <VChip
                  v-for="(cond, idx) in rule.conditions"
                  :key="idx"
                  size="small"
                  variant="tonal"
                  color="primary"
                  class="me-2 mb-2"
                >
                  {{ cond.field }} {{ cond.operator }} {{ cond.value }}
                </VChip>
              </div>
              <p
                v-else
                class="text-body-2 text-medium-emphasis"
              >
                {{ t('workflows.noConditions') }}
              </p>
            </VCardText>
          </VCard>

          <!-- Actions -->
          <VCard>
            <VCardTitle>{{ t('workflows.actions') }}</VCardTitle>
            <VCardText>
              <VList density="compact">
                <VListItem
                  v-for="(action, idx) in rule.actions"
                  :key="idx"
                >
                  <template #prepend>
                    <VAvatar
                      size="32"
                      :color="action.type === 'webhook' ? 'warning' : 'info'"
                      variant="tonal"
                    >
                      <VIcon
                        :icon="action.type === 'webhook' ? 'tabler-webhook' : action.type === 'log' ? 'tabler-file-text' : 'tabler-bell'"
                        size="18"
                      />
                    </VAvatar>
                  </template>
                  <VListItemTitle>
                    {{ t(`workflows.action_${action.type}`) }}
                  </VListItemTitle>
                  <VListItemSubtitle v-if="action.config?.url">
                    {{ action.config.url }}
                  </VListItemSubtitle>
                </VListItem>
              </VList>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Execution logs -->
        <VCol
          cols="12"
          md="7"
        >
          <VCard>
            <VCardTitle class="d-flex align-center justify-space-between">
              <span>{{ t('workflows.executionLogs') }}</span>
              <VBtn
                variant="text"
                size="small"
                icon
                @click="loadLogs"
              >
                <VIcon icon="tabler-refresh" />
              </VBtn>
            </VCardTitle>
            <VCardText>
              <!-- Loading -->
              <div
                v-if="store.loading.logs"
                class="text-center pa-4"
              >
                <VProgressCircular
                  indeterminate
                  size="24"
                />
              </div>

              <!-- Empty -->
              <div
                v-else-if="store.logs.length === 0"
                class="text-center pa-6"
              >
                <VIcon
                  icon="tabler-history"
                  size="40"
                  class="mb-3 text-medium-emphasis"
                />
                <p class="text-body-2 text-medium-emphasis">
                  {{ t('workflows.noLogs') }}
                </p>
              </div>

              <!-- Timeline -->
              <VTimeline
                v-else
                align="start"
                line-inset="19"
                truncate-line="start"
                density="compact"
              >
                <VTimelineItem
                  v-for="log in store.logs"
                  :key="log.id"
                  fill-dot
                  size="small"
                >
                  <template #icon>
                    <div class="v-timeline-avatar-wrapper rounded-circle">
                      <VAvatar
                        size="28"
                        :color="statusColors[log.status]"
                        variant="tonal"
                      >
                        <VIcon
                          :icon="statusIcons[log.status]"
                          size="16"
                        />
                      </VAvatar>
                    </div>
                  </template>

                  <div class="d-flex align-center justify-space-between mb-1">
                    <VChip
                      :color="statusColors[log.status]"
                      size="x-small"
                    >
                      {{ t(`workflows.status_${log.status}`) }}
                    </VChip>
                    <span class="text-caption text-medium-emphasis">
                      {{ formatDateTime(log.created_at) }}
                    </span>
                  </div>

                  <div
                    v-if="log.duration_ms"
                    class="text-caption text-medium-emphasis"
                  >
                    {{ log.duration_ms }}ms
                  </div>

                  <div
                    v-if="log.error_message"
                    class="text-caption text-error mt-1"
                  >
                    {{ log.error_message }}
                  </div>
                </VTimelineItem>
              </VTimeline>

              <!-- Pagination -->
              <div
                v-if="store.logsPagination.last_page > 1"
                class="d-flex justify-center mt-4"
              >
                <VPagination
                  v-model="logsPage"
                  :length="store.logsPagination.last_page"
                  :total-visible="5"
                  size="small"
                />
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- Edit Dialog -->
    <VDialog
      v-model="isEditing"
      max-width="500"
    >
      <VCard :title="t('workflows.editTitle')">
        <VCardText>
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="editForm.name"
                :label="t('workflows.name')"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model.number="editForm.max_executions_per_day"
                :label="t('workflows.maxPerDay')"
                type="number"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model.number="editForm.cooldown_minutes"
                :label="t('workflows.cooldown')"
                type="number"
              />
            </VCol>
            <VCol cols="12">
              <VSwitch
                v-model="editForm.enabled"
                :label="t('workflows.enabledLabel')"
                color="success"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="isEditing = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="store.loading.saving"
            @click="saveEdit"
          >
            {{ t('common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete confirmation -->
    <VDialog
      v-model="deleteDialog"
      max-width="400"
    >
      <VCard>
        <VCardTitle>{{ t('workflows.deleteTitle') }}</VCardTitle>
        <VCardText>{{ t('workflows.deleteConfirm', { name: rule?.name }) }}</VCardText>
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

<style lang="scss">
.v-timeline-avatar-wrapper {
  background-color: rgb(var(--v-theme-surface));
}
</style>
