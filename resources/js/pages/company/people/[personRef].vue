<script setup>
// ADR-753 (Phase 1) + ADR-768 (M7 LOT 2) — fiche Collaborateur UNIFIÉE et OPÉRATIONNELLE (Core).
// Un seul parcours pour gérer une personne : identité, accès & rôle, emploi, contrat, planning,
// temps, congés, documents, bulletins, historique. Aucun moteur reconstruit : chaque section
// réutilise le composant et l'endpoint sécurisé existants (Members / Workforce / DMS). Les
// sections et actions visibles viennent du serveur (`available_sections`, `can.*`).
import { useRoute, useRouter } from 'vue-router'
import AppSectionNavigation from '@/core/components/AppSectionNavigation.vue'
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
import EmployeeFields from '@/pages/company/workforce/employees/_EmployeeFields.vue'
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
import DocumentDepositDialog from '@/company/views/DocumentDepositDialog.vue'
import SignatureWizard from '@/pages/company/workforce/signature/_SignatureWizard.vue'
import { $api } from '@/utils/api'

// ADR-767 : même règle que l'API (`members.view` OU `workforce.view`).
definePage({ meta: { module: 'core.members', anyPermission: ['members.view', 'workforce.view'] } })

const { t, te } = useI18n()
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

// ── Rubriques (ADR-777 / UX2) : le serveur décide toujours des SECTIONS visibles (`available_sections`,
// une par facette de droits) ; l'interface les range en CINQ rubriques compréhensibles. Une rubrique
// n'existe que si l'une de ses sections est autorisée ; chaque section garde SA garde — partager une
// rubrique ne fusionne aucune permission. « employment » se décline en Emploi + Contrat.
const SECTION_ORDER = ['overview', 'personal', 'access', 'employment', 'contract', 'planning', 'time', 'leave', 'documents', 'payslips', 'history']
const sectionKeys = computed(() => {
  const set = new Set(store.sections || [])
  if (set.has('employment')) set.add('contract')

  return SECTION_ORDER.filter(k => set.has(k))
})
const has = key => sectionKeys.value.includes(key)
const GROUPS = [
  { key: 'overview', icon: 'tabler-layout-dashboard', sections: ['overview', 'history'] },
  { key: 'profile', icon: 'tabler-user', sections: ['personal', 'employment'] },
  { key: 'documents', icon: 'tabler-file-certificate', sections: ['contract', 'documents', 'payslips'] },
  { key: 'time', icon: 'tabler-calendar-time', sections: ['planning', 'time', 'leave'] },
  { key: 'access', icon: 'tabler-key', sections: ['access'] },
]
const groups = computed(() => GROUPS
  .map(g => ({ ...g, sections: g.sections.filter(has) }))
  .filter(g => g.sections.length))
const tabKeys = computed(() => groups.value.map(g => g.key))
const tabSections = computed(() => groups.value.map(g => ({ value: g.key, label: t(`people.groups.${g.key}`), icon: g.icon })))
const currentGroup = computed(() => groups.value.find(g => g.key === currentTab.value) || null)
const titled = computed(() => (currentGroup.value?.sections.length || 0) > 1)

