<script setup>
// ADR-754/755 (P2+P3) — « Collaborateurs » : espace CENTRAL de gestion des personnes, rôles et
// accès. Onglets selon les FACETTES : « Personnes » (liste unifiée, members.view|workforce.view)
// et « Rôles & autorisations » (définitions RBAC, roles.view — RÉUTILISE /company/roles sans
// duplication). Un utilisateur roles.view-seul voit l'onglet Rôles sans la liste des personnes.
import { useRouter } from 'vue-router'
import { usePeopleListStore } from '@/modules/company/people/people-list.store'
import { useCan } from '@/composables/useCan'
import RolesManager from '@/pages/company/roles.vue'

definePage({ meta: { module: 'core.members', surface: 'structure', anyPermission: ['members.view', 'workforce.view', 'roles.view'] } })

const { t } = useI18n()
const router = useRouter()
const store = usePeopleListStore()
const { can } = useCan()

const canPeople = computed(() => can('members.view') || can('workforce.view'))
const canRoles = computed(() => can('roles.view'))
const tab = ref(canPeople.value ? 'people' : 'roles')

const search = ref('')
const options = ref({ page: 1, itemsPerPage: 20, sortBy: [] })
const filters = ref({ access_state: null, employment_status: null })

const ACCOUNT_META = {
  connected: { color: 'success', label: 'people.account.connected' },
  active: { color: 'success', label: 'people.account.active' },
  invited: { color: 'warning', label: 'people.account.invited' },
  suspended: { color: 'error', label: 'people.account.suspended' },
}
const accountStates = ['connected', 'active', 'invited', 'suspended']
const employmentStates = ['active', 'inactive', 'on_leave', 'suspended', 'terminated']

const headers = computed(() => {
  const h = [{ title: t('peopleList.person'), key: 'name', sortable: true }]
  if (store.facets.access) {
    h.push({ title: t('peopleList.account'), key: 'account_state', sortable: false })
    h.push({ title: t('peopleList.role'), key: 'role', sortable: false })
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
onMounted(() => { if (canPeople.value) reload() })

const openFiche = row => router.push(`/company/people/${row.person_ref}`)
const accountMeta = s => ACCOUNT_META[s] || { color: 'secondary', label: 'people.account.none' }
const initials = name => (name || '?').split(' ').map(p => p.charAt(0)).slice(0, 2).join('').toUpperCase()
</script>

<template>
  <div>
    <div class="d-flex align-center flex-wrap gap-2 mb-4">
      <div>
        <h4 class="text-h4 mb-1">{{ t('peopleList.title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ t('peopleList.subtitle') }}</p>
      </div>
      <VSpacer />
      <VMenu v-if="tab === 'people' && (can('members.invite') || can('workforce.manage'))">
        <template #activator="{ props }">
          <VBtn v-bind="props" color="primary" prepend-icon="tabler-plus">{{ t('peopleList.new') }}</VBtn>
        </template>
        <VList>
          <VListItem v-if="can('members.invite')" to="/company/members" prepend-icon="tabler-user-plus" :title="t('peopleList.inviteUser')" :subtitle="t('peopleList.inviteUserHint')" />
          <VListItem v-if="can('workforce.manage')" to="/company/workforce/employees" prepend-icon="tabler-id-badge-2" :title="t('peopleList.createEmployee')" :subtitle="t('peopleList.createEmployeeHint')" />
        </VList>
      </VMenu>
    </div>

    <VCard flat border>
      <VTabs v-model="tab" class="border-b">
        <VTab v-if="canPeople" value="people"><VIcon icon="tabler-users" start size="18" />{{ t('peopleList.tabPeople') }}</VTab>
        <VTab v-if="canRoles" value="roles"><VIcon icon="tabler-shield-lock" start size="18" />{{ t('peopleList.tabRoles') }}</VTab>
      </VTabs>

      <VWindow v-model="tab" class="disable-tab-transition">
        <!-- ═══ PERSONNES ═══ -->
        <VWindowItem value="people">
          <VCardText class="d-flex flex-wrap gap-3">
            <AppTextField v-model="search" :placeholder="t('peopleList.searchPlaceholder')" prepend-inner-icon="tabler-search" density="compact" style="max-inline-size: 280px" clearable />
            <AppSelect v-if="store.facets.access" v-model="filters.access_state" :items="accountStates.map(s => ({ value: s, title: t('people.account.' + s) }))" :placeholder="t('peopleList.account')" density="compact" clearable style="max-inline-size: 200px" />
            <AppSelect v-if="store.facets.hr" v-model="filters.employment_status" :items="employmentStates.map(s => ({ value: s, title: t('people.empStatus.' + s, s) }))" :placeholder="t('peopleList.employment')" density="compact" clearable style="max-inline-size: 200px" />
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
            <template #item.account_state="{ item }">
              <VChip v-if="item.account_state" :color="accountMeta(item.account_state).color" size="small" variant="tonal" label>{{ t(accountMeta(item.account_state).label) }}</VChip>
              <span v-else class="text-disabled">—</span>
            </template>
            <template #item.role="{ item }">
              <VChip v-if="item.is_owner" color="primary" size="small" variant="tonal" label>{{ t('people.owner') }}</VChip>
              <VChip v-else-if="item.role" :color="item.role.is_administrative ? 'warning' : 'secondary'" size="small" variant="tonal" label>{{ item.role.name }}</VChip>
              <span v-else class="text-disabled">—</span>
            </template>
            <template #item.employment_status="{ item }">
              <span v-if="item.employment_status" class="text-body-2">{{ t('people.empStatus.' + item.employment_status, item.employment_status) }}</span>
              <span v-else class="text-disabled">—</span>
            </template>
            <template #item.job_role="{ item }"><span :class="item.job_role ? '' : 'text-disabled'">{{ item.job_role || '—' }}</span></template>
            <template #item.department="{ item }"><span :class="item.department ? '' : 'text-disabled'">{{ item.department || '—' }}</span></template>
            <template #item.actions="{ item }"><VBtn icon variant="text" size="small" @click.stop="openFiche(item)"><VIcon icon="tabler-chevron-right" /></VBtn></template>
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
          <RolesManager v-if="tab === 'roles' && canRoles" />
        </VWindowItem>
      </VWindow>
    </VCard>
  </div>
</template>
