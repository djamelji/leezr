<script setup>
import { $platformApi } from '@/utils/platformApi'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  prefill: { type: Object, default: null },
})

const emit = defineEmits(['update:modelValue', 'sent'])

const { t } = useI18n()
const fileInput = ref(null)
const bodyWrapper = ref(null)
const uploadedFiles = ref([])
const isUploading = ref(false)

// ADR-604 (LOT 3): Tab from Subject jumps straight into the body editor, skipping the Tiptap
// toolbar (which lives in the immutable @core TiptapEditor). Preserves To → Subject → Body.
const focusEditor = () => {
  bodyWrapper.value?.querySelector('.ProseMirror')?.focus()
}

const to = ref('')
const subject = ref('')
const body = ref('')
const cc = ref('')
const bcc = ref('')
const isEmailCc = ref(false)
const isEmailBcc = ref(false)
const isSending = ref(false)
const isMinimized = ref(false)
const toError = ref('')
const ccError = ref('')
const bccError = ref('')

// ADR-605 (LOT 4): draft autosave state. `draftSaveSeq` guards against a stale network response
// overwriting newer content (last-writer-wins by sequence); `draftDiscarded` prevents a late save
// from resurrecting a draft that was already sent/deleted.
const draftId = ref(null)
const draftStatus = ref('idle') // idle | saving | saved | error
const draftSaveSeq = ref(0)
const draftDiscarded = ref(false)
let draftTimer = null

const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const validateEmail = email => emailRegex.test(email.trim())

const validateEmails = value => {
  if (!value) return true

  return value.split(',').every(e => validateEmail(e.trim()))
}

const validateToField = () => {
  if (!to.value) {
    toError.value = ''

    return
  }
  toError.value = validateEmail(to.value) ? '' : t('emailInbox.compose.invalidEmail')
}

const validateCcField = () => {
  if (!cc.value) {
    ccError.value = ''

    return
  }
  ccError.value = validateEmails(cc.value) ? '' : t('emailInbox.compose.invalidEmail')
}

const validateBccField = () => {
  if (!bcc.value) {
    bccError.value = ''

    return
  }
  bccError.value = validateEmails(bcc.value) ? '' : t('emailInbox.compose.invalidEmail')
}

const isValid = computed(() => {
  return to.value
    && subject.value
    && body.value
    && body.value !== '<p></p>'
    && validateEmail(to.value)
    && (!cc.value || validateEmails(cc.value))
    && (!bcc.value || validateEmails(bcc.value))
    && !toError.value && !ccError.value && !bccError.value
})

const handleFileSelect = async event => {
  const files = Array.from(event.target.files || [])
  if (!files.length) return

  const maxSize = 10 * 1024 * 1024

  for (const file of files) {
    if (uploadedFiles.value.length >= 5) break
    if (file.size > maxSize) continue

    isUploading.value = true
    try {
      const formData = new FormData()
      formData.append('file', file)

      const result = await $platformApi('/email/inbox/attachments', {
        method: 'POST',
        body: formData,
        rawBody: true,
      })

      uploadedFiles.value.push({
        id: result.id,
        name: result.original_filename,
        size: result.human_size,
      })
    }
    catch {
      // skip failed upload
    }
    finally {
      isUploading.value = false
    }
  }

  if (fileInput.value) fileInput.value.value = ''
}

const removeFile = index => {
  uploadedFiles.value.splice(index, 1)
}

const resetValues = () => {
  clearTimeout(draftTimer)
  draftSaveSeq.value++ // invalidate any in-flight autosave
  to.value = ''
  subject.value = ''
  body.value = ''
  cc.value = ''
  bcc.value = ''
  isEmailCc.value = false
  isEmailBcc.value = false
  isMinimized.value = false
  toError.value = ''
  ccError.value = ''
  bccError.value = ''
  uploadedFiles.value = []
  draftId.value = null
  draftStatus.value = 'idle'
  draftDiscarded.value = false
}

const handleClose = () => {
  emit('update:modelValue', false)
  resetValues()
}

