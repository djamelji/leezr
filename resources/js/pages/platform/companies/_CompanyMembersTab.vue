<script setup>
import { formatDate } from '@/utils/datetime'

const props = defineProps({
  members: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
})

const emit = defineEmits(['refresh'])

const { t } = useI18n()

const roleColor = role => {
  const map = { owner: 'primary', management: 'warning', employee: 'info' }

  return map[role] || 'secondary'
}

const headers = [
  { title: t('common.name'), key: 'name' },
  { title: t('common.email'), key: 'email' },
  { title: t('common.role'), key: 'role', width: 120 },
  { title: t('common.joined'), key: 'created_at', width: 130 },
]
</script>

<template>
  <VCard flat border>
    <VCardTitle class="d-flex align-center">
      <VIcon icon="tabler-users" class="me-2" />
      {{ t('platformCompanyDetail.members.title') }}
      <VChip size="x-small" class="ms-2" color="info" variant="tonal">
        {{ members.length }}
      </VChip>
      <VSpacer />
      <VBtn
        icon
        variant="text"
        size="small"
        @click="emit('refresh')"
      >
        <VIcon icon="tabler-refresh" size="20" />
        <VTooltip activator="parent">
          {{ t('common.refresh') }}
        </VTooltip>
      </VBtn>
    </VCardTitle>

    <div v-if="loading" class="text-center pa-8">
      <VProgressCircular indeterminate />
    </div>

    <VDataTable
      v-else-if="members.length"
      :items="members"
      :headers="headers"
      density="compact"
      :items-per-page="-1"
      hide-default-footer
    >
      <template #item.name="{ item }">
        <span class="font-weight-medium">{{ item.name || '—' }}</span>
        <VChip
          v-if="item.role === 'owner'"
          size="x-small"
          color="warning"
          variant="tonal"
          class="ms-2"
        >
          <VIcon icon="tabler-crown" size="12" class="me-1" />
          Owner
        </VChip>
      </template>
      <template #item.email="{ item }">
        <a :href="`mailto:${item.email}`" class="text-primary text-decoration-none">
          {{ item.email || '—' }}
        </a>
      </template>
      <template #item.role="{ item }">
        <VChip :color="roleColor(item.role)" size="x-small">
          {{ item.role }}
        </VChip>
      </template>
      <template #item.created_at="{ item }">
        {{ formatDate(item.created_at) }}
      </template>
    </VDataTable>

    <VCardText v-else>
      <span class="text-disabled">{{ t('platformCompanyDetail.members.noMembers') }}</span>
    </VCardText>
  </VCard>
</template>
