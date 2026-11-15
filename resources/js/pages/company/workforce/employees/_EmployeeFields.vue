<script setup>
// ADR-795 (L5) — informations complémentaires d'une fiche salarié, AVEC OU SANS compte. Réutilise le
// rendu des champs dynamiques existant (DynamicFormRenderer) ; les statuts, la propriété des données,
// le masquage des valeurs sensibles et la protection contractuelle sont calculés par le serveur.
import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  employeeId: { type: Number, required: true },
  canEdit: { type: Boolean, default: false },
})

const { t } = useI18n()
const { toast } = useAppToast()
const fields = ref([])
const hasAccount = ref(false)
const form = ref({})
const loading = ref(true)
const saving = ref(false)
const confirmOpen = ref(false)

const load = async () => {
  loading.value = true
  try {
    const res = await $api(`/workforce/employees/${props.employeeId}/fields`)

    fields.value = res.fields
    hasAccount.value = res.has_account
    form.value = Object.fromEntries(res.fields.filter(f => f.editable).map(f => [f.code, f.value]))
  }
  catch { fields.value = [] }
  finally { loading.value = false }
}
watch(() => props.employeeId, load, { immediate: true })

const editable = computed(() => fields.value.filter(f => f.editable && props.canEdit))
const readOnly = computed(() => fields.value.filter(f => !(f.editable && props.canEdit)))
const changes = computed(() => Object.fromEntries(Object.entries(form.value).filter(([code, v]) => {
  const f = fields.value.find(x => x.code === code)

  return f && f.editable && (v ?? '') !== (f.value ?? '')
})))
const needsConfirm = computed(() => Object.keys(changes.value).some(code => fields.value.find(f => f.code === code)?.contract_protected))
const reviewCount = computed(() => fields.value.filter(f => f.status === 'needs_review').length)

const save = async (confirmContractual = false) => {
  if (needsConfirm.value && !confirmContractual) {
    confirmOpen.value = true

    return
  }
  saving.value = true
  try {
    const res = await $api(`/workforce/employees/${props.employeeId}/fields`, { method: 'PUT', body: { fields: changes.value, confirm_contractual: confirmContractual } })

    fields.value = res.fields
    form.value = Object.fromEntries(res.fields.filter(f => f.editable).map(f => [f.code, f.value]))
    confirmOpen.value = false
    toast(t('employeeFields.saved'), 'success')
  }
  catch (e) { toast(e?.data?.message || t('employeeFields.error'), 'error') }
  finally { saving.value = false }
}

const display = f => (f.masked ? t('employeeFields.masked') : (f.value === null || f.value === '' ? t('employeeFields.empty') : (f.type === 'boolean' ? t(f.value ? 'common.yes' : 'common.no') : String(f.value))))
const statusText = f => (f.status === 'needs_review'
  ? t(f.source === 'legacy_account' ? 'employeeFields.legacy' : 'employeeFields.toReconcile')
  : f.managed_by_account ? t('employeeFields.fromAccount') : '')
</script>

<template>
  <div
    class="mt-6"
    data-test="employee-fields"
  >
    <div class="text-subtitle-1 font-weight-medium mb-1">
      {{ t('employeeFields.title') }}
    </div>
    <p class="text-body-2 text-medium-emphasis mb-4">
      {{ hasAccount ? t('employeeFields.hintAccount') : t('employeeFields.hintNoAccount') }}
    </p>
    <VSkeletonLoader
      v-if="loading"
      type="article"
    />
    <template v-else>
      <VAlert
        v-if="reviewCount"
        type="warning"
        variant="tonal"
        density="compact"
        class="mb-4"
        data-test="employee-fields-review"
      >
        {{ t('employeeFields.reviewNotice', { n: reviewCount }) }}
      </VAlert>
      <p
        v-if="!fields.length"
        class="text-body-2 text-medium-emphasis"
      >
        {{ t('employeeFields.none') }}
      </p>
      <DynamicFormRenderer
        v-if="editable.length"
        v-model="form"
        :fields="editable"
      />
      <div
        v-if="editable.length"
        class="d-flex justify-end mt-2"
      >
        <VBtn
          color="primary"
          :disabled="!Object.keys(changes).length"
          :loading="saving"
          data-test="employee-fields-save"
          @click="save()"
        >
          {{ t('common.save') }}
        </VBtn>
      </div>
      <VList
        v-if="readOnly.length"
        lines="two"
        class="py-0 mt-2"
      >
        <VListItem
          v-for="f in readOnly"
          :key="f.code"
          :title="display(f)"
          :subtitle="[f.label, statusText(f)].filter(Boolean).join(' · ')"
          :data-test="`employee-field-${f.code}`"
        />
      </VList>
    </template>

    <AppDialog
      v-model="confirmOpen"
      :title="t('employeeFields.confirmTitle')"
      :max-width="520"
    >
      <p class="mb-0">
        {{ t('employeeFields.confirmText') }}
      </p>
      <template #actions>
        <VBtn
          variant="text"
          @click="confirmOpen = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="saving"
          data-test="employee-fields-confirm"
          @click="save(true)"
        >
          {{ t('employeeFields.confirmBtn') }}
        </VBtn>
      </template>
    </AppDialog>
  </div>
</template>