const handleSend = async () => {
  if (!isValid.value) return

  isSending.value = true
  // ADR-605 (LOT 4): stop autosave and remove the backing draft (so a sent email does not also
  // linger in Drafts, and no late save resurrects it).
  draftDiscarded.value = true
  draftSaveSeq.value++
  clearTimeout(draftTimer)
  const sentDraftId = draftId.value
  try {
    emit('sent', {
      to: to.value,
      subject: subject.value,
      body: body.value,
      cc: cc.value || null,
      bcc: bcc.value || null,
      attachment_ids: uploadedFiles.value.map(f => f.id),
    })
    if (sentDraftId) {
      $platformApi(`/email/inbox/draft/${sentDraftId}`, { method: 'DELETE' }).catch(() => {})
    }
    handleClose()
  } finally {
    isSending.value = false
  }
}

// ADR-604 (LOT 3): keyboard productivity shortcuts (mouse-free composition).
// Cmd/Ctrl+Enter = send; Cmd/Ctrl+Shift+C = toggle Cc; Cmd/Ctrl+Shift+B = toggle Bcc.
const handleKeydown = e => {
  // Tab from Subject → jump into the body editor (skip the immutable @core Tiptap toolbar).
  if (e.key === 'Tab' && !e.shiftKey && !e.ctrlKey && !e.metaKey && e.target?.closest?.('[data-test="compose-subject"]')) {
    e.preventDefault()
    focusEditor()

    return
  }

  const meta = e.ctrlKey || e.metaKey
  if (!meta) return
  const key = (e.key || '').toLowerCase()
  if (key === 'enter') { e.preventDefault(); handleSend() }
  else if (e.shiftKey && key === 'c') { e.preventDefault(); isEmailCc.value = !isEmailCc.value }
  else if (e.shiftKey && key === 'b') { e.preventDefault(); isEmailBcc.value = !isEmailBcc.value }
}

// ── ADR-605 (LOT 4): draft autosave ──
const hasContent = () => !!(to.value || subject.value || body.value || cc.value || bcc.value)

async function autosaveDraft() {
  if (draftDiscarded.value || !hasContent()) { draftStatus.value = 'idle'; return }
  const seq = ++draftSaveSeq.value
  draftStatus.value = 'saving'
  const payload = { to: to.value, subject: subject.value, body: body.value, cc: cc.value || null, bcc: bcc.value || null }
  try {
    const res = draftId.value
      ? await $platformApi(`/email/inbox/draft/${draftId.value}`, { method: 'PUT', body: payload })
      : await $platformApi('/email/inbox/draft', { method: 'POST', body: payload })

    // Ignore a stale response (a newer save started, or the draft was sent/deleted meanwhile).
    if (seq !== draftSaveSeq.value || draftDiscarded.value) return
    if (res?.draft_id) draftId.value = res.draft_id
    draftStatus.value = 'saved'
  } catch {
    if (seq === draftSaveSeq.value) draftStatus.value = 'error' // keep local content; user can retry
  }
}

function scheduleAutosave() {
  if (draftDiscarded.value) return
  if (hasContent()) draftStatus.value = 'saving'
  clearTimeout(draftTimer)
  draftTimer = setTimeout(autosaveDraft, 1200)
}

watch([to, subject, body, cc, bcc], () => {
  if (props.modelValue) scheduleAutosave()
})

// Warn before leaving with unsaved (not yet persisted) content.
const beforeUnloadGuard = e => {
  if (props.modelValue && hasContent() && draftStatus.value !== 'saved') {
    e.preventDefault()
    e.returnValue = ''
  }
}
onMounted(() => window.addEventListener('beforeunload', beforeUnloadGuard))
onBeforeUnmount(() => { window.removeEventListener('beforeunload', beforeUnloadGuard); clearTimeout(draftTimer) })

// Watch for prefill data (forward, draft editing)
watch(() => props.prefill, val => {
  if (!val) return
  to.value = val.to || ''
  subject.value = val.subject || ''
  body.value = val.body || ''
  cc.value = val.cc || ''
  bcc.value = val.bcc || ''
  if (val.cc) isEmailCc.value = true
  if (val.bcc) isEmailBcc.value = true
  // ADR-605 (LOT 4): resuming an existing draft → keep updating the same draft row.
  draftId.value = val.draft_id || null
  draftDiscarded.value = false
  draftStatus.value = val.draft_id ? 'saved' : 'idle'
}, { immediate: true })
</script>

