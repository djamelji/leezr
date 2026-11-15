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
import OnboardingPanel from './_OnboardingPanel.vue'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

// ADR-767 : plus de `surface: 'structure'` — un manager/RH NON administratif détenant la facette RH
// (`workforce.view`) doit atteindre ses collaborateurs. L'accès reste décidé par permission (facettes)
// et le périmètre de données par le serveur (WorkforceAccess).
definePage({ meta: { module: 'core.members', anyPermission: ['members.view', 'workforce.view', 'roles.view'] } })

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = usePeopleListStore()
const { can } = useCan()
const settings = useCompanySettingsStore()
const { toast } = useAppToast()

// ── ADR-777 (UX2) — « Ajouter une personne » : un seul parcours (tiroir unique). L'invitation et la
// fiche salarié restent les mêmes endpoints ; l'utilisateur ne choisit plus entre deux concepts.
const addOpen = ref(false)
const addPreset = ref(null)
const canAdd = computed(() => can('members.invite') || can('workforce.manage'))
const openAdd = (preset = null) => { addPreset.value = preset; addOpen.value = true }
const onPersonAdded = ({ personRef, tab }) => {
  if (personRef) router.push({ path: `/company/people/${personRef}`, query: tab ? { tab } : {} })
  else reload()
}

const canPeople = computed(() => can('members.view') || can('workforce.view'))
const canRoles = computed(() => can('roles.view'))
// ADR-766 (M7) — deep-link `?tab=roles` (depuis l'alias /company/roles) ou repli selon les facettes.
const initialTab = (route.query.tab === 'roles' && canRoles.value) ? 'roles' : (canPeople.value ? 'people' : 'roles')
const tab = ref(initialTab)
watch(tab, v => {
  const q = { ...route.query }
  if (v === 'roles') q.tab = 'roles'
  else delete q.tab
  router.replace({ query: q })
})

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
const employmentStates = ['active', 'inactive', 'on_leave', 'suspended', 'terminated']

const headers = computed(() => {
  const h = [{ title: t('peopleList.person'), key: 'name', sortable: true }]
  if (store.facets.access) {
    h.push({ title: t('peopleList.access'), key: 'account_state', sortable: false })
  }
  if (store.facets.hr) {
    h.push({ title: t('peopleList.employment'), key: 'employment_status', sortable: false })
    h.push({ title: t('peopleList.job'), key: 'job_role', sortable: false })
    h.push({ title: t('peopleList.department'), key: 'department', sortable: false })
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
      <!-- Outils des listes historiques dont la parité n'est pas acquise (import / export / actions
           groupées, réglage des champs personnalisés) : accessibles, jamais masqués. -->
      <VMenu v-if="tab === 'people' && (can('workforce.import') || can('workforce.export') || can('workforce.manage') || can('members.manage'))">
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
            v-if="can('workforce.view') && (can('workforce.import') || can('workforce.export') || can('workforce.manage'))"
            :to="{ name: 'company-workforce-employees' }"
            prepend-icon="tabler-table-import"
            :title="t('peopleList.toolsHr')"
            :subtitle="t('peopleList.toolsHrHint')"
          />
          <VListItem
            v-if="can('members.view') && can('members.manage')"
            to="/company/members"
            prepend-icon="tabler-forms"
            :title="t('peopleList.toolsFields')"
            :subtitle="t('peopleList.toolsFieldsHint')"
          />
        </VList>
      </VMenu>
      <VBtn
        v-if="tab === 'people' && canAdd"
        color="primary"
        prepend-icon="tabler-user-plus"
        data-test="people-add"
        @click="openAdd()"
      >
        {{ t('people.add.title') }}
      </VBtn>
    </div>

    <!-- ADR-796 : intégrations en cours (RH) — même page Équipe, aucun nouveau menu -->
    <OnboardingPanel v-if="tab === 'people' && can('workforce.manage')" />

    <VCard flat border>
      <VTabs v-model="tab" class="border-b">
        <VTab v-if="canPeople" value="people"><VIcon icon="tabler-users" start size="18" />{{ t('peopleList.tabPeople') }}</VTab>
        <VTab v-if="canRoles" value="roles"><VIcon icon="tabler-shield-lock" start size="18" />{{ t('peopleList.tabRoles') }}</VTab>
      </VTabs>

      <VWindow v-model="tab" class="disable-tab-transition">
        <!-- ═══ PERSONNES ═══ -->
        <VWindowItem value="people">
          <VCardText class="d-flex flex-wrap gap-3">
            <AppTextField v-model="search" :placeholder="t('peopleList.searchPlaceholder')" prepend-inner-icon="tabler-search" density="compact" style="flex: 0 1 300px; min-inline-size: 200px" clearable />
            <AppSelect v-if="store.facets.access" v-model="filters.access_state" :items="accountStates.map(s => ({ value: s, title: t('people.account.' + s) }))" :placeholder="t('peopleList.account')" density="compact" clearable style="flex: 0 1 200px; min-inline-size: 160px" />
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
        <VWindowItem value="roles">
          <RolesManager v-if="tab === 'roles' && canRoles" embedded />
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
