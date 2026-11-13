<script setup>
// ADR-753 (Phase 1) + ADR-768 (M7 LOT 2) — fiche Collaborateur UNIFIÉE et OPÉRATIONNELLE (Core).
// Un seul parcours pour gérer une personne : identité, accès & rôle, emploi, contrat, planning,
// temps, congés, documents, bulletins, historique. Aucun moteur reconstruit : chaque section
// réutilise le composant et l'endpoint sécurisé existants (Members / Workforce / DMS). Les
// sections et actions visibles viennent du serveur (`available_sections`, `can.*`).
import { useRoute, useRouter } from 'vue-router'
import AppSectionNavigation from '@/core/components/AppSectionNavigation.vue'
import SmartActionBar from '@/core/components/SmartActionBar.vue'
import ErrorState from '@/core/components/ErrorState.vue'
import { usePersonStore } from '@/modules/company/people/person.store'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useCan } from '@/composables/useCan'
import { useAppToast } from '@/composables/useAppToast'
import PersonOverview from './_PersonOverview.vue'
import PersonIdentity from './_PersonIdentity.vue'
import PersonAccess from './_PersonAccess.vue'
import PersonPayslips from './_PersonPayslips.vue'
import PersonHrLinkDialog from './_PersonHrLinkDialog.vue'
import EmployeeProfile from '@/pages/company/workforce/employees/_EmployeeProfile.vue'
import EmployeeContract from '@/pages/company/workforce/employees/_EmployeeContract.vue'
import EmployeePlanningSection from '@/pages/company/workforce/employees/_EmployeePlanningSection.vue'
import EmployeeTimeSection from '@/pages/company/workforce/employees/_EmployeeTimeSection.vue'
import EmployeeLeaveSection from '@/pages/company/workforce/employees/_EmployeeLeaveSection.vue'
import EmployeeDocumentsSection from '@/pages/company/workforce/employees/_EmployeeDocumentsSection.vue'
import EmployeeSignaturesSection from '@/pages/company/workforce/employees/_EmployeeSignaturesSection.vue'
import EmployeeAccessDialog from '@/pages/company/workforce/employees/_EmployeeAccessDialog.vue'
import GenerateContractDialog from '@/pages/company/workforce/employees/_GenerateContractDialog.vue'
import MemberDocumentsWorkflowPanel from '@/views/pages/company-members/MemberDocumentsWorkflowPanel.vue'
import CreateDocumentTypeDrawer from '@/company/views/CreateDocumentTypeDrawer.vue'

// ADR-767 : même règle que l'API (`members.view` OU `workforce.view`).
definePage({ meta: { module: 'core.members', anyPermission: ['members.view', 'workforce.view'] } })

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = usePersonStore()
const employeesStore = useEmployeesStore()
const { can } = useCan()
const { toast } = useAppToast()

const personRef = computed(() => String(route.params.personRef || ''))
const currentTab = ref('overview')
const accessDialogOpen = ref(false)
const contractDialogOpen = ref(false)
const hrLinkOpen = ref(false)
const hrLinkMode = ref('create')
const docTypeDrawerOpen = ref(false)
const editSignal = ref(0)

// L'objet Employee complet (édition RH / contrat) — endpoint Workforce scoppé (ADR-767).
const employee = ref(null)
const loadEmployee = async () => {
  employee.value = null
  if (!store.employeeId || !store.can.view_hr) return
  try { employee.value = await employeesStore.fetchEmployee(store.employeeId) }
  catch { employee.value = null }
}

// ── Onglets : le serveur décide des sections ; « employment » se décline en Emploi + Contrat. ──
const TAB_ICONS = {
  overview: 'tabler-layout-dashboard', personal: 'tabler-user', access: 'tabler-key',
  employment: 'tabler-briefcase', contract: 'tabler-file-text', planning: 'tabler-calendar',
  time: 'tabler-clock', leave: 'tabler-beach', documents: 'tabler-file-certificate',
  payslips: 'tabler-receipt', history: 'tabler-history',
}
const TAB_ORDER = ['overview', 'personal', 'access', 'employment', 'contract', 'planning', 'time', 'leave', 'documents', 'payslips', 'history']
const tabKeys = computed(() => {
  const s = new Set(store.sections || [])
  if (s.has('employment')) s.add('contract')

  return TAB_ORDER.filter(k => s.has(k))
})
const tabSections = computed(() => tabKeys.value.map(k => ({ value: k, label: t(`people.tabs.${k}`), icon: TAB_ICONS[k] })))

