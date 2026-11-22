<script setup>
import AppPageHeader from '@/components/app/AppPageHeader.vue'
// ADR-822 — registre des contrats de travail de la société (lecture). La machine à états reste sur la
// fiche collaborateur : chaque ligne y mène (section Contrat). Périmètre = celui du lecteur (serveur).
import { $api } from '@/utils/api'
import { formatDate } from '@/utils/datetime'

definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const { t, te } = useI18n()
const router = useRouter()

const rows = ref([])
const total = ref(0)
const counts = ref({})
const loading = ref(true)
const loadError = ref(false)
const page = ref(1)
const search = ref('')
const status = ref('all')
const endingSoon = ref(false)

const STATUS_COLOR = { draft: 'warning', active: 'success', suspended: 'info', terminated: 'secondary', cancelled: 'error' }
const FILTERS = ['all', 'active', 'draft', 'suspended', 'terminated', 'cancelled']
const statusLabel = st => (te(`workforce.statuses.contract.${st}`) ? t(`workforce.statuses.contract.${st}`) : st)
const countFor = f => (f === 'all' ? Object.values(counts.value).reduce((a, n) => a + n, 0) : (counts.value[f] || 0))

const headers = computed(() => [
  { title: t('contractsRegister.col.person'), key: 'employee_name', sortable: false },
  { title: t('contractsRegister.col.contract'), key: 'contract_type', sortable: false },
  { title: t('contractsRegister.col.period'), key: 'start_date', sortable: false },
  { title: t('contractsRegister.col.status'), key: 'status', sortable: false, width: 140 },
  { title: '', key: 'open', sortable: false, align: 'end', width: 60 },
])

const load = async () => {
  loading.value = true
  loadError.value = false
  try {
    const res = await $api('/workforce/contracts', { query: { page: page.value, per_page: 20, search: search.value || undefined, status: status.value === 'all' ? undefined : status.value, ending_soon: endingSoon.value ? 1 : undefined } })
    rows.value = res.data
    total.value = res.total
    counts.value = res.counts || {}
  }
  catch { loadError.value = true }
  finally { loading.value = false }
}

let timer
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => { page.value = 1; load() }, 300) })
watch([status, endingSoon], () => { page.value = 1; load() })
watch(page, load)
onMounted(load)

const open = item => router.push({ name: 'company-people-person-ref', params: { personRef: item.person_ref, tab: 'contract' } })
const daysLeft = d => Math.ceil((new Date(d) - new Date()) / 86400000)
</script>

<template>
  <div>
    <AppPageHeader
      :eyebrow="t('l7.family.documents')"
      :title="t('contractsRegister.title')"
      :description="t('contractsRegister.subtitle')"
    >
      <template #actions>
        <VBtn
          variant="tonal"
          prepend-icon="tabler-template"
          :to="{ name: 'company-documents-tab', params: { tab: 'templates' } }"
        >
          {{ t('contractsRegister.templates') }}
        </VBtn>
      </template>
    </AppPageHeader>

    <VCard>
      <VCardText class="d-flex flex-wrap align-center gap-4">
        <VChipGroup
          v-model="status"
          mandatory
          selected-class="text-primary"
          data-test="contracts-filter"
        >
          <VChip
            v-for="f in FILTERS"
            :key="f"
            :value="f"
            label
            variant="tonal"
            :data-test="`contracts-filter-${f}`"
          >
            {{ f === 'all' ? t('contractsRegister.all') : statusLabel(f) }} · {{ countFor(f) }}
          </VChip>
        </VChipGroup>
        <VSpacer />
        <VCheckbox
          v-model="endingSoon"
          :label="t('contractsRegister.endingSoon')"
          density="compact"
          hide-details
          data-test="contracts-ending-soon"
        />
        <AppTextField
          v-model="search"
          :placeholder="t('contractsRegister.search')"
          prepend-inner-icon="tabler-search"
          clearable
          style="max-inline-size: 260px;"
          data-test="contracts-search"
        />
      </VCardText>
      <VDivider />

      <VAlert
        v-if="loadError"
        type="error"
        variant="tonal"
        class="ma-4"
      >
        {{ t('contractsRegister.loadError') }}
      </VAlert>

      <VDataTableServer
        v-else
        v-model:page="page"
        :headers="headers"
        :items="rows"
        :items-length="total"
        :items-per-page="20"
        :loading="loading"
        hover
        class="text-no-wrap"
        data-test="contracts-table"
        @click:row="(_, { item }) => open(item)"
      >
        <template #item.employee_name="{ item }">
          <div class="d-flex align-center gap-3 py-2">
            <VAvatar
              size="34"
              color="primary"
              variant="tonal"
            >
              {{ item.employee_name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase() }}
            </VAvatar>
            <div>
              <div class="text-body-1 font-weight-medium text-high-emphasis">
                {{ item.employee_name }}
              </div>
              <div
                v-if="item.employee_status === 'terminated'"
                class="text-caption text-disabled"
              >
                {{ t('contractsRegister.leftCompany') }}
              </div>
            </div>
          </div>
        </template>
        <template #item.contract_type="{ item }">
          <div class="font-weight-medium">
            {{ (item.contract_type || '').toUpperCase() }}
          </div>
          <div class="text-caption text-medium-emphasis">
            {{ t('contractsRegister.hours', { h: Number(item.weekly_hours || 0).toLocaleString() }) }}
          </div>
        </template>
        <template #item.start_date="{ item }">
          <div>{{ formatDate(item.start_date) }} → {{ item.end_date ? formatDate(item.end_date) : t('contractsRegister.noEnd') }}</div>
          <div
            v-if="item.end_date && item.status === 'active' && daysLeft(item.end_date) >= 0 && daysLeft(item.end_date) <= 60"
            class="text-caption text-warning"
          >
            {{ t('contractsRegister.endsIn', { n: daysLeft(item.end_date) }) }}
          </div>
          <div
            v-else-if="item.probation_end_date && item.status === 'active' && daysLeft(item.probation_end_date) >= 0"
            class="text-caption text-info"
          >
            {{ t('contractsRegister.probation', { date: formatDate(item.probation_end_date) }) }}
          </div>
        </template>
        <template #item.status="{ item }">
          <VChip
            :color="STATUS_COLOR[item.status] || 'secondary'"
            size="small"
            label
          >
            {{ statusLabel(item.status) }}
          </VChip>
        </template>
        <template #item.open>
          <VIcon
            icon="tabler-chevron-right"
            class="text-disabled"
          />
        </template>
        <template #no-data>
          <div class="text-center py-8">
            <VIcon
              icon="tabler-file-off"
              size="40"
              class="text-disabled mb-2"
            />
            <div class="text-body-1">
              {{ t('contractsRegister.empty') }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('contractsRegister.emptyHint') }}
            </div>
          </div>
        </template>
      </VDataTableServer>
    </VCard>
  </div>
</template>
