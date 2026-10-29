<script setup>
// W-G2 (ADR-642) — assistant d'import de NOUVEAUX collaborateurs (CSV).
// Fichier → correspondance des colonnes → prévisualisation (dry-run) → confirmation → résultat.
// Aucune écriture avant confirmation ; aucun ID technique manipulé par l'utilisateur.
import { useAppToast } from '@/composables/useAppToast'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
})
const emit = defineEmits(['update:modelValue', 'imported'])

const { t } = useI18n()
const { toast } = useAppToast()
const store = useEmployeesStore()

const isOpen = computed({
  get: () => props.modelValue,
  set: v => emit('update:modelValue', v),
})

const step = ref(1)
const loading = ref(false)
const fileName = ref('')
const content = ref('')
const headers = ref([])
const mapping = ref({})
const preview = ref(null)
const result = ref(null)

// Champs cibles du modèle Employee (jamais de propriétés internes arbitraires).
const TARGET_FIELDS = [
  { key: 'first_name', required: true },
  { key: 'last_name', required: true },
  { key: 'hire_date', required: true },
  { key: 'employee_number', required: false },
  { key: 'email', required: false },
  { key: 'phone', required: false },
  { key: 'status', required: false },
  { key: 'department', required: false },
  { key: 'job_role', required: false },
  { key: 'manager', required: false },
]

const reset = () => {
  step.value = 1
  fileName.value = ''
  content.value = ''
  headers.value = []
  mapping.value = {}
  preview.value = null
  result.value = null
}

watch(isOpen, v => { if (!v) reset() })

const detectDelimiter = line => {
  const counts = { ',': (line.match(/,/g) || []).length, ';': (line.match(/;/g) || []).length, '\t': (line.match(/\t/g) || []).length }

  return Object.entries(counts).sort((a, b) => b[1] - a[1])[0][0]
}

const onFile = async e => {
  const file = e.target.files?.[0]
  if (!file) return
  fileName.value = file.name
  content.value = await file.text()
  const firstLine = content.value.replace(/^﻿/, '').split(/\r\n|\r|\n/)[0] || ''
  const delim = detectDelimiter(firstLine)

  headers.value = firstLine.split(delim).map(h => h.trim().replace(/^"|"$/g, ''))

  // Auto-correspondance par nom de colonne.
  const auto = {}
  for (const f of TARGET_FIELDS) {
    const match = headers.value.find(h => h.toLowerCase().replace(/[\s_-]/g, '') === f.key.replace(/_/g, ''))
    if (match) auto[f.key] = match
  }
  mapping.value = auto
}

const headerOptions = computed(() => headers.value.map(h => ({ title: h, value: h })))

const mappingValid = computed(() => TARGET_FIELDS.filter(f => f.required).every(f => !!mapping.value[f.key]))

const runPreview = async () => {
  loading.value = true
  try {
    preview.value = await store.importPreview({ content: content.value, mapping: mapping.value })
    step.value = 3
  } catch (err) {
    toast(err.response?.data?.message || t('common.error'), 'error')
  } finally {
    loading.value = false
  }
}

const canConfirm = computed(() => (preview.value?.counts?.create || 0) > 0)

const runApply = async () => {
  loading.value = true
  try {
    result.value = await store.importApply({ content: content.value, mapping: mapping.value })
    step.value = 4
    emit('imported')
  } catch (err) {
    toast(err.response?.data?.message || t('common.error'), 'error')
  } finally {
    loading.value = false
  }
}

const downloadTemplate = async () => {
  const blob = await store.downloadImportTemplate()
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')

  a.href = url
  a.download = 'modele-import-collaborateurs.csv'
  a.click()
  setTimeout(() => URL.revokeObjectURL(url), 10000)
}

const actionColor = a => ({ create: 'success', skip_duplicate: 'secondary', conflict: 'warning', invalid: 'error' }[a] || 'secondary')
</script>

