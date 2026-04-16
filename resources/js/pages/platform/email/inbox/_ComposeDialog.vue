<script setup>
import { $platformApi } from '@/utils/platformApi'

const props = defineProps({
  isVisible: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'sent'])

const { t } = useI18n()
const { toast } = useAppToast()

const formRef = ref()
const to = ref('')
const toName = ref('')
const subject = ref('')
const body = ref('')
const isSending = ref(false)

const resetForm = () => {
  to.value = ''
  toName.value = ''
  subject.value = ''
  body.value = ''
  nextTick(() => formRef.value?.resetValidation())
}

const handleSend = async () => {
  const { valid } = await formRef.value.validate()
  if (!valid) return

  isSending.value = true
  try {
    await $platformApi('/email/inbox/compose', {
      method: 'POST',
      body: {
        to: to.value,
        to_name: toName.value || undefined,
        subject: subject.value,
        body: body.value,
      },
    })
    emit('sent')
    emit('close')
    resetForm()
  } catch (e) {
    toast(t('emailInbox.compose.error'), 'error')
  } finally {
    isSending.value = false
  }
}

const handleClose = () => {
  emit('close')
  resetForm()
}
</script>

<template>
  <VNavigationDrawer
    :model-value="isVisible"
    temporary
    location="right"
    width="560"
    @update:model-value="val => !val && handleClose()"
  >
    <div class="d-flex align-center pa-4">
      <h5 class="text-h5">
        {{ t('emailInbox.compose.title') }}
      </h5>
      <VSpacer />
      <IconBtn
        size="small"
        @click="handleClose"
      >
        <VIcon icon="tabler-x" />
      </IconBtn>
    </div>

    <VDivider />

    <VForm ref="formRef" @submit.prevent="handleSend">
      <div class="pa-4 d-flex flex-column gap-4">
        <AppTextField
          v-model="to"
          :label="t('emailInbox.compose.to')"
          type="email"
          :rules="[requiredValidator, emailValidator]"
          prepend-inner-icon="tabler-mail"
        />

        <AppTextField
          v-model="toName"
          :label="t('emailInbox.compose.toName')"
          prepend-inner-icon="tabler-user"
        />

        <AppTextField
          v-model="subject"
          :label="t('emailInbox.compose.subject')"
          :rules="[requiredValidator]"
        />

        <AppTextarea
          v-model="body"
          :label="t('emailInbox.compose.body')"
          :rules="[requiredValidator]"
          rows="10"
          auto-grow
        />
      </div>

      <VDivider />

      <div class="d-flex align-center justify-end gap-4 pa-4">
        <VBtn
          variant="tonal"
          color="secondary"
          @click="handleClose"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          type="submit"
          color="primary"
          append-icon="tabler-send"
          :loading="isSending"
          :disabled="isSending"
        >
          {{ t('emailInbox.compose.send') }}
        </VBtn>
      </div>
    </VForm>
  </VNavigationDrawer>
</template>
