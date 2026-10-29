<script setup>
// W-SIGNATURE-1 (ADR-651) — assistant de demande de signature (document émis → signataires → prestataire/niveau → récap).
import { useSignatureStore } from '@/modules/company/workforce/signature.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({ isDialogVisible: { type: Boolean, default: false } })
const emit = defineEmits(['update:isDialogVisible', 'created'])

const { t } = useI18n()
const store = useSignatureStore()
const { toast } = useAppToast()

const step = ref(0)
const loading = ref(false)
const submitting = ref(false)
const documents = ref([])
const options = ref(null)

const form = ref({
  generated_document_id: null,
  signing_order: 'sequential',
  signature_level: null,
  signers: [
    { role: 'employee', name: '', email: '' },
    { role: 'company_representative', name: '', email: '' },
  ],
})

const reset = () => {
  step.value = 0
  form.value = {
    generated_document_id: null,
    signing_order: options.value?.default_order ?? 'sequential',
    signature_level: options.value?.default_level ?? null,
    signers: [
      { role: 'employee', name: '', email: '' },
      { role: 'company_representative', name: '', email: '' },
    ],
  }
}

watch(() => props.isDialogVisible, async v => {
  if (!v) return
  loading.value = true
  try {
    [documents.value, options.value] = await Promise.all([store.fetchEligibleDocuments(), store.fetchOptions()])
    reset()
  } finally { loading.value = false }
})

const documentItems = computed(() => documents.value.map(d => ({ title: d.label, value: d.id, subtitle: d.subject_type })))
const levelItems = computed(() => (options.value?.levels ?? []).map(l => ({
  title: t(`signature.level.${l.level}`) + (l.certified ? '' : ' · ' + t('signature.level.uncertified')),
  value: l.level,
})))
const roleItems = computed(() => ['employee', 'company_representative', 'external'].map(r => ({ title: t(`signature.role.${r}`), value: r })))
const orderItems = computed(() => ['sequential', 'parallel'].map(o => ({ title: t(`signature.order.${o}`), value: o })))

const addSigner = () => form.value.signers.push({ role: 'external', name: '', email: '' })
const removeSigner = i => form.value.signers.splice(i, 1)

const canNext = computed(() => {
  if (step.value === 0) return !!form.value.generated_document_id
  if (step.value === 1) return form.value.signers.length > 0 && form.value.signers.every(s => s.name && s.email && s.role)
  if (step.value === 2) return !!form.value.signature_level
  return true
})

const selectedDocLabel = computed(() => documents.value.find(d => d.id === form.value.generated_document_id)?.label ?? '—')
const providerCertified = computed(() => options.value?.provider_certified)
const providerDevOnly = computed(() => options.value?.provider_dev_only)

const close = () => emit('update:isDialogVisible', false)

const submit = async () => {
  submitting.value = true
  try {
    const payload = {
      generated_document_id: form.value.generated_document_id,
      signing_order: form.value.signing_order,
      signature_level: form.value.signature_level,
      signers: form.value.signers.map((s, i) => ({ ...s, order: i + 1 })),
    }
    await store.create(payload)
    emit('created')
  } catch (e) {
    toast(e.response?._data?.message || t('common.error'), 'error')
  } finally { submitting.value = false }
}
</script>

