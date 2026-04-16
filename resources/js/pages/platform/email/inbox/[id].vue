<script setup>
import { $platformApi } from '@/utils/platformApi'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.email',
    navActiveKey: 'platform-messaging',
  },
})

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { toast } = useAppToast()

const threadId = computed(() => route.params.id)

// State
const thread = ref(null)
const messages = ref([])
const isLoading = ref(true)
const isError = ref(false)
const replyBody = ref('')
const isSending = ref(false)
const showReplyBox = ref(false)

// Fetch thread
const fetchThread = async () => {
  isLoading.value = true
  isError.value = false
  try {
    const data = await $platformApi(`/email/inbox/${threadId.value}`)
    thread.value = data.thread
    messages.value = data.messages
  } catch (e) {
    isError.value = true
    toast(t('emailInbox.threadError'), 'error')
  } finally {
    isLoading.value = false
  }
}

// Reply
const handleReply = async () => {
  if (!replyBody.value.trim()) return

  isSending.value = true
  try {
    await $platformApi(`/email/inbox/${threadId.value}/reply`, {
      method: 'POST',
      body: { body: replyBody.value },
    })
    replyBody.value = ''
    showReplyBox.value = false
    toast(t('emailInbox.replySent'), 'success')
    await fetchThread()
  } catch (e) {
    toast(t('emailInbox.replyError'), 'error')
  } finally {
    isSending.value = false
  }
}

// Update status
const updateStatus = async newStatus => {
  try {
    await $platformApi(`/email/inbox/${threadId.value}/status`, {
      method: 'PUT',
      body: { status: newStatus },
    })
    thread.value.status = newStatus
    toast(t('emailInbox.statusUpdated'), 'success')
  } catch (e) {
    toast(t('emailInbox.statusError'), 'error')
  }
}

