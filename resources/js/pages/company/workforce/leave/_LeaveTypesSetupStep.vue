<script setup>
// ADR-804 — étape « Types de congés » de l'assistant : RÉCAPITULATIF (types actifs, validation requise ou non)
// lu sur l'API existante. La gestion reste dans l'écran Congés → Configuration (aucun formulaire dupliqué).
// Sous-composant — pas de definePage.
import { $api } from '@/utils/api'

const { t } = useI18n()
const types = ref([])
const loading = ref(true)
onMounted(async () => {
  try { const d = await $api('/workforce/leaves/types?all=1'); types.value = d.data ?? d }
  finally { loading.value = false }
})
const enabled = computed(() => types.value.filter(x => x.enabled))
</script>

<template>
  <div data-test="leave-types-setup">
    <p class="text-body-2 text-medium-emphasis mb-3">
      {{ t('moduleSetup.leaveTypesIntro') }}
    </p>
    <VProgressLinear
      v-if="loading"
      indeterminate
    />
    <VAlert
      v-else-if="!enabled.length"
      type="warning"
      variant="tonal"
      density="compact"
    >
      {{ t('moduleSetup.leaveTypesNone') }}
    </VAlert>
    <VTable
      v-else
      density="compact"
    >
      <tbody>
        <tr
          v-for="lt in enabled"
          :key="lt.id"
        >
          <td>{{ lt.name }}</td>
          <td class="text-end">
            <VChip
              size="small"
              :color="lt.requires_approval ? 'info' : undefined"
            >
              {{ lt.requires_approval ? t('moduleSetup.requiresApproval') : t('moduleSetup.noApproval') }}
            </VChip>
          </td>
        </tr>
      </tbody>
    </VTable>
    <VBtn
      class="mt-3"
      variant="text"
      prepend-icon="tabler-external-link"
      to="/company/workforce/leave?tab=config"
      data-test="leave-types-manage"
    >
      {{ t('moduleSetup.leaveTypesManage') }}
    </VBtn>
  </div>
</template>
