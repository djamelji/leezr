<script setup>
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const { toast } = useAppToast()

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  store: { type: Object, required: true },
})

const emit = defineEmits(['update:modelValue', 'generated'])

const isOpen = computed({
  get: () => props.modelValue,
  set: val => emit('update:modelValue', val),
})

const subjectTypeOptions = [
  { title: t('workforceDocuments.subjects.payrollLine'), value: 'payroll_line' },
  { title: t('workforceDocuments.subjects.employee'), value: 'employee' },
  { title: t('workforceDocuments.subjects.contract'), value: 'contract' },
  { title: t('workforceDocuments.subjects.leaveRequest'), value: 'leave_request' },
]

const formLoading = ref(false)

const formData = ref({
  templateCode: '',
  subjectType: null,
  subjectId: '',
  forceVault: false,
})

const resetForm = () => {
  formData.value = {
    templateCode: '',
    subjectType: null,
    subjectId: '',
    forceVault: false,
  }
}

const submitGenerate = async () => {
  formLoading.value = true
  try {
    await props.store.generateDocument({
      templateCode: formData.value.templateCode,
      subjectType: formData.value.subjectType,
      subjectId: formData.value.subjectId,
      forceVault: formData.value.forceVault,
    })

    isOpen.value = false
    resetForm()
    emit('generated')
  } catch (e) {
    toast(e?.response?.data?.message || e.message, 'error')
  } finally {
    formLoading.value = false
  }
}
</script>

<template>
  <VNavigationDrawer
    v-model="isOpen"
    temporary
    location="end"
    width="400"
  >
    <div class="d-flex align-center pa-4">
      <h5 class="text-h5 flex-grow-1">
        {{ $t('workforceDocuments.drawer.title') }}
      </h5>
      <VBtn
        icon
        variant="text"
        size="small"
        @click="isOpen = false"
      >
        <VIcon icon="tabler-x" />
      </VBtn>
    </div>

    <VDivider />

    <div class="pa-4">
      <VForm @submit.prevent="submitGenerate">
        <VRow>
          <VCol cols="12">
            <AppTextField
              v-model="formData.templateCode"
              :label="$t('workforceDocuments.fields.templateCode')"
              :rules="[v => !!v || $t('validation.required')]"
            />
          </VCol>
          <VCol cols="12">
            <AppSelect
              v-model="formData.subjectType"
              :items="subjectTypeOptions"
              :label="$t('workforceDocuments.fields.subjectType')"
              :rules="[v => !!v || $t('validation.required')]"
            />
          </VCol>
          <VCol cols="12">
            <AppTextField
              v-model="formData.subjectId"
              :label="$t('workforceDocuments.fields.subjectId')"
              type="number"
              :rules="[v => !!v || $t('validation.required')]"
            />
          </VCol>
          <VCol cols="12">
            <VSwitch
              v-model="formData.forceVault"
              :label="$t('workforceDocuments.fields.forceVault')"
              color="primary"
            />
          </VCol>
          <VCol cols="12">
            <div class="d-flex gap-3 justify-end">
              <VBtn
                variant="outlined"
                @click="isOpen = false"
              >
                {{ $t('common.cancel') }}
              </VBtn>
              <VBtn
                v-can="'workforce_documents.generate'"
                type="submit"
                color="primary"
                :loading="formLoading"
              >
                {{ $t('workforceDocuments.generate') }}
              </VBtn>
            </div>
          </VCol>
        </VRow>
      </VForm>
    </div>
  </VNavigationDrawer>
</template>
