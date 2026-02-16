<script setup>
import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'

const props = defineProps({
  member: {
    type: Object,
    default: null,
  },
  baseFields: {
    type: Object,
    default: () => ({}),
  },
  dynamicFields: {
    type: Array,
    default: () => [],
  },
  editable: {
    type: Boolean,
    default: false,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  companyRoles: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['save'])

const form = ref({
  first_name: '',
  last_name: '',
  company_role_id: null,
})

const dynamicForm = ref({})
const isSaving = ref(false)

const roleOptions = computed(() =>
  props.companyRoles.map(r => ({ title: r.name, value: r.id })),
)

watch(
  () => [props.baseFields, props.member],
  () => {
    form.value.first_name = props.baseFields?.first_name || ''
    form.value.last_name = props.baseFields?.last_name || ''
    form.value.company_role_id = props.member?.company_role?.id || null
  },
  { immediate: true },
)

watch(
  () => props.dynamicFields,
  fields => {
    const df = {}
    for (const field of fields || []) {
      df[field.code] = field.value ?? null
    }
    dynamicForm.value = df
  },
  { immediate: true },
)

const handleSave = () => {
  const payload = {
    first_name: form.value.first_name,
    last_name: form.value.last_name,
  }

  // Send company_role_id if it changed
  const currentRoleId = props.member?.company_role?.id || null
  if (form.value.company_role_id !== currentRoleId) {
    payload.company_role_id = form.value.company_role_id
  }

  if (props.dynamicFields.length > 0) {
    payload.dynamic_fields = { ...dynamicForm.value }
  }

  emit('save', payload)
}
</script>

<template>
  <VForm @submit.prevent="handleSave">
    <VRow>
      <!-- Base fields -->
      <VCol
        cols="12"
        md="6"
      >
        <AppTextField
          v-model="form.first_name"
          label="First Name"
          placeholder="First Name"
          :disabled="!editable"
        />
      </VCol>
      <VCol
        cols="12"
        md="6"
      >
        <AppTextField
          v-model="form.last_name"
          label="Last Name"
          placeholder="Last Name"
          :disabled="!editable"
        />
      </VCol>

      <!-- Email (always readonly) -->
      <VCol
        cols="12"
        md="6"
      >
        <AppTextField
          :model-value="baseFields?.email || ''"
          label="Email"
          disabled
        />
      </VCol>

      <!-- Role -->
      <VCol
        cols="12"
        md="6"
      >
        <AppSelect
          v-if="editable && member?.role !== 'owner'"
          v-model="form.company_role_id"
          label="Role"
          :items="roleOptions"
          clearable
          placeholder="No role"
        />
        <AppTextField
          v-else
          :model-value="member?.company_role?.name || member?.role || ''"
          label="Role"
          disabled
          class="text-capitalize"
        />
      </VCol>

      <!-- Dynamic fields -->
      <template v-if="dynamicFields.length && !loading">
        <VCol cols="12">
          <VDivider />
        </VCol>
        <VCol cols="12">
          <h6 class="text-h6">
            Additional Information
          </h6>
        </VCol>
        <DynamicFormRenderer
          v-model="dynamicForm"
          :fields="dynamicFields"
          :disabled="!editable"
        />
      </template>

      <VCol
        v-if="loading"
        cols="12"
      >
        <VProgressLinear indeterminate />
      </VCol>

      <!-- Save button -->
      <VCol
        v-if="editable"
        cols="12"
      >
        <VBtn
          type="submit"
          :loading="isSaving"
        >
          Save changes
        </VBtn>
      </VCol>
    </VRow>
  </VForm>
</template>
