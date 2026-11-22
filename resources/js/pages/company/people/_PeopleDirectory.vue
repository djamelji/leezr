<script setup>
// ADR-858 (Leezr 5.0 L5) — « Personnes » directory (V22). Same API as before (`GET /company/people`, facets and
// perimeter decided server-side) : synthesis tiles that filter, search, filters with counts, identity / position /
// HR status / access / file completeness / next step, and a quick view on click. A figure or a column the actor may
// not read is simply absent (the server sends null). Phones get a compact card list. Sub-component.
import { useDisplay } from 'vuetify'
import { usePeopleListStore } from '@/modules/company/people/people-list.store'
import PersonPreview from './_PersonPreview.vue'

const { t, te } = useI18n()
const router = useRouter()
const store = usePeopleListStore()
const { smAndDown } = useDisplay()

const search = ref('')
const options = ref({ page: 1, itemsPerPage: 20, sortBy: [] })
const filters = ref({ attention: null, employment_status: null, department: null, job_role: null, access_state: null })

let debounce = null
function reload() {
  const sb = options.value.sortBy?.[0]

  store.fetch({
    page: options.value.page, perPage: options.value.itemsPerPage,
    search: search.value, sortBy: sb?.key || 'name', sortDir: sb?.order || 'asc',
    filters: { ...filters.value },
  })
}
watch(options, reload, { deep: true })
watch(filters, () => { options.value.page = 1; reload() }, { deep: true })
watch(search, () => { clearTimeout(debounce); debounce = setTimeout(() => { options.value.page = 1; reload() }, 300) })
onMounted(reload)
defineExpose({ reload })

// ── synthesis tiles (real figures ; a null figure is not readable by this actor → no tile) ──
const TILES = [
  { key: null, figure: 'total', icon: 'tabler-users', tone: 'primary' },
  { key: 'to_act', figure: 'to_act', icon: 'tabler-bolt', tone: 'warning' },
  { key: 'incomplete', figure: 'incomplete', icon: 'tabler-file-alert', tone: 'error' },
  { key: 'invitations', figure: 'invitations', icon: 'tabler-mail-forward', tone: 'info' },
  { key: 'preparatory', figure: 'preparatory', icon: 'tabler-user-plus', tone: 'success' },
]

const tiles = computed(() => {
  const s = store.summary
  if (!s) return []

  return TILES.filter(x => s[x.figure] !== null && s[x.figure] !== undefined && (x.key === null || s[x.figure] > 0 || filters.value.attention === x.key))
    .map(x => ({ ...x, value: s[x.figure], sub: x.key === null ? t('l5.summary.active', { n: s.active }, s.active) : t(`l5.summary.${x.key}Hint`) }))
})

const setAttention = key => { filters.value.attention = filters.value.attention === key ? null : key }

// ── filters with counts (only the dimensions the server counted for this actor) ──
const empLabel = v => t('people.empStatus.' + v, v)
const accessLabel = v => t(`l5.access.${v}`, v)

const selects = computed(() => {
  const c = store.filterCounts || {}

  const mk = (key, label, title = v => v) => (c[key] && Object.keys(c[key]).length
    ? { key, label: t(label), items: Object.entries(c[key]).map(([v, n]) => ({ value: v, title: `${title(v)} · ${n}` })) }
    : null)

  return [
    mk('employment_status', 'l5.filters.status', empLabel),
    mk('department', 'l5.filters.team'),
    mk('job_role', 'l5.filters.position'),
    mk('access_state', 'l5.filters.access', accessLabel),
  ].filter(Boolean)
})

const activeFilters = computed(() => Object.entries(filters.value).filter(([k, v]) => v && k !== 'attention').length)
const clearFilters = () => { filters.value = { attention: null, employment_status: null, department: null, job_role: null, access_state: null }; search.value = '' }

// ── columns by facet ──
const hasCompleteness = computed(() => (store.facets.account_details !== false && store.facets.access) || store.facets.hr) // ADR-859 : HR files too

