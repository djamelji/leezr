<script setup>
import { $platformApi } from '@/utils/platformApi'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  prefill: { type: Object, default: null },
})

const emit = defineEmits(['update:modelValue', 'sent'])

const { t } = useI18n()
const fileInput = ref(null)
const uploadedFiles = ref([])
const isUploading = ref(false)

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
}

const handleClose = () => {
  emit('update:modelValue', false)
  resetValues()
}

const handleSend = async () => {
  if (!isValid.value) return

  isSending.value = true
  try {
    emit('sent', {
      to: to.value,
      subject: subject.value,
      body: body.value,
      cc: cc.value || null,
      bcc: bcc.value || null,
      attachment_ids: uploadedFiles.value.map(f => f.id),
    })
    handleClose()
  } finally {
    isSending.value = false
  }
}

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
}, { immediate: true })
</script>

<template>
  <VCard
    v-show="modelValue"
    class="email-compose-dialog"
    elevation="10"
    :max-width="$vuetify.display.smAndDown ? '100%' : '30vw'"
    :min-width="$vuetify.display.smAndDown ? '100%' : '400px'"
  >
    <!-- Header -->
    <VCardItem class="py-3 px-6">
      <div class="d-flex align-center">
        <h5 class="text-h5">
          {{ t('emailInbox.compose.title') }}
        </h5>
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
            :error-messages="toError"
            @blur="validateToField"
          >
            <template #prepend-inner>
              <div class="text-base font-weight-medium text-disabled">
                {{ t('emailInbox.compose.to') }}:
              </div>
            </template>
            <template #append>
              <span class="cursor-pointer text-sm">
                <span @click="isEmailCc = !isEmailCc">{{ t('emailInbox.compose.cc') }}</span>
                <span> | </span>
                <span @click="isEmailBcc = !isEmailBcc">{{ t('emailInbox.compose.bcc') }}</span>
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
        <TiptapEditor
          v-model="body"
          class="compose-editor"
          :placeholder="t('emailInbox.compose.body')"
        />

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