<template>
  <VCard
    v-show="modelValue"
    class="email-compose-dialog"
    elevation="10"
    data-test="email-compose"
    :max-width="$vuetify.display.smAndDown ? '100%' : '30vw'"
    :min-width="$vuetify.display.smAndDown ? '100%' : '400px'"
    @keydown="handleKeydown"
  >
    <!-- Header -->
    <VCardItem class="py-3 px-6">
      <div class="d-flex align-center">
        <h5 class="text-h5">
          {{ t('emailInbox.compose.title') }}
        </h5>
        <!-- ADR-605 (LOT 4): autosave indicator -->
        <span
          v-if="draftStatus !== 'idle'"
          class="text-caption ms-3 d-inline-flex align-center gap-1"
          :class="draftStatus === 'error' ? 'text-error' : 'text-disabled'"
          data-test="compose-draft-status"
        >
          <VIcon
            size="14"
            :icon="draftStatus === 'saving' ? 'tabler-loader-2' : draftStatus === 'error' ? 'tabler-alert-circle' : 'tabler-check'"
          />
          {{ t(`emailInbox.compose.draft${draftStatus === 'saving' ? 'Saving' : draftStatus === 'error' ? 'Error' : 'Saved'}`) }}
        </span>
        <VSpacer />

        <div class="d-flex align-center gap-x-2">
          <IconBtn
            size="small"
            icon="tabler-minus"
            @click="isMinimized = !isMinimized"
          />
          <IconBtn
            size="small"
            icon="tabler-x"
            @click="handleClose"
          />
        </div>
      </div>
    </VCardItem>

    <!-- Compose body (hidden when minimized) -->
    <VExpandTransition>
      <div v-show="!isMinimized">
        <!-- To field -->
        <div class="px-1 pe-6 py-1">
          <VTextField
            v-model="to"
            density="compact"
            data-test="compose-to"
            :error-messages="toError"
            @blur="validateToField"
          >
            <template #prepend-inner>
              <div class="text-base font-weight-medium text-disabled">
                {{ t('emailInbox.compose.to') }}:
              </div>
            </template>
            <template #append>
              <span class="text-sm d-inline-flex align-center gap-1">
                <!-- tabindex=-1 keeps the natural To → Subject → Body tab flow; reachable via
                     Cmd/Ctrl+Shift+C / +B shortcuts and by click (ADR-604 LOT 3). -->
                <button
                  type="button"
                  tabindex="-1"
                  class="compose-toggle"
                  data-test="compose-cc-toggle"
                  :title="t('emailInbox.compose.cc') + ' (Ctrl/Cmd+Shift+C)'"
                  @click="isEmailCc = !isEmailCc"
                >{{ t('emailInbox.compose.cc') }}</button>
                <span>|</span>
                <button
                  type="button"
                  tabindex="-1"
                  class="compose-toggle"
                  data-test="compose-bcc-toggle"
                  :title="t('emailInbox.compose.bcc') + ' (Ctrl/Cmd+Shift+B)'"
                  @click="isEmailBcc = !isEmailBcc"
                >{{ t('emailInbox.compose.bcc') }}</button>
              </span>
            </template>
          </VTextField>
        </div>

        <!-- CC -->
        <VExpandTransition>
          <div v-if="isEmailCc">
            <VDivider />
            <div class="px-1 pe-6 py-1">
              <VTextField
                v-model="cc"
                density="compact"
                data-test="compose-cc"
                :error-messages="ccError"
                @blur="validateCcField"
              >
                <template #prepend-inner>
                  <div class="text-disabled font-weight-medium">
                    {{ t('emailInbox.compose.cc') }}:
                  </div>
                </template>
              </VTextField>
            </div>
          </div>
        </VExpandTransition>

        <!-- BCC -->
        <VExpandTransition>
          <div v-if="isEmailBcc">
            <VDivider />
            <div class="px-1 pe-6 py-1">
              <VTextField
                v-model="bcc"
                density="compact"
                data-test="compose-bcc"
                :error-messages="bccError"
                @blur="validateBccField"
              >
                <template #prepend-inner>
                  <div class="text-disabled font-weight-medium">
                    {{ t('emailInbox.compose.bcc') }}:
                  </div>
                </template>
              </VTextField>
            </div>
          </div>
        </VExpandTransition>

        <VDivider />

        <!-- Subject -->
        <div class="px-1 pe-6 py-1">
          <VTextField
            v-model="subject"
            density="compact"
            data-test="compose-subject"
          >
            <template #prepend-inner>
              <div class="text-base font-weight-medium text-disabled">
                {{ t('emailInbox.compose.subject') }}:
              </div>
            </template>
          </VTextField>
        </div>

        <VDivider />

        <!-- TiptapEditor body -->
        <div ref="bodyWrapper" data-test="compose-body">
          <TiptapEditor
            v-model="body"
            class="compose-editor"
            :placeholder="t('emailInbox.compose.body')"
          />
        </div>

        <!-- Uploaded files list -->
        <div
          v-if="uploadedFiles.length"
          class="px-6 py-2"
        >
          <div
            v-for="(file, idx) in uploadedFiles"
            :key="file.id"
            class="d-flex align-center gap-2 py-1"
          >
            <VIcon
              icon="tabler-paperclip"
              size="16"
              class="text-disabled"
            />
            <span class="text-body-2 text-truncate">{{ file.name }}</span>
            <span class="text-caption text-disabled">({{ file.size }})</span>
            <VSpacer />
            <IconBtn
              size="x-small"
              @click="removeFile(idx)"
            >
              <VIcon
                icon="tabler-x"
                size="14"
              />
            </IconBtn>
          </div>
        </div>

        <!-- Hidden file input -->
        <input
          ref="fileInput"
          type="file"
          multiple
          hidden
          accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip,.txt,.csv"
          @change="handleFileSelect"
        >

        <!-- Footer -->
        <div class="d-flex align-center px-6 py-4">
          <VBtn
            color="primary"
            class="me-4"
            append-icon="tabler-send"
            data-test="compose-send"
            :disabled="!isValid"
            :loading="isSending"
            @click="handleSend"
          >
            {{ t('emailInbox.compose.send') }}
          </VBtn>

          <IconBtn
            size="small"
            :loading="isUploading"
            @click="fileInput?.click()"
          >
            <VIcon icon="tabler-paperclip" />
            <VTooltip
              activator="parent"
              location="top"
            >
              {{ t('emailInbox.compose.attachments') }}
            </VTooltip>
          </IconBtn>

          <IconBtn size="small">
            <VIcon icon="tabler-dots-vertical" />
            <VTooltip
              activator="parent"
              location="top"
            >
              {{ t('emailInbox.compose.moreOptions') }}
            </VTooltip>
          </IconBtn>

          <VSpacer />

          <IconBtn
            size="small"
            @click="handleClose"
          >
            <VIcon icon="tabler-trash" />
            <VTooltip
              activator="parent"
              location="top"
            >
              {{ t('emailInbox.compose.discardDraft') }}
            </VTooltip>
          </IconBtn>
        </div>
      </div>
    </VExpandTransition>
  </VCard>
