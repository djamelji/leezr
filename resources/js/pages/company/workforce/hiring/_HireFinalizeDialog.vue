<script setup>
// ADR-818 — Finaliser une embauche validée : date d'entrée, poste, service, responsable (salarié actif).
// Une transaction serveur : la fiche préparatoire devient un collaborateur actif. Aucun compte, aucun droit,
// aucun contrat n'est créé ici : ces étapes restent explicites (liens proposés après la finalisation).
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({ isDialogVisible: { type: Boolean, default: false }, file: { type: Object, default: null } })
const emit = defineEmits(['update:isDialogVisible', 'finalized'])
const { t } = useI18n()
const { can } = useCan()
const { toast } = useAppToast()

const form = ref({ hire_date: '', job_role_id: null, department_id: null, manager_id: null })
const jobs = ref([])
const departments = ref([])
const managers = ref([])
const busy = ref(false)
const done = ref(null)
const rows = res => (Array.isArray(res) ? res : Object.values(res?.data ?? res ?? {})).filter(r => r && typeof r === 'object' && r.id)

watch(() => props.isDialogVisible, async v => {
  if (!v || !props.file)
    return
  done.value = null
  form.value = { hire_date: props.file.hire_date || new Date().toISOString().slice(0, 10), job_role_id: null, department_id: null, manager_id: null }
  const [j, d, e] = await Promise.all([
    $api('/workforce/job-roles').catch(() => []),
    $api('/workforce/departments').catch(() => []),
    $api('/workforce/employees', { query: { per_page: 200, status: 'active' } }).catch(() => ({ data: [] })),
  ])
  jobs.value = rows(j).map(r => ({ title: r.title, value: r.id }))
  departments.value = rows(d).map(r => ({ title: r.name, value: r.id }))
  managers.value = rows(e.data ?? e).filter(r => r.status === 'active').map(r => ({ title: `${r.first_name} ${r.last_name}`, value: r.id }))
  form.value.job_role_id = jobs.value.find(o => o.title === props.file.job_role)?.value ?? null
})

const submit = async () => {
  busy.value = true
  try {
    done.value = await $api(`/workforce/onboarding-files/${props.file.id}/finalize`, { method: 'POST', body: { ...form.value, ...(props.file.revision ? { revision: props.file.revision } : {}) } }) // ADR-819 : révision chargée
    toast(t('hiring.finalized'), 'success')
    emit('finalized')
  }
  catch (e) { toast(Object.values(e?.data?.errors || {})[0]?.[0] || e?.data?.message || t('common.error'), 'error') }
  finally { busy.value = false }
}
</script>

<template>
  <AppDialog
    :model-value="isDialogVisible"
    :title="t('hiring.finalizeTitle', { name: file?.name || '' })"
    :subtitle="t('hiring.finalizeSubtitle')"
    :max-width="640"
    @update:model-value="v => emit('update:isDialogVisible', v)"
  >
    <template v-if="done">
      <VAlert
        type="success"
        variant="tonal"
        class="mb-4"
        data-test="hiring-finalized"
      >
        {{ t('hiring.finalizedLong') }}
      </VAlert>
      <div class="d-flex flex-column gap-2">
        <VBtn
          variant="tonal"
          prepend-icon="tabler-id"
          :to="`/company/people/${done.person_ref}`"
        >
          {{ t('hiring.openFile') }}
        </VBtn>
        <VBtn
          v-if="can('workforce.contracts')"
          variant="tonal"
          prepend-icon="tabler-file-certificate"
          :to="`/company/people/${done.person_ref}/contract`"
        >
          {{ t('hiring.createContract') }}
        </VBtn>
        <VBtn
          v-if="can('members.invite')"
          variant="tonal"
          prepend-icon="tabler-key"
          :to="`/company/people/${done.person_ref}/access`"
        >
          {{ t('hiring.giveAccess') }}
        </VBtn>
      </div>
    </template>
    <VRow v-else>
      <VCol
        cols="12"
        sm="6"
      >
        <AppDateTimePicker
          v-model="form.hire_date"
          :label="`${t('onboardingFile.hireDate')} *`"
          data-test="hiring-finalize-date"
        />
      </VCol>
      <VCol
        cols="12"
        sm="6"
      >
        <AppSelect
          v-model="form.job_role_id"
          :items="jobs"
          :label="t('hiring.position')"
        />
      </VCol>
      <VCol
        cols="12"
        sm="6"
      >
        <AppSelect
          v-model="form.department_id"
          :items="departments"
          clearable
          :label="t('positions.fields.department')"
        />
      </VCol>
      <VCol
        cols="12"
        sm="6"
      >
        <AppAutocomplete
          v-model="form.manager_id"
          :items="managers"
          clearable
          :label="t('employees.fields.manager')"
        />
      </VCol>
      <VCol cols="12">
        <p class="text-caption text-medium-emphasis mb-0">
          {{ t('hiring.finalizeNote') }}
        </p>
      </VCol>
    </VRow>

    <template #actions>
      <VBtn
        variant="text"
        @click="emit('update:isDialogVisible', false)"
      >
        {{ done ? t('common.close') : t('common.cancel') }}
      </VBtn>
      <VBtn
        v-if="!done"
        color="primary"
        :loading="busy"
        :disabled="!form.hire_date"
        data-test="hiring-finalize-submit"
        @click="submit"
      >
        {{ t('hiring.finalize') }}
      </VBtn>
    </template>
  </AppDialog>
</template>
