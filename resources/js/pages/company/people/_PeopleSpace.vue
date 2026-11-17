<script setup>
// ADR-754/755 (P2+P3) — « Collaborateurs » : espace CENTRAL de gestion des personnes, rôles et
// accès. Onglets selon les FACETTES : « Personnes » (liste unifiée, members.view|workforce.view)
// et « Rôles & autorisations » (définitions RBAC, roles.view — RÉUTILISE /company/roles sans
// duplication). Un utilisateur roles.view-seul voit l'onglet Rôles sans la liste des personnes.
import { useRoute, useRouter } from 'vue-router'
import { usePeopleListStore } from '@/modules/company/people/people-list.store'
import { useCan } from '@/composables/useCan'
import RolesManager from '@/pages/company/roles.vue'
import PersonAddDrawer from './_PersonAddDrawer.vue'
import PeopleHrTools from './_PeopleHrTools.vue'
import ProfileFieldsSettings from './_ProfileFieldsSettings.vue'
import MemberHealth from './_MemberHealth.vue'
import RoleCapabilities from './_RoleCapabilities.vue'
import PendingAccessReview from './_PendingAccessReview.vue'
import { useModuleStore } from '@/core/stores/module'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

// ADR-767 : plus de `surface: 'structure'` — un manager/RH NON administratif détenant la facette RH
// (`workforce.view`) doit atteindre ses collaborateurs. L'accès reste décidé par permission (facettes)
// et le périmètre de données par le serveur (WorkforceAccess).
// ADR-820 — sous-composant (sans definePage) monté par les pages /company/people, /people/hr, /people/roles :
// l'onglet est dans le CHEMIN (ADR-475), plus dans `?tab=`.
const props = defineProps({ section: { type: String, default: 'people' } })

const { t } = useI18n()
const route = useRoute()
const accessRevision = ref(0) // ADR-809 : rafraîchit les vues après confirmation groupée
const router = useRouter()
const store = usePeopleListStore()
const { can, canModule } = useCan()
const settings = useCompanySettingsStore()
const { toast } = useAppToast()

// ── ADR-777 (UX2) — « Ajouter une personne » : un seul parcours (tiroir unique). L'invitation et la
// fiche salarié restent les mêmes endpoints ; l'utilisateur ne choisit plus entre deux concepts.
const addOpen = ref(false)
const addPreset = ref(null)
const canAdd = computed(() => can('members.invite') || can('workforce.manage'))
const openAdd = (preset = null) => { addPreset.value = preset; addOpen.value = true }
const onPersonAdded = ({ personRef, tab }) => {
  if (personRef) router.push({ path: tab ? `/company/people/${personRef}/${tab}` : `/company/people/${personRef}` })
  else reload()
}

const canPeople = computed(() => can('members.view') || can('workforce.view'))
const canRoles = computed(() => can('roles.view'))
// ADR-802 — « Gestion RH » = l'ancienne liste /company/workforce/employees (même garde : module workforce +
// workforce.view). « Champs du profil » = l'ancien tiroir de /company/members (members.manage).
const moduleStore = useModuleStore()
const canHr = computed(() => moduleStore.isActive('workforce') && can('workforce.view'))
const fieldsRef = ref(null)
const rolesView = ref('capabilities') // ADR-803.1
// ADR-820 — la vue vient du chemin ; repli selon les facettes si elle n'est pas permise.
const ROUTE = { people: 'company-people', hr: 'company-people-hr', roles: 'company-people-roles' }
const initialTab = (props.section === 'roles' && canRoles.value) ? 'roles'
  : (props.section === 'hr' && canHr.value) ? 'hr'
    : (canPeople.value ? 'people' : 'roles')
const tab = ref(initialTab)
watch(tab, v => {
  // Changer de vue repart d'une URL propre (les filtres de la vue Gestion RH ne fuient pas ailleurs).
  if (route.name !== ROUTE[v]) router.replace({ name: ROUTE[v] })
})
watch(() => props.section, v => { if (v && v !== tab.value) tab.value = v })

const search = ref('')
const options = ref({ page: 1, itemsPerPage: 20, sortBy: [] })
const filters = ref({ access_state: null, employment_status: null })

