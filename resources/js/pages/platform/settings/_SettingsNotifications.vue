<script setup>
import { usePlatformNotificationStore } from '@/modules/platform-admin/notifications/notifications.store'

const { t } = useI18n()
const store = usePlatformNotificationStore()
const isEditDialogVisible = ref(false)
const editingTopic = ref(null)
const editForm = ref({})

onMounted(() => store.fetchTopics())

const categoryColors = {
  billing: 'primary',
  members: 'info',
  modules: 'success',
  security: 'error',
  system: 'secondary',
  support: 'warning',
}

const severityColors = {
  info: 'info',
  success: 'success',
  warning: 'warning',
  error: 'error',
}

const headers = [
  { title: '', key: 'icon', width: 50, sortable: false },
  { title: t('notifications.topic'), key: 'label' },
  { title: t('notifications.category'), key: 'category' },
  { title: t('notifications.scope'), key: 'scope' },
  { title: t('notifications.severity'), key: 'severity' },
  { title: t('notifications.defaultChannels'), key: 'default_channels', sortable: false },
  { title: t('notifications.deliveries7d'), key: 'delivery_count_7d' },
  { title: t('notifications.active'), key: 'is_active', sortable: false },
]

const openEdit = topic => {
  editingTopic.value = topic
  editForm.value = {
    label: topic.label,
    icon: topic.icon,
    severity: topic.severity,
    default_channels: [...(topic.default_channels || [])],
    sort_order: topic.sort_order,
  }
  isEditDialogVisible.value = true
}

const saveEdit = async () => {
  if (!editingTopic.value) return
  await store.updateTopic(editingTopic.value.key, editForm.value)
  isEditDialogVisible.value = false
}

const toggleActive = async topic => {
  await store.toggleTopic(topic.key)
}
</script>

<template>
  <VCard>
    <VCardTitle class="d-flex align-center">
      <VIcon
        icon="tabler-bell-cog"
        class="me-2"
      />
      {{ t('notifications.topicManagement') }}
    </VCardTitle>
    <VCardSubtitle>
      {{ t('notifications.topicManagementDesc') }}
    </VCardSubtitle>

    <VDataTable
      :headers="headers"
      :items="store.topics"
      :loading="store.loading"
      item-value="key"
      class="text-no-wrap"
      @click:row="(e, { item }) => openEdit(item)"
    >
      <template #item.icon="{ item }">
        <VAvatar
          size="32"
          :color="severityColors[item.severity] || 'info'"
          variant="tonal"
        >
          <VIcon
            :icon="item.icon"
            size="18"
          />
        </VAvatar>
      </template>

      <template #item.category="{ item }">
        <VChip
          size="small"
          :color="categoryColors[item.category] || 'secondary'"
        >
          {{ t(`notifications.category${item.category.charAt(0).toUpperCase() + item.category.slice(1)}`) }}
        </VChip>
      </template>

      <template #item.scope="{ item }">
        <VChip
          size="small"
          variant="outlined"
        >
          {{ t(`notifications.scope${item.scope.charAt(0).toUpperCase() + item.scope.slice(1)}`) }}
        </VChip>
      </template>

      <template #item.severity="{ item }">
        <VChip
          size="small"
          :color="severityColors[item.severity]"
        >
          {{ item.severity }}
        </VChip>
      </template>

      <template #item.default_channels="{ item }">
        <VChip
          v-for="ch in item.default_channels"
          :key="ch"
          size="small"
          class="me-1"
        >
          {{ ch === 'in_app' ? 'In-App' : 'Email' }}
        </VChip>
      </template>

      <template #item.is_active="{ item }">
        <VSwitch
          :model-value="item.is_active"
          hide-details
          density="compact"
          @update:model-value="toggleActive(item)"
        />
      </template>
    </VDataTable>
  </VCard>

  <!-- Edit Dialog -->
  <VDialog
    v-model="isEditDialogVisible"
    max-width="500"
  >
    <VCard :title="t('notifications.editTopic')">
      <VCardText>
        <VRow>
          <VCol cols="12">
            <AppTextField
              v-model="editForm.label"
              :label="t('notifications.label')"
            />
          </VCol>
          <VCol cols="12">
            <AppTextField
              v-model="editForm.icon"
              :label="t('notifications.icon')"
            />
          </VCol>
          <VCol cols="12">
            <AppSelect
              v-model="editForm.severity"
              :items="['info', 'success', 'warning', 'error']"
              :label="t('notifications.severity')"
            />
          </VCol>
          <VCol cols="12">
            <div class="text-body-2 mb-2">
              {{ t('notifications.defaultChannels') }}
            </div>
            <VCheckbox
              :model-value="editForm.default_channels.includes('in_app')"
              label="In-App"
              hide-details
              @update:model-value="v => {
                if (v) editForm.default_channels.push('in_app')
                else editForm.default_channels = editForm.default_channels.filter(c => c !== 'in_app')
              }"
            />
            <VCheckbox
              :model-value="editForm.default_channels.includes('email')"
              label="Email"
              hide-details
              @update:model-value="v => {
                if (v) editForm.default_channels.push('email')
                else editForm.default_channels = editForm.default_channels.filter(c => c !== 'email')
              }"
            />
          </VCol>
          <VCol cols="12">
            <AppTextField
              v-model.number="editForm.sort_order"
              :label="t('notifications.sortOrder')"
              type="number"
            />
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          variant="outlined"
          @click="isEditDialogVisible = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          @click="saveEdit"
        >
          {{ t('common.save') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