</template>

<style lang="scss">
// ADR-604 (LOT 3): Cc/Bcc toggles rendered as real buttons (semantics) styled like inline links.
.compose-toggle {
  border: none;
  background: transparent;
  color: rgba(var(--v-theme-on-surface), 0.6);
  cursor: pointer;
  padding: 0 2px;
  font: inherit;

  &:hover {
    color: rgb(var(--v-theme-primary));
  }
}

.v-card.email-compose-dialog {
  position: fixed;
  z-index: 910 !important;
  inset-block-end: 0;
  inset-inline-end: 24px;

  .v-field--prepended {
    padding-inline-start: 20px;
  }

  .v-field__prepend-inner {
    align-items: center;
    padding: 0;
  }

  .v-card-item {
    background-color: rgba(var(--v-theme-on-surface), var(--v-hover-opacity));
  }

  .v-field__outline {
    display: none;
  }

  .v-input {
    .v-field__prepend-inner {
      display: flex;
      align-items: center;
      padding-block-start: 0;
    }
  }

  .app-text-field {
    .v-field__input {
      padding-block-start: 6px;
    }

    .v-field--focused {
      box-shadow: none !important;
    }
  }

  .compose-editor {
    .ProseMirror {
      block-size: 100px;
      overflow-y: auto;
      padding: 0.5rem;
    }
  }
}
</style>