const formatDate = dateStr => {
  if (!dateStr) return ''
  return new Date(dateStr).toLocaleString('fr-FR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const statusColor = status => {
  if (status === 'open') return 'success'
  if (status === 'closed') return 'default'
  return 'warning'
}

const goBack = () => {
  router.push({ name: 'platform-email-tab', params: { tab: 'inbox' } })
}

onMounted(fetchThread)
</script>

<template>
  <div>
    <!-- Breadcrumbs -->
    <VBreadcrumbs class="mb-4 px-0">
      <VBreadcrumbsItem
        :title="t('emailInbox.title')"
        :to="{ name: 'platform-email-tab', params: { tab: 'inbox' } }"
      />
      <VBreadcrumbsDivider>
        <VIcon icon="tabler-chevron-right" />
      </VBreadcrumbsDivider>
      <VBreadcrumbsItem
        :title="thread?.subject || '...'"
        disabled
      />
    </VBreadcrumbs>

    <!-- Loading -->
    <VSkeletonLoader
      v-if="isLoading"
      type="card, card, card"
    />

    <!-- Error -->
    <VCard v-else-if="isError">
      <VCardText class="text-center pa-12">
        <VIcon
          icon="tabler-alert-triangle"
          size="64"
          color="error"
          class="mb-4"
        />
        <p class="text-h6">
          {{ t('emailInbox.threadError') }}
        </p>
        <VBtn
          variant="tonal"
          @click="fetchThread"
        >
          {{ t('common.retry') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Thread content -->
    <template v-else-if="thread">
      <!-- Thread header -->
      <VCard class="mb-4">
        <VCardText class="d-flex align-center flex-wrap gap-4">
          <IconBtn @click="goBack">
            <VIcon icon="tabler-arrow-left" />
          </IconBtn>

          <div class="flex-grow-1">
            <h5 class="text-h5 d-flex align-center gap-2">
              {{ thread.subject }}
              <VChip
                :color="statusColor(thread.status)"
                size="small"
                label
              >
                {{ thread.status }}
              </VChip>
            </h5>
            <p class="text-body-2 text-medium-emphasis mb-0">
              {{ thread.participant_name || thread.participant_email }}
              <template v-if="thread.company">
                — {{ thread.company.name }}
              </template>
              · {{ thread.message_count }} {{ t('emailInbox.messages') }}
            </p>
          </div>

          <div class="d-flex gap-2">
            <VBtn
              v-if="thread.status === 'open'"
              variant="tonal"
              color="secondary"
              size="small"
              prepend-icon="tabler-check"
              @click="updateStatus('closed')"
            >
              {{ t('emailInbox.actions.close') }}
            </VBtn>
            <VBtn
              v-else-if="thread.status === 'closed'"
              variant="tonal"
              color="success"
              size="small"
              prepend-icon="tabler-refresh"
              @click="updateStatus('open')"
            >
              {{ t('emailInbox.actions.reopen') }}
            </VBtn>
            <VBtn
              v-if="thread.status !== 'archived'"
              variant="tonal"
              color="warning"
              size="small"
              prepend-icon="tabler-archive"
              @click="updateStatus('archived')"
            >
              {{ t('emailInbox.actions.archive') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>

      <!-- Messages -->
      <div class="d-flex flex-column gap-4 mb-4">
        <VCard
          v-for="msg in messages"
          :key="msg.id"
          :class="{ 'ms-8': msg.direction === 'sent', 'me-8': msg.direction === 'received' }"
        >
          <VCardText>
            <!-- Message header -->
            <div class="d-flex align-center gap-3 mb-3">
              <VAvatar
                :color="msg.direction === 'sent' ? 'primary' : 'info'"
                variant="tonal"
                size="36"
              >
                <VIcon :icon="msg.direction === 'sent' ? 'tabler-send' : 'tabler-mail-forward'" />
              </VAvatar>
              <div class="flex-grow-1">
                <div class="d-flex align-center gap-2">
                  <span class="text-body-1 font-weight-medium">
                    {{ msg.direction === 'sent' ? msg.from_email : (msg.recipient_name || msg.from_email) }}
                  </span>
                  <VChip
                    :color="msg.direction === 'sent' ? 'primary' : 'info'"
                    size="x-small"
                    label
                  >
                    {{ msg.direction === 'sent' ? t('emailInbox.sent') : t('emailInbox.received') }}
                  </VChip>
                  <VChip
                    v-if="msg.status === 'failed'"
                    color="error"
                    size="x-small"
                    label
                  >
                    {{ t('emailInbox.failed') }}
                  </VChip>
                </div>
                <span class="text-caption text-disabled">
                  {{ msg.direction === 'sent' ? `→ ${msg.recipient_email}` : `← ${msg.from_email}` }}
                </span>
              </div>
              <span class="text-caption text-disabled">
                {{ formatDate(msg.created_at) }}
              </span>
            </div>

            <!-- Message body -->
            <div
              v-if="msg.body_html"
              class="text-body-1 pa-3 rounded"
              style="background: rgba(var(--v-theme-on-surface), 0.03);"
              v-html="msg.body_html"
            />
            <div
              v-else-if="msg.body_text"
              class="text-body-1 pa-3 rounded"
              style="background: rgba(var(--v-theme-on-surface), 0.03); white-space: pre-wrap;"
            >
              {{ msg.body_text }}
            </div>
            <div
              v-else
              class="text-body-2 text-disabled font-italic pa-3"
            >
              {{ t('emailInbox.noContent') }}
            </div>
          </VCardText>
        </VCard>
      </div>

      <!-- Reply section -->
      <VCard v-if="thread.status !== 'archived'">
        <VCardText v-if="!showReplyBox">
          <div
            class="text-body-1 cursor-pointer text-center pa-4"
            @click="showReplyBox = true"
          >
            <VIcon
              icon="tabler-arrow-back-up"
              class="me-2"
            />
            {{ t('emailInbox.clickToReply') }}
          </div>
        </VCardText>

        <VCardText v-else>
          <h6 class="text-h6 mb-4">
            {{ t('emailInbox.replyTo', { name: thread.participant_name || thread.participant_email }) }}
          </h6>

          <AppTextarea
            v-model="replyBody"
            :placeholder="t('emailInbox.replyPlaceholder')"
            rows="6"
            auto-grow
            class="mb-4"
          />

          <div class="d-flex justify-end gap-4">
            <VBtn
              variant="tonal"
              color="secondary"
              @click="showReplyBox = false; replyBody = ''"
            >
              {{ t('common.cancel') }}
            </VBtn>
            <VBtn
              color="primary"
              append-icon="tabler-send"
              :loading="isSending"
              :disabled="!replyBody.trim() || isSending"
              @click="handleReply"
            >
              {{ t('emailInbox.compose.send') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </template>
  </div>
</template>
