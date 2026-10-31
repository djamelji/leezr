<script setup>
// ADR-640 (W-C) — fiche collaborateur UNIFIÉE : en-tête + synthèse RH + sections conditionnées par les
// modules actifs et les permissions. Agrège via /workforce/employees/{id}/file (read-model composite) ;
// chaque section détaillée se charge à la demande depuis les endpoints existants (scope W-D).
import StatusChip from '@/core/components/StatusChip.vue'
import EmployeeProfile from './_EmployeeProfile.vue'
import EmployeeContract from './_EmployeeContract.vue'
import EmployeeLeaveSection from './_EmployeeLeaveSection.vue'
import EmployeeTimeSection from './_EmployeeTimeSection.vue'
import EmployeePlanningSection from './_EmployeePlanningSection.vue'
import EmployeeDocumentsSection from './_EmployeeDocumentsSection.vue'
import EmployeeSignaturesSection from './_EmployeeSignaturesSection.vue'
import GenerateContractDialog from './_GenerateContractDialog.vue'
import EmployeeAccessDialog from './_EmployeeAccessDialog.vue'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'
import { $api } from '@/utils/api'
import { formatDate, formatDateTime } from '@/utils/datetime'
import { formatMoney } from '@/utils/money'

definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const store = useEmployeesStore()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const { can } = useCan()
const { toast } = useAppToast()

const loading = ref(true)
const file = ref(null)
const currentTab = ref('overview')
const contractDialogOpen = ref(false) // W-CONTRACT-1
const accessDialogOpen = ref(false) // W-HR-UX-3 — accès collaborateur

const employee = computed(() => store.currentEmployee)
const empId = computed(() => route.params.id)

const load = async () => {
  loading.value = true
  try {
    const [, f] = await Promise.all([
      store.fetchEmployee(empId.value),
      $api(`/workforce/employees/${empId.value}/file`),
    ])
    file.value = f
  }
  catch { toast(t('employees.loadError'), 'error') }
  finally { loading.value = false }
}

onMounted(load)
onBeforeUnmount(() => store.clearCurrentEmployee())

const TAB_META = {
  overview: 'tabler-layout-dashboard', profile: 'tabler-user', contract: 'tabler-file-text',
  planning: 'tabler-calendar', time: 'tabler-clock', leave: 'tabler-beach',
  documents: 'tabler-file-certificate', history: 'tabler-history',
}
const TAB_ORDER = ['overview', 'profile', 'contract', 'planning', 'time', 'leave', 'documents', 'history']
const visibleTabs = computed(() => {
  const avail = file.value?.available_sections || []

  return TAB_ORDER.filter(k => ['overview', 'profile', 'contract', 'history'].includes(k) || avail.includes(k))
})

const ident = computed(() => file.value?.identity || {})
const pos = computed(() => file.value?.position || {})
const contract = computed(() => file.value?.contract)

// W-HR-UX-1 — checklist d'INTÉGRATION guidée (transforme un parcours fragmenté en étapes claires).
const onboarding = computed(() => file.value?.onboarding || {})
const onboardingSteps = computed(() => {
  const o = onboarding.value
  const steps = [
    { key: 'info', done: !!o.info, action: () => { currentTab.value = 'profile' } },
    { key: 'contract', done: !!o.contract, can: can('workforce_contract.generate'), action: () => { contractDialogOpen.value = true } },
  ]
  if (o.signature_module)
    steps.push({ key: 'signature', done: !!o.signed_contract, can: can('workforce_signature.request'), action: () => { currentTab.value = 'documents' } })
  steps.push({ key: 'account', done: !!o.account, state: o.account_state, action: () => { accessDialogOpen.value = true } })
  // §8 — jalon one-time "premier créneau programmé" (préparation du 1er jour). Pointage/congés
  // sont des activités RÉCURRENTES et restent volontairement HORS de cette checklist.
  if (o.planning_module)
    steps.push({ key: 'planning', done: !!o.planning_scheduled, action: () => { currentTab.value = 'planning' } })

  return steps
})
const onboardingDone = computed(() => onboardingSteps.value.filter(s => s.done).length)
const onboardingComplete = computed(() => onboardingDone.value === onboardingSteps.value.length)
const fmtMoney = c => formatMoney(c, { currency: contract.value?.compensation?.currency || 'EUR' })
</script>

