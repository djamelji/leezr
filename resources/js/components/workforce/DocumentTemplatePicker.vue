<script setup>
// W-G1 (ADR-641) — sélecteur de modèle documentaire. Fin de la saisie d'un code template à
// l'aveugle : propose UNIQUEMENT les modèles disponibles pour la Company (surcharge Company >
// plateforme), filtrés par type de sujet. v-model = code du modèle ; emit `selected` = objet complet.
import { useWorkforceDocumentsStore } from '@/modules/company/workforce/workforce-documents.store'

const props = defineProps({
  modelValue: { type: String, default: '' },
  subjectType: { type: String, default: '' }, // filtre : ne propose jamais un modèle incompatible
  label: { type: String, default: '' },
  rules: { type: Array, default: () => [] },
  density: { type: String, default: 'comfortable' },
})

const emit = defineEmits(['update:modelValue', 'selected'])

const { t } = useI18n()
const store = useWorkforceDocumentsStore()

const loading = ref(false)
const templates = ref([])

const items = computed(() => templates.value.map(tpl => ({
  title: tpl.name,
  subtitle: tpl.source === 'company' ? t('workforceDocuments.templatePicker.companyTemplate') : t('workforceDocuments.templatePicker.platformTemplate'),
  value: tpl.code,
  raw: tpl,
})))

const selectedCode = computed({
  get: () => props.modelValue,
  set: v => {
    emit('update:modelValue', v)
    emit('selected', templates.value.find(tpl => tpl.code === v) ?? null)
  },
})

const load = async () => {
  loading.value = true
  try {
    templates.value = await store.fetchTemplates(props.subjectType || undefined)
    // Réinitialise la sélection si le modèle courant n'est plus compatible.
    if (props.modelValue && ! templates.value.some(tpl => tpl.code === props.modelValue))
      selectedCode.value = null
  } catch {
    templates.value = []
  } finally {
    loading.value = false
  }
}

watch(() => props.subjectType, load)
onMounted(load)
</script>

<template>
  <AppSelect
    v-model="selectedCode"
    :items="items"
    :loading="loading"
    :label="label || t('workforceDocuments.templatePicker.label')"
    :rules="rules"
    :density="density"
    item-title="title"
    item-value="value"
    :no-data-text="loading ? t('common.loading') : t('workforceDocuments.templatePicker.noResult')"
    prepend-inner-icon="tabler-file-text"
  >
    <template #item="{ props: itemProps, item }">
      <VListItem
        v-bind="itemProps"
        :subtitle="item.raw.subtitle"
      />
    </template>
  </AppSelect>
</template>