const headers = computed(() => [
  { title: t('l5.col.person'), key: 'name', sortable: true },
  ...(store.facets.hr ? [{ title: t('l5.col.position'), key: 'job_role', sortable: true }, { title: t('l5.col.status'), key: 'employment_status', sortable: false }] : []),
  ...(store.facets.access && store.facets.account_details !== false ? [{ title: t('l5.col.access'), key: 'account_state', sortable: false }] : []),
  ...(hasCompleteness.value ? [{ title: t('l5.col.file'), key: 'completeness', sortable: false }] : []),
  { title: t('l5.col.next'), key: 'next_action', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end', width: 56 },
])

const EMP_TONE = { active: 'success', preparatory: 'info', on_leave: 'info', suspended: 'warning', terminated: 'secondary', inactive: 'secondary' }
const ACCESS_TONE = { connected: 'success', active: 'success', invited: 'warning', pending_acceptance: 'warning', invitation_expired: 'secondary', suspended: 'error' }

const NEXT = {
  resend_invitation: { icon: 'tabler-mail-forward', tone: 'warning', tab: 'access' },
  awaiting_acceptance: { icon: 'tabler-hourglass', tone: 'info', tab: 'access' },
  finish_onboarding: { icon: 'tabler-user-plus', tone: 'primary', tab: null },
  complete_file: { icon: 'tabler-forms', tone: 'error', tab: 'personal' },
}

const initials = name => (name || '?').split(' ').map(p => p.charAt(0)).slice(0, 2).join('').toUpperCase()
const pct = c => (c && c.total ? Math.round((c.filled / c.total) * 100) : null)
const contractType = c => (te(`employees.contractTypes.${c}`) ? t(`employees.contractTypes.${c}`) : c)
const fileTo = (row, tab) => (tab ? `/company/people/${row.person_ref}/${tab}` : `/company/people/${row.person_ref}`)

// ── quick view ──
const preview = ref(false)
const current = ref(null)
const open = row => { current.value = row; preview.value = true }
const goNext = row => router.push(fileTo(row, NEXT[row.next_action]?.tab))

const pageCount = computed(() => Math.max(1, Math.ceil(store.total / options.value.itemsPerPage)))
</script>

<template>
  <div class="lz-dir">
    <!-- synthesis -->
    <div
      v-if="tiles.length"
      class="lz-tiles mb-5 lzr-stagger"
      data-test="people-summary"
    >
      <button
        v-for="x in tiles"
        :key="String(x.key)"
        type="button"
        class="lz-tile lzr-enter"
        :class="{ 'lz-tile--on': filters.attention === x.key && x.key !== null }"
        :aria-pressed="filters.attention === x.key"
        :data-test="`people-summary-${x.key || 'all'}`"
        @click="x.key === null ? clearFilters() : setAttention(x.key)"
      >
        <VAvatar
          :color="x.tone"
          variant="tonal"
          rounded="lg"
          size="42"
        >
          <VIcon :icon="x.icon" />
        </VAvatar>
        <span class="lz-tile__txt">
          <span class="lz-tile__value">{{ x.value }}</span>
          <span class="lz-tile__label">{{ t(`l5.summary.${x.key || 'all'}`) }}</span>
          <span class="lz-tile__sub">{{ x.sub }}</span>
        </span>
      </button>
    </div>

    <VCard class="lz-dir__card">
      <!-- toolbar -->
      <div class="lz-toolbar">
        <AppTextField
          v-model="search"
          :placeholder="t('l5.search')"
          prepend-inner-icon="tabler-search"
          density="compact"
          clearable
          class="lz-toolbar__search"
          data-test="people-search"
        />
        <AppSelect
          v-for="s in selects"
          :key="s.key"
          v-model="filters[s.key]"
          :items="s.items"
          :placeholder="s.label"
          density="compact"
          clearable
          class="lz-toolbar__select"
          :data-test="`people-filter-${s.key}`"
        />
        <VBtn
          v-if="activeFilters || search || filters.attention"
          variant="text"
          size="small"
          prepend-icon="tabler-filter-off"
          data-test="people-filters-clear"
          @click="clearFilters"
        >
          {{ t('l5.filters.clear') }}
        </VBtn>
      </div>

      <!-- phones : compact cards -->
      <div
        v-if="smAndDown"
        class="lz-cards"
        data-test="people-cards"
      >
        <VSkeletonLoader
          v-if="store.loading && !store.rows.length"
          type="list-item-avatar-two-line@5"
        />
        <button
          v-for="row in store.rows"
          :key="row.person_ref"
          type="button"
          class="lz-pcard"
          :data-test="`people-row-${row.person_ref}`"
          @click="open(row)"
        >
          <VAvatar
            size="40"
            color="primary"
            variant="tonal"
          >
            <VImg
              v-if="row.avatar"
              :src="row.avatar"
            />
            <span
              v-else
              class="text-caption font-weight-bold"
            >{{ initials(row.name) }}</span>
          </VAvatar>
          <span class="lz-pcard__main">
            <span class="lz-pcard__name">{{ row.name && row.name !== row.email ? row.name : t('peopleList.nameMissing') }}</span>
            <span class="lz-pcard__sub">{{ [row.job_role, row.department].filter(Boolean).join(' · ') || row.email || '—' }}</span>
            <span class="d-flex flex-wrap gap-1 mt-1">
              <VChip
                v-if="row.employment_status"
                size="x-small"
                label
                variant="tonal"
                :color="EMP_TONE[row.employment_status] || 'secondary'"
              >{{ t('people.empStatus.' + row.employment_status, row.employment_status) }}</VChip>
              <VChip
                v-if="row.next_action"
                size="x-small"
                label
                variant="tonal"
                :color="NEXT[row.next_action].tone"
              >{{ t(`l5.next.${row.next_action}`) }}</VChip>
            </span>
          </span>
          <VIcon
            icon="tabler-chevron-right"
            size="18"
            class="text-medium-emphasis"
          />
        </button>
        <div
          v-if="!store.loading && !store.rows.length"
          class="lz-empty"
        >
          <VIcon
            icon="tabler-users-group"
            size="36"
            class="text-disabled mb-2"
          />
          <div>{{ search || activeFilters || filters.attention ? t('l5.emptyFiltered') : t('peopleList.empty') }}</div>
        </div>
        <VPagination
          v-if="pageCount > 1"
          v-model="options.page"
          :length="pageCount"
          density="compact"
          total-visible="5"
          class="my-3"
        />
      </div>

      <!-- desktop / tablet : table -->
      <VDataTableServer
        v-else
        v-model:options="options"
        :items-per-page="options.itemsPerPage"
        :headers="headers"
        :items="store.rows"
        :items-length="store.total"
        :loading="store.loading"
        item-value="person_ref"
        hover
        class="lz-table"
        data-test="people-table"
        @click:row="(_, { item }) => open(item)"
      >
        <template #item.name="{ item }">
          <div
            class="d-flex align-center gap-3 py-2 min-w-0"
            :data-test="`people-row-${item.person_ref}`"
          >
            <VAvatar
              size="40"
              color="primary"
              variant="tonal"
            >
              <VImg
                v-if="item.avatar"
                :src="item.avatar"
              />
              <span
                v-else
                class="text-caption font-weight-bold"
              >{{ initials(item.name) }}</span>
            </VAvatar>
            <div class="min-w-0">
              <button
                type="button"
                class="lz-name"
                :class="{ 'lz-name--missing': !item.name || item.name === item.email }"
                :data-test="!item.name || item.name === item.email ? 'person-name-missing' : undefined"
                @click.stop="open(item)"
              >
                {{ item.name && item.name !== item.email ? item.name : t('peopleList.nameMissing') }}
              </button>
              <div
                v-if="item.email"
                class="text-caption text-medium-emphasis text-truncate"
              >
                {{ item.email }}
              </div>
            </div>
          </div>
        </template>
        <template #item.job_role="{ item }">
          <div :class="item.job_role ? 'text-body-2 text-high-emphasis' : 'text-disabled'">
            {{ item.job_role || t('l5.noPosition') }}
          </div>
          <div
            v-if="item.department"
            class="text-caption text-medium-emphasis"
          >
            {{ item.department }}
          </div>
        </template>
        <template #item.employment_status="{ item }">
          <VChip
            v-if="item.employment_status"
            size="small"
            label
            variant="tonal"
            :color="EMP_TONE[item.employment_status] || 'secondary'"
          >
            {{ t('people.empStatus.' + item.employment_status, item.employment_status) }}
          </VChip>
          <span
            v-else
            class="text-disabled"
          >{{ t('l5.noRecord') }}</span>
          <div
            v-if="item.contract_type"
            class="text-caption text-medium-emphasis mt-1"
          >
            {{ contractType(item.contract_type) }}
          </div>
        </template>
        <template #item.account_state="{ item }">
          <div
            v-if="item.account_state"
            class="d-flex flex-column align-start gap-1"
          >
            <VChip
              size="small"
              label
              variant="tonal"
              :color="ACCESS_TONE[item.account_state] || 'secondary'"
            >
              {{ t(`l5.access.${item.account_state}`, item.account_state) }}
            </VChip>
            <span class="text-caption text-medium-emphasis">{{ item.is_owner ? t('people.owner') : (item.role?.name || '') }}</span>
          </div>
          <span
            v-else
            class="text-disabled"
          >{{ t('l5.access.none') }}</span>
        </template>
        <template #item.completeness="{ item }">
          <div
            v-if="item.completeness && item.completeness.total"
            class="d-flex align-center gap-2"
            :title="t('l5.fileTitle', { filled: item.completeness.filled, total: item.completeness.total })"
          >
            <VProgressCircular
              :model-value="pct(item.completeness)"
              :color="item.completeness.complete ? (item.completeness.to_collect ? 'info' : 'success') : 'warning'"
              size="30"
              width="4"
            >
              <VIcon
                v-if="item.completeness.complete && !item.completeness.to_collect"
                icon="tabler-check"
                size="14"
                color="success"
              />
            </VProgressCircular>
            <span class="d-flex flex-column">
              <span class="text-caption">{{ item.completeness.filled }}/{{ item.completeness.total }}</span>
              <span
                v-if="item.completeness.to_collect"
                class="text-caption text-info"
                :title="t('l6.file.toCollectHint')"
              >{{ t('l6.file.toCollect', { n: item.completeness.to_collect }, item.completeness.to_collect) }}</span>
            </span>
          </div>
          <span
            v-else
            class="text-disabled"
          >—</span>
        </template>
        <template #item.next_action="{ item }">
          <VBtn
            v-if="item.next_action"
            size="small"
            variant="tonal"
            :color="NEXT[item.next_action].tone"
            :prepend-icon="NEXT[item.next_action].icon"
            class="lz-next-btn"
            :data-test="`people-next-${item.person_ref}`"
            @click.stop="goNext(item)"
          >
            {{ t(`l5.next.${item.next_action}`) }}
          </VBtn>
          <span
            v-else-if="item.completeness?.complete"
            class="text-caption text-success d-inline-flex align-center gap-1"
          ><VIcon
            icon="tabler-circle-check"
            size="16"
          />{{ t('l5.next.none') }}</span>
          <span
            v-else
            class="text-disabled"
          >—</span>
        </template>
        <template #item.actions="{ item }">
          <IconBtn
            :aria-label="t('l5.rowMenu', { name: item.name })"
            @click.stop
          >
            <VIcon icon="tabler-dots-vertical" />
            <VMenu
              activator="parent"
              location="bottom end"
            >
              <VList density="compact">
                <VListItem
                  prepend-icon="tabler-eye"
                  :title="t('l5.preview.quick')"
                  @click="open(item)"
                />
                <VListItem
                  prepend-icon="tabler-id"
                  :title="t('l5.preview.openFile')"
                  :to="fileTo(item)"
                />
                <VListItem
                  v-if="item.next_action"
                  :prepend-icon="NEXT[item.next_action].icon"
                  :title="t(`l5.next.${item.next_action}`)"
                  @click="goNext(item)"
                />
              </VList>
            </VMenu>
          </IconBtn>
        </template>
        <template #no-data>
          <div class="lz-empty">
            <VIcon
              icon="tabler-users-group"
              size="40"
              class="text-disabled mb-2"
            />
            <div class="font-weight-medium">
              {{ search || activeFilters || filters.attention ? t('l5.emptyFiltered') : t('peopleList.empty') }}
            </div>
            <VBtn
              v-if="search || activeFilters || filters.attention"
              variant="text"
              size="small"
              class="mt-2"
              @click="clearFilters"
            >
              {{ t('l5.filters.clear') }}
            </VBtn>
          </div>
        </template>
      </VDataTableServer>
    </VCard>

    <PersonPreview
      v-model="preview"
      :row="current"
    />
  </div>
</template>

<style scoped>
.lz-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
.lz-tile {
  display: flex; align-items: center; gap: .875rem; padding: 1rem 1.125rem; border-radius: 16px; text-align: start; min-inline-size: 0;
  background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-border-color), .1);
  box-shadow: 0 2px 6px rgba(47, 43, 61, .05); transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.lz-tile:hover, .lz-tile:focus-visible { transform: translateY(-2px); box-shadow: 0 10px 24px -14px rgba(var(--v-theme-primary), .5); border-color: rgba(var(--v-theme-primary), .3); }
