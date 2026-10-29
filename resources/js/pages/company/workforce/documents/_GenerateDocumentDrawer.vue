<script setup>
// W-G1 (ADR-641) — génération documentaire SANS identifiant technique.
// Flux métier : type de document → modèle disponible → collaborateur → contexte → génération.
// Le code template et le subject_id ne sont jamais saisis : ils proviennent des sélecteurs.
import { useAppToast } from '@/composables/useAppToast'
import { $api } from '@/utils/api'
import EmployeePicker from '@/components/workforce/EmployeePicker.vue'
import DocumentTemplatePicker from '@/components/workforce/DocumentTemplatePicker.vue'

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

// Types de document que l'on génère ad hoc à propos d'une personne (les bulletins/DSN ont leurs
// propres écrans). On ne propose donc QUE les sujets rattachés au collaborateur.
const subjectTypeOptions = [
  { title: t('workforceDocuments.subjects.employee'), value: 'employee' },
  { title: t('workforceDocuments.subjects.contract'), value: 'contract' },
]

const formLoading = ref(false)

const blank = () => ({
  subjectType: 'employee',
  templateCode: '',
  template: null,
  employeeId: null,
  employee: null,
  forceVault: false,
})
const formData = ref(blank())

const resetForm = () => { formData.value = blank() }

// Changer de type de document invalide le modèle choisi (incompatible).
watch(() => formData.value.subjectType, () => {
  formData.value.templateCode = ''
  formData.value.template = null
})

const canSubmit = computed(() =>
  !!formData.value.templateCode && !!formData.value.employeeId,
)

// Résout le subject_id attendu par l'API à partir du contexte métier (jamais saisi à la main).
const resolveSubjectId = async () => {
  if (formData.value.subjectType === 'employee')
    return formData.value.employeeId

  // subject_type = contract → contrat courant du collaborateur
  const detail = await $api(`/workforce/employees/${formData.value.employeeId}`)
  const contracts = detail?.contracts ?? []
  const current = contracts.find(c => c.is_current) ?? contracts[0]
  if (!current)
    throw new Error(t('workforceDocuments.drawer.noActiveContract'))

  return current.id
}

const submitGenerate = async () => {
  if (!canSubmit.value) return
  formLoading.value = true
  try {
    const subjectId = await resolveSubjectId()

    await props.store.generateDocument({
      templateCode: formData.value.templateCode,
      subjectType: formData.value.subjectType,
      subjectId,
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
    width="420"
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
          <!-- 1. Type de document -->
          <VCol cols="12">
            <AppSelect
              v-model="formData.subjectType"
              :items="subjectTypeOptions"
              :label="$t('workforceDocuments.drawer.documentType')"
            />
          </VCol>

          <!-- 2. Modèle disponible (filtré par type) -->
          <VCol cols="12">
            <DocumentTemplatePicker
              v-model="formData.templateCode"
              :subject-type="formData.subjectType"
              :rules="[v => !!v || $t('validation.required')]"
              @selected="formData.template = $event"
            />
          </VCol>

          <!-- 3. Collaborateur -->
          <VCol cols="12">
            <EmployeePicker
              v-model="formData.employeeId"
              :rules="[v => !!v || $t('validation.required')]"
              status-filter="active"
              @selected="formData.employee = $event"
            />
          </VCol>

          <!-- 4. Contexte -->
          <VCol
            v-if="formData.template && formData.employee"
            cols="12"
          >
            <VAlert
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ $t('workforceDocuments.drawer.contextSummary', {
                template: formData.template.name,
                employee: `${formData.employee.first_name} ${formData.employee.last_name}`,
              }) }}
            </VAlert>
          </VCol>

          <VCol cols="12">
            <VSwitch
              v-model="formData.forceVault"
              :label="$t('workforceDocuments.fields.forceVault')"
              color="primary"
            />
          </VCol>

          <!-- 5. Génération -->
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
                :disabled="!canSubmit"
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
