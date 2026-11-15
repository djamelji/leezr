<script setup>
// ADR-796 (L6) — vérification RH d'un dossier d'intégration : valeurs reçues face aux valeurs actuelles
// (décision par valeur, jamais d'écrasement implicite), pièces en vérification dans le DMS, complément,
// validation, révocation, nouveau lien. Les valeurs sensibles ne s'affichent qu'avec le droit adapté.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({ isDialogVisible: { type: Boolean, default: false }, invitationId: { type: Number, default: null } })
const emit = defineEmits(['update:isDialogVisible', 'changed'])
const { t, te } = useI18n()
const docLabel = d => (te(`documents.type.${d.code}`) ? t(`documents.type.${d.code}`) : d.label)
const { toast } = useAppToast()

const file = ref(null)
const decisions = ref({})
const message = ref('')
const confirmContractual = ref(false)
const needsConfirm = ref(false)
const link = ref(null)
const busy = ref(false)

const load = async () => {
  file.value = await $api(`/workforce/onboarding-files/${props.invitationId}`)
  decisions.value = Object.fromEntries(file.value.fields.filter(f => f.decision === 'pending').map(f => [f.code, f.conflict ? 'keep' : 'accept']))
  link.value = null
  needsConfirm.value = false
}
watch(() => [props.isDialogVisible, props.invitationId], ([v]) => { if (v && props.invitationId) load() }, { immediate: true })

const act = async (path, body = {}) => {
  busy.value = true
  try {
    const res = await $api(`/workforce/onboarding-files/${props.invitationId}/${path}`, { method: 'POST', body })
    if (res.url) link.value = res.url
    else await load()
    emit('changed')
  }
  catch (e) {
    if (e?.data?.errors?.confirm_contractual) needsConfirm.value = true
    toast(e?.data?.message || t('onboardingFile.error'), 'error')
  }
  finally { busy.value = false }
}
const display = v => (v === null || v === undefined || v === '' ? '—' : String(v))
</script>

<template>
  <AppDialog
    :model-value="isDialogVisible"
    :title="t('onboardingFile.reviewTitle', { name: file?.name || '' })"
    :subtitle="file ? t(`onboardingFile.status.${file.status}`) : ''"
    :max-width="760"
    @update:model-value="v => emit('update:isDialogVisible', v)"
  >
    <template v-if="file">
      <VAlert
        v-if="link"
        type="success"
        variant="tonal"
        class="mb-4"
      >
        {{ t('onboardingFile.linkReady') }}
        <AppTextField
          :model-value="link"
          readonly
          class="mt-2"
        />
      </VAlert>

      <div class="text-overline">
        {{ t('onboardingFile.fieldsTitle') }}
      </div>
      <div
        v-for="f in file.fields"
        :key="f.code"
        class="onb-review"
        :data-test="`onb-review-${f.code}`"
      >
        <div class="onb-review__main">
          <div class="text-body-1">
            {{ f.label }}<span
              v-if="f.required"
              class="text-error"
            > *</span>
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ t('onboardingFile.provided') }} : {{ display(f.provided) }} · {{ t('onboardingFile.current') }} : {{ display(f.current) }}<template v-if="f.conflict">
              · <span class="text-warning">{{ t('onboardingFile.conflict') }}</span>
            </template>
          </div>
        </div>
        <div
          v-if="decisions[f.code] !== undefined && file.status === 'submitted'"
          class="d-flex gap-1 flex-none"
          role="radiogroup"
          :aria-label="f.label"
        >
          <VBtn
            v-for="choice in ['accept', 'keep']"
            :key="choice"
            size="small"
            role="radio"
            :aria-checked="decisions[f.code] === choice"
            :variant="decisions[f.code] === choice ? 'tonal' : 'text'"
            :color="decisions[f.code] === choice ? 'primary' : undefined"
            @click="decisions[f.code] = choice"
          >
            {{ t(`onboardingFile.${choice}`) }}
          </VBtn>
        </div>
        <AppStatusText
          v-else-if="f.decision"
          :tone="f.decision === 'accepted' ? 'success' : 'info'"
          :text="t(`onboardingFile.decision.${f.decision}`)"
        />
      </div>

      <div class="text-overline mt-4">
        {{ t('onboardingFile.documentsTitle') }}
      </div>
      <div
        v-for="d in file.documents"
        :key="d.code"
        class="onb-review"
      >
        <div class="onb-review__main text-body-1">
          {{ docLabel(d) }}
        </div>
        <AppStatusText
          :tone="d.dms_status === 'approved' ? 'success' : (d.dms_status ? 'warning' : 'info')"
          :text="t(`onboardingFile.dms.${d.dms_status || 'none'}`)"
        />
      </div>
      <RouterLink
        :to="`/company/people/${file.person_ref}?tab=documents`"
        class="text-body-2 d-inline-block mt-2"
      >
        {{ t('onboardingFile.openDocuments') }}
      </RouterLink>

      <template v-if="file.status === 'submitted'">
        <AppTextarea
          v-model="message"
          rows="2"
          class="mt-4"
          :label="t('onboardingFile.changesMessage')"
        />
        <VCheckbox
          v-if="needsConfirm"
          v-model="confirmContractual"
          :label="t('onboardingFile.confirmContractual')"
          hide-details
        />
      </template>
    </template>

    <template #actions>
      <VBtn
        v-if="file && !['validated', 'revoked'].includes(file.status)"
        variant="text"
        color="error"
        :loading="busy"
        @click="act('revoke')"
      >
        {{ t('onboardingFile.revoke') }}
      </VBtn>
      <VBtn
        v-if="file && ['sent', 'in_progress', 'changes_requested', 'expired', 'draft'].includes(file.status)"
        variant="tonal"
        :loading="busy"
        @click="act('link', { days: 7 })"
      >
        {{ t('onboardingFile.newLink') }}
      </VBtn>
      <template v-if="file?.status === 'submitted'">
        <VBtn
          variant="tonal"
          :disabled="!message"
          :loading="busy"
          @click="act('request-changes', { message })"
        >
          {{ t('onboardingFile.requestChanges') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="busy"
          data-test="onb-validate"
          @click="act('validate', { decisions, confirm_contractual: confirmContractual })"
        >
          {{ t('onboardingFile.validate') }}
        </VBtn>
      </template>
    </template>
  </AppDialog>
</template>

<style scoped>
.onb-review { display: flex; align-items: center; gap: 12px; padding-block: 8px; flex-wrap: wrap; }
.onb-review + .onb-review { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.onb-review__main { flex: 1; min-inline-size: 220px; }
.flex-none { flex: none; }
</style>
