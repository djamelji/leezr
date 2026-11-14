<script setup>
// ADR-768 (M7 LOT 2) — vue d'ensemble de la fiche unifiée : alertes RH, checklist d'intégration
// (mêmes données que l'ancienne fiche Workforce — read-model EmployeeFileReadModel) et synthèse
// accès / contrat / situation. La rémunération n'apparaît que si le serveur l'a fournie.
import MoneyDisplay from '@/core/components/MoneyDisplay.vue'
import { usePersonStore } from '@/modules/company/people/person.store'
import { useCan } from '@/composables/useCan'
import { formatDate } from '@/utils/datetime'

defineProps({
  accountMeta: { type: Object, required: true },
})

const emit = defineEmits(['go', 'generate-contract', 'manage-access'])

const { t, te } = useI18n()
const store = usePersonStore()
const { can } = useCan()

const emp = computed(() => store.employment)
const contract = computed(() => emp.value?.contract)

const steps = computed(() => {
  const o = emp.value?.onboarding
  if (!o) return []
  const list = [
    { key: 'info', done: !!o.info, can: store.can.edit_hr, action: () => emit('go', 'employment') },
    { key: 'contract', done: !!o.contract, can: store.can.generate_contract, action: () => emit('generate-contract') },
  ]
  if (o.signature_module)
    list.push({ key: 'signature', done: !!o.signed_contract, can: can('workforce_signature.request'), action: () => emit('go', 'documents') })
  list.push({ key: 'account', done: !!o.account, can: store.can.manage_access, action: () => emit('manage-access') })
  if (o.planning_module)
    list.push({ key: 'planning', done: !!o.planning_scheduled, can: store.sections.includes('planning'), action: () => emit('go', 'planning') })

  return list
})
const doneCount = computed(() => steps.value.filter(s => s.done).length)
</script>

<template>
  <div>
    <VAlert
      v-for="(a, i) in emp?.alerts || []"
      :key="i"
      :type="a.level"
      variant="tonal"
      density="compact"
      class="mb-2"
    >
      {{ t('employeeFile.alerts.' + a.key, { count: a.count || 0 }) }}
    </VAlert>

    <VCard
      v-if="steps.length && doneCount < steps.length"
      variant="outlined"
      class="mb-4 border-s-lg border-primary"
      data-test="person-onboarding"
    >
      <VCardText>
        <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
          <div class="text-subtitle-1 font-weight-medium">
            <VIcon
              icon="tabler-checklist"
              start
              size="20"
            />
            {{ t('onboarding.title') }}
          </div>
          <span class="text-caption">{{ doneCount }} / {{ steps.length }}</span>
        </div>
        <VList class="bg-transparent py-0">
          <VListItem
            v-for="step in steps"
            :key="step.key"
            class="px-0"
          >
            <template #prepend>
              <VIcon
                :icon="step.done ? 'tabler-circle-check-filled' : 'tabler-circle'"
                :color="step.done ? 'success' : 'disabled'"
                class="me-3"
              />
            </template>
            <VListItemTitle :class="step.done ? 'text-medium-emphasis' : 'font-weight-medium'">
              {{ t('onboarding.steps.' + step.key) }}
            </VListItemTitle>
            <template #append>
              <VChip
                v-if="step.done"
                size="small"
                color="success"
                variant="tonal"
                label
              >
                {{ t('onboarding.ok') }}
              </VChip>
              <VBtn
                v-else-if="step.can"
                size="small"
                variant="tonal"
                @click="step.action"
              >
                {{ t('onboarding.do') }}
              </VBtn>
            </template>
          </VListItem>
        </VList>
      </VCardText>
    </VCard>

    <VRow class="card-grid card-grid-sm">
      <VCol
        cols="12"
        :md="emp ? 4 : 6"
      >
        <VCard variant="outlined">
          <VCardText>
            <div class="text-overline mb-2">
              {{ t('people.tabs.access') }}
            </div>
            <VChip
              :color="accountMeta.color"
              variant="tonal"
              size="small"
              label
              class="mb-2"
            >
              {{ accountMeta.label }}
            </VChip>
            <div
              v-if="store.account.role"
              class="text-body-2"
            >
              {{ store.account.role.name }}
            </div>
            <div
              v-if="store.identity.login_email"
              class="text-body-2 text-medium-emphasis text-truncate"
            >
              {{ store.identity.login_email }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        v-if="emp"
        cols="12"
        md="4"
      >
        <VCard variant="outlined">
          <VCardText>
            <div class="text-overline mb-2">
              {{ t('employeeFile.tabs.contract') }}
            </div>
            <template v-if="contract">
              <div>{{ t('employeeFile.contractType') }}: <strong>{{ contract.contract_type?.toUpperCase() }}</strong> ({{ te('workforce.statuses.contract.' + contract.status) ? t('workforce.statuses.contract.' + contract.status).toLowerCase() : contract.status }})</div>
              <div>{{ t('employeeFile.weeklyHours') }}: {{ contract.weekly_hours }} h</div>
              <div v-if="contract.convention">
                {{ contract.convention }}
              </div>
              <div
                v-if="contract.compensation && store.can.view_compensation"
                class="d-flex align-center gap-1"
                data-test="person-overview-salary"
              >
                {{ t('employeeFile.salary') }}:
                <MoneyDisplay
                  :amount="contract.compensation.base_salary_cents"
                  :currency="contract.compensation.currency || 'EUR'"
                  color="high-emphasis"
                />
                <span class="text-medium-emphasis">/ {{ te('employees.payFrequency.' + contract.compensation.pay_frequency) ? t('employees.payFrequency.' + contract.compensation.pay_frequency) : contract.compensation.pay_frequency }}</span>
              </div>
            </template>
            <div
              v-else
              class="text-disabled"
            >
              {{ t('employeeFile.noContract') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        v-if="emp"
        cols="12"
        md="4"
      >
        <VCard variant="outlined">
          <VCardText>
            <div class="text-overline mb-2">
              {{ t('employeeFile.situation') }}
            </div>
            <div v-if="emp.planning">
              {{ t('employeeFile.nextShift') }}: {{ emp.planning.date ? formatDate(emp.planning.date) : '—' }}
            </div>
            <div v-if="emp.time">
              {{ t('employeeFile.lastClock') }}: {{ emp.time.date ? formatDate(emp.time.date) : '—' }} ({{ t(`workforce.statuses.timeEntry.${emp.time.status}`, emp.time.status) }})
            </div>
            <div v-if="emp.leave">
              {{ t('employeeFile.pendingLeave') }}: {{ emp.leave.pending_requests }}
            </div>
            <div v-if="emp.documents">
              {{ t('employeeFile.tabs.documents') }}: {{ emp.documents.count }}
            </div>
            <div
              v-if="!emp.planning && !emp.time && !emp.leave && !emp.documents"
              class="text-disabled"
            >
              —
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        v-if="!emp && store.can.create_hr_record"
        cols="12"
        md="6"
      >
        <VCard variant="outlined">
          <VCardText>
            <div class="text-overline mb-2">
              {{ t('people.hrLink.noRecordTitle') }}
            </div>
            <p class="text-body-2 text-medium-emphasis mb-3">
              {{ t('people.hrLink.noRecordHint') }}
            </p>
            <VBtn
              size="small"
              variant="tonal"
              prepend-icon="tabler-id-badge-2"
              @click="emit('go', 'create-hr')"
            >
              {{ t('people.hrLink.createTitle') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
