<script setup>
import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'

const { t } = useI18n()

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
  profileCompleteness: {
    type: Object,
    default: () => ({ filled: 0, total: 0, complete: true }),
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

const emit = defineEmits(['save', 'role-change'])

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

// ADR-168: group dynamic fields by category
const fieldsByCategory = computed(() => {
  const groups = { base: [], hr: [], domain: [] }

  for (const field of props.dynamicFields) {
    const cat = field.category || 'base'
    if (groups[cat])
      groups[cat].push(field)
    else
      groups.base.push(field)
  }

  return groups
})

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

// ADR-164: Emit role-change when user picks a different role
const initialRoleSet = ref(false)

watch(() => form.value.company_role_id, (newId, oldId) => {
  if (!initialRoleSet.value) {
    initialRoleSet.value = true

    return
  }
  if (newId !== oldId) {
    emit('role-change', newId)
  }
})

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
      <!-- ADR-168b: profile completeness indicator -->
      <VCol
        v-if="!profileCompleteness.complete"
        cols="12"
      >
        <VAlert
          type="warning"
          variant="tonal"
        >
          {{ t('fields.profileIncomplete', { filled: profileCompleteness.filled, total: profileCompleteness.total }) }}
        </VAlert>
      </VCol>
      <VCol
        v-else-if="profileCompleteness.total > 0"
        cols="12"
      >
        <VChip
          color="success"
          variant="tonal"
          size="small"
        >
          {{ t('fields.profileComplete') }}
        </VChip>
      </VCol>

      <!-- Base fields -->
      <VCol
        cols="12"
        md="6"
      >
        <AppTextField
          v-model="form.first_name"
          :label="t('members.firstName')"
          :placeholder="t('members.firstName')"
          :disabled="!editable"
        />
      </VCol>
      <VCol
        cols="12"
        md="6"
      >
        <AppTextField
          v-model="form.last_name"
          :label="t('members.lastName')"
          :placeholder="t('members.lastName')"
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
          :label="t('common.email')"
          disabled
        />
      </VCol>

      <!-- Role -->
      <VCol
        cols="12"
        md="6"
      >
        <AppSelect
          v-if="editable && !member?._isProtected"
          v-model="form.company_role_id"
          :label="t('members.role')"
          :items="roleOptions"
          clearable
          :placeholder="t('members.noRole')"
        />
        <AppTextField
          v-else
          :model-value="member?.company_role?.name || member?.role || ''"
          :label="t('members.role')"
          disabled
          class="text-capitalize"
        />
      </VCol>

      <!-- Dynamic fields grouped by category (ADR-168) -->
      <template v-if="dynamicFields.length && !loading">
        <template
          v-for="cat in ['base', 'hr', 'domain']"
          :key="cat"
        >
          <template v-if="fieldsByCategory[cat]?.length">
            <VCol cols="12">
              <VDivider />
            </VCol>
            <VCol cols="12">
              <h6 class="text-h6">
                {{ t(`fields.category.${cat}`) }}
              </h6>
            </VCol>
            <DynamicFormRenderer
              v-model="dynamicForm"
              :fields="fieldsByCategory[cat]"
              :disabled="!editable"
            />
          </template>
        </template>
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
          {{ t('common.saveChanges') }}
        </VBtn>
      </VCol>
    </VRow>
  </VForm>
</template>
