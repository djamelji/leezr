<script setup>
import { useSupportStore } from '@/modules/company/support/support.store'
import { formatDate } from '@/utils/datetime'

definePage({
  meta: {
    navActiveKey: 'company-support',
    module: 'core.support',
    permission: 'support.view',
  },
})

const { t } = useI18n()
const router = useRouter()
const store = useSupportStore()

const statusFilter = ref('')
const page = ref(1)
const isCreateDialogOpen = ref(false)

const newTicket = ref({
  subject: '',
  body: '',
  category: 'general',
})

const headers = computed(() => [
  { title: '#', key: 'id', width: 60 },
  { title: t('support.subject'), key: 'subject' },
  { title: t('support.status'), key: 'status', width: 140 },
  { title: t('support.priority'), key: 'priority', width: 120 },
  { title: t('support.lastMessage'), key: 'last_message_at', width: 160 },
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
  medium: 'info',
  normal: 'info',
  high: 'warning',
  urgent: 'error',
}

const categoryOptions = [
  { title: t('support.categoryGeneral'), value: 'general' },
  { title: t('support.categoryTechnical'), value: 'technical' },
  { title: t('support.categoryBilling'), value: 'billing' },
]

const priorityOptions = [
  { title: t('support.priorityLow'), value: 'low' },
  { title: t('support.priorityNormal'), value: 'normal' },
  { title: t('support.priorityHigh'), value: 'high' },
  { title: t('support.priorityUrgent'), value: 'urgent' },
]

const statusOptions = [
  { title: t('common.all'), value: '' },
  { title: t('support.statusOpen'), value: 'open' },
  { title: t('support.statusInProgress'), value: 'in_progress' },
  { title: t('support.statusWaitingCustomer'), value: 'waiting_customer' },
  { title: t('support.statusResolved'), value: 'resolved' },
  { title: t('support.statusClosed'), value: 'closed' },
]

const loadTickets = () => {
  const params = { page: page.value }

  if (statusFilter.value)
    params.status = statusFilter.value

  store.fetchTickets(params)
}

const createTicket = async () => {
  try {
    const ticket = await store.createTicket(newTicket.value)

    isCreateDialogOpen.value = false
    newTicket.value = { subject: '', body: '', category: 'general' }
    router.push({ name: 'company-support-id', params: { id: ticket.id } })
  }
  catch {
    // handled by store
  }
}

const openTicket = row => {
  router.push({ name: 'company-support-id', params: { id: row.id } })
}


watch([statusFilter], () => {
  page.value = 1
  loadTickets()
})

onMounted(loadTickets)
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between">
        <span>{{ t('support.myTickets') }}</span>
        <VBtn
          v-can="'support.create'"
          color="primary"
          prepend-icon="tabler-plus"
          @click="isCreateDialogOpen = true"
        >
          {{ t('support.newTicket') }}
        </VBtn>
      </VCardTitle>

      <VCardText>
        <VRow class="mb-4">
          <VCol
            cols="12"
            md="4"
          >
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :label="t('support.status')"
              clearable
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
              <p class="text-body-1 text-medium-emphasis mb-4">
                {{ t('support.noTicketsYet') }}
              </p>
              <VBtn
                v-can="'support.create'"
                color="primary"
                prepend-icon="tabler-plus"
                @click="isCreateDialogOpen = true"
              >
                {{ t('support.newTicket') }}
              </VBtn>
            </div>
          </template>
        </VDataTable>
      </VCardText>
    </VCard>

    <!-- Create Ticket Dialog -->
    <VDialog
      v-model="isCreateDialogOpen"
      max-width="600"
    >
      <VCard :title="t('support.newTicket')">
        <VCardText>
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="newTicket.subject"
                :label="t('support.subject')"
                :placeholder="t('support.subjectPlaceholder')"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="newTicket.category"
                :items="categoryOptions"
                :label="t('support.category')"
              />
            </VCol>
            <VCol cols="12">
              <AppTextarea
                v-model="newTicket.body"
                :label="t('support.message')"
                :placeholder="t('support.messagePlaceholder')"
                rows="5"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="outlined"
            @click="isCreateDialogOpen = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            v-can="'support.create'"
            color="primary"
            :disabled="!newTicket.subject || !newTicket.body"
            @click="createTicket"
          >
            {{ t('support.send') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
