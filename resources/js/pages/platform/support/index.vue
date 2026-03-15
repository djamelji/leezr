<script setup>
import { usePlatformSupportStore } from '@/modules/platform-admin/support/support.store'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    navActiveKey: 'platform-support',
    module: 'platform.support',
  },
})

const { t } = useI18n()
const router = useRouter()
const store = usePlatformSupportStore()

const statusFilter = ref(null)
const priorityFilter = ref(null)
const search = ref('')
const page = ref(1)

const headers = computed(() => [
  { title: '#', key: 'id', width: 60 },
  { title: t('support.subject'), key: 'subject' },
  { title: t('support.company'), key: 'company.name', width: 160 },
  { title: t('support.status'), key: 'status', width: 140 },
  { title: t('support.priority'), key: 'priority', width: 120 },
  { title: t('support.assignedTo'), key: 'assignee', width: 160 },
  { title: t('support.lastMessage'), key: 'last_message_at', width: 140 },
])

const statusColors = {
  open: 'info',
  in_progress: 'warning',
  waiting_customer: 'primary',
  resolved: 'success',
  closed: 'secondary',
}

const priorityColors = {
  low: 'secondary',
  normal: 'info',
  high: 'warning',
  urgent: 'error',
}

const statusOptions = [
  { title: t('support.statusOpen'), value: 'open' },
  { title: t('support.statusInProgress'), value: 'in_progress' },
  { title: t('support.statusWaitingCustomer'), value: 'waiting_customer' },
  { title: t('support.statusResolved'), value: 'resolved' },
  { title: t('support.statusClosed'), value: 'closed' },
]

const priorityOptions = [
  { title: t('support.priorityLow'), value: 'low' },
  { title: t('support.priorityNormal'), value: 'normal' },
  { title: t('support.priorityHigh'), value: 'high' },
  { title: t('support.priorityUrgent'), value: 'urgent' },
]

const loadTickets = () => {
  const params = { page: page.value }

  if (statusFilter.value) params.status = statusFilter.value
  if (priorityFilter.value) params.priority = priorityFilter.value
  if (search.value) params.search = search.value

  store.fetchTickets(params)
}

const openTicket = row => {
  router.push({ name: 'platform-support-id', params: { id: row.id } })
}

const formatDate = d => d ? new Date(d).toLocaleDateString() : '—'

watch([statusFilter, priorityFilter, search], () => {
  page.value = 1
  loadTickets()
})

onMounted(() => {
  store.fetchMetrics()
  loadTickets()
})
</script>

<template>
  <div>
    <!-- Metrics -->
    <VRow
      v-if="store.metrics"
      class="mb-4"
    >
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h4">
              {{ store.metrics.open }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('support.statusOpen') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h4">
              {{ store.metrics.in_progress }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('support.statusInProgress') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h4">
              {{ store.metrics.waiting_customer }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('support.statusWaitingCustomer') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h4 text-warning">
              {{ store.metrics.unassigned }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('support.unassigned') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tickets List -->
    <VCard>
      <VCardTitle>{{ t('support.allTickets') }}</VCardTitle>
      <VCardText>
        <VRow class="mb-4">
          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model="search"
              :placeholder="t('support.searchPlaceholder')"
              prepend-inner-icon="tabler-search"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :placeholder="t('support.status')"
              clearable
              @click:clear="statusFilter = null"
            />
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <AppSelect
              v-model="priorityFilter"
              :items="priorityOptions"
              :placeholder="t('support.priority')"
              clearable
              @click:clear="priorityFilter = null"
            />
          </VCol>
        </VRow>

        <VDataTable
          :headers="headers"
          :items="store.tickets"
          :loading="store.loading"
          item-value="id"
          class="cursor-pointer"
          @click:row="(_, { item }) => openTicket(item)"
        >
          <template #item.status="{ item }">
            <VChip
              :color="statusColors[item.status]"
              size="small"
            >
              {{ t(`support.status${item.status.charAt(0).toUpperCase() + item.status.slice(1).replace(/_([a-z])/g, (_, c) => c.toUpperCase())}`) }}
            </VChip>
          </template>

          <template #item.priority="{ item }">
            <VChip
              :color="priorityColors[item.priority]"
              size="small"
              variant="tonal"
            >
              {{ t(`support.priority${item.priority.charAt(0).toUpperCase() + item.priority.slice(1)}`) }}
            </VChip>
          </template>

          <template #item.assignee="{ item }">
            <span v-if="item.assignee">
              {{ item.assignee.first_name }} {{ item.assignee.last_name }}
            </span>
            <VChip
              v-else
              size="small"
              color="warning"
              variant="tonal"
            >
              {{ t('support.unassigned') }}
            </VChip>
          </template>

          <template #item.last_message_at="{ item }">
            {{ formatDate(item.last_message_at) }}
          </template>

          <template #no-data>
            <div class="text-center pa-8">
              <VIcon
                icon="tabler-headset"
                size="48"
                class="mb-4 text-medium-emphasis"
              />
              <p class="text-body-1 text-medium-emphasis">
                {{ t('support.noTicketsYet') }}
              </p>
            </div>
          </template>
        </VDataTable>
      </VCardText>
    </VCard>
  </div>
</template>
