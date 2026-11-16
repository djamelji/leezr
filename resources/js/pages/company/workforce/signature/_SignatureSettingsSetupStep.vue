<script setup>
// ADR-805 — étape « Réglages de signature » de l'assistant : RÉCAPITULATIF des réglages RÉSOLUS (prestataire,
// niveau, validité) via l'API existante ; la modification reste dans Réglages → Signature électronique.
// Sous-composant — pas de definePage.
import { $api } from '@/utils/api'

const { t } = useI18n()
const o = ref(null)
const loading = ref(true)
const denied = ref(false)
onMounted(async () => {
  try { o.value = await $api('/workforce/signature/options') }
  catch (e) { denied.value = e?.status === 403 || e?.response?.status === 403 }
  finally { loading.value = false }
})
</script>

<template>
  <div data-test="signature-settings-setup">
    <p class="text-body-2 text-medium-emphasis mb-3">
      {{ t('moduleSetup.signatureIntro') }}
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
    <template v-else-if="o">
      <VAlert
        v-if="!o.provider"
        type="warning"
        variant="tonal"
        density="compact"
        class="mb-3"
        data-test="signature-no-provider"
      >
        {{ t('moduleSetup.signatureNoProvider') }}
      </VAlert>
      <VAlert
        v-else-if="o.provider_dev_only"
        type="warning"
        variant="tonal"
        density="compact"
        class="mb-3"
        data-test="signature-dev-provider"
      >
        {{ t('moduleSetup.signatureDevProvider') }}
      </VAlert>
      <VTable density="compact">
        <tbody>
          <tr>
            <td>{{ t('moduleSetup.signatureProvider') }}</td>
            <td class="text-end">
              {{ o.provider || '—' }}
            </td>
          </tr>
          <tr>
            <td>{{ t('moduleSetup.signatureLevel') }}</td>
            <td class="text-end">
              {{ o.default_level }}
            </td>
          </tr>
          <tr v-if="o.validity_days">
            <td>{{ t('moduleSetup.signatureValidity') }}</td>
            <td class="text-end">
              {{ t('moduleSetup.days', { n: o.validity_days }) }}
            </td>
          </tr>
        </tbody>
      </VTable>
    </template>
    <VBtn
      class="mt-3"
      variant="text"
      prepend-icon="tabler-external-link"
      :to="{ name: 'company-workforce-settings-tab', params: { tab: 'signature' } }"
      data-test="signature-settings-manage"
    >
      {{ t('moduleSetup.signatureManage') }}
    </VBtn>
  </div>
</template>
