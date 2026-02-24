<script setup>
import { usePlatformTranslationsStore } from '@/modules/platform-admin/translations/translations.store'
import { usePlatformMarketsStore } from '@/modules/platform-admin/markets/markets.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const translationsStore = usePlatformTranslationsStore()
const marketsStore = usePlatformMarketsStore()
const { toast } = useAppToast()

const activeSubTab = ref('matrix')
const isLoading = ref(false)

// ─── Matrix state ─────────────────────────────────────
const matrixSection = ref('')
const matrixLocales = ref([])
const matrixSearch = ref('')
const matrixPage = ref(1)
const matrixPerPage = ref(50)
const dirtyRows = ref({}) // { "key": { locale: "new value", ... } }
const isSaving = ref(false)

// Debounced search
let searchTimeout = null
const debouncedSearch = val => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    matrixSearch.value = val
    matrixPage.value = 1
    loadMatrix()
  }, 400)
}

const searchInput = ref('')

watch(searchInput, debouncedSearch)

// Available namespaces + languages
const availableNamespaces = computed(() => translationsStore.availableNamespaces)
const availableLanguages = computed(() => marketsStore.languages)

const localeOptions = computed(() => {
  return availableLanguages.value.map(l => ({
    title: `${l.native_name} (${l.key})`,
    value: l.key,
  }))
})

const namespaceOptions = computed(() => {
  return availableNamespaces.value.map(ns => ({
    title: ns,
    value: ns,
  }))
})

// Matrix data
const matrixRows = computed(() => translationsStore.matrixRows)
const matrixPagination = computed(() => translationsStore.matrixPagination)

// Dynamic table headers
const matrixHeaders = computed(() => {
  const headers = [
    { title: t('translatePage.key'), key: 'key', width: '280px', sortable: false },
  ]

  matrixLocales.value.forEach(locale => {
    headers.push({
      title: locale.toUpperCase(),
      key: `locale_${locale}`,
      sortable: false,
    })
  })

  return headers
})

// Dirty count
const dirtyCount = computed(() => Object.keys(dirtyRows.value).length)

