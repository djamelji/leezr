<script setup>
import { formatDate } from '@/utils/datetime'
import EmptyState from '@/core/components/EmptyState.vue'

const props = defineProps({
  members: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
})

const emit = defineEmits(['refresh'])

const { t } = useI18n()

const search = ref('')
const roleFilter = ref('')

const roleColor = role => {
  const map = { owner: 'primary', management: 'warning', employee: 'info' }

  return map[role] || 'secondary'
}

const roleOptions = computed(() => [
  { title: t('common.all'), value: '' },
  { title: t('roles.owner'), value: 'owner' },
  { title: t('roles.management'), value: 'management' },
  { title: t('roles.employee'), value: 'employee' },
])

const headers = [
  { title: t('common.name'), key: 'name' },
  { title: t('common.email'), key: 'email' },
  { title: t('common.role'), key: 'role', width: 120 },
  { title: t('common.joined'), key: 'created_at', width: 130 },
]

const filteredMembers = computed(() => {
  let result = props.members

  if (roleFilter.value) {
    result = result.filter(m => m.role === roleFilter.value)
  }

  if (search.value) {
    const q = search.value.toLowerCase()

    result = result.filter(m =>
      m.name?.toLowerCase().includes(q)
      || m.email?.toLowerCase().includes(q),
    )
  }

  return result
})
</script>

<template>
  <VCard
    flat
    border
  >
    <VCardTitle class="d-flex align-center flex-wrap gap-2">
      <VIcon
        icon="tabler-users"
        class="me-2"
      />
      {{ t('platformCompanyDetail.members.title') }}
      <VChip
        size="x-small"
        class="ms-2"
        color="info"
        variant="tonal"
      >
        {{ members.length }}
      </VChip>
      <VSpacer />
      <AppTextField
        v-model="search"
        :placeholder="t('common.search')"
        density="compact"
        prepend-inner-icon="tabler-search"
        style="max-inline-size: 200px;"
        clearable
      />
      <AppSelect
        v-model="roleFilter"
        :items="roleOptions"
        density="compact"
        style="max-inline-size: 140px;"
      />
      <VBtn
        icon
        variant="text"
        size="small"
        @click="emit('refresh')"
      >
        <VIcon
          icon="tabler-refresh"
          size="20"
        />
        <VTooltip activator="parent">
          {{ t('common.refresh') }}
        </VTooltip>
      </VBtn>
    </VCardTitle>

    <div
      v-if="loading"
      class="text-center pa-8"
    >
      <VProgressCircular indeterminate />
    </div>

    <VDataTable
      v-else-if="filteredMembers.length"
      :items="filteredMembers"
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
          <VIcon
            icon="tabler-crown"
            size="12"
            class="me-1"
          />
          {{ t('roles.owner') }}
        </VChip>
      </template>
      <template #item.email="{ item }">
        <a
          :href="`mailto:${item.email}`"
          class="text-primary text-decoration-none"
        >
          {{ item.email || '—' }}
        </a>
      </template>
      <template #item.role="{ item }">
        <VChip
          :color="roleColor(item.role)"
          size="x-small"
        >
          {{ t(`roles.${item.role}`) }}
        </VChip>
      </template>
      <template #item.created_at="{ item }">
        {{ formatDate(item.created_at) }}
      </template>
    </VDataTable>

    <EmptyState
      v-else-if="!loading"
      icon="tabler-users-minus"
      :title="t('platformCompanyDetail.members.noMembers')"
    />
  </VCard>
</template>