<template>
  <div>
    <VBtn
      variant="text"
      :to="{ name: 'company-workforce-employees' }"
      prepend-icon="tabler-arrow-left"
      class="mb-4"
    >
      {{ $t('employees.backToList') }}
    </VBtn>

    <VCard
      v-if="loading"
      class="pa-8 text-center"
    >
      <VProgressCircular
        indeterminate
        color="primary"
      />
    </VCard>

    <template v-else-if="file">
      <!-- En-tête permanent -->
      <VCard class="mb-6">
        <VCardText>
          <div class="d-flex align-center gap-4 flex-wrap">
            <VAvatar
              color="primary"
              variant="tonal"
              size="64"
            >
              <span class="text-h5 font-weight-medium">{{ (ident.first_name?.[0] || '') + (ident.last_name?.[0] || '') }}</span>
            </VAvatar>
            <div class="flex-grow-1">
              <h4 class="text-h4 font-weight-bold">
                {{ ident.first_name }} {{ ident.last_name }}
              </h4>
              <div class="d-flex align-center gap-3 mt-1 flex-wrap text-body-2 text-medium-emphasis">
                <StatusChip
                  :status="ident.status"
                  domain="employee"
                >
                  {{ $t(`workforce.statuses.employee.${ident.status}`, ident.status) }}
                </StatusChip>
                <span v-if="pos.job_role">{{ pos.job_role }}</span>
                <span v-if="pos.department">· {{ pos.department }}</span>
                <span v-if="pos.manager">· {{ t('employeeFile.manager') }}: {{ pos.manager }}</span>
                <span v-if="ident.hire_date">· {{ t('employeeFile.since') }} {{ formatDate(ident.hire_date) }}</span>
              </div>
            </div>
            <!-- Actions rapides (conditionnées par permissions / modules) -->
            <div class="d-flex gap-2 flex-wrap">
              <VBtn
                v-can="'workforce.manage'"
                variant="outlined"
                prepend-icon="tabler-edit"
                @click="currentTab = 'profile'"
              >
                {{ $t('common.edit') }}
              </VBtn>
              <!-- W-HR-UX-6 (P3) : boutons "Congés"/"Pointages" retirés de l'en-tête — ils doublonnaient
                   les onglets de la fiche ET renvoyaient vers les pages GLOBALES (perte du contexte du
                   collaborateur). L'accès par salarié se fait via les onglets Congés/Pointages. -->
              <!-- W-CONTRACT-1 : générer un contrat (si module actif + permission) -->
              <VBtn
                v-can="'workforce_contract.generate'"
                color="primary"
                variant="tonal"
                prepend-icon="tabler-file-text"
                data-test="generate-contract"
                @click="contractDialogOpen = true"
              >
                {{ t('generateContract.action') }}
              </VBtn>
            </div>
          </div>
        </VCardText>
      </VCard>

      <VCard>
        <VTabs
          v-model="currentTab"
          class="v-tabs-pill"
          show-arrows
        >
          <VTab
            v-for="k in visibleTabs"
            :key="k"
            :value="k"
          >
            <VIcon
              :icon="TAB_META[k]"
              size="20"
              class="me-2"
            />
            {{ t('employeeFile.tabs.' + k) }}
          </VTab>
        </VTabs>
        <VDivider />
        <VCardText>
          <VWindow v-model="currentTab">
            <!-- Vue d'ensemble (synthèse RH) -->
            <VWindowItem value="overview">
              <VAlert
                v-for="(a, i) in file.alerts"
                :key="i"
                :type="a.level"
                variant="tonal"
                density="compact"
                class="mb-2"
              >
                {{ t('employeeFile.alerts.' + a.key, { count: a.count || 0 }) }}
              </VAlert>

              <!-- W-HR-UX-1 — Checklist d'intégration : la RH voit la prochaine étape sans deviner -->
              <VCard
                v-if="!onboardingComplete"
                variant="tonal"
                color="primary"
                class="mb-4"
              >
                <VCardText>
                  <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
                    <div class="text-subtitle-1 font-weight-medium">
                      <VIcon icon="tabler-checklist" start size="20" />
                      {{ t('onboarding.title') }}
                    </div>
                    <span class="text-caption">{{ onboardingDone }} / {{ onboardingSteps.length }}</span>
                  </div>
                  <VList class="bg-transparent py-0">
                    <VListItem
                      v-for="(step, i) in onboardingSteps"
                      :key="i"
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
                        <VBtn
                          v-if="!step.done && step.can !== false"
                          size="small"
                          variant="tonal"
                          @click="step.action"
                        >
                          {{ t('onboarding.do') }}
                        </VBtn>
                        <VChip v-else-if="step.done" size="small" color="success" variant="tonal" label>
                          {{ t('onboarding.ok') }}
                        </VChip>
                      </template>
                    </VListItem>
                  </VList>
                </VCardText>
              </VCard>

              <VRow class="mt-2">
                <VCol
                  cols="12"
                  md="6"
                >
                  <VCard
                    variant="outlined"
                    class="h-100"
                  >
                    <VCardText>
                      <div class="text-overline mb-2">
                        {{ t('employeeFile.tabs.contract') }}
                      </div>
                      <template v-if="contract">
                        <div>{{ t('employeeFile.contractType') }}: <strong>{{ contract.contract_type?.toUpperCase() }}</strong> ({{ contract.status }})</div>
                        <div>{{ t('employeeFile.weeklyHours') }}: {{ contract.weekly_hours }} h</div>
                        <div v-if="contract.convention">
                          {{ contract.convention }}
                        </div>
                        <div v-if="contract.compensation">
                          {{ t('employeeFile.salary') }}: <strong>{{ fmtMoney(contract.compensation.base_salary_cents) }}</strong> / {{ contract.compensation.pay_frequency }}
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
                  cols="12"
                  md="6"
                >
                  <VCard
                    variant="outlined"
                    class="h-100"
                  >
                    <VCardText>
                      <div class="text-overline mb-2">
                        {{ t('employeeFile.situation') }}
                      </div>
                      <div v-if="file.planning">
                        {{ t('employeeFile.nextShift') }}: {{ file.planning.date ? formatDate(file.planning.date) : '—' }}
                      </div>
                      <div v-if="file.time">
                        {{ t('employeeFile.lastClock') }}: {{ file.time.date ? formatDate(file.time.date) : '—' }} ({{ file.time.status }})
                      </div>
                      <div v-if="file.leave">
                        {{ t('employeeFile.pendingLeave') }}: {{ file.leave.pending_requests }}
                      </div>
                      <div v-if="file.documents">
                        {{ t('employeeFile.tabs.documents') }}: {{ file.documents.count }}
                      </div>
                    </VCardText>
                  </VCard>
                </VCol>
              </VRow>
            </VWindowItem>

            <VWindowItem value="profile">
              <EmployeeProfile
                v-if="employee"
                :employee="employee"
                @updated="load().then(() => toast(t('employees.updated'), 'success'))"
              />
            </VWindowItem>
            <VWindowItem value="contract">
              <EmployeeContract
                v-if="employee"
                :employee="employee"
                @updated="load"
              />
            </VWindowItem>
            <VWindowItem value="planning">
              <EmployeePlanningSection
                v-if="currentTab === 'planning'"
                :employee-id="empId"
                :can-manage="can('workforce.planning_manage')"
              />
            </VWindowItem>
            <VWindowItem value="time">
              <EmployeeTimeSection
                v-if="currentTab === 'time'"
                :employee-id="empId"
              />
            </VWindowItem>
            <VWindowItem value="leave">
              <EmployeeLeaveSection
                v-if="currentTab === 'leave'"
                :employee-id="empId"
              />
            </VWindowItem>
            <VWindowItem value="documents">
              <EmployeeDocumentsSection
                v-if="currentTab === 'documents'"
                :employee-id="empId"
              />
              <!-- W-SIGNATURE-1 : demandes de signature liées (contrat, attestations…), masqué si module inactif -->
              <EmployeeSignaturesSection
                v-if="currentTab === 'documents' && can('workforce_signature.view')"
                :employee-id="empId"
                class="mt-6"
              />
            </VWindowItem>
            <VWindowItem value="history">
              <div class="text-center text-disabled py-8">
                {{ t('employeeFile.historyEmpty') }}
              </div>
            </VWindowItem>
          </VWindow>
        </VCardText>
      </VCard>

      <!-- W-CONTRACT-1 : génération de contrat -->
      <GenerateContractDialog
        v-model="contractDialogOpen"
        :employee-id="empId"
        @generated="load"
      />

      <!-- W-HR-UX-3 — accès à l'espace collaborateur depuis la fiche -->
      <EmployeeAccessDialog
        v-model="accessDialogOpen"
        :employee-id="empId"
        :employee-email="ident.email || ''"
        @granted="load"
      />
    </template>
  </div>
</template>