const accountMeta = computed(() => ({
  connected: { color: 'success', label: t('people.account.connected') },
  active: { color: 'success', label: t('people.account.active') },
  invited: { color: 'warning', label: t('people.account.invited') },
  pending_acceptance: { color: 'warning', label: t('people.account.pending_acceptance') },
  invitation_expired: { color: 'secondary', label: t('people.account.invitation_expired') },
  suspended: { color: 'error', label: t('people.account.suspended') },
  none: { color: 'secondary', label: t('people.account.none') },
}[store.account.state] || (store.account.state
  ? { color: 'secondary', label: store.account.state }

  // ADR-771 : sans la facette comptes, le serveur ne dit que ce que porte la fiche RH — un compte y
  // est lié, ou non (ni statut, ni rôle, ni e-mail de connexion).
  : store.account.has_account
    ? { color: 'info', label: t('people.account.linked') }
    : { color: 'secondary', label: t('people.account.none') })))

// ── Actions d'en-tête (déclaratives, filtrées par les capacités serveur) ──
const openHrLink = mode => { hrLinkMode.value = mode; hrLinkOpen.value = true }
const editPerson = () => {
  if (store.can.edit_hr && employee.value) { currentTab.value = 'employment'; editSignal.value++ }
  else currentTab.value = 'personal'
}
const headerActions = computed(() => [
  (store.can.edit_hr && store.employeeId) || store.can.edit_identity
    ? { id: 'edit', label: t('common.edit'), icon: 'tabler-edit', priority: 100, primary: true, handler: editPerson, dataTest: 'person-edit' } : null,
  store.can.generate_contract
    ? { id: 'contract', label: t('generateContract.action'), icon: 'tabler-file-text', priority: 80, handler: () => { contractDialogOpen.value = true }, dataTest: 'person-generate-contract' } : null,
  store.can.manage_access && store.employeeId
    ? { id: 'access', label: t('people.manageAccess'), icon: 'tabler-key', priority: 70, handler: () => { accessDialogOpen.value = true }, dataTest: 'person-manage-access' } : null,
  store.can.create_hr_record
    ? { id: 'hr', label: t('people.hrLink.createTitle'), icon: 'tabler-id-badge-2', priority: 60, handler: () => openHrLink('create'), dataTest: 'person-create-hr' } : null,
  store.can.link_account
    ? { id: 'link', label: t('people.hrLink.linkTitle'), icon: 'tabler-link', priority: 50, handler: () => openHrLink('link'), dataTest: 'person-link-account' } : null,
].filter(Boolean))

async function reload() {
  await store.load(personRef.value)
  if (!store.data) return

  // Référence canonique (la fiche RH prime) : l'URL suit, sans perdre l'onglet demandé.
  if (store.personRef && store.personRef !== personRef.value) {
    router.replace({ params: { personRef: store.personRef }, query: route.query })

    return
  }
  if (!tabKeys.value.includes(currentTab.value)) currentTab.value = 'overview'
  await loadEmployee()
  if (currentTab.value === 'history') store.loadHistory(personRef.value)
}

watch(personRef, reload)
onMounted(async () => {
  if (typeof route.query.tab === 'string') currentTab.value = route.query.tab
  await reload()
})
onBeforeUnmount(() => employeesStore.clearCurrentEmployee())

// Deep-link : l'onglet courant vit dans l'URL (retour navigateur et partage de lien).
watch(currentTab, tab => {
  const q = { ...route.query }
  if (tab === 'overview') delete q.tab
  else q.tab = tab
  router.replace({ query: q })
  if (tab === 'history') store.loadHistory(personRef.value)
})

const go = target => {
  if (target === 'create-hr') openHrLink('create')
  else if (tabKeys.value.includes(target)) currentTab.value = target
}
const goBack = () => (window.history.state?.back ? router.back() : router.push({ name: 'company-people' }))
const onRemoved = () => router.push({ name: 'company-people' })
const onHrLinked = newRef => { if (newRef) router.replace({ params: { personRef: newRef } }); else reload() }
const initials = computed(() => `${(store.identity.first_name || '?').charAt(0)}${(store.identity.last_name || '').charAt(0)}`.toUpperCase())
</script>

