<script setup>
// ADR-753 (Phase 1) — fiche Collaborateur UNIFIÉE (Core). Un seul parcours pour gérer une
// personne : identité (User), accès & rôle (Membership/CompanyRole) et, si le module est actif
// ET qu'une fiche RH existe, les onglets Workforce RÉUTILISÉS (planning/temps/congés/documents).
// Aucune duplication : les sections Workforce sont les composants existants, auto-chargés.
import { useRoute, useRouter } from 'vue-router'
import { usePersonStore } from '@/modules/company/people/person.store'
import { useCan } from '@/composables/useCan'
import EmployeePlanningSection from '@/pages/company/workforce/employees/_EmployeePlanningSection.vue'
import EmployeeTimeSection from '@/pages/company/workforce/employees/_EmployeeTimeSection.vue'
import EmployeeLeaveSection from '@/pages/company/workforce/employees/_EmployeeLeaveSection.vue'
import EmployeeDocumentsSection from '@/pages/company/workforce/employees/_EmployeeDocumentsSection.vue'
import EmployeeAccessDialog from '@/pages/company/workforce/employees/_EmployeeAccessDialog.vue'

definePage({ meta: { module: 'core.members', surface: 'structure', permission: 'members.view' } })

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = usePersonStore()
const { can } = useCan()

const personRef = computed(() => String(route.params.personRef || ''))
const currentTab = ref('overview')
const accessDialogOpen = ref(false)

const TAB_META = {
  overview: { icon: 'tabler-layout-dashboard' },
  personal: { icon: 'tabler-user' },
  access: { icon: 'tabler-key' },
  employment: { icon: 'tabler-file-text' },
  planning: { icon: 'tabler-calendar' },
  time: { icon: 'tabler-clock' },
  leave: { icon: 'tabler-beach' },
  documents: { icon: 'tabler-file-certificate' },
  history: { icon: 'tabler-history' },
}
const tabs = computed(() => (store.sections || []).filter(s => TAB_META[s]).map(s => ({ value: s, icon: TAB_META[s].icon, label: t(`people.tabs.${s}`) })))

const accountMeta = computed(() => ({
  connected: { color: 'success', label: t('people.account.connected') },
  active: { color: 'success', label: t('people.account.active') },
  invited: { color: 'warning', label: t('people.account.invited') },
  suspended: { color: 'error', label: t('people.account.suspended') },
  none: { color: 'secondary', label: t('people.account.none') },
}[store.account.state] || { color: 'secondary', label: store.account.state }))

const money = v => v == null ? '—' : (v / 100).toLocaleString('fr-FR', { style: 'currency', currency: store.employment?.contract?.compensation?.currency || 'EUR', maximumFractionDigits: 0 })

async function reload() {
  await store.load(personRef.value)
  if (store.sections.includes('history')) store.loadHistory(personRef.value)
  if (!store.sections.includes(currentTab.value)) currentTab.value = 'overview'
}
watch(personRef, reload)
onMounted(reload)
watch(currentTab, tab => { if (tab === 'history') store.loadHistory(personRef.value) })

function onAccessChanged() { reload() }
</script>

