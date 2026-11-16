<script setup>
import { usePlatformSupportStore } from '@/modules/platform-admin/support/support.store'
import { useAppToast } from '@/composables/useAppToast'
import { formatDate } from '@/utils/datetime'

// ADR-801.1 — assignment queue for `assign_support` WITHOUT `manage_support` : dispatch only.
// Minimal projection from /support/assignment-queue (no messages, internal notes, requester identity).
// Sub-component — no definePage — hub: platform/communications/[tab].vue

const { t } = useI18n()
const { toast } = useAppToast()
const store = usePlatformSupportStore()

const tickets = ref([])
const total = ref(0)
const page = ref(1)
const search = ref('')
const loading = ref(false)
const assignees = ref([])
const saving = ref(null)

const headers = computed(() => [
  { title: '#', key: 'id', width: 60 },
  { title: t('support.subject'), key: 'subject' },
  { title: t('support.company'), key: 'company.name', width: 180 },
  { title: t('support.status'), key: 'status', width: 140 },
  { title: t('support.priority'), key: 'priority', width: 120 },
  { title: t('support.lastMessage'), key: 'last_message_at', width: 140 },
  { title: t('support.assignTo'), key: 'assignee', width: 240, sortable: false },
])

const statusLabel = s => t(`support.status${s.charAt(0).toUpperCase() + s.slice(1).replace(/_([a-z])/g, (_, c) => c.toUpperCase())}`)
const priorityLabel = p => t(`support.priority${p.charAt(0).toUpperCase() + p.slice(1)}`)
const agentItems = computed(() => assignees.value.map(a => ({ title: `${a.first_name ?? ''} ${a.last_name ?? ''}`.trim(), value: a.id })))

const load = async () => {
  loading.value = true
  try {
    const data = await store.fetchAssignmentQueue({ page: page.value, search: search.value })

    tickets.value = data.data || []
    total.value = data.total || 0
  }
  finally {
    loading.value = false
  }
}

const assign = async (ticket, platformUserId) => {
  if (!platformUserId || platformUserId === ticket.assignee?.id) return
  saving.value = ticket.id
  try {
    const updated = await store.assignTicket(ticket.id, platformUserId)

    ticket.assignee = updated.assignee
    ticket.status = updated.status
    toast(t('support.assigned'), 'success')
  }
  catch (e) {
    toast(e?.data?.code === 'assignee_not_eligible' ? t('support.assigneeNotEligible') : (e?.data?.message || t('common.error')), 'error')
  }
  finally {
    saving.value = null
  }
}

watch(search, () => { page.value = 1; load() })
watch(page, load)
onMounted(async () => {
  assignees.value = await store.fetchAssignees()
  await load()
})
</script>

<template>
  <VCard data-test="support-assignment-queue">
    <VCardItem>
      <VCardTitle>{{ t('support.assignmentQueueTitle') }}</VCardTitle>
      <VCardSubtitle class="text-wrap">
        {{ t('support.assignmentQueueIntro') }}
      </VCardSubtitle>
    </VCardItem>
    <VCardText>
      <VAlert
        v-if="!assignees.length && !loading"
        type="warning"
        variant="tonal"
        density="compact"
        class="mb-4"
      >
        {{ t('support.noAgent') }}
      </VAlert>
      <AppTextField
        v-model="search"
        :placeholder="t('support.searchPlaceholder')"
        prepend-inner-icon="tabler-search"
        clearable
        class="mb-4"
        style="max-inline-size: 360px;"
      />
      <VDataTableServer
        v-model:page="page"
        :headers="headers"
        :items="tickets"
        :items-length="total"
        :loading="loading"
        :items-per-page="15"
      >
        <template #item.status="{ item }">
          <VChip size="small">
            {{ statusLabel(item.status) }}
          </VChip>
        </template>
        <template #item.priority="{ item }">
          <VChip size="small">
            {{ priorityLabel(item.priority) }}
          </VChip>
        </template>
        <template #item.last_message_at="{ item }">
          {{ item.last_message_at ? formatDate(item.last_message_at) : '—' }}
        </template>
        <template #item.assignee="{ item }">
          <AppSelect
            :model-value="item.assignee?.id ?? null"
            :items="agentItems"
            :placeholder="t('support.unassigned')"
            :loading="saving === item.id"
            :disabled="saving === item.id || !agentItems.length"
            density="compact"
            :data-test="`assign-${item.id}`"
            @update:model-value="v => assign(item, v)"
          />
        </template>
      </VDataTableServer>
    </VCardText>
  </VCard>
</template>
