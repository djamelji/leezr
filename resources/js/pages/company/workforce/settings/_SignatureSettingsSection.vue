<script setup>
// W-SIGNATURE-1 (ADR-651) — Paramètres RH → Signature électronique.
// Prestataire, niveau par défaut, validité, relances, ordre, notifications, copie signée au collaborateur.
import { useSignatureStore } from '@/modules/company/workforce/signature.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'

const { t } = useI18n()
const store = useSignatureStore()
const { toast } = useAppToast()
const { can } = useCan()

const loading = ref(true)
const saving = ref(false)
const form = ref(null)
const options = ref(null)

onMounted(async () => {
  try {
    [form.value, options.value] = await Promise.all([store.getSettings(), store.fetchOptions()])
  } finally { loading.value = false }
})

const levelItems = computed(() => (options.value?.levels ?? []).map(l => ({
  title: t(`signature.level.${l.level}`) + (l.certified ? '' : ' · ' + t('signature.level.uncertified')),
  value: l.level,
})))
const orderItems = computed(() => ['sequential', 'parallel'].map(o => ({ title: t(`signature.order.${o}`), value: o })))

// W-SIGNATURE-8 — mode de signature (vocabulaire métier). Les modes indisponibles sont désactivés
// avec leur motif ; la Company ne peut pas sélectionner un mode non autorisé par Platform/capacités.
const modeItems = computed(() => (form.value?.available_formats ?? []).map(m => ({
  title: t(`signatureFormat.${m.mode}.label`) + (m.available ? '' : ' · ' + t(`signatureFormat.reason.${m.reason}`)),
  value: m.mode,
  props: { disabled: !m.available, subtitle: t(`signatureFormat.${m.mode}.desc`) },
})))
const selectedMode = computed(() => (form.value?.available_formats ?? []).find(m => m.mode === form.value?.default_format))

const save = async () => {
  saving.value = true
  try {
    form.value = await store.updateSettings(form.value)
    toast(t('signatureSettings.saved'), 'success')
  } catch (e) { toast(e.response?._data?.message || t('common.error'), 'error') } finally { saving.value = false }
}
</script>

<template>
  <div>
    <p class="text-body-2 text-medium-emphasis mb-4">
      {{ t('signatureSettings.intro') }}
    </p>

    <VProgressLinear v-if="loading" indeterminate color="primary" />

    <template v-else-if="form">
      <VAlert
        v-if="options?.provider_dev_only"
        type="warning"
        variant="tonal"
        density="compact"
        class="mb-4"
      >
        {{ t('signatureSettings.devOnlyNotice') }}
      </VAlert>

      <VRow>
        <VCol cols="12">
          <AppSelect
            v-model="form.default_format"
            :items="modeItems"
            :label="t('signatureSettings.defaultFormat')"
            :disabled="!can('workforce_signature.admin')"
          />
          <VAlert
            v-if="selectedMode"
            :type="selectedMode.mode === 'personal_digital' ? 'success' : 'info'"
            variant="tonal"
            density="compact"
            class="mt-1 mb-2"
          >
            <div class="text-body-2">
              {{ t(`signatureFormat.${selectedMode.mode}.desc`) }}
            </div>
            <div class="text-caption mt-1">
              {{ t('signatureFormat.trustNotice') }}
            </div>
          </VAlert>
        </VCol>
        <VCol cols="12" md="6">
          <AppSelect
            v-model="form.default_level"
            :items="levelItems"
            :label="t('signatureSettings.defaultLevel')"
            :disabled="!can('workforce_signature.admin')"
          />
        </VCol>
        <VCol cols="12" md="6">
          <AppSelect
            v-model="form.default_order"
            :items="orderItems"
            :label="t('signatureSettings.defaultOrder')"
            :disabled="!can('workforce_signature.admin')"
          />
        </VCol>
        <VCol cols="12" md="6">
          <AppTextField
            v-model.number="form.validity_days"
            type="number"
            :label="t('signatureSettings.validityDays')"
            :disabled="!can('workforce_signature.admin')"
          />
        </VCol>
        <VCol cols="12" md="6">
          <AppTextField
            v-model.number="form.reminder_days"
            type="number"
            :label="t('signatureSettings.reminderDays')"
            :hint="t('signatureSettings.reminderHint')"
            persistent-hint
            :disabled="!can('workforce_signature.admin')"
          />
        </VCol>
      </VRow>

      <VDivider class="my-4" />

      <VSwitch
        v-model="form.notify_enabled"
        :label="t('signatureSettings.notifyEnabled')"
        :disabled="!can('workforce_signature.admin')"
        class="mb-2"
      />
      <VSwitch
        v-model="form.send_signed_copy_to_employee"
        :label="t('signatureSettings.sendSignedCopy')"
        :disabled="!can('workforce_signature.admin')"
      />
      <p class="text-caption text-medium-emphasis mb-4">
        {{ t('signatureSettings.signedCopyNotice') }}
      </p>

      <VBtn
        color="primary"
        :loading="saving"
        :disabled="!can('workforce_signature.admin')"
        @click="save"
      >
        {{ t('common.save') }}
      </VBtn>
    </template>
  </div>
</template>