// Ouvre une rubrique, éventuellement sur une de ses sections (anciens liens `?tab=contract`, actions).
const openSection = (key, section = null) => {
  const group = groups.value.find(g => g.key === key) || groups.value.find(g => g.sections.includes(key))
  if (!group) return false
  const target = section || (group.key === key ? null : key)

  currentTab.value = group.key
  if (target && group.sections.includes(target)) {
    nextTick(() => setTimeout(() => document.getElementById(`person-section-${target}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150))
  }

  return true
}

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
const editPerson = async () => {
  if (store.can.edit_hr && store.employeeId) {
    openSection('profile', 'employment')

    // Clic avant la fin du chargement de la fiche RH : on l'attend plutôt que d'ouvrir autre chose.
    if (!employee.value) await loadEmployee()

    // La section se monte à l'ouverture de la rubrique : le signal d'édition part une fois montée.
    await nextTick()
    await nextTick()
    editSignal.value++
  }
  else openSection('profile', 'personal')
}

// ── Actions (ADR-777) : UNE action principale libellée + un menu « Actions » lisible. Chaque entrée
// vient d'une capacité calculée par le serveur (`can.*`) ; l'endpoint la revérifie de toute façon.
const accessIntent = ref(null)
const terminateOpen = ref(false)
const terminateForm = ref({ reason: '', termination_date: '' })
const terminating = ref(false)
const isActiveEmployee = computed(() => !!employee.value && !['terminated'].includes(employee.value.status))
const canDocuments = computed(() => has('documents') && can('documents.view'))

const primaryAction = computed(() => ((store.can.edit_hr && store.employeeId) || store.can.edit_identity
  ? { id: 'edit', label: t('common.edit'), icon: 'tabler-edit', handler: editPerson } : null))

// ── Actions DIRECTES (ADR-778 / UX3) : l'action ouvre son formulaire, la personne déjà renseignée. ──
const depositOpen = ref(false)
const signOpen = ref(false)
const signDocumentId = ref(null)
const noDocumentToSign = ref(false)
const documentsPanel = ref(null)

const addDocument = () => { depositOpen.value = true }
const onDeposited = () => { openSection('documents', 'documents'); documentsPanel.value?.refresh?.() }

// « Demander un document » : la section s'ouvre sur le choix de la pièce (aucune autre saisie).
const requestDocument = async () => {
  openSection('documents', 'documents')
  for (let i = 0; i < 20 && !documentsPanel.value; i++) await new Promise(r => setTimeout(r, 100))
  await new Promise(r => setTimeout(r, 400))
  if (documentsPanel.value?.openRequest?.() === false) toast(t('people.actions.nothingToRequest'), 'info')
}

// « Faire signer » : le document le plus récent de la personne qui n'est ni signé ni en cours de
// signature est présélectionné ; l'assistant garde sa confirmation. Sans document : on guide.
const sign = async () => {
  let candidate = null
  try {
    const docs = (await $api(`/workforce/documents/by-employee/${store.employeeId}`)).documents || []

    candidate = docs.find(d => !['signed', 'pending', 'in_progress'].includes(d.signature_status)) || null
  }
  catch { candidate = null }
  if (!candidate) { noDocumentToSign.value = true

    return }
  signDocumentId.value = candidate.id
  signOpen.value = true
}

const canSign = computed(() => !!store.employeeId && store.can.view_signatures && can('workforce_signature.request') && can('workforce_documents.view'))

const menuActions = computed(() => [
  canDocuments.value && can('documents.manage')
    ? { id: 'add-document', label: t('people.actions.addDocument'), icon: 'tabler-file-upload', handler: addDocument } : null,
  canDocuments.value && can('documents.manage')
    ? { id: 'request-document', label: t('people.actions.requestDocument'), icon: 'tabler-file-import', handler: requestDocument } : null,
  store.can.generate_contract
    ? { id: 'generate-contract', label: t('generateContract.action'), icon: 'tabler-file-text', handler: () => { contractDialogOpen.value = true } } : null,
  canSign.value
    ? { id: 'sign', label: t('people.actions.sign'), icon: 'tabler-signature', handler: sign } : null,
  store.can.manage_access && store.employeeId
    ? { id: 'manage-access', label: t('people.manageAccess'), icon: 'tabler-key', handler: () => { accessDialogOpen.value = true } } : null,
  store.can.create_hr_record
    ? { id: 'create-hr', label: t('people.hrLink.createTitle'), icon: 'tabler-id-badge-2', handler: () => openHrLink('create') } : null,
  store.can.link_account
    ? { id: 'link-account', label: t('people.hrLink.linkTitle'), icon: 'tabler-link', handler: () => openHrLink('link') } : null,
  store.can.view_history && has('history')
    ? { id: 'history', label: t('people.tabs.history'), icon: 'tabler-history', handler: () => openSection('overview', 'history') } : null,
  store.can.suspend_access && store.account.state !== 'suspended'
    ? { id: 'suspend', label: t('people.actions.suspend'), icon: 'tabler-lock', danger: true, divider: true, handler: () => { accessIntent.value = 'suspend'; openSection('access') } } : null,
  store.can.edit_hr && isActiveEmployee.value
    ? { id: 'terminate', label: t('people.actions.terminate'), icon: 'tabler-user-off', danger: true, handler: () => { terminateForm.value = { reason: '', termination_date: '' }; terminateOpen.value = true } } : null,
].filter(Boolean))

const terminate = async () => {
  terminating.value = true
  try {
    await employeesStore.terminateEmployee(store.employeeId, { reason: terminateForm.value.reason, termination_date: terminateForm.value.termination_date || undefined })
    terminateOpen.value = false
    toast(t('people.actions.terminated'), 'success')
    await reload()
  }
  catch (e) {
    toast(Object.values(e?.data?.errors || {})[0]?.[0] || e?.data?.message || t('common.error'), 'error')
  }
  finally { terminating.value = false }
}

async function reload() {
  await store.load(personRef.value)
  if (!store.data) return

  // Référence canonique (la fiche RH prime) : l'URL suit, sans perdre l'onglet demandé.
  if (store.personRef && store.personRef !== personRef.value) {
    router.replace({ params: { personRef: store.personRef }, query: route.query })

    return
  }
  // Ancien lien (`?tab=contract`, `?tab=payslips`…) ou rubrique indisponible : rubrique équivalente, sinon la première.
  if (requestedTab.value) { openSection(requestedTab.value); requestedTab.value = null }

  // ADR-778 : lien direct vers une action (`?do=deposit|request|sign`) — fonctionne après rechargement ;
  // l'action n'est exécutée que si elle figure dans le menu (capacités serveur), puis retirée de l'adresse.
  const wanted = { deposit: 'add-document', request: 'request-document', sign: 'sign' }[route.query.do]
  if (wanted) {
    const { do: _, ...query } = route.query

    router.replace({ query })
    nextTick(() => menuActions.value.find(a => a.id === wanted)?.handler())
  }
  if (!tabKeys.value.includes(currentTab.value)) currentTab.value = tabKeys.value[0] || 'overview'
  await loadEmployee()
}

watch(personRef, reload)
// La rubrique demandée par l'adresse n'est appliquée qu'une fois les sections connues : `currentTab`
// ne porte jamais une valeur absente de la navigation (elle la réinitialisait, et l'adresse avec).
const requestedTab = ref(null)
onMounted(async () => {
  if (typeof route.query.tab === 'string') requestedTab.value = route.query.tab
  await reload()
})
onBeforeUnmount(() => employeesStore.clearCurrentEmployee())

// Deep-link : l'onglet courant vit dans l'URL (retour navigateur et partage de lien).
watch(currentTab, tab => {
  // Un ancien lien (`?tab=contract`, `?tab=personal`…) reste tel quel tant qu'il désigne une section
  // de la rubrique affichée : les liens partagés et les favoris ne changent pas d'adresse.
  if (groups.value.find(g => g.key === tab)?.sections.includes(route.query.tab)) return
  const q = { ...route.query }
  if (tab === 'overview') delete q.tab
  else q.tab = tab
  if (q.tab !== route.query.tab) router.replace({ query: q })
})
// Un lien interne vers la même fiche avec un autre `?tab=` change bien de rubrique (ADR-774, constat).
watch(() => route.query.tab, tab => {
  if (typeof tab === 'string' && tab !== currentTab.value && store.data) openSection(tab)
})
// Historique en libellés métier (ADR-778) ; un code inconnu reste lisible (« shift.updated » → « Shift updated »).
const historyLabel = action => {
  const key = `people.history.${String(action).replace(/\./g, '__')}`
  if (te(key)) return t(key)
  const words = String(action).replace(/[._]/g, ' ')

  return words.charAt(0).toUpperCase() + words.slice(1)
}
const historyOpened = ref([])
watch(historyOpened, v => { if (v.length && !store.history.length) store.loadHistory(personRef.value) })

const go = target => {
  if (target === 'create-hr') openHrLink('create')
  else openSection(target)
}
const goBack = () => (window.history.state?.back ? router.back() : router.push({ name: 'company-people' }))
const onRemoved = () => router.push({ name: 'company-people' })
const onHrLinked = newRef => { if (newRef) router.replace({ params: { personRef: newRef } }); else reload() }
const initials = computed(() => `${(store.identity.first_name || '?').charAt(0)}${(store.identity.last_name || '').charAt(0)}`.toUpperCase())

// ADR-818 (chantier C) — « Demander les pièces manquantes » : demandes créées par une action explicite.
const requestingMissing = ref(false)
const requestMissing = async () => {
  requestingMissing.value = true
  try {
    const res = await $api(`/company/people/${store.personRef}/documents/request-missing`, { method: 'POST' })

    toast(res.requested?.length ? t('people.requestMissingDone', { n: res.requested.length }) : t('people.requestMissingNone'), 'success')
    documentsPanel.value?.refresh?.()
  }
  catch (e) { toast(e?.data?.message || t('common.error'), 'error') }
  finally { requestingMissing.value = false }
}
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
      <!-- ADR-812 (ADR-811 F1) : 403/404 = hors périmètre ou inexistante ; « Réessayer » n'y changerait rien. -->
      <ErrorState
        v-if="[403, 404].includes(store.errorStatus)"
        icon="tabler-user-off"
        color="warning"
        :title="t('people.notAvailableTitle')"
        :message="t('people.notAvailableMessage')"
        :show-retry="false"
        data-test="person-not-available"
      />
      <ErrorState
        v-else
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
            <div
              v-if="primaryAction || menuActions.length"
              class="d-flex align-center gap-2 flex-wrap"
              data-test="person-actions"
            >
              <VBtn
                v-if="primaryAction"
                color="primary"
                :prepend-icon="primaryAction.icon"
                data-test="person-edit"
                @click="primaryAction.handler"
              >
                {{ primaryAction.label }}
              </VBtn>
              <VBtn
                v-if="menuActions.length"
                variant="tonal"
                append-icon="tabler-chevron-down"
                data-test="person-actions-menu"
              >
                {{ t('people.actions.menu') }}
                <VMenu
                  activator="parent"
                  location="bottom end"
                >
                  <VList
                    density="comfortable"
                    min-width="240"
                  >
                    <template
                      v-for="action in menuActions"
                      :key="action.id"
                    >
                      <VDivider
                        v-if="action.divider"
                        class="my-1"
                      />
                      <VListItem
                        :prepend-icon="action.icon"
                        :base-color="action.danger ? 'error' : undefined"
                        :data-test="`person-action-${action.id}`"
                        @click="action.handler"
                      >
                        <VListItemTitle>{{ action.label }}</VListItemTitle>
                      </VListItem>
                    </template>
                  </VList>
                </VMenu>
              </VBtn>
            </div>
          </div>
        </VCardText>
      </VCard>

      <!-- ═══ NAVIGATION : cinq rubriques au plus (onglets desktop / barre mobile) ═══ -->
      <AppSectionNavigation
        v-model="currentTab"
        :sections="tabSections"
        class="mb-2 mb-md-5"
      />
      <div
        v-if="currentGroup"
        class="d-md-none text-subtitle-1 font-weight-medium mb-3"
        data-test="person-group-label"
      >
        {{ t(`people.groups.${currentGroup.key}`) }}
      </div>
      <VCard>
        <VCardText>
          <VWindow
            v-model="currentTab"
            class="disable-tab-transition"
          >
            <!-- ── Vue d'ensemble (+ historique, à la demande) ── -->
            <VWindowItem value="overview">
              <PersonOverview
                :account-meta="accountMeta"
                @go="go"
                @generate-contract="contractDialogOpen = true"
                @manage-access="accessDialogOpen = true"
              />
              <VExpansionPanels
                v-if="has('history')"
                id="person-section-history"
                v-model="historyOpened"
                multiple
                class="mt-6"
                data-test="person-history"
              >
                <VExpansionPanel
                  value="history"
                  :title="t('people.tabs.history')"
                >
                  <VExpansionPanelText>
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
                          <span class="text-body-2 font-weight-medium">{{ historyLabel(e.action) }}</span>
                          <span class="text-caption text-medium-emphasis">{{ new Date(e.created_at).toLocaleString('fr-FR') }}</span>
                        </div>
                        <div class="text-caption text-medium-emphasis">
                          {{ te(`people.historyBy.${e.actor_type}`) ? t(`people.historyBy.${e.actor_type}`) : e.actor_type }}
                        </div>
                      </VTimelineItem>
                    </VTimeline>
                    <div
                      v-else
                      class="text-body-2 text-medium-emphasis py-4 text-center"
                    >
                      {{ t('people.noHistory') }}
                    </div>
                  </VExpansionPanelText>
                </VExpansionPanel>
              </VExpansionPanels>
            </VWindowItem>

            <!-- ── Informations & emploi ── -->
            <VWindowItem value="profile">
              <template v-if="currentTab === 'profile'">
                <section
                  v-if="has('personal')"
                  data-test="person-section-personal"
                >
              <h6
                v-if="titled"
                :id="`person-section-personal`"
                class="text-h6 mb-4 person-section-title"
              >
                {{ t('people.tabs.personal') }}
              </h6>
                  <PersonIdentity
                    :membership-id="store.membershipId"
                    :identity="store.identity"
                    :employee-number="store.employment?.identity?.employee_number || null"
                    :can-view-access="!!store.can.view_access"
                    :can-edit="!!store.can.edit_identity"
                    :lock-name="!store.can.edit_global_name"
                    :shared="!!store.data.identity_shared"
                    @updated="reload"
                  />
                </section>
                <VDivider
                  v-if="has('personal') && has('employment')"
                  class="my-6"
                />
                <!-- Emploi : informations RH (département, poste, responsable, matricule, dates, statut) -->
                <section
                  v-if="has('employment')"
                  data-test="person-section-employment"
                >
              <h6
                v-if="titled"
                :id="`person-section-employment`"
                class="text-h6 mb-4 person-section-title"
              >
                {{ t('people.tabs.employment') }}
              </h6>
                  <EmployeeProfile
                    v-if="employee"
                    :employee="employee"
                    :with-identity="!(store.membershipId && store.can.view_access)"
                    :edit-signal="editSignal"
                    @updated="reload().then(() => toast(t('employees.updated'), 'success'))"
                  />
                  <VSkeletonLoader
                    v-else
                    type="article"
                  />
                  <!-- ADR-795 : informations complémentaires, avec ou sans compte -->
                  <EmployeeFields
                    v-if="store.employeeId"
                    :employee-id="store.employeeId"
                    :can-edit="!!store.can.edit_hr"
                  />
                </section>
              </template>
            </VWindowItem>

            <!-- ── Documents & contrats (contrat · pièces, documents générés, signatures · bulletins) ── -->
            <VWindowItem value="documents">
              <template v-if="currentTab === 'documents'">
                <!-- Contrat : consultation, création, activation, rémunération (permissions séparées) -->
                <section
                  v-if="has('contract')"
                  data-test="person-section-contract"
                >
              <h6
                v-if="titled"
                :id="`person-section-contract`"
                class="text-h6 mb-4 person-section-title"
              >
                {{ t('people.tabs.contract') }}
              </h6>
                  <EmployeeContract
                    v-if="employee"
                    :employee="employee"
                    @updated="reload"
                  />
                  <VSkeletonLoader
                    v-else
                    type="article"
                  />
                </section>
                <VDivider
                  v-if="has('contract') && has('documents')"
                  class="my-6"
                />
                <!-- Documents : coffre DMS de la personne + documents RH générés + signatures -->
                <section
                  v-if="has('documents')"
                  data-test="person-section-documents"
                >
              <h6
                v-if="titled"
                :id="`person-section-documents`"
                class="text-h6 mb-4 person-section-title"
              >
                {{ t('people.tabs.documents') }}
              </h6>
                  <!-- ADR-810.1 : deux catégories distinctes, chacune titrée (jamais deux « Aucun document » anonymes). -->
                  <div
                    v-if="can('documents.view') && store.personRef"
                    class="mb-3"
                    data-test="person-documents-requested-title"
                  >
                    <div class="text-subtitle-1 font-weight-medium">
                      {{ t('people.documentsRequestedTitle') }}
                    </div>
                    <div class="text-body-2 text-medium-emphasis">
                      {{ t('people.documentsRequestedHint') }}
                    </div>
                    <!-- ADR-818 : création EXPLICITE des demandes des pièces exigées (jamais lors d'une lecture) -->
                    <VBtn
                      v-if="can('documents.manage')"
                      size="small"
                      variant="tonal"
                      class="mt-2"
                      prepend-icon="tabler-mail-question"
                      :loading="requestingMissing"
                      data-test="person-request-missing"
                      @click="requestMissing"
                    >
                      {{ t('people.requestMissing') }}
                    </VBtn>
                  </div>
                  <MemberDocumentsWorkflowPanel
                    v-if="can('documents.view') && store.personRef"
                    ref="documentsPanel"
                    :resource-base="`/company/people/${store.personRef}`"
                    :supports-requests="!!store.userId"
                    :can-edit="can('documents.manage')"
                    @create-custom-type="docTypeDrawerOpen = true"
                  />
                  <template v-if="store.employeeId && store.employment?.documents && can('workforce_documents.view')">
                    <div
                      class="mt-6 mb-3"
                      data-test="person-documents-generated-title"
                    >
                      <div class="text-subtitle-1 font-weight-medium">
                        {{ t('people.documentsGeneratedTitle') }}
                      </div>
                      <div class="text-body-2 text-medium-emphasis">
                        {{ t('people.documentsGeneratedHint') }}
                      </div>
                    </div>
                    <EmployeeDocumentsSection :employee-id="store.employeeId" />
                  </template>
                  <EmployeeSignaturesSection
                    v-if="store.employeeId && store.can.view_signatures"
                    :employee-id="store.employeeId"
                    class="mt-6"
                  />
                </section>
                <!-- Bulletins : autorité paie uniquement (section servie par le serveur à ce seul titre) -->
                <VDivider
                  v-if="has('payslips') && (has('contract') || has('documents'))"
                  class="my-6"
                />
                <section
                  v-if="has('payslips')"
                  data-test="person-section-payslips"
                >
              <h6
                v-if="titled"
                :id="`person-section-payslips`"
                class="text-h6 mb-4 person-section-title"
              >
                {{ t('people.tabs.payslips') }}
              </h6>
                  <VLazy
                    :min-height="80"
                    transition="none"
                  >
                    <PersonPayslips
                      v-if="store.personRef"
                      :person-ref="store.personRef"
                    />
                  </VLazy>
                </section>
              </template>
            </VWindowItem>

            <!-- ── Temps & absences (planning · pointage · congés), chargés à l'affichage ── -->
            <VWindowItem value="time">
              <template v-if="currentTab === 'time' && store.employeeId">
                <section
                  v-if="has('planning')"
                  data-test="person-section-planning"
                >
              <h6
                v-if="titled"
                :id="`person-section-planning`"
                class="text-h6 mb-4 person-section-title"
              >
                {{ t('people.tabs.planning') }}
              </h6>
                  <EmployeePlanningSection
                    :employee-id="store.employeeId"
                    :can-manage="can('workforce.planning_manage')"
                  />
                </section>
                <VDivider
                  v-if="has('planning') && has('time')"
                  class="my-6"
                />
                <section
                  v-if="has('time')"
                  data-test="person-section-time"
                >
              <h6
                v-if="titled"
                :id="`person-section-time`"
                class="text-h6 mb-4 person-section-title"
              >
                {{ t('people.tabs.time') }}
              </h6>
                  <VLazy
                    :min-height="80"
                    transition="none"
                  >
                    <EmployeeTimeSection :employee-id="store.employeeId" />
                  </VLazy>
                </section>
                <VDivider
                  v-if="has('leave') && (has('planning') || has('time'))"
                  class="my-6"
                />
                <section
                  v-if="has('leave')"
                  data-test="person-section-leave"
                >
              <h6
                v-if="titled"
                :id="`person-section-leave`"
                class="text-h6 mb-4 person-section-title"
              >
                {{ t('people.tabs.leave') }}
              </h6>
                  <VLazy
                    :min-height="80"
                    transition="none"
                  >
                    <EmployeeLeaveSection :employee-id="store.employeeId" />
                  </VLazy>
                </section>
              </template>
            </VWindowItem>

            <!-- ── Accès & autorisations ── -->
            <VWindowItem value="access">
              <PersonAccess
                v-if="currentTab === 'access'"
                :account-meta="accountMeta"
                :intent="accessIntent"
                @changed="reload"
                @removed="onRemoved"
                @grant="accessDialogOpen = true"
                @intent-handled="accessIntent = null"
              />
            </VWindowItem>
          </VWindow>
        </VCardText>
      </VCard>

      <!-- Fin de collaboration (endpoint Workforce existant, `workforce.manage`, motif obligatoire) -->
      <VDialog
        v-model="terminateOpen"
        max-width="480"
      >
        <VCard :title="t('people.actions.terminateTitle')">
          <VCardText>
            <p class="text-body-2 text-medium-emphasis">
              {{ t('people.actions.terminateHint') }}
            </p>
            <AppTextarea
              v-model="terminateForm.reason"
              :label="t('people.actions.terminateReason') + ' *'"
              rows="2"
              class="mb-4"
              data-test="person-terminate-reason"
            />
            <AppTextField
              v-model="terminateForm.termination_date"
              type="date"
              :label="t('people.actions.terminateDate')"
            />
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn
              variant="text"
              @click="terminateOpen = false"
            >
              {{ t('common.cancel') }}
            </VBtn>
            <VBtn
              color="error"
              :disabled="!terminateForm.reason.trim()"
              :loading="terminating"
              data-test="person-terminate-confirm"
              @click="terminate"
            >
              {{ t('people.actions.terminate') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

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
      <DocumentDepositDialog
        v-model="depositOpen"
        :subject="store.personRef"
        :subject-name="store.identity.display_name"
        @deposited="onDeposited"
      />
      <SignatureWizard
        v-if="canSign"
        v-model:is-dialog-visible="signOpen"
        :preselected-document-id="signDocumentId"
        @created="openSection('documents', 'documents')"
      />
      <!-- Rien à faire signer : on guide vers la génération, on ne lance rien. -->
      <VDialog
        v-model="noDocumentToSign"
        max-width="440"
      >
        <VCard
          :title="t('people.actions.noDocumentToSignTitle')"
          data-test="person-sign-guide"
        >
          <VCardText class="text-body-1">
            {{ t('people.actions.noDocumentToSign') }}
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn
              variant="text"
              @click="noDocumentToSign = false"
            >
              {{ t('common.close') }}
            </VBtn>
            <VBtn
              v-if="store.can.generate_contract"
              color="primary"
              data-test="person-sign-guide-generate"
              @click="noDocumentToSign = false; contractDialogOpen = true"
            >
              {{ t('generateContract.action') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>
    </template>
  </div>
</template>
