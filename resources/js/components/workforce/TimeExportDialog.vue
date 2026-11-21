<script setup>
import AppDialog from '@/components/app/AppDialog.vue'
import { $api } from '@/utils/api'
import { exportPeriod, exportWarnings } from '@/modules/company/workforce/timeExport'

// ADR-844 (Leezr 4.2 M7, FUN-06) — export of clock-ins, breaks, planned vs worked, planning and absences by period.
// Filters → preview (counts, first rows, warnings) → CSV download. Server : /workforce/exports/time/* (workforce.export,
// module and visibility scope per dataset). The file is streamed, nothing is stored.
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  preset: { type: Array, default: () => ['daily'] }, // datasets pre-selected by the current view
})

const emit = defineEmits(['update:modelValue'])
const { t, te } = useI18n()
const open = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })

const range = ref('last_month')
const custom = ref({ from: '', to: '' })
const datasets = ref([...props.preset])
const employeeIds = ref([])
const workLocationId = ref(null)
const employees = ref([])
const locations = ref([])
const available = ref(['entries', 'breaks', 'daily', 'planning', 'leaves'])
const preview = ref(null)
const loading = ref(false)
const error = ref('')
const downloading = ref(null)

const RANGES = ['this_week', 'last_week', 'this_month', 'last_month', 'custom']
const period = computed(() => exportPeriod(range.value, new Date(), custom.value))

const params = computed(() => {
  const p = new URLSearchParams({ from: period.value.from, to: period.value.to })

  employeeIds.value.forEach(id => p.append('employee_ids[]', id))
  if (workLocationId.value && datasets.value.includes('planning')) p.set('work_location_id', workLocationId.value)

  return p
})

const columnLabel = c => (te(`timeExport.columns.${c}`) ? t(`timeExport.columns.${c}`) : c)

watch(open, async v => {
  if (!v) return
  datasets.value = [...props.preset]
  preview.value = null
  error.value = ''
  employees.value = (await $api('/workforce/employees?per_page=200&status=active').catch(() => ({ data: [] }))).data || []
  locations.value = (await $api('/workforce/planning/locations').catch(() => ({ data: [] })))?.data || []
}, { immediate: true })
watch([period, datasets, employeeIds, workLocationId], () => { preview.value = null })

const loadPreview = async () => {
  if (!period.value.from || !period.value.to || !datasets.value.length) return
  loading.value = true
  error.value = ''
  try {
    const p = new URLSearchParams(params.value)

    datasets.value.forEach(d => p.append('datasets[]', d))
    preview.value = await $api(`/workforce/exports/time/preview?${p}`)
    available.value = preview.value.available || available.value
  }
  catch (e) {
    error.value = Object.values(e?.data?.errors || {}).flat()[0] || e?.data?.message || t('timeExport.errors.generation')
  }
  finally { loading.value = false }
}

