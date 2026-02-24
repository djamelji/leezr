<script setup>
import { usePlatformMarketsStore } from '@/modules/platform-admin/markets/markets.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    navActiveLink: 'platform-international-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-markets-key')
const router = useRouter()
const marketsStore = usePlatformMarketsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)
const activeTab = ref('general')

// General form
const form = reactive({
  key: '',
  name: '',
  currency: '',
  locale: '',
  timezone: '',
  dial_code: '',
  is_active: true,
  is_default: false,
  sort_order: 0,
})

// Legal status dialog
const isLegalDialogOpen = ref(false)
const legalDialogMode = ref('create')
const legalForm = reactive({
  id: null,
  key: '',
  name: '',
  description: '',
  is_vat_applicable: true,
  vat_rate: 20,
  is_default: false,
  sort_order: 0,
})

// Companies pagination
const companiesPage = ref(1)

const loadMarket = async () => {
  isLoading.value = true

  try {
    const data = await marketsStore.fetchMarket(route.params.key, companiesPage.value)

    Object.assign(form, {
      key: data.market.key,
      name: data.market.name,
      currency: data.market.currency,
      locale: data.market.locale,
      timezone: data.market.timezone,
      dial_code: data.market.dial_code,
      is_active: data.market.is_active,
      is_default: data.market.is_default,
      sort_order: data.market.sort_order,
    })
  }
  catch {
    toast(t('common.error'), 'error')
    router.push({ name: 'platform-international-tab', params: { tab: 'markets' } })
  }
  finally {
    isLoading.value = false
  }
}

onMounted(loadMarket)

