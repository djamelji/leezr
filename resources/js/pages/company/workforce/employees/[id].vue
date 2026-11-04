<script setup>
// ADR-640 (W-C) — fiche collaborateur UNIFIÉE : en-tête + synthèse RH + sections conditionnées par les
// modules actifs et les permissions. Agrège via /workforce/employees/{id}/file (read-model composite) ;
// chaque section détaillée se charge à la demande depuis les endpoints existants (scope W-D).
import StatusChip from '@/core/components/StatusChip.vue'
import MoneyDisplay from '@/core/components/MoneyDisplay.vue'
import ErrorState from '@/core/components/ErrorState.vue'
import SmartActionBar from '@/core/components/SmartActionBar.vue'
import AppSectionNavigation from '@/core/components/AppSectionNavigation.vue'
import EmployeeProfile from './_EmployeeProfile.vue'
import EmployeeContract from './_EmployeeContract.vue'
import EmployeeLeaveSection from './_EmployeeLeaveSection.vue'
import EmployeeTimeSection from './_EmployeeTimeSection.vue'
import EmployeePlanningSection from './_EmployeePlanningSection.vue'
import EmployeeDocumentsSection from './_EmployeeDocumentsSection.vue'
import EmployeeSignaturesSection from './_EmployeeSignaturesSection.vue'
import GenerateContractDialog from './_GenerateContractDialog.vue'
import EmployeeAccessDialog from './_EmployeeAccessDialog.vue'
import EmployeeAccessCard from './_EmployeeAccessCard.vue'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'
import { $api } from '@/utils/api'
import { formatDate, formatDateTime } from '@/utils/datetime'

definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const store = useEmployeesStore()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const { can } = useCan()
const { toast } = useAppToast()

const loading = ref(true)
const loadError = ref(false)
const file = ref(null)
// E3-A : la rémunération n'est JAMAIS rendue sans la capacité de lecture (défense en profondeur,
// en plus du scrubber serveur). Évite toute fuite de salaire dans la synthèse.
const canReadComp = computed(() => can('workforce.compensation_read'))
const currentTab = ref('overview')
const contractDialogOpen = ref(false) // W-CONTRACT-1
const accessDialogOpen = ref(false) // W-HR-UX-3 — accès collaborateur
const accessReloadSignal = ref(0) // P3-A — rafraîchit la carte Accès après invite/relance
// W-HR-UX-7 (P3) — « Modifier » mène DIRECTEMENT au formulaire : va sur l'onglet Profil ET déclenche
// le mode édition (compteur-signal observé par _EmployeeProfile), sans 2ᵉ clic « Modifier ».
const profileEditSignal = ref(0)
const editProfile = () => { currentTab.value = 'profile'; profileEditSignal.value++ }

// SMART RESPONSIVE UX (ADR-694) — actions d'en-tête DÉCLARATIVES pour SmartActionBar : action
// principale explicite (Modifier), secondaires regroupées intelligemment, filtrées par permission.
// L'espace « Préparer le départ » (E5) n'est PAS exposé ici (non livré).
const headerActions = computed(() => [
  { id: 'edit', label: t('common.edit'), icon: 'tabler-edit', priority: 100, primary: true, permission: 'workforce.manage', handler: editProfile, dataTest: 'emp-edit' },
  { id: 'contract', label: t('generateContract.action'), icon: 'tabler-file-text', priority: 80, permission: 'workforce_contract.generate', handler: () => { contractDialogOpen.value = true }, dataTest: 'generate-contract' },
  { id: 'access', label: t('employeeFile.manageAccess'), icon: 'tabler-key', priority: 70, handler: () => { accessDialogOpen.value = true }, dataTest: 'emp-manage-access' },
])

const employee = computed(() => store.currentEmployee)
const empId = computed(() => route.params.id)

const load = async () => {
  loading.value = true
  loadError.value = false
  try {
    const [, f] = await Promise.all([
      store.fetchEmployee(empId.value),
      $api(`/workforce/employees/${empId.value}/file`),
    ])
    file.value = f
  }
  catch { loadError.value = true }
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

// SMART RESPONSIVE UX : sections pour AppSectionNavigation (onglets desktop / sélecteur mobile).
const tabSections = computed(() => visibleTabs.value.map(k => ({ value: k, label: t('employeeFile.tabs.' + k), icon: TAB_META[k] })))

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

    <!-- E3-A : skeleton de structure (en-tête + corps) au lieu d'un spinner plein écran -->
    <template v-if="loading">
      <VCard class="mb-6">
        <VCardText>
          <VSkeletonLoader type="list-item-avatar-two-line" />
        </VCardText>
      </VCard>
      <VCard>
        <VCardText>
          <VSkeletonLoader type="article, table-row@3" />
        </VCardText>
      </VCard>
    </template>

    <VCard v-else-if="loadError">
      <ErrorState
        :message="$t('employees.loadError')"
        @retry="load"
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
            <!-- SMART RESPONSIVE UX : command bar adaptative (overflow piloté par la largeur réelle) -->
            <SmartActionBar
              :actions="headerActions"
              class="smart-action-bar--header"
            />
          </div>
        </VCardText>
      </VCard>

      <VCard>
        <!-- SMART RESPONSIVE UX : navigation secondaire adaptative (onglets desktop / sélecteur mobile) -->
        <div class="pa-3 pb-0">
          <AppSectionNavigation
            v-model="currentTab"
            :sections="tabSections"
          />
        </div>
        <VDivider class="mt-3" />
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

              <!-- W-HR-UX-1 — Checklist d'intégration : la RH voit la prochaine étape sans deviner.
                   E3-A : variante sobre (outlined) pour ne pas écraser la synthèse. -->
              <VCard
                v-if="!onboardingComplete"
                variant="outlined"
                class="mb-4 border-s-lg border-primary"
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
                  md="4"
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
                        <!-- E3-A : salaire affiché UNIQUEMENT avec workforce.compensation_read (fix fuite overview) -->
                        <div
                          v-if="contract.compensation && canReadComp"
                          class="d-flex align-center gap-1"
                        >
                          {{ t('employeeFile.salary') }}:
                          <MoneyDisplay
                            :amount="contract.compensation.base_salary_cents"
                            :currency="contract.compensation.currency || 'EUR'"
                            color="high-emphasis"
                          />
                          <span class="text-medium-emphasis">/ {{ contract.compensation.pay_frequency }}</span>
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
                  md="4"
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
                <!-- P3-A — Accès & compte : identité unifiée (Employee ↔ compte ↔ membership) -->
                <VCol
                  cols="12"
                  md="4"
                >
                  <EmployeeAccessCard
                    :employee-id="empId"
                    :reload-signal="accessReloadSignal"
                    @manage="accessDialogOpen = true"
                  />
                </VCol>
              </VRow>
            </VWindowItem>

            <VWindowItem value="profile">
              <EmployeeProfile
                v-if="employee"
                :employee="employee"
                :edit-signal="profileEditSignal"
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
        @granted="load(); accessReloadSignal++"
      />
    </template>
  </div>
</template>
