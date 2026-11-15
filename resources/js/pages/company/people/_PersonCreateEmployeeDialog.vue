<script setup>
// ADR-768 (M7 LOT 2) — création d'une fiche salarié SANS compte, en place depuis Collaborateurs.
// Même endpoint et mêmes validations que la liste Workforce (`POST /workforce/employees`).
import AppAdaptiveDialog from '@/core/components/AppAdaptiveDialog.vue'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'created'])

const { t } = useI18n()
const store = useEmployeesStore()
const { toast } = useAppToast()

const open = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })
const blank = () => ({ first_name: '', last_name: '', email: '', phone: '', employee_number: '', hire_date: new Date().toISOString().slice(0, 10) })
const form = ref(blank())
const formRef = ref(null)
const saving = ref(false)
const errors = ref({})

const submit = async () => {
  const res = await formRef.value?.validate?.()
  if (res && res.valid === false) return
  saving.value = true
  errors.value = {}
  try {
    const created = await store.createEmployee(form.value)
    const emp = created?.data ?? created

    form.value = blank()
    open.value = false
    toast(t('employees.created'), 'success')
    emit('created', emp)
  }
  catch (e) {
    errors.value = e?.data?.errors || e?.response?._data?.errors || {}
    if (!Object.keys(errors.value).length) toast(e?.data?.message || t('common.error'), 'error')
  }
  finally { saving.value = false }
}
</script>

<template>
  <AppAdaptiveDialog
    v-model="open"
    :title="t('employees.addEmployee')"
    :max-width="480"
  >
    <VForm
      ref="formRef"
      @submit.prevent="submit"
    >
      <VRow>
        <VCol cols="12">
          <AppTextField
            v-model="form.first_name"
            data-test="emp-first-name"
            :label="t('employees.fields.firstName') + ' *'"
            :rules="[v => !!v || t('validation.required')]"
            :error-messages="errors.first_name"
          />
        </VCol>
        <VCol cols="12">
          <AppTextField
            v-model="form.last_name"
            data-test="emp-last-name"
            :label="t('employees.fields.lastName') + ' *'"
            :rules="[v => !!v || t('validation.required')]"
            :error-messages="errors.last_name"
          />
        </VCol>
        <VCol cols="12">
          <AppTextField
            v-model="form.email"
            :label="t('employees.fields.email')"
            type="email"
            :error-messages="errors.email"
          />
        </VCol>
        <VCol cols="12">
          <AppTextField
            v-model="form.phone"
            :label="t('employees.fields.phone')"
          />
        </VCol>
        <VCol cols="12">
          <AppTextField
            v-model="form.employee_number"
            :label="t('employees.fields.employeeNumber')"
                        :placeholder="t('employees.fields.employeeNumberAuto')"
                        persistent-placeholder
            :error-messages="errors.employee_number"
          />
        </VCol>
        <VCol cols="12">
          <AppDateTimePicker
            v-model="form.hire_date"
            data-test="emp-hire-date"
            :label="t('employees.fields.hireDate') + ' *'"
            :config="{ dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y' }"
            :rules="[v => !!v || t('validation.required')]"
            :error-messages="errors.hire_date"
          />
        </VCol>
      </VRow>
    </VForm>
    <template #actions="{ close }">
      <VSpacer />
      <VBtn
        variant="outlined"
        @click="close"
      >
        {{ t('common.cancel') }}
      </VBtn>
      <VBtn
        color="primary"
        data-test="emp-submit"
        :loading="saving"
        @click="submit"
      >
        {{ t('common.create') }}
      </VBtn>
    </template>
  </AppAdaptiveDialog>
</template>