const download = async dataset => {
  downloading.value = dataset
  error.value = ''
  try {
    const p = new URLSearchParams(params.value)

    p.set('dataset', dataset)

    const blob = await $api(`/workforce/exports/time/download?${p}`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = `${t(`timeExport.datasets.${dataset}.file`)}_${period.value.from}_${period.value.to}.csv`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  }
  catch (e) {
    error.value = e?.data?.errors?.dataset?.[0] || t('timeExport.errors.generation')
  }
  finally { downloading.value = null }
}
</script>

<template>
  <AppDialog
    v-model="open"
    :title="t('timeExport.title')"
    :subtitle="t('timeExport.subtitle')"
    max-width="960"
  >
    <div data-test="time-export-dialog">
      <VRow>
        <VCol
          cols="12"
          md="4"
        >
          <AppSelect
            v-model="range"
            :items="RANGES.map(r => ({ value: r, title: t(`timeExport.ranges.${r}`) }))"
            :label="t('timeExport.period')"
            data-test="time-export-range"
          />
        </VCol>
        <template v-if="range === 'custom'">
          <VCol
            cols="6"
            md="4"
          >
            <AppTextField
              v-model="custom.from"
              type="date"
              :label="t('timeExport.from')"
              data-test="time-export-from"
            />
          </VCol>
          <VCol
            cols="6"
            md="4"
          >
            <AppTextField
              v-model="custom.to"
              type="date"
              :label="t('timeExport.to')"
              data-test="time-export-to"
            />
          </VCol>
        </template>
        <VCol
          v-else
          cols="12"
          md="8"
          class="d-flex align-center text-body-2 text-medium-emphasis"
          data-test="time-export-period-label"
        >
          {{ t('timeExport.periodLabel', { from: period.from, to: period.to }) }}
        </VCol>

        <VCol
          cols="12"
          md="8"
        >
          <AppAutocomplete
            v-model="employeeIds"
            :items="employees.map(e => ({ value: e.id, title: `${e.first_name || ''} ${e.last_name || ''}`.trim() + (e.employee_number ? ` · ${e.employee_number}` : '') }))"
            :label="t('timeExport.employees')"
            :placeholder="t('timeExport.allEmployees')"
            multiple
            chips
            closable-chips
            clearable
            data-test="time-export-employees"
          />
        </VCol>
        <VCol
          v-if="locations.length && datasets.includes('planning')"
          cols="12"
          md="4"
        >
          <AppSelect
            v-model="workLocationId"
            :items="locations.map(l => ({ value: l.id, title: l.name }))"
            :label="t('timeExport.site')"
            :hint="t('timeExport.siteHint')"
            persistent-hint
            clearable
            data-test="time-export-site"
          />
        </VCol>
      </VRow>

      <div class="text-subtitle-2 mt-4 mb-1">
        {{ t('timeExport.whatToExport') }}
      </div>
      <div class="d-flex flex-wrap gap-x-6">
        <VCheckbox
          v-for="d in available"
          :key="d"
          v-model="datasets"
          :value="d"
          :label="t(`timeExport.datasets.${d}.label`)"
          :hint="t(`timeExport.datasets.${d}.hint`)"
          persistent-hint
          density="compact"
          :data-test="`time-export-dataset-${d}`"
        />
      </div>

      <VAlert
        v-if="error"
        type="error"
        variant="tonal"
        class="mt-4"
        data-test="time-export-error"
      >
        {{ error }}
      </VAlert>

      <!-- Preview : counts, warnings and the first rows, before any download -->
      <template v-if="preview">
        <VDivider class="my-4" />
        <div
          class="text-body-2 text-medium-emphasis mb-3"
          data-test="time-export-filters"
        >
          {{ t('timeExport.appliedFilters', { from: preview.filters.from, to: preview.filters.to, people: employeeIds.length || t('timeExport.everyoneVisible'), tz: preview.timezone }) }}
        </div>
        <VCard
          v-for="(res, key) in preview.datasets"
          :key="key"
          flat
          border
          class="mb-4"
          :data-test="`time-export-preview-${key}`"
        >
          <VCardItem>
            <VCardTitle class="text-subtitle-1">
              {{ t(`timeExport.datasets.${key}.label`) }}
              <VChip
                size="small"
                class="ms-2"
                :data-test="`time-export-count-${key}`"
              >
                {{ t('timeExport.rows', { n: res.count }) }}
              </VChip>
            </VCardTitle>
            <template #append>
              <VBtn
                color="primary"
                prepend-icon="tabler-download"
                :loading="downloading === key"
                :disabled="!res.count || res.warnings.includes('too_large')"
                :data-test="`time-export-download-${key}`"
                @click="download(key)"
              >
                {{ t('timeExport.downloadCsv') }}
              </VBtn>
            </template>
          </VCardItem>
          <VCardText class="pt-0">
            <VAlert
              v-for="w in exportWarnings(res.warnings)"
              :key="w.key"
              :type="w.type"
              variant="tonal"
              density="compact"
              class="mb-2"
              :data-test="`time-export-warning-${w.key}`"
            >
              {{ t(`timeExport.warnings.${w.key}`, { n: w.n, max: preview.limits.max_rows }) }}
            </VAlert>
            <VTable
              v-if="res.rows.length"
              density="compact"
              class="text-no-wrap"
              style="max-block-size: 260px; overflow: auto;"
            >
              <thead>
                <tr>
                  <th
                    v-for="c in res.columns"
                    :key="c"
                  >
                    {{ columnLabel(c) }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(row, i) in res.rows"
                  :key="i"
                >
                  <td
                    v-for="c in res.columns"
                    :key="c"
                  >
                    {{ row[c] }}
                  </td>
                </tr>
              </tbody>
            </VTable>
            <div
              v-if="res.count > res.rows.length"
              class="text-caption text-medium-emphasis mt-1"
            >
              {{ t('timeExport.previewTruncated', { shown: res.rows.length, n: res.count }) }}
            </div>
          </VCardText>
        </VCard>
      </template>
    </div>

    <template #actions>
      <VBtn
        variant="tonal"
        color="secondary"
        @click="open = false"
      >
        {{ t('common.close') }}
      </VBtn>
      <VBtn
        color="primary"
        :loading="loading"
        :disabled="!datasets.length || !period.from || !period.to"
        data-test="time-export-preview"
        @click="loadPreview"
      >
        {{ t('timeExport.preview') }}
      </VBtn>
    </template>
  </AppDialog>
</template>