<template>
  <div>
    <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-3" @click="router.back()">
      {{ t('common.back') }}
    </VBtn>

    <VProgressLinear v-if="store.loading" indeterminate color="primary" class="mb-4" />
    <VAlert v-else-if="store.error" type="error" variant="tonal" class="mb-4">{{ t('people.loadError') }}</VAlert>

    <template v-else-if="store.data">
      <!-- ═══ EN-TÊTE ═══ -->
      <VCard class="mb-4" flat border>
        <VCardText class="d-flex flex-wrap align-center gap-4">
          <VAvatar size="64" color="primary" variant="tonal">
            <VImg v-if="store.identity.avatar" :src="store.identity.avatar" />
            <span v-else class="text-h5">{{ (store.identity.first_name || '?').charAt(0) }}{{ (store.identity.last_name || '').charAt(0) }}</span>
          </VAvatar>
          <div class="flex-grow-1" style="min-inline-size: 0">
            <div class="d-flex align-center gap-2 flex-wrap">
              <h4 class="text-h4 mb-0 text-truncate">{{ store.identity.display_name }}</h4>
              <VChip :color="accountMeta.color" size="small" variant="tonal" label>{{ accountMeta.label }}</VChip>
              <VChip v-if="store.account.is_owner" color="primary" size="small" variant="tonal" label>{{ t('people.owner') }}</VChip>
              <VChip v-else-if="store.account.role" :color="store.account.role.is_administrative ? 'warning' : 'secondary'" size="small" variant="tonal" label>{{ store.account.role.name }}</VChip>
            </div>
            <div class="text-body-2 text-medium-emphasis mt-1 d-flex flex-wrap gap-x-4 gap-y-1">
              <span v-if="store.identity.login_email"><VIcon icon="tabler-mail" size="14" /> {{ store.identity.login_email }}</span>
              <span v-if="store.employment?.position?.job_role"><VIcon icon="tabler-briefcase" size="14" /> {{ store.employment.position.job_role }}</span>
              <span v-if="store.employment?.position?.department"><VIcon icon="tabler-building" size="14" /> {{ store.employment.position.department }}</span>
              <span v-if="store.employment?.identity?.status"><VIcon icon="tabler-id-badge" size="14" /> {{ t(`people.empStatus.${store.employment.identity.status}`, store.employment.identity.status) }}</span>
            </div>
          </div>
          <div class="d-flex gap-2">
            <VBtn v-if="store.can.manage_access && store.employeeId" color="primary" variant="tonal" prepend-icon="tabler-key" @click="accessDialogOpen = true">
              {{ t('people.manageAccess') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>

      <!-- ═══ ONGLETS ═══ -->
      <VCard flat border>
        <VTabs v-model="currentTab" show-arrows class="border-b">
          <VTab v-for="tb in tabs" :key="tb.value" :value="tb.value">
            <VIcon :icon="tb.icon" start size="18" />{{ tb.label }}
          </VTab>
        </VTabs>

        <VCardText>
          <VWindow v-model="currentTab" class="disable-tab-transition">
            <!-- Vue d'ensemble -->
            <VWindowItem value="overview">
              <VRow class="card-grid card-grid-sm">
                <VCol cols="12" md="6">
                  <VCard variant="tonal" color="primary">
                    <VCardText>
                      <div class="text-overline mb-2">{{ t('people.tabs.access') }}</div>
                      <div class="d-flex align-baseline gap-2"><span class="text-h6">{{ accountMeta.label }}</span></div>
                      <div v-if="store.account.role" class="text-body-2 text-medium-emphasis">{{ store.account.role.name }}</div>
                    </VCardText>
                  </VCard>
                </VCol>
                <VCol v-if="store.employment" cols="12" md="6">
                  <VCard variant="tonal">
                    <VCardText>
                      <div class="text-overline mb-2">{{ t('people.tabs.employment') }}</div>
                      <div class="text-body-2">{{ store.employment.contract?.contract_type || t('people.noContract') }}</div>
                      <div v-if="store.can.view_compensation && store.employment.contract?.compensation" class="text-body-2 text-medium-emphasis">{{ money(store.employment.contract.compensation.base_salary_cents) }}</div>
                    </VCardText>
                  </VCard>
                </VCol>
              </VRow>
            </VWindowItem>

            <!-- Informations personnelles -->
            <VWindowItem value="personal">
              <VList lines="two" class="py-0">
                <VListItem :title="store.identity.display_name" :subtitle="t('people.fields.name')" prepend-icon="tabler-user" />
                <VListItem v-if="store.identity.login_email" :title="store.identity.login_email" :subtitle="t('people.fields.loginEmail')" prepend-icon="tabler-mail" />
                <VListItem v-if="store.identity.contact_email && store.identity.contact_email !== store.identity.login_email" :title="store.identity.contact_email" :subtitle="t('people.fields.hrEmail')" prepend-icon="tabler-at" />
                <VListItem v-if="store.identity.phone" :title="store.identity.phone" :subtitle="t('people.fields.phone')" prepend-icon="tabler-phone" />
                <VListItem v-if="store.employment?.identity?.employee_number" :title="store.employment.identity.employee_number" :subtitle="t('people.fields.employeeNumber')" prepend-icon="tabler-id" />
              </VList>
            </VWindowItem>

            <!-- Accès & autorisations -->
            <VWindowItem value="access">
              <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
                <div>
                  <div class="text-body-1 font-weight-medium">{{ t('people.access.title') }}</div>
                  <div class="text-body-2 text-medium-emphasis">{{ accountMeta.label }}<span v-if="store.account.role"> · {{ store.account.role.name }}</span></div>
                </div>
                <VBtn v-if="store.can.manage_access && store.employeeId" color="primary" variant="tonal" size="small" prepend-icon="tabler-key" @click="accessDialogOpen = true">{{ t('people.manageAccess') }}</VBtn>
              </div>
              <VDivider class="mb-3" />
              <div class="text-overline mb-2">{{ t('people.access.effectivePermissions') }}</div>
              <div v-if="(store.account.effective_permissions || []).length" class="d-flex flex-wrap gap-1">
                <VChip v-for="p in store.account.effective_permissions" :key="p" size="x-small" label variant="outlined">{{ p }}</VChip>
              </div>
              <div v-else class="text-body-2 text-medium-emphasis">{{ t('people.access.noPermissions') }}</div>
            </VWindowItem>

            <!-- Emploi & contrat -->
            <VWindowItem value="employment">
              <VList lines="two" class="py-0">
                <VListItem :title="store.employment?.contract?.contract_type || t('people.noContract')" :subtitle="t('people.tabs.employment')" prepend-icon="tabler-file-text" />
                <VListItem v-if="store.employment?.identity?.hire_date" :title="store.employment.identity.hire_date" :subtitle="t('people.fields.hireDate')" prepend-icon="tabler-calendar-plus" />
                <VListItem v-if="store.can.view_compensation && store.employment?.contract?.compensation" :title="money(store.employment.contract.compensation.base_salary_cents)" :subtitle="t('people.fields.compensation')" prepend-icon="tabler-businessplan" />
                <VListItem v-if="store.employment?.position?.manager" :title="store.employment.position.manager" :subtitle="t('people.fields.manager')" prepend-icon="tabler-users" />
              </VList>
            </VWindowItem>

            <!-- Onglets Workforce RÉUTILISÉS (auto-chargés) -->
            <VWindowItem value="planning"><EmployeePlanningSection v-if="currentTab === 'planning' && store.employeeId" :employee-id="store.employeeId" /></VWindowItem>
            <VWindowItem value="time"><EmployeeTimeSection v-if="currentTab === 'time' && store.employeeId" :employee-id="store.employeeId" /></VWindowItem>
            <VWindowItem value="leave"><EmployeeLeaveSection v-if="currentTab === 'leave' && store.employeeId" :employee-id="store.employeeId" /></VWindowItem>
            <VWindowItem value="documents"><EmployeeDocumentsSection v-if="currentTab === 'documents' && store.employeeId" :employee-id="store.employeeId" /></VWindowItem>

            <!-- Historique (réel) -->
            <VWindowItem value="history">
              <VProgressLinear v-if="store.historyLoading" indeterminate color="primary" />
              <VTimeline v-else-if="store.history.length" density="compact" side="end" truncate-line="both" class="mt-2">
                <VTimelineItem v-for="e in store.history" :key="e.id" :dot-color="e.severity === 'warning' ? 'warning' : e.severity === 'critical' ? 'error' : 'primary'" size="x-small">
                  <div class="d-flex justify-space-between gap-2 flex-wrap">
                    <span class="text-body-2 font-weight-medium">{{ e.action }}</span>
                    <span class="text-caption text-medium-emphasis">{{ new Date(e.created_at).toLocaleString('fr-FR') }}</span>
                  </div>
                  <div class="text-caption text-medium-emphasis">{{ e.target_type }} · {{ e.actor_type }}</div>
                </VTimelineItem>
              </VTimeline>
              <div v-else class="text-body-2 text-medium-emphasis py-4 text-center">{{ t('people.noHistory') }}</div>
            </VWindowItem>
          </VWindow>
        </VCardText>
      </VCard>

      <!-- Dialog d'accès RÉUTILISÉ (grant/suspend/revoke via EmployeeAccessService sécurisé) -->
      <EmployeeAccessDialog
        v-if="store.employeeId"
        v-model="accessDialogOpen"
        :employee-id="store.employeeId"
        :employee-email="store.identity.contact_email || store.identity.login_email || ''"
        @granted="onAccessChanged"
      />
    </template>
  </div>
</template>
