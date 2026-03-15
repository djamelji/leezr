<script setup>
import { useSupportStore } from '@/modules/company/support/support.store'
import { useAuthStore } from '@/core/stores/auth'

definePage({
  meta: {
    navActiveKey: 'company-support',
    module: 'core.support',
  },
})

const { t } = useI18n()
const route = useRoute()
const store = useSupportStore()
const auth = useAuthStore()

const newMessage = ref('')
const sending = ref(false)
const chatLogRef = ref(null)

const ticketId = computed(() => Number(route.params.id))

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

const scrollToBottom = () => {
  nextTick(() => {
    if (chatLogRef.value)
      chatLogRef.value.scrollTop = chatLogRef.value.scrollHeight
  })
}

const sendMessage = async () => {
  if (!newMessage.value.trim() || sending.value)
    return

  sending.value = true
  try {
    await store.sendMessage(ticketId.value, newMessage.value)
    newMessage.value = ''
    scrollToBottom()
  }
  finally {
    sending.value = false
  }
}

const formatTime = d => {
  if (!d) return ''
  const date = new Date(d)

  return `${date.toLocaleDateString()} ${date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`
}

const isMyMessage = msg => msg.sender_type === 'company_user' && msg.sender_id === auth.user?.id

onMounted(async () => {
  await Promise.all([
    store.fetchTicket(ticketId.value),
    store.fetchMessages(ticketId.value),
  ])
  scrollToBottom()
})
</script>

<template>
  <div>
    <VBtn
      variant="text"
      prepend-icon="tabler-arrow-left"
      class="mb-4"
      :to="{ name: 'company-support' }"
    >
      {{ t('support.backToTickets') }}
    </VBtn>

    <VRow v-if="store.currentTicket">
      <!-- Ticket info -->
      <VCol
        cols="12"
        md="4"
      >
        <VCard>
          <VCardTitle class="text-body-1 font-weight-bold">
            #{{ store.currentTicket.id }} — {{ store.currentTicket.subject }}
          </VCardTitle>
          <VCardText>
            <div class="d-flex flex-column gap-3">
              <div class="d-flex align-center gap-2">
                <span class="text-body-2 text-medium-emphasis">{{ t('support.status') }}:</span>
                <VChip
                  :color="statusColors[store.currentTicket.status]"
                  size="small"
                >
                  {{ t(`support.status${store.currentTicket.status.charAt(0).toUpperCase() + store.currentTicket.status.slice(1).replace(/_([a-z])/g, (_, c) => c.toUpperCase())}`) }}
                </VChip>
              </div>

              <div class="d-flex align-center gap-2">
                <span class="text-body-2 text-medium-emphasis">{{ t('support.priority') }}:</span>
                <VChip
                  :color="priorityColors[store.currentTicket.priority]"
                  size="small"
                  variant="tonal"
                >
                  {{ t(`support.priority${store.currentTicket.priority.charAt(0).toUpperCase() + store.currentTicket.priority.slice(1)}`) }}
                </VChip>
              </div>

              <div class="d-flex align-center gap-2">
                <span class="text-body-2 text-medium-emphasis">{{ t('support.category') }}:</span>
                <span class="text-body-2">{{ t(`support.category${(store.currentTicket.category || 'general').charAt(0).toUpperCase() + (store.currentTicket.category || 'general').slice(1)}`) }}</span>
              </div>

              <div
                v-if="store.currentTicket.assignee"
                class="d-flex align-center gap-2"
              >
                <span class="text-body-2 text-medium-emphasis">{{ t('support.assignedTo') }}:</span>
                <span class="text-body-2">{{ store.currentTicket.assignee.first_name }} {{ store.currentTicket.assignee.last_name }}</span>
              </div>

              <div class="d-flex align-center gap-2">
                <span class="text-body-2 text-medium-emphasis">{{ t('support.createdAt') }}:</span>
                <span class="text-body-2">{{ formatTime(store.currentTicket.created_at) }}</span>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Chat -->
      <VCol
        cols="12"
        md="8"
      >
        <VCard class="d-flex flex-column" style="min-block-size: 500px;">
          <VCardTitle>{{ t('support.conversation') }}</VCardTitle>

          <VDivider />

          <!-- Messages -->
          <div
            ref="chatLogRef"
            class="flex-grow-1 pa-4"
            style="overflow-y: auto; max-block-size: 500px;"
          >
            <div
              v-for="msg in store.messages"
              :key="msg.id"
              class="d-flex mb-4"
              :class="isMyMessage(msg) ? 'justify-end' : 'justify-start'"
            >
              <div
                class="pa-3 rounded-lg"
                :class="isMyMessage(msg) ? 'bg-primary text-white' : 'bg-surface'"
                style="max-inline-size: 70%; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));"
              >
                <div
                  v-if="!isMyMessage(msg)"
                  class="text-caption font-weight-bold mb-1"
                >
                  {{ msg.sender_type === 'platform_admin' ? t('support.platformTeam') : t('support.you') }}
                </div>
                <p class="text-body-2 mb-1" style="white-space: pre-wrap;">
                  {{ msg.body }}
                </p>
                <div
                  class="text-caption"
                  :class="isMyMessage(msg) ? 'text-white' : 'text-medium-emphasis'"
                  style="opacity: 0.7;"
                >
                  {{ formatTime(msg.created_at) }}
                </div>
              </div>
            </div>
          </div>

          <VDivider />

          <!-- Input -->
          <div
            v-if="!['closed', 'resolved'].includes(store.currentTicket.status)"
            class="pa-4"
          >
            <VForm @submit.prevent="sendMessage">
              <div class="d-flex gap-2">
                <AppTextField
                  v-model="newMessage"
                  :placeholder="t('support.typeMessage')"
                  class="flex-grow-1"
                  @keydown.enter.exact.prevent="sendMessage"
                />
                <VBtn
                  color="primary"
                  icon="tabler-send"
                  :loading="sending"
                  :disabled="!newMessage.trim()"
                  type="submit"
                />
              </div>
            </VForm>
          </div>

          <VAlert
            v-else
            type="info"
            variant="tonal"
            class="ma-4"
          >
            {{ t('support.ticketClosed') }}
          </VAlert>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