<template>
  <VDialog
    :model-value="isDialogVisible"
    max-width="720"
    @update:model-value="emit('update:isDialogVisible', $event)"
  >
    <VCard>
      <VCardItem>
        <VCardTitle>{{ t('signature.wizard.title') }}</VCardTitle>
        <template #append>
          <VBtn
            icon
            variant="text"
            size="small"
            color="default"
            @click="close"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </template>
      </VCardItem>

      <VCardText>
        <VStepper
          v-model="step"
          class="elevation-0 mb-4"
        >
          <VStepperHeader>
            <template
              v-for="(label, i) in [t('signature.wizard.step1'), t('signature.wizard.step2'), t('signature.wizard.step3'), t('signature.wizard.step4')]"
              :key="i"
            >
              <VStepperItem
                :value="i"
                :title="label"
                :complete="step > i"
              />
              <VDivider v-if="i < 3" />
            </template>
          </VStepperHeader>
        </VStepper>

        <!-- Étape 1 : document -->
        <div v-if="step === 0">
          <p class="text-body-2 text-medium-emphasis mb-3">
            {{ t('signature.wizard.pickDocument') }}
          </p>
          <AppSelect
            v-model="form.generated_document_id"
            :items="documentItems"
            :loading="loading"
            :label="t('signature.wizard.document')"
            :no-data-text="t('signature.wizard.noEligible')"
          />
        </div>

        <!-- Étape 2 : signataires + ordre -->
        <div v-else-if="step === 1">
          <AppSelect
            v-model="form.signing_order"
            :items="orderItems"
            :label="t('signature.wizard.order')"
            class="mb-4"
          />
          <VCard
            v-for="(signer, i) in form.signers"
            :key="i"
            variant="outlined"
            class="mb-3"
          >
            <VCardText>
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="text-caption text-medium-emphasis">{{ t('signature.wizard.signer') }} {{ i + 1 }}</span>
                <VBtn
                  v-if="form.signers.length > 1"
                  icon
                  variant="text"
                  size="x-small"
                  color="error"
                  @click="removeSigner(i)"
                >
                  <VIcon icon="tabler-trash" />
                </VBtn>
              </div>
              <VRow>
                <VCol cols="12" sm="4">
                  <AppSelect
                    v-model="signer.role"
                    :items="roleItems"
                    :label="t('signature.wizard.role')"
                    density="compact"
                  />
                </VCol>
                <VCol cols="12" sm="4">
                  <AppTextField
                    v-model="signer.name"
                    :label="t('signature.wizard.name')"
                    density="compact"
                  />
                </VCol>
                <VCol cols="12" sm="4">
                  <AppTextField
                    v-model="signer.email"
                    type="email"
                    :label="t('signature.wizard.email')"
                    density="compact"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
          <VBtn
            variant="tonal"
            size="small"
            prepend-icon="tabler-plus"
            @click="addSigner"
          >
            {{ t('signature.wizard.addSigner') }}
          </VBtn>
        </div>

        <!-- Étape 3 : prestataire + niveau -->
        <div v-else-if="step === 2">
          <VAlert
            :type="providerCertified ? 'info' : 'warning'"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ t('signature.wizard.provider') }} : <strong>{{ options?.provider || '—' }}</strong>
            <template v-if="providerDevOnly"> — {{ t('signature.wizard.devOnlyWarning') }}</template>
            <template v-else-if="!providerCertified"> — {{ t('signature.wizard.uncertifiedWarning') }}</template>
          </VAlert>
          <AppSelect
            v-model="form.signature_level"
            :items="levelItems"
            :label="t('signature.wizard.level')"
            :no-data-text="t('signature.wizard.noLevel')"
          />
        </div>

        <!-- Étape 4 : récap -->
        <div v-else>
          <VList lines="two" class="py-0">
            <VListItem :title="selectedDocLabel" :subtitle="t('signature.wizard.document')" />
            <VListItem :title="t(`signature.order.${form.signing_order}`)" :subtitle="t('signature.wizard.order')" />
            <VListItem :title="form.signature_level ? t(`signature.level.${form.signature_level}`) : '—'" :subtitle="t('signature.wizard.level')" />
            <VListItem :subtitle="t('signature.wizard.signers')">
              <template #title>
                <div class="d-flex flex-wrap gap-1 mt-1">
                  <VChip v-for="(s, i) in form.signers" :key="i" size="small" variant="tonal">
                    {{ i + 1 }}. {{ s.name }} ({{ t(`signature.role.${s.role}`) }})
                  </VChip>
                </div>
              </template>
            </VListItem>
          </VList>
          <VAlert type="info" variant="tonal" density="compact" class="mt-2">
            {{ t('signature.wizard.confirmNotice') }}
          </VAlert>
        </div>
      </VCardText>

      <VCardActions class="px-4 pb-4">
        <VBtn
          v-if="step > 0"
          variant="tonal"
          color="secondary"
          @click="step--"
        >
          {{ t('common.back') }}
        </VBtn>
        <VSpacer />
        <VBtn
          v-if="step < 3"
          :disabled="!canNext"
          @click="step++"
        >
          {{ t('common.next') }}
        </VBtn>
        <VBtn
          v-else
          color="primary"
          :loading="submitting"
          prepend-icon="tabler-send"
          @click="submit"
        >
          {{ t('signature.wizard.confirm') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