const ACCOUNT_META = {
  connected: { color: 'success', label: 'people.account.connected' },
  active: { color: 'success', label: 'people.account.active' },
  invited: { color: 'warning', label: 'people.account.invited' },
  pending_acceptance: { color: 'warning', label: 'people.account.pending_acceptance' },
  invitation_expired: { color: 'secondary', label: 'people.account.invitation_expired' },
  suspended: { color: 'error', label: 'people.account.suspended' },
}
const accountStates = ['connected', 'active', 'invited', 'pending_acceptance', 'invitation_expired', 'suspended']
const employmentStates = ['active', 'preparatory', 'inactive', 'on_leave', 'suspended', 'terminated'] // ADR-796.1 : recrue en préparation

const headers = computed(() => {
  const h = [{ title: t('peopleList.person'), key: 'name', sortable: true }]
  // ADR-816 : l'annuaire (members.view) liste les personnes ; l'état des comptes n'est montré qu'à
  // l'administration des accès (sinon la colonne afficherait à tort « Aucun compte »).
  if (store.facets.access && store.facets.account_details !== false) {
    h.push({ title: t('peopleList.access'), key: 'account_state', sortable: false })
  }
  if (store.facets.hr) {
    h.push({ title: t('peopleList.employment'), key: 'employment_status', sortable: false })
    h.push({ title: t('peopleList.job'), key: 'job_role', sortable: false })
    h.push({ title: t('peopleList.department'), key: 'department', sortable: false })
    h.push({ title: t('peopleList.manager'), key: 'manager', sortable: false }) // ADR-818
    h.push({ title: t('peopleList.contract'), key: 'contract_type', sortable: false })
  }
  h.push({ title: '', key: 'actions', sortable: false, align: 'end' })

  return h
})

let debounce = null
function reload() {
  if (!canPeople.value) return
  const sb = options.value.sortBy?.[0]
  store.fetch({
    page: options.value.page, perPage: options.value.itemsPerPage,
    search: search.value, sortBy: sb?.key || 'name', sortDir: sb?.order || 'asc',
    filters: { access_state: filters.value.access_state, employment_status: filters.value.employment_status },
  })
}
watch(options, reload, { deep: true })
watch(filters, () => { options.value.page = 1; reload() }, { deep: true })
watch(search, () => { clearTimeout(debounce); debounce = setTimeout(() => { options.value.page = 1; reload() }, 350) })
onMounted(() => {
  if (canPeople.value) reload()

  // Deep-links des anciens raccourcis (tableau de bord, onboarding) : `?action=invite|create`.
  if (route.query.action === 'invite' && can('members.invite')) openAdd(true)
  if (route.query.action === 'create' && can('workforce.manage')) openAdd(false)
})

const openFiche = row => router.push(`/company/people/${row.person_ref}`)
const accountMeta = s => ACCOUNT_META[s] || { color: 'secondary', label: 'people.account.none' }
const initials = name => (name || '?').split(' ').map(p => p.charAt(0)).slice(0, 2).join('').toUpperCase()
</script>

