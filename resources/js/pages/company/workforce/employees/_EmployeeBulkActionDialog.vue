<script setup>
// W-G2 (ADR-642) — assistant d'action groupée sûre : changer département / poste / responsable.
// Affiche le nombre concerné, la cible, les inéligibles, prévisualise, puis confirme. Garde-fous
// backend (tenant/scope/cycle). Jamais de contrat, rémunération, rôle applicatif ni sortie.
import { useAppToast } from '@/composables/useAppToast'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import EmployeePicker from '@/components/workforce/EmployeePicker.vue'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  action: { type: String, required: true }, // set_department | set_job_role | set_manager
  employeeIds: { type: Array, required: true },
})
const emit = defineEmits(['update:modelValue', 'done'])

const { t } = useI18n()
const { toast } = useAppToast()
const store = useEmployeesStore()

const isOpen = computed({
  get: () => props.modelValue,
  set: v => emit('update:modelValue', v),
})

const targetId = ref(null)
const preview = ref(null)
const loading = ref(false)
const applying = ref(false)

const reset = () => { targetId.value = null; preview.value = null }
watch(isOpen, v => {
  if (v) {
    reset()
    if (props.action === 'set_department') store.fetchDepartments()
    if (props.action === 'set_job_role') store.fetchJobRoles()
  }
})

const targetItems = computed(() => {
  if (props.action === 'set_department') return store.departments.map(d => ({ title: d.name, value: d.id }))
  if (props.action === 'set_job_role') return store.jobRoles.map(r => ({ title: r.title, value: r.id }))

  return []
})

const runPreview = async () => {
  if (!targetId.value) return
  loading.value = true
  try {
    preview.value = await store.bulkPreview({ action: props.action, employeeIds: props.employeeIds, targetId: targetId.value })
  } catch (err) {
    toast(err.response?.data?.message || t('common.error'), 'error')
    preview.value = null
  } finally {
    loading.value = false
  }
}

watch(targetId, v => { if (v) runPreview(); else preview.value = null })

const eligibleCount = computed(() => preview.value?.eligible?.length || 0)

const apply = async () => {
  applying.value = true
  try {
    const res = await store.bulkApply({ action: props.action, employeeIds: props.employeeIds, targetId: targetId.value })

    toast(t('employees.bulk.applied', { updated: res.updated, skipped: res.skipped }), 'success')
    emit('done')
    isOpen.value = false
  } catch (err) {
    toast(err.response?.data?.message || t('common.error'), 'error')
  } finally {
    applying.value = false
  }
}
</script>

<template>
  <VDialog
    v-model="isOpen"
    max-width="560"
    persistent
  >
    <VCard>
      <VCardItem>
        <VCardTitle>{{ t('employees.bulk.titles.' + action) }}</VCardTitle>
        <VCardSubtitle>{{ t('employees.bulk.affected', { count: employeeIds.length }) }}</VCardSubtitle>
      </VCardItem>

      <VCardText>
        <!-- Cible -->
        <EmployeePicker
          v-if="action === 'set_manager'"
          v-model="targetId"
          :label="t('employees.bulk.targets.set_manager')"
          status-filter="active"
        />
        <AppSelect
          v-else
          v-model="targetId"
          :items="targetItems"
          :label="t('employees.bulk.targets.' + action)"
        />

        <!-- Prévisualisation d'éligibilité -->
        <template v-if="preview">
          <div class="d-flex gap-2 mt-4 mb-2">
            <VChip color="success" variant="tonal" size="small">
              {{ t('employees.bulk.eligible', { count: eligibleCount }) }}
            </VChip>
            <VChip
              v-if="preview.ineligible.length"
              color="warning"
              variant="tonal"
              size="small"
            >
              {{ t('employees.bulk.ineligible', { count: preview.ineligible.length }) }}
            </VChip>
          </div>
          <VList
            v-if="preview.ineligible.length"
            density="compact"
            class="text-caption"
            max-height="160"
          >
            <VListItem
              v-for="i in preview.ineligible"
              :key="i.id"
              :title="i.name || ('#' + i.id)"
              :subtitle="i.reason"
            >
              <template #prepend>
                <VIcon
                  icon="tabler-alert-circle"
                  color="warning"
                  size="18"
                />
              </template>
            </VListItem>
          </VList>
        </template>
      </VCardText>

      <VCardText class="d-flex justify-end gap-3 pt-0">
        <VBtn
          variant="outlined"
          color="secondary"
          :disabled="applying"
          @click="isOpen = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="applying"
          :disabled="!preview || eligibleCount === 0"
          @click="apply"
        >
          {{ t('employees.bulk.confirm', { count: eligibleCount }) }}
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>
</template>
