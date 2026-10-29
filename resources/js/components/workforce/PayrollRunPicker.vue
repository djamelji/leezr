<script setup>
// W-G1 (ADR-641) — sélecteur de traitement de paie. Remplace la saisie d'un ID technique de run
// par un choix contextualisé : période, statut, nombre de salariés. Options issues des runs
// réels et autorisés de la Company (endpoint existant /workforce/payroll).
import { $api } from '@/utils/api'

const props = defineProps({
  modelValue: { type: [Number, String], default: null },
  label: { type: String, default: '' },
  rules: { type: Array, default: () => [] },
  statusFilter: { type: String, default: '' }, // ex. 'validated' pour la paie officielle / DSN
  density: { type: String, default: 'comfortable' },
  clearable: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'selected'])

const { t, locale } = useI18n()

const loading = ref(false)
const runs = ref([])

const fmtDate = d => d ? new Date(d).toLocaleDateString(locale.value, { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const items = computed(() => runs.value.map(r => ({
  title: `${fmtDate(r.period_start)} → ${fmtDate(r.period_end)}`,
  subtitle: `${t(`workforce.statuses.payrollRun.${r.status}`, r.status)} · ${r.employee_count ?? 0} ${t('workforce.pickers.employeesShort')}`,
  value: r.id,
  raw: r,
})))

const selectedId = computed({
  get: () => props.modelValue,
  set: v => {
    emit('update:modelValue', v)
    emit('selected', runs.value.find(r => r.id === v) ?? null)
  },
})

const fetchRuns = async () => {
  loading.value = true
  try {
    const params = new URLSearchParams()
    if (props.statusFilter) params.set('status', props.statusFilter)
    params.set('per_page', '50')

    const data = await $api(`/workforce/payroll?${params.toString()}`)

    runs.value = data.data ?? []
  } catch {
    runs.value = []
  } finally {
    loading.value = false
  }
}

onMounted(fetchRuns)
</script>

<template>
  <AppSelect
    v-model="selectedId"
    :items="items"
    :loading="loading"
    :label="label || t('workforce.pickers.payrollRun')"
    :rules="rules"
    :density="density"
    :clearable="clearable"
    item-title="title"
    item-value="value"
    :no-data-text="loading ? t('common.loading') : t('workforce.pickers.payrollRunNoResult')"
    prepend-inner-icon="tabler-calculator"
  >
    <template #item="{ props: itemProps, item }">
      <VListItem
        v-bind="itemProps"
        :subtitle="item.raw.subtitle"
      />
    </template>
  </AppSelect>
</template>