<template>
  <div>
    <SettingsReturnLink /> <!-- ADR-781 -->
    <div class="d-flex align-center flex-wrap gap-2 mb-4">
      <div>
        <h4 class="text-h4 mb-1">{{ t('peopleList.title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ t('peopleList.subtitle') }}</p>
      </div>
      <VSpacer />
      <!-- ADR-802 : les outils ne renvoient plus vers d'autres pages — Gestion RH est un onglet d'Équipe,
           les champs du profil s'ouvrent ici. -->
      <VMenu v-if="tab === 'people' && can('members.view') && can('settings.manage')">
        <template #activator="{ props }">
          <VBtn
            v-bind="props"
            variant="tonal"
            color="secondary"
            prepend-icon="tabler-tool"
            data-test="people-tools"
          >
            {{ t('peopleList.tools') }}
          </VBtn>
        </template>
        <VList>
          <VListItem
            data-test="people-tools-fields"
            prepend-icon="tabler-forms"
            @click="fieldsRef?.open()"
            :title="t('peopleList.toolsFields')"
            :subtitle="t('peopleList.toolsFieldsHint')"
          />
        </VList>
      </VMenu>
      <VBtn
        v-if="(tab === 'people' || tab === 'hr') && canModule('workforce') && can('workforce.manage')"
        color="primary"
        prepend-icon="tabler-user-check"
        to="/company/workforce/hiring?new=1"
        data-test="people-hire"
      >
        {{ t('hiring.hire') }}
      </VBtn>
      <VBtn
        v-if="(tab === 'people' || tab === 'hr') && canAdd"
        :color="canModule('workforce') && can('workforce.manage') ? 'secondary' : 'primary'"
        :variant="canModule('workforce') && can('workforce.manage') ? 'tonal' : 'flat'"
        prepend-icon="tabler-user-plus"
        data-test="people-add"
        @click="openAdd()"
      >
        {{ t('people.add.title') }}
      </VBtn>
    </div>

    <!-- ADR-818 : les embauches vivent dans l'espace « Embauches » (une seule liste, un seul assistant) -->
    <MemberHealth v-if="tab === 'people' && can('members.view')" />
    <!-- Garde = celle de l'API (POST field-activations / field-definitions : settings.manage) -->
    <ProfileFieldsSettings
      v-if="can('members.view') && can('settings.manage')"
      ref="fieldsRef"
    />

    <VCard flat border>
      <VTabs v-model="tab" class="border-b">
        <VTab v-if="canPeople" value="people"><VIcon icon="tabler-users" start size="18" />{{ t('peopleList.tabPeople') }}</VTab>
        <VTab v-if="canHr" value="hr" data-test="people-tab-hr"><VIcon icon="tabler-table-options" start size="18" />{{ t('peopleList.tabHr') }}</VTab>
        <VTab v-if="canRoles" value="roles"><VIcon icon="tabler-shield-lock" start size="18" />{{ t('peopleList.tabRoles') }}</VTab>
      </VTabs>

      <VWindow v-model="tab" class="disable-tab-transition">
        <!-- ═══ PERSONNES ═══ -->
        <VWindowItem value="people">
          <VCardText class="d-flex flex-wrap gap-3">
            <AppTextField v-model="search" :placeholder="t('peopleList.searchPlaceholder')" prepend-inner-icon="tabler-search" density="compact" style="flex: 0 1 300px; min-inline-size: 200px" clearable />
            <AppSelect v-if="store.facets.access && store.facets.account_details !== false" v-model="filters.access_state" :items="accountStates.map(s => ({ value: s, title: t('people.account.' + s) }))" :placeholder="t('peopleList.account')" density="compact" clearable style="flex: 0 1 200px; min-inline-size: 160px" />
            <AppSelect v-if="store.facets.hr" v-model="filters.employment_status" :items="employmentStates.map(s => ({ value: s, title: t('people.empStatus.' + s, s) }))" :placeholder="t('peopleList.employment')" density="compact" clearable style="flex: 0 1 200px; min-inline-size: 160px" />
          </VCardText>
          <VDivider />
          <VDataTableServer
            v-model:options="options"
            :headers="headers"
            :items="store.rows"
            :items-length="store.total"
            :loading="store.loading"
            item-value="person_ref"
            hover
            class="text-no-wrap"
            @click:row="(_, { item }) => openFiche(item)"
          >
            <template #item.name="{ item }">
              <div class="d-flex align-center gap-3 py-2">
                <VAvatar size="38" color="primary" variant="tonal">
                  <VImg v-if="item.avatar" :src="item.avatar" />
                  <span v-else class="text-caption">{{ initials(item.name) }}</span>
                </VAvatar>
                <div>
                  <div class="font-weight-medium text-high-emphasis">{{ item.name }}</div>
                  <div v-if="item.email" class="text-caption text-medium-emphasis">{{ item.email }}</div>
                </div>
              </div>
            </template>
            <!-- Accès : un seul état (puce) + le rôle en clair — pas deux colonnes de badges. -->
            <template #item.account_state="{ item }">
              <div v-if="item.account_state" class="d-flex align-center gap-2 flex-wrap">
                <VChip :color="accountMeta(item.account_state).color" size="small" variant="tonal" label>{{ t(accountMeta(item.account_state).label) }}</VChip>
                <span class="text-body-2 text-medium-emphasis">{{ item.is_owner ? t('people.owner') : (item.role?.name || '') }}</span>
              </div>
              <span v-else class="text-disabled">{{ t('people.account.none') }}</span>
            </template>
            <template #item.employment_status="{ item }">
              <span v-if="item.employment_status" class="text-body-2">{{ t('people.empStatus.' + item.employment_status, item.employment_status) }}</span>
              <span v-else class="text-disabled">—</span>
            </template>
            <template #item.job_role="{ item }"><span :class="item.job_role ? '' : 'text-disabled'">{{ item.job_role || '—' }}</span></template>
            <template #item.department="{ item }"><span :class="item.department ? '' : 'text-disabled'">{{ item.department || '—' }}</span></template>
            <template #item.manager="{ item }"><span :class="item.manager ? '' : 'text-disabled'">{{ item.manager || '—' }}</span></template>
            <template #item.contract_type="{ item }"><span :class="item.contract_type ? '' : 'text-disabled'">{{ item.contract_type ? ($te(`employees.contractTypes.${item.contract_type}`) ? $t(`employees.contractTypes.${item.contract_type}`) : item.contract_type.toUpperCase()) : '—' }}</span></template>
            <template #item.actions="{ item }"><VBtn icon variant="text" size="small" :aria-label="t('peopleList.openFile', { name: item.name })" @click.stop="openFiche(item)"><VIcon icon="tabler-chevron-right" /></VBtn></template>
            <template #no-data>
              <div class="text-center py-8 text-medium-emphasis">
                <VIcon icon="tabler-users-group" size="40" class="text-disabled mb-2" />
                <div>{{ t('peopleList.empty') }}</div>
              </div>
            </template>
          </VDataTableServer>
        </VWindowItem>

        <!-- ═══ RÔLES & AUTORISATIONS (réutilise /company/roles) ═══ -->
        <VWindowItem value="hr">
          <VCardText>
            <PeopleHrTools v-if="tab === 'hr' && canHr" @create="openAdd(false)" />
          </VCardText>
        </VWindowItem>
        <VWindowItem value="roles">
          <!-- ADR-803.1 : vue simple « par capacité » (même moteur que l'assistant de module) ; « par rôle » = vue avancée -->
          <div
            v-if="tab === 'roles' && canRoles"
            class="pa-4 pb-0"
          >
            <!-- ADR-806 : onglets « pill » (preset DemoTabsBasicPill) — le VBtnToggle Vuexy est réservé aux icônes
                 (largeur forcée à 36 px) : les deux libellés se chevauchaient. -->
            <VTabs
              v-model="rolesView"
              class="v-tabs-pill"
              density="compact"
              data-test="roles-view"
            >
              <VTab
                value="capabilities"
                data-test="roles-view-capabilities"
              >
                {{ t('moduleSetup.byCapability') }}
              </VTab>
              <VTab
                value="roles"
                data-test="roles-view-roles"
              >
                {{ t('moduleSetup.byRole') }}
              </VTab>
            </VTabs>
          </div>
          <!-- ADR-809 : revue groupée des accès en attente (une seule décision) -->
          <div
            v-if="tab === 'roles' && canRoles"
            class="px-4 pt-4"
          >
            <PendingAccessReview @confirmed="accessRevision++" />
          </div>
          <VCardText v-if="tab === 'roles' && canRoles && rolesView === 'capabilities'">
            <RoleCapabilities :key="`caps-${accessRevision}`" />
          </VCardText>
          <RolesManager
            v-if="tab === 'roles' && canRoles && rolesView === 'roles'"
            :key="`roles-${accessRevision}`"
            embedded
          />
        </VWindowItem>
      </VWindow>
    </VCard>

    <PersonAddDrawer
      v-if="canAdd"
      v-model="addOpen"
      :preset-access="addPreset"
      @created="onPersonAdded"
    />
  </div>
</template>
