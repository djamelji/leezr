<script setup>
// ADR-805 — étape « Modèles de contrat » de l'assistant : RÉCAPITULATIF des modèles publiés (API existante) ;
// la gestion reste dans Documents → Modèles. Aucun modèle n'est créé ni copié ici. Sous-composant — pas de definePage.
import { $api } from '@/utils/api'

const { t } = useI18n()
const templates = ref([])
const loading = ref(true)
const denied = ref(false)
onMounted(async () => {
  try { const d = await $api('/workforce/contract/templates'); templates.value = d.data ?? d.templates ?? d }
  catch (e) { denied.value = e?.status === 403 || e?.response?.status === 403 }
  finally { loading.value = false }
})
const published = computed(() => (Array.isArray(templates.value) ? templates.value : []).filter(x => x.status === 'published' && x.is_active))
</script>

<template>
  <div data-test="contract-templates-setup">
    <p class="text-body-2 text-medium-emphasis mb-3">
      {{ t('moduleSetup.contractTemplatesIntro') }}
    </p>
    <VProgressLinear
      v-if="loading"
      indeterminate
    />
    <VAlert
      v-else-if="denied"
      type="info"
      variant="tonal"
      density="compact"
    >
      {{ t('moduleSetup.summaryNotAllowed') }}
    </VAlert>
    <VAlert
      v-else-if="!published.length"
      type="warning"
      variant="tonal"
      density="compact"
    >
      {{ t('moduleSetup.contractTemplatesNone') }}
    </VAlert>
    <VTable
      v-else
      density="compact"
    >
      <tbody>
        <tr
          v-for="tpl in published"
          :key="tpl.id"
        >
          <td>{{ tpl.name }} <span class="text-medium-emphasis">v{{ tpl.version }}</span></td>
          <td class="text-end">
            <VChip
              v-if="tpl.is_default"
              size="small"
              color="primary"
              class="me-1"
            >
              {{ t('moduleSetup.defaultTemplate') }}
            </VChip>
            <VChip
              size="small"
              :color="tpl.legal_status === 'example_unvalidated' ? 'warning' : undefined"
            >
              {{ tpl.source === 'platform' ? t('moduleSetup.platformTemplate') : t('moduleSetup.companyTemplate') }}
              <template v-if="tpl.legal_status === 'example_unvalidated'">
                · {{ t('moduleSetup.unvalidatedExample') }}
              </template>
            </VChip>
          </td>
        </tr>
      </tbody>
    </VTable>
    <VBtn
      class="mt-3"
      variant="text"
      prepend-icon="tabler-external-link"
      :to="{ name: 'company-documents-tab', params: { tab: 'templates' } }"
      data-test="contract-templates-manage"
    >
      {{ t('moduleSetup.contractTemplatesManage') }}
    </VBtn>
  </div>
</template>