const loadMatrix = async () => {
  if (!matrixSection.value || matrixLocales.value.length === 0)
    return

  isLoading.value = true

  try {
    await translationsStore.fetchMatrix({
      section: matrixSection.value,
      locales: matrixLocales.value,
      q: matrixSearch.value || undefined,
      page: matrixPage.value,
      perPage: matrixPerPage.value,
    })
  }
  catch {
    toast(t('common.error'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

watch(matrixSection, () => {
  matrixPage.value = 1
  dirtyRows.value = {}
  loadMatrix()
})

watch(matrixLocales, () => {
  matrixPage.value = 1
  dirtyRows.value = {}
  loadMatrix()
})

const onPageChange = page => {
  matrixPage.value = page
  loadMatrix()
}

// Cell editing
const getCellValue = (row, locale) => {
  // Check dirty first
  if (dirtyRows.value[row.key]?.[locale] !== undefined)
    return dirtyRows.value[row.key][locale]

  return row.values[locale] ?? ''
}

const getDefaultValue = row => {
  // EN is always the default locale (first locale)
  const defaultLocale = matrixLocales.value[0] || 'en'

  if (dirtyRows.value[row.key]?.[defaultLocale] !== undefined)
    return dirtyRows.value[row.key][defaultLocale]

  return row.values[defaultLocale] ?? ''
}

const isCellEmpty = (row, locale) => {
  const val = getCellValue(row, locale)

  return val === '' || val === null || val === undefined
}

const isCellDirty = (row, locale) => {
  return dirtyRows.value[row.key]?.[locale] !== undefined
}

const onCellInput = (row, locale, value) => {
  const original = row.values[locale] ?? ''

  if (value === original) {
    // Remove from dirty if reverted
    if (dirtyRows.value[row.key]) {
      delete dirtyRows.value[row.key][locale]
      if (Object.keys(dirtyRows.value[row.key]).length === 0)
        delete dirtyRows.value[row.key]
    }
  }
  else {
    if (!dirtyRows.value[row.key])
      dirtyRows.value[row.key] = {}
    dirtyRows.value[row.key][locale] = value
  }
}

const saveMatrix = async () => {
  if (dirtyCount.value === 0)
    return

  isSaving.value = true

  try {
    // Build rows payload from dirty state
    const rows = Object.entries(dirtyRows.value).map(([key, values]) => ({
      key,
      values,
    }))

    const data = await translationsStore.updateMatrix({
      section: matrixSection.value,
      locales: matrixLocales.value,
      rows,
    })

    dirtyRows.value = {}
    toast(t('translatePage.matrixSaved', { count: data.updated_count }), 'success')

    // Reload to get fresh data
    await loadMatrix()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    isSaving.value = false
  }
}

// ─── Overrides state ──────────────────────────────────
const overrideMarketKey = ref('')
const overrideLocale = ref('')
const isLoadingOverrides = ref(false)
const overrideForm = reactive({ namespace: '', key: '', value: '' })
const isAddingOverride = ref(false)

const marketOptions = computed(() => {
  return marketsStore.markets.map(m => ({
    title: `${m.name} (${m.key})`,
    value: m.key,
  }))
})

const loadOverrides = async () => {
  if (!overrideMarketKey.value || !overrideLocale.value)
    return

  isLoadingOverrides.value = true

  try {
    await translationsStore.fetchOverrides(overrideMarketKey.value, overrideLocale.value)
  }
  catch {
    toast(t('common.error'), 'error')
  }
  finally {
    isLoadingOverrides.value = false
  }
}

watch(overrideMarketKey, loadOverrides)
watch(overrideLocale, loadOverrides)

const addOverride = async () => {
  try {
    await translationsStore.upsertOverrides(overrideMarketKey.value, {
      locale: overrideLocale.value,
      overrides: [{ ...overrideForm }],
    })

    toast(t('translatePage.overrideSaved'), 'success')
    isAddingOverride.value = false
    Object.assign(overrideForm, { namespace: '', key: '', value: '' })
    await loadOverrides()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}

const deleteOverride = async id => {
  try {
    await translationsStore.deleteOverride(id)
    toast(t('translatePage.overrideDeleted'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}

// ─── Import/Export state ──────────────────────────────
const importLocale = ref('')
const importFile = ref(null)
const importDiffs = ref(null)
const isImporting = ref(false)

const handleImportPreview = async () => {
  if (!importFile.value || !importLocale.value)
    return

  isImporting.value = true

  try {
    const formData = new FormData()

    formData.append('locale', importLocale.value)
    formData.append('file', importFile.value)

    const data = await translationsStore.importPreview(formData)

    importDiffs.value = data
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    isImporting.value = false
  }
}

const handleImportApply = async () => {
  if (!importFile.value || !importLocale.value)
    return

  isImporting.value = true

  try {
    const formData = new FormData()

    formData.append('locale', importLocale.value)
    formData.append('file', importFile.value)

    const data = await translationsStore.importApply(formData)

    toast(data.message, 'success')
    importDiffs.value = null
    importFile.value = null
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    isImporting.value = false
  }
}

const handleExport = async locale => {
  try {
    const data = await translationsStore.exportLocale(locale)
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = `translations-${locale}.json`
    a.click()
    URL.revokeObjectURL(url)
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}

// ─── Init ─────────────────────────────────────────────
onMounted(async () => {
  await Promise.all([
    translationsStore.loadNamespaces(),
    marketsStore.fetchLanguages(),
    marketsStore.fetchMarkets(),
  ])

  // Default: select first namespace + all active languages
  if (availableNamespaces.value.length > 0)
    matrixSection.value = availableNamespaces.value[0]

  // Default locales: en first, then others
  const langs = availableLanguages.value.map(l => l.key)
  const sorted = langs.includes('en')
    ? ['en', ...langs.filter(l => l !== 'en')]
    : langs

  matrixLocales.value = sorted
})
</script>

<template>
  <div>
    <!-- Sub-tabs -->
    <VTabs
      v-model="activeSubTab"
      class="v-tabs-pill mb-6"
    >
      <VTab value="matrix">
        <VIcon
          size="20"
          start
          icon="tabler-table"
        />
        {{ t('translatePage.tabs.matrix') }}
      </VTab>
      <VTab value="overrides">
        <VIcon
          size="20"
          start
          icon="tabler-adjustments"
        />
        {{ t('translatePage.tabs.overrides') }}
      </VTab>
      <VTab value="importExport">
        <VIcon
          size="20"
          start
          icon="tabler-file-import"
        />
        {{ t('translatePage.tabs.importExport') }}
      </VTab>
    </VTabs>

    <VWindow
      v-model="activeSubTab"
      class="disable-tab-transition"
      :touch="false"
    >
      <!-- Sub-tab 1: Matrix Editor -->
      <VWindowItem value="matrix">
        <VCard>
          <!-- Controls -->
          <VCardText>
            <VRow>
              <VCol
                cols="12"
                md="4"
              >
                <AppSelect
                  v-model="matrixSection"
                  :items="namespaceOptions"
                  :label="t('translatePage.section')"
                  :placeholder="t('translatePage.section')"
                />
              </VCol>
              <VCol
                cols="12"
                md="4"
              >
                <AppSelect
                  v-model="matrixLocales"
                  :items="localeOptions"
                  :label="t('translatePage.locales')"
                  multiple
                  chips
                  closable-chips
                />
              </VCol>
              <VCol
                cols="12"
                md="4"
              >
                <AppTextField
                  v-model="searchInput"
                  :label="t('translatePage.search')"
                  prepend-inner-icon="tabler-search"
                  clearable
                />
              </VCol>
            </VRow>
          </VCardText>

          <VDivider />

          <!-- Matrix Table -->
          <VDataTable
            :headers="matrixHeaders"
            :items="matrixRows"
            :loading="isLoading"
            :items-per-page="-1"
            class="text-no-wrap"
            density="compact"
          >
            <!-- Key column -->
            <template #item.key="{ item }">
              <span class="font-weight-medium text-body-2">{{ item.key }}</span>
            </template>

            <!-- Dynamic locale columns -->
            <template
              v-for="locale in matrixLocales"
              :key="locale"
              #[`item.locale_${locale}`]="{ item }"
            >
              <div
                class="d-flex align-center py-1"
                :class="{ 'bg-warning-lighten-5': isCellDirty(item, locale) }"
                style="min-inline-size: 200px;"
              >
                <!-- Editable field -->
                <AppTextField
                  v-if="!isCellEmpty(item, locale) || locale === matrixLocales[0]"
                  :model-value="getCellValue(item, locale)"
                  density="compact"
                  hide-details
                  variant="plain"
                  class="matrix-cell"
                  @update:model-value="onCellInput(item, locale, $event)"
                />

                <!-- Fallback display: show EN value when cell is empty (non-default locale) -->
                <VTooltip
                  v-else-if="isCellEmpty(item, locale) && getDefaultValue(item)"
                  location="top"
                >
                  <template #activator="{ props: tooltipProps }">
                    <AppTextField
                      :model-value="getCellValue(item, locale)"
                      density="compact"
                      hide-details
                      variant="plain"
                      class="matrix-cell"
                      :placeholder="getDefaultValue(item)"
                      v-bind="tooltipProps"
                      @update:model-value="onCellInput(item, locale, $event)"
                    />
                  </template>
                  {{ t('translatePage.fallbackTooltip') }}
                </VTooltip>

                <!-- Empty field (no fallback available) -->
                <AppTextField
                  v-else
                  :model-value="getCellValue(item, locale)"
                  density="compact"
                  hide-details
                  variant="plain"
                  class="matrix-cell"
                  @update:model-value="onCellInput(item, locale, $event)"
                />
              </div>
            </template>

            <!-- Header: mark default locale -->
            <template
              v-for="locale in matrixLocales"
              :key="`header-${locale}`"
              #[`header.locale_${locale}`]="{ column }"
            >
              <div class="d-flex align-center gap-2">
                {{ column.title }}
                <VChip
                  v-if="locale === matrixLocales[0]"
                  size="x-small"
                  color="primary"
                  variant="flat"
                >
                  {{ t('translatePage.defaultLocale') }}
                </VChip>
              </div>
            </template>

            <!-- No data -->
            <template #no-data>
              <div class="text-center pa-6 text-medium-emphasis">
                <template v-if="!matrixSection">
                  {{ t('translatePage.section') }}...
                </template>
                <template v-else>
                  {{ t('translatePage.noResults') }}
                </template>
              </div>
            </template>

            <!-- Pagination -->
            <template #bottom>
              <VDivider />
              <div class="d-flex align-center justify-space-between pa-4">
                <div class="d-flex align-center gap-4">
                  <VBtn
                    color="primary"
                    :loading="isSaving"
                    :disabled="dirtyCount === 0"
                    @click="saveMatrix"
                  >
                    {{ t('translatePage.saveMatrix') }}
                  </VBtn>
                  <span
                    v-if="dirtyCount > 0"
                    class="text-body-2 text-warning"
                  >
                    {{ t('translatePage.dirtyCount', dirtyCount) }}
                  </span>
                </div>
                <VPagination
                  v-if="matrixPagination.last_page > 1"
                  :model-value="matrixPage"
                  :length="matrixPagination.last_page"
                  density="comfortable"
                  @update:model-value="onPageChange"
                />
              </div>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <!-- Sub-tab 2: Market Overrides -->
      <VWindowItem value="overrides">
        <VCard>
          <VCardText>
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppSelect
                  v-model="overrideMarketKey"
                  :items="marketOptions"
                  :label="t('translatePage.selectMarket')"
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <AppSelect
                  v-model="overrideLocale"
                  :items="localeOptions"
                  :label="t('translatePage.selectLocale')"
                />
              </VCol>
            </VRow>
          </VCardText>

          <VDivider />

          <template v-if="overrideMarketKey && overrideLocale">
            <!-- Overrides exist -->
            <template v-if="translationsStore.overrides.length > 0 || isLoadingOverrides">
              <VCardTitle class="d-flex align-center justify-space-between">
                <span>{{ t('translatePage.tabs.overrides') }}</span>
                <VBtn
                  size="small"
                  color="primary"
                  prepend-icon="tabler-plus"
                  @click="isAddingOverride = true"
                >
                  {{ t('translatePage.addOverride') }}
                </VBtn>
              </VCardTitle>

              <VDataTable
                :headers="[
                  { title: t('translatePage.overrideNamespace'), key: 'namespace', width: '150px' },
                  { title: t('translatePage.overrideKey'), key: 'key' },
                  { title: t('translatePage.overrideValue'), key: 'value' },
                  { title: t('common.actions'), key: 'actions', sortable: false, width: '80px' },
                ]"
                :items="translationsStore.overrides"
                :loading="isLoadingOverrides"
                class="text-no-wrap"
              >
                <template #item.actions="{ item }">
                  <VBtn
                    icon="tabler-trash"
                    variant="text"
                    size="small"
                    color="error"
                    @click="deleteOverride(item.id)"
                  />
                </template>
              </VDataTable>
            </template>

            <!-- Empty state: no overrides yet -->
            <VCardText
              v-else
              class="text-center pa-8"
            >
              <VIcon
                icon="tabler-language-off"
                size="48"
                color="secondary"
                class="mb-4"
              />
              <p class="text-body-1 text-medium-emphasis mb-4">
                {{ t('translatePage.overridesEmpty') }}
              </p>
              <VBtn
                color="primary"
                prepend-icon="tabler-plus"
                @click="isAddingOverride = true"
              >
                {{ t('translatePage.addOverride') }}
              </VBtn>
            </VCardText>
          </template>

          <VCardText
            v-else
            class="text-center text-medium-emphasis pa-8"
          >
            {{ t('translatePage.selectMarket') }} & {{ t('translatePage.selectLocale') }}
          </VCardText>
        </VCard>

        <!-- Add override dialog -->
        <VDialog
          v-model="isAddingOverride"
          max-width="500"
        >
          <VCard :title="t('translatePage.addOverride')">
            <VCardText>
              <VRow>
                <VCol cols="12">
                  <AppTextField
                    v-model="overrideForm.namespace"
                    :label="t('translatePage.overrideNamespace')"
                    placeholder="common"
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextField
                    v-model="overrideForm.key"
                    :label="t('translatePage.overrideKey')"
                    placeholder="greeting.hello"
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextField
                    v-model="overrideForm.value"
                    :label="t('translatePage.overrideValue')"
                  />
                </VCol>
              </VRow>
            </VCardText>
            <VCardActions>
              <VSpacer />
              <VBtn
                variant="outlined"
                @click="isAddingOverride = false"
              >
                {{ t('common.cancel') }}
              </VBtn>
              <VBtn
                color="primary"
                @click="addOverride"
              >
                {{ t('common.save') }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VDialog>
      </VWindowItem>

      <!-- Sub-tab 3: Import / Export -->
      <VWindowItem value="importExport">
        <VRow>
          <!-- Import -->
          <VCol
            cols="12"
            md="6"
          >
            <VCard>
              <VCardTitle>{{ t('translations.import') }}</VCardTitle>
              <VCardText>
                <VRow>
                  <VCol cols="12">
                    <AppSelect
                      v-model="importLocale"
                      :items="localeOptions"
                      :label="t('translatePage.selectLocale')"
                    />
                  </VCol>
                  <VCol cols="12">
                    <VFileInput
                      v-model="importFile"
                      accept=".json"
                      label="JSON"
                      prepend-icon="tabler-file-upload"
                    />
                  </VCol>
                  <VCol cols="12">
                    <div class="d-flex gap-2">
                      <VBtn
                        :disabled="!importFile || !importLocale"
                        :loading="isImporting"
                        @click="handleImportPreview"
                      >
                        {{ t('translations.importPreview') }}
                      </VBtn>
                      <VBtn
                        v-if="importDiffs"
                        color="primary"
                        :loading="isImporting"
                        @click="handleImportApply"
                      >
                        {{ t('translations.apply') }}
                      </VBtn>
                    </div>
                  </VCol>
                </VRow>

                <!-- Preview diffs -->
                <div
                  v-if="importDiffs"
                  class="mt-4"
                >
                  <p class="text-body-2 mb-2">
                    {{ importDiffs.namespaces_affected }} {{ t('translatePage.overrideNamespace') }}(s) affected
                  </p>
                  <div
                    v-for="(diff, ns) in importDiffs.diffs"
                    :key="ns"
                  >
                    <p class="font-weight-medium">
                      {{ ns }}
                    </p>
                    <VChip
                      v-if="Object.keys(diff.added).length"
                      size="small"
                      color="success"
                      class="me-2"
                    >
                      + {{ Object.keys(diff.added).length }} {{ t('translations.added') }}
                    </VChip>
                    <VChip
                      v-if="Object.keys(diff.changed).length"
                      size="small"
                      color="warning"
                      class="me-2"
                    >
                      ~ {{ Object.keys(diff.changed).length }} {{ t('translations.changed') }}
                    </VChip>
                    <VChip
                      v-if="Object.keys(diff.removed).length"
                      size="small"
                      color="error"
                    >
                      - {{ Object.keys(diff.removed).length }} {{ t('translations.removed') }}
                    </VChip>
                  </div>
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <!-- Export -->
          <VCol
            cols="12"
            md="6"
          >
            <VCard>
              <VCardTitle>{{ t('translations.export') }}</VCardTitle>
              <VCardText>
                <p class="text-body-2 text-medium-emphasis mb-4">
                  {{ t('translations.title') }}
                </p>
                <div class="d-flex flex-wrap gap-3">
                  <VBtn
                    v-for="lang in availableLanguages"
                    :key="lang.key"
                    variant="outlined"
                    prepend-icon="tabler-download"
                    @click="handleExport(lang.key)"
                  >
                    {{ lang.native_name }} ({{ lang.key }})
                  </VBtn>
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>
    </VWindow>
  </div>
</template>

<style lang="scss">
.matrix-cell {
  .v-field__input {
    font-size: 0.8125rem;
    padding-block: 4px;
    min-block-size: unset;
  }

  .v-field {
    --v-field-padding-start: 4px;
    --v-field-padding-end: 4px;
  }

  .v-input__details {
    display: none;
  }
}

.bg-warning-lighten-5 {
  background-color: rgba(var(--v-theme-warning), 0.08) !important;
}
</style>
