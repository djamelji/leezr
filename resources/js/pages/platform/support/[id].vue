<script setup>
import { usePlatformSupportStore } from '@/modules/platform-admin/support/support.store'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { formatDateTime } from '@/utils/datetime'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    navActiveLink: 'platform-communications-tab',
    module: 'platform.support',
  },
})

const { t } = useI18n()
const route = useRoute()
const store = usePlatformSupportStore()
const auth = usePlatformAuthStore()

const newMessage = ref('')
const internalNote = ref('')
const sending = ref(false)
const activeTab = ref('reply')
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
  medium: 'info',
  normal: 'info',
  high: 'warning',
  urgent: 'error',
}

const priorityOptions = [
  { title: t('support.priorityLow'), value: 'low' },
  { title: t('support.priorityNormal'), value: 'normal' },
  { title: t('support.priorityHigh'), value: 'high' },
  { title: t('support.priorityUrgent'), value: 'urgent' },
]

const onPriorityChange = async priority => {
  await store.updatePriority(route.params.id, priority)
}

const scrollToBottom = () => {
  nextTick(() => {
    if (chatLogRef.value)
      chatLogRef.value.scrollTop = chatLogRef.value.scrollHeight
  })
}

const sendReply = async () => {
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

const sendNote = async () => {
  if (!internalNote.value.trim() || sending.value)
    return

  sending.value = true
  try {
    await store.sendInternalNote(ticketId.value, internalNote.value)
    internalNote.value = ''
    scrollToBottom()
  }
  finally {
    sending.value = false
  }
}

const assignToMe = () => store.assignTicket(ticketId.value)
const resolve = () => store.resolveTicket(ticketId.value)
const close = () => store.closeTicket(ticketId.value)

const slaColors = {
  on_track: 'success',
  warning: 'warning',
  breached: 'error',
}

const slaLabels = {
  on_track: () => t('support.slaOnTrack'),
  warning: () => t('support.slaWarning'),
  breached: () => t('support.slaBreached'),
}

const formatHours = hours => {
  if (hours === null || hours === undefined) return '-'
  if (hours < 1) return `${Math.round(hours * 60)}min`

  return t('support.slaHours', { n: hours })
}

const getOverallSla = ticket => {
  const sla = ticket?.sla_status
  if (!sla) return 'on_track'
  const statuses = [sla.response?.status, sla.resolution?.status]
  if (statuses.includes('breached')) return 'breached'
  if (statuses.includes('warning')) return 'warning'

  return 'on_track'
}


const isMyMessage = msg => msg.sender_type === 'platform_admin' && msg.sender_id === auth.user?.id

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
      :to="{ name: 'platform-communications-tab', params: { tab: 'support' } }"
    >
      {{ t('support.backToTickets') }}
    </VBtn>

    <VRow v-if="store.currentTicket">
      <!-- Ticket Info + Actions -->
      <VCol
        cols="12"
        md="4"
      >
        <VCard class="mb-4">
          <VCardTitle class="d-flex align-center gap-2 text-body-1 font-weight-bold">
            <span>#{{ store.currentTicket.id }} — {{ store.currentTicket.subject }}</span>
            <VSpacer />
            <VChip
              :color="slaColors[getOverallSla(store.currentTicket)]"
              size="small"
              variant="tonal"
            >
              {{ slaLabels[getOverallSla(store.currentTicket)]() }}
            </VChip>
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

              <div>
                <AppSelect
                  :model-value="store.currentTicket.priority"
                  :items="priorityOptions"
                  :label="t('support.priority')"
                  density="compact"
                  @update:model-value="onPriorityChange"
                />
              </div>

              <div class="d-flex align-center gap-2">
                <span class="text-body-2 text-medium-emphasis">{{ t('support.company') }}:</span>
                <span class="text-body-2">{{ store.currentTicket.company?.name }}</span>
              </div>

              <div class="d-flex align-center gap-2">
                <span class="text-body-2 text-medium-emphasis">{{ t('support.createdBy') }}:</span>
                <span class="text-body-2">{{ store.currentTicket.creator?.first_name }} {{ store.currentTicket.creator?.last_name }}</span>
              </div>

              <div class="d-flex align-center gap-2">
                <span class="text-body-2 text-medium-emphasis">{{ t('support.assignedTo') }}:</span>
                <span
                  v-if="store.currentTicket.assignee"
                  class="text-body-2"
                >
                  {{ store.currentTicket.assignee.first_name }} {{ store.currentTicket.assignee.last_name }}
                </span>
                <VChip
                  v-else
                  size="small"
                  color="warning"
                  variant="tonal"
                >
                  {{ t('support.unassigned') }}
                </VChip>
              </div>

              <div class="d-flex align-center gap-2">
                <span class="text-body-2 text-medium-emphasis">{{ t('support.createdAt') }}:</span>
                <span class="text-body-2">{{ formatDateTime(store.currentTicket.created_at) }}</span>
              </div>

              <div
                v-if="store.currentTicket.first_response_at"
                class="d-flex align-center gap-2"
              >
                <span class="text-body-2 text-medium-emphasis">{{ t('support.firstResponse') }}:</span>
                <span class="text-body-2">{{ formatDateTime(store.currentTicket.first_response_at) }}</span>
              </div>

              <!-- SLA Timers -->
              <VDivider v-if="store.currentTicket.sla_status" />

              <div
                v-if="store.currentTicket.sla_status"
                class="d-flex flex-column gap-2"
              >
                <div class="text-body-2 font-weight-bold">
                  {{ t('support.sla') }}
                </div>

                <!-- Response SLA -->
                <div class="d-flex align-center justify-space-between">
                  <span class="text-body-2 text-medium-emphasis">{{ t('support.slaResponseTime') }}:</span>
                  <span v-if="store.currentTicket.sla_status.response.completed">
                    <VChip
                      :color="slaColors[store.currentTicket.sla_status.response.status]"
                      size="x-small"
                      variant="tonal"
                    >
                      {{ t('support.slaResponded') }} — {{ formatHours(store.currentTicket.sla_status.response.elapsed_hours) }}
                    </VChip>
                  </span>
                  <span v-else>
                    <VChip
                      :color="slaColors[store.currentTicket.sla_status.response.status]"
                      size="x-small"
                      variant="tonal"
                    >
                      <template v-if="store.currentTicket.sla_status.response.remaining_hours > 0">
                        {{ t('support.slaResponseIn', { time: formatHours(store.currentTicket.sla_status.response.remaining_hours) }) }}
                      </template>
                      <template v-else>
                        {{ t('support.slaDeadlinePassed') }}
                      </template>
                    </VChip>
                  </span>
                </div>

                <!-- Resolution SLA -->
                <div class="d-flex align-center justify-space-between">
                  <span class="text-body-2 text-medium-emphasis">{{ t('support.slaResolutionTime') }}:</span>
                  <span v-if="store.currentTicket.sla_status.resolution.completed">
                    <VChip
                      :color="slaColors[store.currentTicket.sla_status.resolution.status]"
                      size="x-small"
                      variant="tonal"
                    >
                      {{ t('support.slaResolved') }} — {{ formatHours(store.currentTicket.sla_status.resolution.elapsed_hours) }}
                    </VChip>
                  </span>
                  <span v-else>
                    <VChip
                      :color="slaColors[store.currentTicket.sla_status.resolution.status]"
                      size="x-small"
                      variant="tonal"
                    >
                      <template v-if="store.currentTicket.sla_status.resolution.remaining_hours > 0">
                        {{ t('support.slaResolutionIn', { time: formatHours(store.currentTicket.sla_status.resolution.remaining_hours) }) }}
                      </template>
                      <template v-else>
                        {{ t('support.slaDeadlinePassed') }}
                      </template>
                    </VChip>
                  </span>
                </div>
              </div>
            </div>
          </VCardText>
        </VCard>

        <!-- Actions -->
        <VCard v-if="!['closed'].includes(store.currentTicket.status)">
          <VCardTitle class="text-body-1">
            {{ t('support.actions') }}
          </VCardTitle>
          <VCardText>
            <div class="d-flex flex-column gap-2">
              <VBtn
                v-if="!store.currentTicket.assignee"
                color="primary"
                variant="outlined"
                block
                prepend-icon="tabler-user-check"
                @click="assignToMe"
              >
                {{ t('support.assignToMe') }}
              </VBtn>
              <VBtn
                v-if="!['resolved', 'closed'].includes(store.currentTicket.status)"
                color="success"
                variant="outlined"
                block
                prepend-icon="tabler-circle-check"
                @click="resolve"
              >
                {{ t('support.resolve') }}
              </VBtn>
              <VBtn
                color="secondary"
                variant="outlined"
                block
                prepend-icon="tabler-archive"
                @click="close"
              >
                {{ t('support.close') }}
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Chat -->
      <VCol
        cols="12"
        md="8"
      >
        <VCard
          class="d-flex flex-column"
          style="min-block-size: 600px;"
        >
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
              :class="[
                msg.sender_type === 'platform_admin' ? 'justify-end' : 'justify-start',
              ]"
            >
              <div
                class="pa-3 rounded-lg"
                :class="[
                  msg.is_internal ? 'bg-warning-lighten' : (msg.sender_type === 'platform_admin' ? 'bg-primary text-white' : 'bg-surface'),
                ]"
                :style="`max-inline-size: 70%; border: 1px solid ${msg.is_internal ? 'rgb(var(--v-theme-warning))' : 'rgba(var(--v-border-color), var(--v-border-opacity))'};`"
              >
                <div class="d-flex align-center gap-1 mb-1">
                  <span class="text-caption font-weight-bold">
                    {{ msg.sender_type === 'platform_admin' ? t('support.platformTeam') : (store.currentTicket.creator?.first_name || t('support.customer')) }}
                  </span>
                  <VChip
                    v-if="msg.is_internal"
                    size="x-small"
                    color="warning"
                  >
                    {{ t('support.internalNote') }}
                  </VChip>
                </div>
                <p
                  class="text-body-2 mb-1"
                  style="white-space: pre-wrap;"
                >
                  {{ msg.body }}
                </p>
                <div
                  class="text-caption"
                  :class="msg.sender_type === 'platform_admin' && !msg.is_internal ? 'text-white' : 'text-medium-emphasis'"
                  style="opacity: 0.7;"
                >
                  {{ formatDateTime(msg.created_at) }}
                </div>
              </div>
            </div>
          </div>

          <VDivider />

          <!-- Input -->
          <div
            v-if="store.currentTicket.status !== 'closed'"
            class="pa-4"
          >
            <VTabs
              v-model="activeTab"
              class="mb-3"
            >
              <VTab value="reply">
                <VIcon
                  start
                  icon="tabler-send"
                />
                {{ t('support.reply') }}
              </VTab>
              <VTab value="internal">
                <VIcon
                  start
                  icon="tabler-note"
                />
                {{ t('support.internalNote') }}
              </VTab>
            </VTabs>

            <VForm
              v-if="activeTab === 'reply'"
              @submit.prevent="sendReply"
            >
              <div class="d-flex gap-2">
                <AppTextField
                  v-model="newMessage"
                  :placeholder="t('support.typeReply')"
                  class="flex-grow-1"
                  @keydown.enter.exact.prevent="sendReply"
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

            <VForm
              v-else
              @submit.prevent="sendNote"
            >
              <div class="d-flex gap-2">
                <AppTextField
                  v-model="internalNote"
                  :placeholder="t('support.typeInternalNote')"
                  class="flex-grow-1"
                  @keydown.enter.exact.prevent="sendNote"
                />
                <VBtn
                  color="warning"
                  icon="tabler-send"
                  :loading="sending"
                  :disabled="!internalNote.trim()"
                  type="submit"
                />
              </div>
            </VForm>
          </div>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