// ─── General save ───────────────────────────────────
const saveGeneral = async () => {
  isSaving.value = true

  try {
    const data = await marketsStore.updateMarket(marketsStore.currentMarket.id, {
      ...form,
      language_keys: marketsStore.currentMarket?.languages?.map(l => l.key) || [],
    })

    toast(data.message || t('markets.saved'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    isSaving.value = false
  }
}

// ─── Legal statuses ─────────────────────────────────
const openAddLegal = () => {
  legalDialogMode.value = 'create'
  Object.assign(legalForm, { id: null, key: '', name: '', description: '', is_vat_applicable: true, vat_rate: 20, is_default: false, sort_order: 0 })
  isLegalDialogOpen.value = true
}

const openEditLegal = ls => {
  legalDialogMode.value = 'edit'
  Object.assign(legalForm, { ...ls })
  isLegalDialogOpen.value = true
}

const saveLegal = async () => {
  try {
    if (legalDialogMode.value === 'create') {
      const data = await marketsStore.createLegalStatus(form.key, { ...legalForm })

      toast(data.message || t('legalStatuses.saved'), 'success')
    }
    else {
      const data = await marketsStore.updateLegalStatus(legalForm.id, { ...legalForm })

      toast(data.message || t('legalStatuses.saved'), 'success')
    }
    isLegalDialogOpen.value = false
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}

const deleteLegal = async id => {
  try {
    const data = await marketsStore.deleteLegalStatus(id)

    toast(data.message || t('legalStatuses.deleted'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}

// ─── Languages ──────────────────────────────────────
const allLanguages = ref([])

const loadLanguages = async () => {
  await marketsStore.fetchLanguages()
  allLanguages.value = marketsStore.languages
}

const assignedLanguageKeys = computed(() => {
  return marketsStore.currentMarket?.languages?.map(l => l.key) || []
})

const toggleLanguage = async langKey => {
  const current = [...assignedLanguageKeys.value]
  const idx = current.indexOf(langKey)

  if (idx !== -1)
    current.splice(idx, 1)
  else
    current.push(langKey)

  try {
    await marketsStore.updateMarket(marketsStore.currentMarket.id, {
      ...form,
      language_keys: current,
    })
    await loadMarket()
    toast(t('markets.saved'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}

watch(activeTab, val => {
  if (val === 'languages' && allLanguages.value.length === 0)
    loadLanguages()
})

// ─── Companies pagination ───────────────────────────
const loadCompaniesPage = async page => {
  companiesPage.value = page
  await marketsStore.fetchMarket(route.params.key, page)
}

const companyHeaders = [
  { title: t('common.name'), key: 'name' },
  { title: t('common.status'), key: 'status', width: '120px' },
  { title: t('legalStatuses.title'), key: 'legal_status_key' },
  { title: t('common.actions'), key: 'actions', sortable: false, width: '100px' },
]

const legalHeaders = [
  { title: t('legalStatuses.key'), key: 'key', width: '120px' },
  { title: t('legalStatuses.name'), key: 'name' },
  { title: t('legalStatuses.description'), key: 'description' },
  { title: t('legalStatuses.subjectToVat'), key: 'vat_display', width: '160px' },
  { title: t('legalStatuses.isDefault'), key: 'is_default', width: '100px', align: 'center' },
  { title: t('common.actions'), key: 'actions', sortable: false, width: '120px' },
]
</script>

<template>
  <div>
    <!-- Back + Header -->
    <div class="d-flex align-center mb-6">
      <VBtn
        icon="tabler-arrow-left"
        variant="text"
        class="me-2"
        @click="router.push({ name: 'platform-international-tab', params: { tab: 'markets' } })"
      />
      <div v-if="!isLoading">
        <h4 class="text-h4">
          {{ form.name }}
        </h4>
        <span class="text-body-2 text-medium-emphasis">{{ form.key }} — {{ form.currency }} — {{ form.locale }}</span>
      </div>
      <VProgressCircular
        v-else
        indeterminate
        size="24"
      />
    </div>

    <!-- Tabs -->
    <VTabs
      v-model="activeTab"
      class="v-tabs-pill mb-6"
    >
      <VTab value="general">
        <VIcon
          size="20"
          start
          icon="tabler-settings"
        />
        {{ t('markets.tabs.general') }}
      </VTab>
      <VTab value="legal">
        <VIcon
          size="20"
          start
          icon="tabler-gavel"
        />
        {{ t('markets.tabs.legalStatuses') }}
      </VTab>
      <VTab value="languages">
        <VIcon
          size="20"
          start
          icon="tabler-language"
        />
        {{ t('markets.tabs.languages') }}
      </VTab>
      <VTab value="companies">
        <VIcon
          size="20"
          start
          icon="tabler-building"
        />
        {{ t('markets.tabs.companies') }}
      </VTab>
    </VTabs>

    <VWindow
      v-model="activeTab"
      class="disable-tab-transition"
      :touch="false"
    >
      <!-- Tab 1: General -->
      <VWindowItem value="general">
        <VCard :loading="isLoading">
          <VCardText v-if="!isLoading">
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.name"
                  :label="t('markets.name')"
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.key"
                  :label="t('markets.key')"
                  disabled
                />
              </VCol>
              <VCol
                cols="12"
                md="4"
              >
                <AppTextField
                  v-model="form.currency"
                  :label="t('markets.currency')"
                />
              </VCol>
              <VCol
                cols="12"
                md="4"
              >
                <AppTextField
                  v-model="form.locale"
                  :label="t('markets.locale')"
                />
              </VCol>
              <VCol
                cols="12"
                md="4"
              >
                <AppTextField
                  v-model="form.dial_code"
                  :label="t('markets.dialCode')"
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.timezone"
                  :label="t('markets.timezone')"
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.sort_order"
                  :label="t('markets.sortOrder')"
                  type="number"
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <VSwitch
                  v-model="form.is_active"
                  :label="t('markets.isActive')"
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <VSwitch
                  v-model="form.is_default"
                  :label="t('markets.isDefault')"
                  disabled
                />
              </VCol>
            </VRow>
          </VCardText>
          <VDivider />
          <VCardActions class="pa-4">
            <VBtn
              color="primary"
              :loading="isSaving"
              :disabled="isLoading"
              @click="saveGeneral"
            >
              {{ t('common.save') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VWindowItem>

      <!-- Tab 2: Legal Statuses -->
      <VWindowItem value="legal">
        <VCard>
          <VCardTitle class="d-flex align-center justify-space-between">
            <span>{{ t('legalStatuses.title') }}</span>
            <VBtn
              size="small"
              color="primary"
              prepend-icon="tabler-plus"
              @click="openAddLegal"
            >
              {{ t('legalStatuses.addStatus') }}
            </VBtn>
          </VCardTitle>
          <VDataTable
            :headers="legalHeaders"
            :items="marketsStore.currentMarket?.legal_statuses || []"
            class="text-no-wrap"
          >
            <template #item.vat_display="{ item }">
              <template v-if="item.is_vat_applicable">
                <VChip
                  color="info"
                  size="small"
                >
                  {{ item.vat_rate }}%
                </VChip>
              </template>
              <span
                v-else
                class="text-medium-emphasis text-body-2"
              >{{ t('legalStatuses.notSubjectToVat') }}</span>
            </template>
            <template #item.is_default="{ item }">
              <VChip
                v-if="item.is_default"
                color="primary"
                size="small"
              >
                {{ t('legalStatuses.isDefault') }}
              </VChip>
            </template>
            <template #item.actions="{ item }">
              <VBtn
                icon="tabler-edit"
                variant="text"
                size="small"
                @click="openEditLegal(item)"
              />
              <VBtn
                icon="tabler-trash"
                variant="text"
                size="small"
                color="error"
                @click="deleteLegal(item.id)"
              />
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <!-- Tab 3: Languages -->
      <VWindowItem value="languages">
        <VCard>
          <VCardTitle>{{ t('markets.tabs.languages') }}</VCardTitle>
          <VCardText>
            <p class="text-body-2 text-medium-emphasis mb-4">
              {{ t('languages.title') }}
            </p>
            <div class="d-flex flex-wrap gap-3">
              <VChip
                v-for="lang in allLanguages"
                :key="lang.key"
                :color="assignedLanguageKeys.includes(lang.key) ? 'primary' : 'default'"
                :variant="assignedLanguageKeys.includes(lang.key) ? 'elevated' : 'outlined'"
                size="large"
                @click="toggleLanguage(lang.key)"
              >
                <VIcon
                  v-if="assignedLanguageKeys.includes(lang.key)"
                  start
                  icon="tabler-check"
                />
                {{ lang.native_name }} ({{ lang.key }})
              </VChip>
            </div>
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- Tab 4: Companies -->
      <VWindowItem value="companies">
        <VCard>
          <VCardTitle>{{ t('markets.tabs.companies') }}</VCardTitle>
          <VDataTable
            :headers="companyHeaders"
            :items="marketsStore.marketCompanies"
          >
            <template #item.status="{ item }">
              <VChip
                :color="item.status === 'active' ? 'success' : 'warning'"
                size="small"
              >
                {{ item.status }}
              </VChip>
            </template>
            <template #item.actions="{ item }">
              <VBtn
                icon="tabler-eye"
                variant="text"
                size="small"
                :to="{ name: 'platform-companies-id', params: { id: item.id } }"
              />
            </template>
            <template #bottom>
              <VDivider />
              <div class="d-flex align-center justify-end pa-2">
                <VPagination
                  v-if="marketsStore.marketCompaniesPagination.last_page > 1"
                  v-model="companiesPage"
                  :length="marketsStore.marketCompaniesPagination.last_page"
                  density="comfortable"
                  @update:model-value="loadCompaniesPage"
                />
              </div>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- Legal Status Dialog -->
    <VDialog
      v-model="isLegalDialogOpen"
      max-width="500"
    >
      <VCard :title="legalDialogMode === 'create' ? t('legalStatuses.addStatus') : t('common.edit')">
        <VCardText>
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="legalForm.key"
                :label="t('legalStatuses.key')"
                :disabled="legalDialogMode === 'edit'"
                placeholder="sas"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="legalForm.name"
                :label="t('legalStatuses.name')"
                placeholder="SAS"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="legalForm.description"
                :label="t('legalStatuses.description')"
              />
            </VCol>
            <VCol cols="12">
              <VSwitch
                v-model="legalForm.is_vat_applicable"
                :label="t('legalStatuses.subjectToVat')"
                color="primary"
              />
            </VCol>
            <VCol
              v-if="legalForm.is_vat_applicable"
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="legalForm.vat_rate"
                :label="t('legalStatuses.vatRate')"
                type="number"
                suffix="%"
              />
            </VCol>
            <VCol
              v-if="!legalForm.is_vat_applicable"
              cols="12"
            >
              <span class="text-body-2 text-medium-emphasis">{{ t('legalStatuses.notSubjectToVat') }}</span>
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <VSwitch
                v-model="legalForm.is_default"
                :label="t('legalStatuses.isDefault')"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="outlined"
            @click="isLegalDialogOpen = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            @click="saveLegal"
          >
            {{ t('common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
