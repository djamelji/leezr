<script setup>
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'

const props = defineProps({
  thread: { type: Object, default: null },
  messages: { type: Array, default: () => [] },
  threadMeta: { type: Object, default: () => ({ hasPrevious: false, hasNext: false }) },
  labels: { type: Array, default: () => [] },
})

const emit = defineEmits([
  'close',
  'refresh',
  'navigated',
  'trash',
  'star',
  'unstar',
  'read',
  'unread',
  'move-to',
  'label',
  'reply-sent',
  'forward',
  'upload-attachment',
])

const { t, locale } = useI18n()

const replyBody = ref('')
const showReplyBox = ref(false)
const showReplyCard = ref(true)
const isSending = ref(false)
const replyAttachments = ref([])
const replyFileInput = ref(null)
const isUploading = ref(false)

const resolveLabelColor = label => {
  return props.labels.find(l => l.title === label)?.color || 'default'
}

const moveToActions = [
  { action: 'inbox', icon: 'tabler-mail' },
  { action: 'spam', icon: 'tabler-alert-octagon' },
  { action: 'trash', icon: 'tabler-trash' },
]

const handleClose = () => {
  showReplyBox.value = false
  showReplyCard.value = true
  replyBody.value = ''
  emit('close')
}

const lastMessage = computed(() => {
  if (!props.messages?.length) return null

  return props.messages[props.messages.length - 1]
})

const buildQuote = () => {
  const msg = lastMessage.value
  if (!msg) return ''

  const date = formatDate(msg.created_at || msg.sent_at)
  const from = msg.direction === 'sent' ? (msg.recipient_email || '') : (msg.from_email || '')
  const originalBody = msg.body_html || msg.body_text || ''

  return `<br><br><blockquote style="border-left: 2px solid #ccc; padding-left: 8px; color: #666;">${t('emailInbox.quote', { date, from })}<br>${originalBody}</blockquote>`
}

const handleClickReply = () => {
  replyBody.value = buildQuote()
  showReplyBox.value = true
  showReplyCard.value = false
}

const handleClickForward = () => {
  const msg = lastMessage.value
  if (!msg) return

  const body = buildQuote()
  const subject = props.thread?.subject || ''

  emit('forward', {
    subject: subject.startsWith('Fwd:') ? subject : `Fwd: ${subject}`,
    body,
    to: '',
  })
}

const handleFileSelect = async event => {
  const files = event.target.files
  if (!files?.length) return

  isUploading.value = true
  try {
    for (const file of files) {
      emit('upload-attachment', file, attachment => {
        replyAttachments.value.push(attachment)
      })
    }
  } finally {
    isUploading.value = false
    // Reset input so same file can be re-selected
    if (replyFileInput.value) replyFileInput.value.value = ''
  }
}

const removeReplyAttachment = index => {
  replyAttachments.value.splice(index, 1)
}

const handleReply = async () => {
  if (!replyBody.value || replyBody.value === '<p></p>') return
  isSending.value = true
  try {
    const attachmentIds = replyAttachments.value.map(a => a.id)

    emit('reply-sent', { body: replyBody.value, attachment_ids: attachmentIds })
    replyBody.value = ''
    replyAttachments.value = []
    showReplyBox.value = false
    showReplyCard.value = true
  } finally {
    isSending.value = false
  }
}

