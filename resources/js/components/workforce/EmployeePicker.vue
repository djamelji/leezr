<script setup>
// W-G1 (ADR-641) — sélecteur collaborateur. Recherche côté SERVEUR (debounced), jamais de
// chargement systématique de tous les collaborateurs : adapté aux grandes Company. Le v-model
// expose l'id du collaborateur ; emit `selected` fournit l'objet complet pour le contexte.
import { $api } from '@/utils/api'

const props = defineProps({
  modelValue: { type: [Number, String], default: null },
  label: { type: String, default: '' },
  rules: { type: Array, default: () => [] },
  clearable: { type: Boolean, default: false },
  density: { type: String, default: 'comfortable' },
  statusFilter: { type: String, default: '' }, // ex. 'active'
})

const emit = defineEmits(['update:modelValue', 'selected'])

const { t } = useI18n()

const search = ref('')
const loading = ref(false)
const items = ref([])
const selectedId = computed({
  get: () => props.modelValue,
  set: v => {
    emit('update:modelValue', v)
    emit('selected', items.value.find(e => e.value === v)?.raw ?? null)
  },
})

const toItem = e => ({
  title: `${e.first_name} ${e.last_name}`.trim() + (e.employee_number ? ` · ${e.employee_number}` : ''),
  value: e.id,
  raw: e,
})

const fetchEmployees = async () => {
  loading.value = true
  try {
    const params = new URLSearchParams()
    if (search.value) params.set('search', search.value)
    if (props.statusFilter) params.set('status', props.statusFilter)
    params.set('per_page', '20')

    const data = await $api(`/workforce/employees?${params.toString()}`)

    items.value = (data.data ?? data ?? []).map(toItem)
  } catch {
    items.value = []
  } finally {
    loading.value = false
  }
}

watchDebounced(search, fetchEmployees, { debounce: 300 })
onMounted(fetchEmployees)
</script>

<template>
  <AppAutocomplete
    v-model="selectedId"
    v-model:search="search"
    :items="items"
    :loading="loading"
    :label="label || t('workforce.pickers.employee')"
    :rules="rules"
    :clearable="clearable"
    :density="density"
    :no-filter="true"
    item-title="title"
    item-value="value"
    :no-data-text="loading ? t('common.loading') : t('workforce.pickers.employeeNoResult')"
    prepend-inner-icon="tabler-user"
  />
</template>