<template>
  <div>
    <VBtn
      variant="text"
      size="small"
      prepend-icon="tabler-arrow-left"
      class="mb-3"
      data-test="person-back"
      @click="goBack"
    >
      {{ t('peopleList.title') }}
    </VBtn>

    <template v-if="store.loading && !store.data">
      <VCard class="mb-5">
        <VCardText><VSkeletonLoader type="list-item-avatar-two-line" /></VCardText>
      </VCard>
      <VCard>
        <VCardText><VSkeletonLoader type="article, table-row@3" /></VCardText>
      </VCard>
    </template>

    <VCard v-else-if="store.error || !store.data">
      <ErrorState
        :message="t('people.loadError')"
        @retry="reload"
      />
    </VCard>

    <template v-else>
      <!-- ═══ EN-TÊTE ═══ -->
      <VCard
        class="mb-5"
        data-test="person-header"
      >
        <VCardText class="py-4">
          <div class="d-flex align-center gap-3 flex-wrap">
            <VAvatar
              color="primary"
              variant="tonal"
              :size="$vuetify.display.smAndDown ? 48 : 64"
            >
              <VImg
                v-if="store.identity.avatar"
                :src="store.identity.avatar"
              />
              <span
                v-else
                class="font-weight-medium"
              >{{ initials }}</span>
            </VAvatar>
            <div
              class="flex-grow-1"
              style="min-inline-size: 0;"
            >
              <div class="d-flex align-center gap-2 flex-wrap">
                <h4 class="text-h5 text-sm-h4 font-weight-bold text-truncate mb-0">
                  {{ store.identity.display_name }}
                </h4>
                <VChip
                  :color="accountMeta.color"
                  size="small"
                  variant="tonal"
                  label
                  data-test="person-account-state"
                >
                  {{ accountMeta.label }}
                </VChip>
                <VChip
                  v-if="store.account.is_owner"
                  color="primary"
                  size="small"
                  variant="tonal"
                  label
                >
                  {{ t('people.owner') }}
                </VChip>
                <VChip
                  v-else-if="store.account.role"
                  :color="store.account.role.is_administrative ? 'warning' : 'secondary'"
                  size="small"
                  variant="tonal"
                  label
                >
                  {{ store.account.role.name }}
                </VChip>
              </div>
              <div class="text-body-2 text-medium-emphasis mt-1 d-flex flex-wrap gap-x-4 gap-y-1">
                <span v-if="store.identity.login_email"><VIcon
                  icon="tabler-mail"
                  size="14"
                /> {{ store.identity.login_email }}</span>
                <span v-if="store.employment?.position?.job_role"><VIcon
                  icon="tabler-briefcase"
                  size="14"
                /> {{ store.employment.position.job_role }}</span>
                <span v-if="store.employment?.position?.department"><VIcon
                  icon="tabler-building"
                  size="14"
                /> {{ store.employment.position.department }}</span>
                <span v-if="store.employment?.position?.manager"><VIcon
                  icon="tabler-users"
                  size="14"
                /> {{ store.employment.position.manager }}</span>
                <span v-if="store.employment?.identity?.status"><VIcon
                  icon="tabler-id-badge"
                  size="14"
                /> {{ t(`people.empStatus.${store.employment.identity.status}`, store.employment.identity.status) }}</span>
              </div>
            </div>
            <SmartActionBar
              v-if="headerActions.length"
              :actions="headerActions"
              class="smart-action-bar--header"
            />
          </div>
        </VCardText>
      </VCard>

      <!-- ═══ NAVIGATION (onglets desktop / sélecteur mobile) ═══ -->
      <AppSectionNavigation
        v-model="currentTab"
        :sections="tabSections"
        class="mb-5"
      />

      <VCard>
        <VCardText>
          <VWindow
            v-model="currentTab"
            class="disable-tab-transition"
          >
            <VWindowItem value="overview">
              <PersonOverview
                :account-meta="accountMeta"
                @go="go"
                @generate-contract="contractDialogOpen = true"
                @manage-access="accessDialogOpen = true"
              />
            </VWindowItem>

            <VWindowItem value="personal">
              <PersonIdentity
                v-if="currentTab === 'personal'"
                :membership-id="store.membershipId"
                :identity="store.identity"
                :employee-number="store.employment?.identity?.employee_number || null"
                :can-view-access="!!store.can.view_access"
                :can-edit="!!store.can.edit_identity"
                :lock-name="!store.can.edit_global_name"
                :shared="!!store.data.identity_shared"
                @updated="reload"
              />
            </VWindowItem>

            <VWindowItem value="access">
              <PersonAccess
                v-if="currentTab === 'access'"
                :account-meta="accountMeta"
                @changed="reload"
                @removed="onRemoved"
                @grant="accessDialogOpen = true"
              />
            </VWindowItem>

            <!-- Emploi : informations RH (département, poste, responsable, matricule, dates, statut) -->
            <VWindowItem value="employment">
              <EmployeeProfile
                v-if="employee"
                :employee="employee"
                :edit-signal="editSignal"
                @updated="reload().then(() => toast(t('employees.updated'), 'success'))"
              />
              <VSkeletonLoader
                v-else
                type="article"
              />
            </VWindowItem>

            <!-- Contrat : consultation, création, activation, rémunération (permissions séparées) -->
            <VWindowItem value="contract">
              <EmployeeContract
                v-if="employee"
                :employee="employee"
                @updated="reload"
              />
              <VSkeletonLoader
                v-else
                type="article"
              />
            </VWindowItem>

            <VWindowItem value="planning">
              <EmployeePlanningSection
                v-if="currentTab === 'planning' && store.employeeId"
                :employee-id="store.employeeId"
                :can-manage="can('workforce.planning_manage')"
              />
            </VWindowItem>
            <VWindowItem value="time">
              <EmployeeTimeSection
                v-if="currentTab === 'time' && store.employeeId"
                :employee-id="store.employeeId"
              />
            </VWindowItem>
            <VWindowItem value="leave">
              <EmployeeLeaveSection
                v-if="currentTab === 'leave' && store.employeeId"
                :employee-id="store.employeeId"
              />
            </VWindowItem>

            <!-- Documents : coffre DMS de la personne + documents RH générés + signatures -->
            <VWindowItem value="documents">
              <template v-if="currentTab === 'documents'">
                <MemberDocumentsWorkflowPanel
                  v-if="can('documents.view') && store.personRef"
                  :resource-base="`/company/people/${store.personRef}`"
                  :supports-requests="!!store.userId"
                  :can-edit="can('documents.manage')"
                  @create-custom-type="docTypeDrawerOpen = true"
                />
                <EmployeeDocumentsSection
                  v-if="store.employeeId && store.employment?.documents && can('workforce_documents.view')"
                  :employee-id="store.employeeId"
                  class="mt-6"
                />
                <EmployeeSignaturesSection
                  v-if="store.employeeId && store.can.view_signatures"
                  :employee-id="store.employeeId"
                  class="mt-6"
                />
              </template>
            </VWindowItem>

            <!-- Bulletins : autorité paie uniquement -->
            <VWindowItem value="payslips">
              <PersonPayslips
                v-if="currentTab === 'payslips' && store.personRef"
                :person-ref="store.personRef"
              />
            </VWindowItem>

            <!-- Historique (traces d'audit réelles) -->
            <VWindowItem value="history">
              <VProgressLinear
                v-if="store.historyLoading"
                indeterminate
                color="primary"
              />
              <VTimeline
                v-else-if="store.history.length"
                density="compact"
                side="end"
                truncate-line="both"
                class="mt-2"
              >
                <VTimelineItem
                  v-for="e in store.history"
                  :key="e.id"
                  :dot-color="e.severity === 'warning' ? 'warning' : e.severity === 'critical' ? 'error' : 'primary'"
                  size="x-small"
                >
                  <div class="d-flex justify-space-between gap-2 flex-wrap">
                    <span class="text-body-2 font-weight-medium">{{ e.action }}</span>
                    <span class="text-caption text-medium-emphasis">{{ new Date(e.created_at).toLocaleString('fr-FR') }}</span>
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    {{ e.target_type }} · {{ e.actor_type }}
                  </div>
                </VTimelineItem>
              </VTimeline>
              <div
                v-else
                class="text-body-2 text-medium-emphasis py-8 text-center"
              >
                {{ t('people.noHistory') }}
              </div>
            </VWindowItem>
          </VWindow>
        </VCardText>
      </VCard>

      <!-- Dialogues RÉUTILISÉS (Workforce) -->
      <EmployeeAccessDialog
        v-if="store.employeeId"
        v-model="accessDialogOpen"
        :employee-id="store.employeeId"
        :employee-email="store.identity.contact_email || store.identity.login_email || ''"
        @granted="reload"
      />
      <GenerateContractDialog
        v-if="store.employeeId && store.can.generate_contract"
        v-model="contractDialogOpen"
        :employee-id="store.employeeId"
        @generated="reload"
      />
      <PersonHrLinkDialog
        v-model="hrLinkOpen"
        :mode="hrLinkMode"
        @done="onHrLinked"
      />
      <CreateDocumentTypeDrawer v-model:is-drawer-open="docTypeDrawerOpen" />
    </template>
  </div>
</template>
