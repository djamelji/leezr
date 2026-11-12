<script setup>
// ADR-768 (M7 LOT 2) — pont EXPLICITE compte ↔ fiche RH, décidé par un humain sur une paire
// précise (jamais de rapprochement par nom ou e-mail) :
//   - mode `create` : donner une fiche RH à un membre existant (aucune fiche inventée d'office) ;
//   - mode `link`   : rattacher une fiche RH sans compte à un membre existant.
import AppAdaptiveDialog from '@/core/components/AppAdaptiveDialog.vue'
import { usePersonStore } from '@/modules/company/people/person.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  mode: { type: String, default: 'create' }, // 'create' | 'link'
})

const emit = defineEmits(['update:modelValue', 'done'])

const { t } = useI18n()
const store = usePersonStore()
const { toast } = useAppToast()

const open = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })
const saving = ref(false)
const errors = ref({})
const form = ref({ hire_date: new Date().toISOString().slice(0, 10), employee_number: '' })
const candidates = ref([])
const candidatesLoading = ref(false)
const selectedUser = ref(null)

watch(open, async v => {
  if (!v) return
  errors.value = {}
  selectedUser.value = null
  if (props.mode !== 'link') return
  candidatesLoading.value = true
  try { candidates.value = await store.fetchLinkCandidates() }
  catch { candidates.value = [] }
  finally { candidatesLoading.value = false }
})

const candidateItems = computed(() => candidates.value.map(c => ({ title: c.name, subtitle: c.email, value: c.user_id })))

const submit = async () => {
  saving.value = true
  errors.value = {}
  try {
    let createdRef = null
    if (props.mode === 'link') {
      await store.linkAccount(selectedUser.value)
    }
    else {
      const created = await store.createHrRecord({ hire_date: form.value.hire_date, employee_number: form.value.employee_number || null })

      createdRef = created?.id ? `employee-${created.id}` : null
    }
    toast(t(props.mode === 'link' ? 'people.hrLink.linked' : 'people.hrLink.created'), 'success')
    open.value = false
    emit('done', createdRef)
  }
  catch (e) {
    errors.value = e?.data?.errors || {}
    toast(Object.values(errors.value)[0]?.[0] || e?.data?.message || t('common.error'), 'error')
  }
  finally { saving.value = false }
}
</script>

<template>
  <AppAdaptiveDialog
    v-model="open"
    :title="t(mode === 'link' ? 'people.hrLink.linkTitle' : 'people.hrLink.createTitle')"
    :max-width="480"
  >
    <p class="text-body-2 text-medium-emphasis">
      {{ t(mode === 'link' ? 'people.hrLink.linkHint' : 'people.hrLink.createHint') }}
    </p>

    <template v-if="mode === 'link'">
      <AppSelect
        v-model="selectedUser"
        :items="candidateItems"
        :loading="candidatesLoading"
        :label="t('people.hrLink.account')"
        :item-props="i => ({ title: i.title, subtitle: i.subtitle })"
        :no-data-text="t('people.hrLink.noCandidate')"
        :error-messages="errors.user_id"
        data-test="hr-link-account"
      />
    </template>
    <VRow v-else>
      <VCol cols="12">
        <AppDateTimePicker
          v-model="form.hire_date"
          :label="t('employees.fields.hireDate') + ' *'"
          :config="{ dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y' }"
          :error-messages="errors.hire_date"
          data-test="hr-link-hire-date"
        />
      </VCol>
      <VCol cols="12">
        <AppTextField
          v-model="form.employee_number"
          :label="t('employees.fields.employeeNumber')"
          :error-messages="errors.employee_number"
        />
      </VCol>
    </VRow>

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
        :loading="saving"
        :disabled="mode === 'link' ? !selectedUser : !form.hire_date"
        data-test="hr-link-submit"
        @click="submit"
      >
        {{ t(mode === 'link' ? 'people.hrLink.linkAction' : 'common.create') }}
      </VBtn>
    </template>
  </AppAdaptiveDialog>
</template>