const formatDate = dateStr => {
  if (!dateStr) return ''

  return new Date(dateStr).toLocaleString(locale.value || 'fr-FR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

// Reset state when thread changes
watch(() => props.thread, () => {
  showReplyBox.value = false
  showReplyCard.value = true
  replyBody.value = ''
  replyAttachments.value = []
})
</script>

<template>
  <Transition name="slide-x">
    <div
      v-if="props.thread"
      class="email-view"
    >
      <!-- Header -->
      <div class="email-view-header d-flex align-center px-5 py-3">
        <IconBtn
          class="me-2"
          @click="handleClose"
        >
          <VIcon
            size="22"
            icon="tabler-chevron-left"
            class="flip-in-rtl"
          />
        </IconBtn>

        <div class="d-flex align-center flex-wrap flex-grow-1 overflow-hidden gap-2">
          <div class="text-body-1 text-high-emphasis text-truncate">
            {{ props.thread.subject }}
          </div>

          <div class="d-flex flex-wrap gap-2">
            <VChip
              v-for="label in (props.thread.labels || [])"
              :key="label"
              :color="resolveLabelColor(label)"
              class="text-capitalize flex-shrink-0"
              size="small"
            >
              {{ label }}
            </VChip>
          </div>
        </div>

        <div class="d-flex align-center">
          <IconBtn
            :disabled="!props.threadMeta.hasPrevious"
            @click="$emit('navigated', 'previous')"
          >
            <VIcon
              icon="tabler-chevron-left"
              class="flip-in-rtl"
            />
          </IconBtn>

          <IconBtn
            :disabled="!props.threadMeta.hasNext"
            @click="$emit('navigated', 'next')"
          >
            <VIcon
              icon="tabler-chevron-right"
              class="flip-in-rtl"
            />
          </IconBtn>
        </div>
      </div>

      <VDivider />

      <!-- Action bar -->
      <div class="email-view-action-bar d-flex align-center text-medium-emphasis px-6 gap-x-1">
        <!-- Trash -->
        <IconBtn @click="$emit('trash'); handleClose()">
          <VIcon
            icon="tabler-trash"
            size="22"
          />
          <VTooltip
            activator="parent"
            location="top"
          >
            {{ t('emailInbox.moveToTrash') }}
          </VTooltip>
        </IconBtn>

        <!-- Read/Unread -->
        <IconBtn @click.stop="$emit('unread'); handleClose()">
          <VIcon
            icon="tabler-mail"
            size="22"
          />
          <VTooltip
            activator="parent"
            location="top"
          >
            {{ t('emailInbox.markUnread') }}
          </VTooltip>
        </IconBtn>

        <!-- Move to folder -->
        <IconBtn>
          <VIcon
            icon="tabler-folder"
            size="22"
          />
          <VTooltip
            activator="parent"
            location="top"
          >
            {{ t('emailInbox.moveTo') }}
          </VTooltip>

          <VMenu activator="parent">
            <VList density="compact">
              <VListItem
                v-for="moveTo in moveToActions"
                :key="moveTo.action"
                @click="$emit('move-to', moveTo.action)"
              >
                <template #prepend>
                  <VIcon
                    :icon="moveTo.icon"
                    class="me-2"
                    size="20"
                  />
                </template>
                <VListItemTitle class="text-capitalize">
                  {{ t(`emailInbox.folders.${moveTo.action}`) }}
                </VListItemTitle>
              </VListItem>
            </VList>
          </VMenu>
        </IconBtn>

        <!-- Labels -->
        <IconBtn>
          <VIcon
            icon="tabler-tag"
            size="22"
          />
          <VTooltip
            activator="parent"
            location="top"
          >
            {{ t('emailInbox.addLabel') }}
          </VTooltip>

          <VMenu activator="parent">
            <VList density="compact">
              <VListItem
                v-for="label in props.labels"
                :key="label.title"
                @click.stop="$emit('label', label.title)"
              >
                <template #prepend>
                  <VBadge
                    inline
                    :color="label.color"
                    dot
                  />
                </template>
                <VListItemTitle class="ms-2 text-capitalize">
                  {{ label.title }}
                </VListItemTitle>
              </VListItem>
            </VList>
          </VMenu>
        </IconBtn>

        <VSpacer />

        <!-- Star -->
        <IconBtn
          :color="props.thread.is_starred ? 'warning' : 'default'"
          @click="props.thread.is_starred ? $emit('unstar') : $emit('star')"
        >
          <VIcon :icon="props.thread.is_starred ? 'tabler-star-filled' : 'tabler-star'" />
        </IconBtn>
      </div>

      <VDivider />

      <!-- Mail Content -->
      <PerfectScrollbar
        tag="div"
        class="mail-content-container flex-grow-1 pa-sm-6 pa-4"
        :options="{ wheelPropagation: false }"
      >
        <VCard
          v-for="msg in props.messages"
          :key="msg.id"
          class="mb-4"
        >
          <div class="d-flex align-start pa-4 gap-x-3">
            <VAvatar
              size="38"
              :color="msg.direction === 'sent' ? 'primary' : 'secondary'"
              variant="tonal"
            >
              <VIcon :icon="msg.direction === 'sent' ? 'tabler-send' : 'tabler-mail'" />
            </VAvatar>

            <div class="d-flex flex-wrap flex-grow-1 overflow-hidden">
              <div class="text-truncate">
                <div class="text-body-1 text-high-emphasis text-truncate">
                  {{ msg.direction === 'sent' ? (msg.recipient_email || '') : (msg.from_email || '') }}
                </div>
                <div class="text-sm">
                  <VChip
                    :color="msg.direction === 'sent' ? 'info' : 'success'"
                    size="x-small"
                    label
                  >
                    {{ t(`emailInbox.${msg.direction}`) }}
                  </VChip>
                  <span
                    v-if="msg.cc"
                    class="text-caption text-disabled ms-2"
                  >
                    Cc: {{ msg.cc }}
                  </span>
                </div>
              </div>

              <VSpacer />

              <div class="d-flex align-center gap-2">
                <div class="text-disabled text-sm">
                  {{ formatDate(msg.created_at || msg.sent_at) }}
                </div>
                <IconBtn size="x-small">
                  <VIcon
                    icon="tabler-dots-vertical"
                    size="18"
                  />
                </IconBtn>
              </div>
            </div>
          </div>

          <VDivider />

          <VCardText>
            <!-- eslint-disable vue/no-v-html -->
            <div
              v-if="msg.body_html"
              class="text-base email-body-content"
              v-html="msg.body_html"
            />
            <!-- eslint-enable -->
            <div
              v-else-if="msg.body_text"
              class="text-base"
              style="white-space: pre-wrap;"
            >
              {{ msg.body_text }}
            </div>
            <div
              v-else
              class="text-disabled"
            >
              {{ t('emailInbox.noContent') }}
            </div>

            <!-- Attachments -->
            <div
              v-if="msg.attachments?.length"
              class="mt-4"
            >
              <div class="text-caption text-disabled mb-2">
                <VIcon
                  icon="tabler-paperclip"
                  size="14"
                  class="me-1"
                />
                {{ t('emailInbox.attachments') }} ({{ msg.attachments.length }})
              </div>
              <div class="d-flex flex-wrap gap-2">
                <VChip
                  v-for="att in msg.attachments"
                  :key="att.id"
                  size="small"
                  :href="att.url"
                  target="_blank"
                  variant="outlined"
                  prepend-icon="tabler-download"
                >
                  {{ att.original_filename }} ({{ att.human_size }})
                </VChip>
              </div>
            </div>
          </VCardText>
        </VCard>

        <!-- Reply/Forward prompt card -->
        <VCard v-show="showReplyCard">
          <VCardText class="font-weight-medium text-high-emphasis">
            <div class="text-base">
              {{ t('emailInbox.replyOrForwardPrefix') }}
              <span
                class="text-primary cursor-pointer"
                @click="handleClickReply"
              >
                {{ t('emailInbox.reply') }}
              </span>
              {{ t('emailInbox.replyOrForwardOr') }}
              <span
                class="text-primary cursor-pointer"
                @click="handleClickForward"
              >
                {{ t('emailInbox.forward') }}
              </span>
            </div>
          </VCardText>
        </VCard>

        <!-- Reply box with TiptapEditor -->
        <VCard v-if="showReplyBox">
          <VCardText>
            <h6 class="text-h6 mb-4">
              {{ t('emailInbox.replyTo', { name: props.thread.participant_email }) }}
            </h6>
            <TiptapEditor
              v-model="replyBody"
              class="reply-editor"
              :placeholder="t('emailInbox.replyPlaceholder')"
            />

            <!-- Reply attachments -->
            <div
              v-if="replyAttachments.length"
              class="d-flex flex-wrap gap-2 pt-2"
            >
              <VChip
                v-for="(att, idx) in replyAttachments"
                :key="att.id"
                size="small"
                variant="outlined"
                closable
                prepend-icon="tabler-paperclip"
                @click:close="removeReplyAttachment(idx)"
              >
                {{ att.name }}
              </VChip>
            </div>

            <input
              ref="replyFileInput"
              type="file"
              multiple
              hidden
              @change="handleFileSelect"
            >

            <div class="d-flex align-center gap-4 pt-4">
              <VBtn
                :loading="isSending"
                append-icon="tabler-send"
                @click="handleReply"
              >
                {{ t('emailInbox.compose.send') }}
              </VBtn>

              <IconBtn
                size="small"
                :loading="isUploading"
                @click="replyFileInput?.click()"
              >
                <VIcon icon="tabler-paperclip" />
              </IconBtn>

              <VSpacer />

              <VBtn
                variant="text"
                color="secondary"
                size="small"
                @click="showReplyBox = false; showReplyCard = true; replyBody = ''"
              >
                <VIcon
                  icon="tabler-x"
                  size="18"
                />
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </PerfectScrollbar>
    </div>
  </Transition>
</template>

<style lang="scss">
.email-view {
  position: absolute;
  z-index: 10;
  inset-block: 0;
  inset-inline-end: 0;
  inline-size: 100%;
  background: rgb(var(--v-theme-surface));
  display: flex;
  flex-direction: column;
  overflow: hidden;

  @media only screen and (min-width: 1280px) {
    inline-size: calc(100% - 260px);
  }
}

.email-view-action-bar {
  min-block-size: 56px;
}

.mail-content-container {
  background-color: rgba(var(--v-theme-on-surface), var(--v-hover-opacity));
}

.email-body-content {
  img {
    max-inline-size: 100%;
    block-size: auto;
  }
}

.reply-editor {
  .ProseMirror {
    block-size: 100px;
    overflow-y: auto;
    padding: 0.5rem;
  }
}

// Slide transition
.slide-x-enter-active,
.slide-x-leave-active {
  transition: transform 0.3s ease;
}

.slide-x-enter-from {
  transform: translateX(100%);
}

.slide-x-leave-to {
  transform: translateX(100%);
}
</style>