<template>
  <VNavigationDrawer
    v-model="isOpen"
    temporary
    location="end"
    width="640"
  >
    <div class="d-flex align-center pa-4">
      <h5 class="text-h5 flex-grow-1">
        {{ t('employees.import.title') }}
      </h5>
      <VBtn
        icon
        variant="text"
        size="small"
        @click="isOpen = false"
      >
        <VIcon icon="tabler-x" />
      </VBtn>
    </div>
    <VDivider />

    <div class="pa-4">
      <VStepper
        v-model="step"
        :items="[t('employees.import.steps.file'), t('employees.import.steps.mapping'), t('employees.import.steps.preview'), t('employees.import.steps.result')]"
        flat
        hide-actions
        class="mb-4"
      />

      <!-- 1. Fichier -->
      <div v-if="step === 1">
        <VAlert
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ t('employees.import.intro') }}
        </VAlert>
        <VBtn
          variant="tonal"
          size="small"
          prepend-icon="tabler-download"
          class="mb-4"
          @click="downloadTemplate"
        >
          {{ t('employees.import.downloadTemplate') }}
        </VBtn>
        <VFileInput
          :label="t('employees.import.chooseFile')"
          accept=".csv,text/csv"
          prepend-icon="tabler-file-spreadsheet"
          @change="onFile"
        />
        <div class="d-flex justify-end mt-4">
          <VBtn
            color="primary"
            :disabled="!headers.length"
            @click="step = 2"
          >
            {{ t('common.next') }}
          </VBtn>
        </div>
      </div>

      <!-- 2. Correspondance -->
      <div v-else-if="step === 2">
        <p class="text-body-2 text-medium-emphasis mb-4">
          {{ t('employees.import.mappingHelp') }}
        </p>
        <VRow>
          <VCol
            v-for="f in TARGET_FIELDS"
            :key="f.key"
            cols="12"
            sm="6"
          >
            <AppSelect
              v-model="mapping[f.key]"
              :items="headerOptions"
              :label="t('employees.import.fields.' + f.key) + (f.required ? ' *' : '')"
              clearable
              density="compact"
            />
          </VCol>
        </VRow>
        <div class="d-flex justify-space-between mt-4">
          <VBtn
            variant="outlined"
            @click="step = 1"
          >
            {{ t('common.previous') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="loading"
            :disabled="!mappingValid"
            @click="runPreview"
          >
            {{ t('employees.import.runPreview') }}
          </VBtn>
        </div>
      </div>

      <!-- 3. Prévisualisation (dry-run) -->
      <div v-else-if="step === 3 && preview">
        <div class="d-flex gap-2 flex-wrap mb-4">
          <VChip color="success" variant="tonal" size="small">{{ t('employees.import.counts.create') }}: {{ preview.counts.create }}</VChip>
          <VChip color="secondary" variant="tonal" size="small">{{ t('employees.import.counts.skip_duplicate') }}: {{ preview.counts.skip_duplicate }}</VChip>
          <VChip color="warning" variant="tonal" size="small">{{ t('employees.import.counts.conflict') }}: {{ preview.counts.conflict }}</VChip>
          <VChip color="error" variant="tonal" size="small">{{ t('employees.import.counts.invalid') }}: {{ preview.counts.invalid }}</VChip>
        </div>
        <VAlert
          v-if="!canConfirm"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ t('employees.import.nothingToCreate') }}
        </VAlert>
        <VTable
          density="compact"
          class="text-no-wrap"
          style="max-block-size: 320px; overflow-y: auto;"
        >
          <thead>
            <tr>
              <th>{{ t('employees.import.line') }}</th>
              <th>{{ t('employees.import.fields.first_name') }}</th>
              <th>{{ t('employees.import.fields.last_name') }}</th>
              <th>{{ t('employees.import.action') }}</th>
              <th>{{ t('employees.import.details') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in preview.rows"
              :key="row.line"
            >
              <td>{{ row.line }}</td>
              <td>{{ row.data.first_name }}</td>
              <td>{{ row.data.last_name }}</td>
              <td>
                <VChip
                  :color="actionColor(row.action)"
                  size="x-small"
                  variant="tonal"
                >
                  {{ t('employees.import.counts.' + row.action) }}
                </VChip>
              </td>
              <td class="text-caption">
                <div
                  v-for="(e, i) in row.errors"
                  :key="'e' + i"
                  class="text-error"
                >
                  {{ e }}
                </div>
                <div
                  v-for="(w, i) in row.warnings"
                  :key="'w' + i"
                  class="text-warning"
                >
                  {{ w }}
                </div>
              </td>
            </tr>
          </tbody>
        </VTable>
        <div class="d-flex justify-space-between mt-4">
          <VBtn
            variant="outlined"
            @click="step = 2"
          >
            {{ t('common.previous') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="loading"
            :disabled="!canConfirm"
            prepend-icon="tabler-database-import"
            @click="runApply"
          >
            {{ t('employees.import.confirm', { count: preview.counts.create }) }}
          </VBtn>
        </div>
      </div>

      <!-- 4. Résultat -->
      <div v-else-if="step === 4 && result">
        <VAlert
          type="success"
          variant="tonal"
          class="mb-4"
        >
          {{ t('employees.import.resultSummary', { created: result.created, skipped: result.skipped, failed: result.failed }) }}
        </VAlert>
        <VTable
          density="compact"
          class="text-no-wrap"
          style="max-block-size: 320px; overflow-y: auto;"
        >
          <thead>
            <tr>
              <th>{{ t('employees.import.line') }}</th>
              <th>{{ t('employees.import.action') }}</th>
              <th>{{ t('employees.import.details') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in result.rows"
              :key="row.line"
            >
              <td>{{ row.line }}</td>
              <td>{{ row.status }}</td>
              <td class="text-caption text-error">
                {{ (row.errors || []).join(' · ') }}
              </td>
            </tr>
          </tbody>
        </VTable>
        <div class="d-flex justify-end mt-4">
          <VBtn
            color="primary"
            @click="isOpen = false"
          >
            {{ t('common.close') }}
          </VBtn>
        </div>
      </div>
    </div>
  </VNavigationDrawer>
</template>