.lz-tile--on { border-color: rgb(var(--v-theme-primary)); box-shadow: inset 0 0 0 1px rgb(var(--v-theme-primary)); }
.lz-tile__txt { display: flex; flex-direction: column; min-inline-size: 0; }
.lz-tile__value { font-size: 1.625rem; font-weight: 700; letter-spacing: -.02em; line-height: 1.1; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.lz-tile__label { font-size: .875rem; font-weight: 500; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.lz-tile__sub { font-size: .75rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.lz-dir__card { overflow: hidden; }
.lz-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; padding: 1rem 1.25rem; border-block-end: 1px solid rgba(var(--v-border-color), .1); }
.lz-toolbar__search { flex: 1 1 260px; max-inline-size: 360px; }
.lz-toolbar__select { flex: 0 1 190px; min-inline-size: 150px; }
.lz-table :deep(thead th) { font-size: .75rem !important; letter-spacing: .06em; text-transform: uppercase; color: rgba(var(--v-theme-on-surface), .55) !important; }
.lz-table :deep(tbody tr) { cursor: pointer; transition: background-color .15s ease; }
.lz-name { font-weight: 600; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); text-align: start; max-inline-size: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }
.lz-name:hover, .lz-name:focus-visible { color: rgb(var(--v-theme-primary)); }
.lz-name--missing { font-style: italic; font-weight: 500; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.lz-next-btn { text-transform: none; letter-spacing: 0; }
.lz-empty { padding: 2.5rem 1rem; text-align: center; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.lz-cards { display: flex; flex-direction: column; }
.lz-pcard { display: flex; align-items: center; gap: .75rem; padding: .875rem 1rem; text-align: start; border-block-end: 1px solid rgba(var(--v-border-color), .08); }
.lz-pcard:active { background: rgba(var(--v-theme-primary), .05); }
.lz-pcard__main { display: flex; flex-direction: column; flex: 1 1 auto; min-inline-size: 0; }
.lz-pcard__name { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lz-pcard__sub { font-size: .8125rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
@media (max-width: 599px) {
  .lz-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .625rem; }
  .lz-tile { padding: .75rem; gap: .625rem; }
  .lz-tile__sub { display: none; }
  .lz-toolbar__search, .lz-toolbar__select { flex: 1 1 100%; max-inline-size: none; }
}
@media (prefers-reduced-motion: reduce) {
  .lz-tile { transition: none; }
  .lz-tile:hover { transform: none; }
}
</style>
