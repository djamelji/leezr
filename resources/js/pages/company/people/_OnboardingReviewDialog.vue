<script setup>
// ADR-796 (L6) / ADR-796.1 — suivi RH d'un dossier d'intégration : valeurs reçues face aux valeurs actuelles
// (décision par valeur, jamais d'écrasement implicite), pièces (analyse antivirus puis vérification DMS),
// envoi par e-mail ou lien à copier, adresse de la recrue, révision des éléments demandés (prévisualisée),
// complément, validation, révocation. Les valeurs sensibles ne s'affichent qu'avec le droit adapté.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({ isDialogVisible: { type: Boolean, default: false }, invitationId: { type: Number, default: null } })
const emit = defineEmits(['update:isDialogVisible', 'changed'])
const { t, te } = useI18n()
const docLabel = d => (te(`documents.type.${d.code}`) ? t(`documents.type.${d.code}`) : d.label)
const itemLabel = i => (i.kind === 'document' ? docLabel(i) : i.label)
const { toast } = useAppToast()

const file = ref(null)
const decisions = ref({})
const message = ref('')
const confirmContractual = ref(false)
const needsConfirm = ref(false)
const link = ref(null)
const busy = ref(false)
const email = ref('')
const editingEmail = ref(false)

// Révision des éléments demandés
const revising = ref(false)
const catalog = ref([])
const draft = ref({}) // « kind:code » → { required }
const preview = ref(null)
const revisionMessage = ref('')

const OPEN = ['draft', 'sent', 'in_progress', 'changes_requested', 'expired']
const isOpen = computed(() => file.value && OPEN.includes(file.value.status))
const canRevise = computed(() => file.value && [...OPEN, 'submitted'].includes(file.value.status) && file.value.status !== 'expired')

const load = async () => {
  file.value = await $api(`/workforce/onboarding-files/${props.invitationId}`)
  decisions.value = Object.fromEntries(file.value.fields.filter(f => f.decision === 'pending').map(f => [f.code, f.conflict ? 'keep' : 'accept']))
  email.value = ''
  editingEmail.value = false
  needsConfirm.value = false
}
watch(() => [props.isDialogVisible, props.invitationId], ([v]) => {
  if (v && props.invitationId) {
    link.value = null
    revising.value = false
    load()
  }
}, { immediate: true })

const call = async (path, body = {}, method = 'POST') => {
  busy.value = true
  try {
    const res = await $api(`/workforce/onboarding-files/${props.invitationId}/${path}`, { method, body })

    emit('changed')

    return res
  }
  catch (e) {
    if (e?.data?.errors?.confirm_contractual) needsConfirm.value = true
    if (e?.data?.file) file.value = e.data.file
    toast(e?.data?.message || e?.data?.error || t('onboardingFile.error'), 'error')

    return null
  }
  finally { busy.value = false }
}

const act = async (path, body = {}) => {
  const res = await call(path, body)
  if (res) await load()
}
const newLink = async () => {
  const res = await call('link', { days: 7 })
  if (res?.url) {
    link.value = res.url
    await load()
  }
}
const sendMail = async () => {
  const res = await call('send', { days: 7 })
  if (res?.delivered) {
    link.value = null
    toast(t('onboardingFile.mailSent'), 'success')
  }
  await load()
}
const saveEmail = async () => {
  const res = await call('contact', { candidate_email: email.value }, 'PUT')
  if (res) {
    file.value = res
    link.value = null
    editingEmail.value = false
    toast(t('onboardingFile.emailChanged'), 'success')
  }
}
const copy = async () => {
  await navigator.clipboard.writeText(link.value)
  toast(t('onboardingFile.copied'), 'success')
}

// ── Révision ──
const startRevision = async () => {
  const q = file.value.job_role_id ? `?job_role_id=${file.value.job_role_id}` : ''
  const res = await $api(`/workforce/onboarding-files/proposal${q}`)
  const current = Object.fromEntries(file.value.items.map(i => [`${i.kind}:${i.code}`, { required: i.required }]))
  const known = new Set(res.items.map(i => `${i.kind}:${i.code}`))

  // Éléments déjà demandés mais plus proposés (règle retirée depuis) : visibles pour pouvoir les garder.
  catalog.value = [...res.items, ...file.value.items.filter(i => !known.has(`${i.kind}:${i.code}`)).map(i => ({ ...i, sources: [], required_allowed: i.required }))]
  draft.value = current
  preview.value = null
  revisionMessage.value = ''
  revising.value = true
}
const toggleItem = item => {
  const key = `${item.kind}:${item.code}`
  const next = { ...draft.value }

  next[key] ? delete next[key] : (next[key] = { required: !!item.suggested_required && !!item.required_allowed })
  draft.value = next
  preview.value = null
}
const draftItems = () => Object.entries(draft.value).map(([k, v]) => ({ kind: k.split(':')[0], code: k.split(':')[1], required: v.required }))
const runPreview = async () => {
  preview.value = await call('items/preview', { items: draftItems() })
}
const confirmRevision = async () => {
  const res = await call('items', { items: draftItems(), message: revisionMessage.value || null }, 'PUT')
  if (res) {
    file.value = res
    revising.value = false
    toast(t('onboardingFile.revised', { version: res.snapshot_version }), 'success')
  }
}
const isEmptyPreview = computed(() => preview.value && !preview.value.added.length && !preview.value.removed.length && !preview.value.required_changed.length)

const display = v => (v === null || v === undefined || v === '' ? '—' : String(v))
const scanTone = s => ({ ingested: 'success', quarantined: 'info', scan_failed: 'warning', infected: 'error' }[s] || 'info')
</script>

<template>
  <AppDialog
    :model-value="isDialogVisible"
    :title="t('onboardingFile.reviewTitle', { name: file?.name || '' })"
    :subtitle="file ? t(`onboardingFile.status.${file.status}`) + (file.snapshot_version > 1 ? ` · v${file.snapshot_version}` : '') : ''"
    :max-width="780"
    @update:model-value="v => emit('update:isDialogVisible', v)"
  >
    <template v-if="file && revising">
      <!-- ── Révision des éléments demandés ── -->
      <p class="text-body-2 text-medium-emphasis">
        {{ t('onboardingFile.reviseHint') }}
      </p>
      <div
        v-for="item in catalog"
        :key="item.kind + item.code"
        class="onb-review"
        :data-test="`onb-revise-${item.kind}-${item.code}`"
      >
        <VCheckbox
          :model-value="!!draft[`${item.kind}:${item.code}`]"
          hide-details
          density="compact"
          :aria-label="itemLabel(item)"
          @update:model-value="toggleItem(item)"
        />
        <div class="onb-review__main">
          <div class="text-body-1">
            {{ itemLabel(item) }}<span
              v-if="item.sensitive"
              class="text-body-2 text-medium-emphasis"
            > · {{ t('onboardingFile.sensitive') }}</span>
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ item.kind === 'document' ? t('onboardingFile.document') : t('onboardingFile.field') }}
          </div>
        </div>
        <VSwitch
          v-if="draft[`${item.kind}:${item.code}`] && item.required_allowed"
          v-model="draft[`${item.kind}:${item.code}`].required"
          hide-details
          density="compact"
          :label="t('onboardingFile.required')"
          @update:model-value="preview = null"
        />
        <span
          v-else-if="draft[`${item.kind}:${item.code}`]"
          class="text-body-2 text-medium-emphasis"
        >{{ t('onboardingFile.optionalOnly') }}</span>
      </div>

      <template v-if="preview">
        <VAlert
          type="info"
          variant="tonal"
          class="mt-4"
          data-test="onb-revision-preview"
        >
          <div class="text-body-1 font-weight-medium mb-1">
            {{ t('onboardingFile.previewTitle', { version: preview.version }) }}
          </div>
          <div v-if="isEmptyPreview">
            {{ t('onboardingFile.previewNone') }}
          </div>
          <div v-if="preview.added.length">
            {{ t('onboardingFile.previewAdded') }} : {{ preview.added.map(itemLabel).join(', ') }}
          </div>
          <div v-if="preview.removed.length">
            {{ t('onboardingFile.previewRemoved') }} : {{ preview.removed.map(itemLabel).join(', ') }}
          </div>
          <div v-if="preview.required_changed.length">
            {{ t('onboardingFile.previewRequired') }} : {{ preview.required_changed.map(i => `${itemLabel(i)} (${i.required ? t('onboardingFile.required') : t('onboardingFile.optional')})`).join(', ') }}
          </div>
          <div v-if="preview.removed_with_answers.length">
            {{ t('onboardingFile.previewKeptAnswers') }}
          </div>
          <div v-if="preview.new_sensitive">
            {{ t('onboardingFile.previewSensitive') }}
          </div>
          <div class="mt-1">
            {{ preview.message_required ? t('onboardingFile.previewAfterSubmit') : t('onboardingFile.previewCandidate') }}
          </div>
        </VAlert>
        <AppTextarea
          v-if="preview.message_required"
          v-model="revisionMessage"
          rows="2"
          class="mt-3"
          :label="t('onboardingFile.revisionMessage')"
          data-test="onb-revision-message"
        />
      </template>
    </template>

    <template v-else-if="file">
      <!-- ── Lien et e-mail ── -->
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
          data-test="onboarding-link"
        />
      </VAlert>
      <div
        v-if="isOpen"
        class="onb-review"
      >
        <div class="onb-review__main">
          <div class="text-body-1">
            {{ t('onboardingFile.deliveryTitle') }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            <template v-if="file.delivery.status === 'sent'">
              {{ t('onboardingFile.deliverySent', { date: new Date(file.delivery.mailed_at).toLocaleString() }) }}
            </template>
            <span
              v-else-if="file.delivery.status === 'failed'"
              class="text-error"
            >{{ t('onboardingFile.deliveryFailed') }}</span>
            <template v-else>
              {{ t('onboardingFile.deliveryNone') }}
            </template>
            · {{ file.link_active ? t('onboardingFile.linkActive') : t('onboardingFile.linkInactive') }}
            <template v-if="!file.delivery.channel">
              · {{ t('onboardingFile.channelUnavailable') }}
            </template>
          </div>
        </div>
        <VBtn
          v-if="file.has_email && file.delivery.channel"
          size="small"
          variant="tonal"
          prepend-icon="tabler-mail-forward"
          :loading="busy"
          data-test="onb-send-mail"
          @click="sendMail"
        >
          {{ file.delivery.status ? t('onboardingFile.resend') : t('onboardingFile.sendMail') }}
        </VBtn>
        <VBtn
          size="small"
          variant="text"
          :loading="busy"
          data-test="onb-new-link"
          @click="newLink"
        >
          {{ t('onboardingFile.newLink') }}
        </VBtn>
        <VBtn
          v-if="link"
          size="small"
          variant="text"
          prepend-icon="tabler-copy"
          @click="copy"
        >
          {{ t('onboardingFile.copy') }}
        </VBtn>
      </div>
      <div
        v-if="isOpen"
        class="onb-review"
      >
        <div class="onb-review__main">
          <div class="text-body-1">
            {{ t('onboardingFile.candidateEmail') }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ file.has_email ? t('onboardingFile.emailSet') : t('onboardingFile.emailUnset') }}<template v-if="file.verified_at">
              · {{ t('onboardingFile.verifiedOn', { date: new Date(file.verified_at).toLocaleString() }) }}
            </template><template v-if="file.verification_locked">
              · <span class="text-warning">{{ t('onboardingFile.verificationLocked') }}</span>
            </template>
          </div>
          <div
            v-if="editingEmail"
            class="d-flex gap-2 align-center mt-2 flex-wrap"
          >
            <AppTextField
              v-model="email"
              type="email"
              density="compact"
              :label="t('onboardingFile.newEmail')"
              style="min-inline-size: 240px"
            />
            <VBtn
              size="small"
              :disabled="!email"
              :loading="busy"
              @click="saveEmail"
            >
              {{ t('common.save') }}
            </VBtn>
          </div>
          <div
            v-if="editingEmail"
            class="text-body-2 text-medium-emphasis mt-1"
          >
            {{ t('onboardingFile.emailChangeHint') }}
          </div>
        </div>
        <VBtn
          v-if="!editingEmail"
          size="small"
          variant="text"
          @click="editingEmail = true"
        >
          {{ t('onboardingFile.changeEmail') }}
        </VBtn>
      </div>

      <!-- ── Informations ── -->
      <div class="text-overline mt-2">
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

      <!-- ── Pièces : analyse puis vérification DMS ── -->
      <div class="text-overline mt-4">
        {{ t('onboardingFile.documentsTitle') }}
      </div>
      <div
        v-for="d in file.documents"
        :key="d.code"
        class="onb-review"
        :data-test="`onb-doc-${d.code}`"
      >
        <div class="onb-review__main">
          <div class="text-body-1">
            {{ docLabel(d) }}
          </div>
          <div
            v-if="d.scan"
            class="text-body-2 text-medium-emphasis"
          >
            {{ t(`onboardingFile.scan.${d.scan.status}`) }}<template v-if="d.scan.engine === 'simulated'">
              · {{ t('onboardingFile.scanSimulated') }}
            </template>
          </div>
        </div>
        <VBtn
          v-if="d.scan?.retryable"
          size="small"
          variant="text"
          :loading="busy"
          @click="act(`uploads/${d.scan.upload_id}/rescan`)"
        >
          {{ t('onboardingFile.rescan') }}
        </VBtn>
        <AppStatusText
          v-if="d.scan && d.scan.status !== 'ingested'"
          :tone="scanTone(d.scan.status)"
          :text="t(`onboardingFile.scanShort.${d.scan.status}`)"
        />
        <AppStatusText
          v-else
          :tone="d.dms_status === 'approved' ? 'success' : (d.dms_status ? 'warning' : 'info')"
          :text="t(`onboardingFile.dms.${d.dms_status || 'none'}`)"
        />
      </div>
      <RouterLink
        :to="`/company/people/${file.person_ref}/documents`"
        class="text-body-2 d-inline-block mt-2"
      >
        {{ t('onboardingFile.openDocuments') }}
      </RouterLink>

      <VAlert
        v-if="file.status === 'submitted' && file.blocking_documents.length"
        type="warning"
        variant="tonal"
        density="compact"
        class="mt-4"
        data-test="onb-blocking"
      >
        {{ t('onboardingFile.blocking') }}
      </VAlert>

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
      <template v-if="revising">
        <VBtn
          variant="text"
          data-test="onb-revision-cancel"
          @click="revising = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          variant="tonal"
          :disabled="!Object.keys(draft).length"
          :loading="busy"
          data-test="onb-revision-preview-btn"
          @click="runPreview"
        >
          {{ t('onboardingFile.previewBtn') }}
        </VBtn>
        <VBtn
          color="primary"
          :disabled="!preview || isEmptyPreview || (preview.message_required && !revisionMessage)"
          :loading="busy"
          data-test="onb-revision-confirm"
          @click="confirmRevision"
        >
          {{ t('onboardingFile.confirmRevision') }}
        </VBtn>
      </template>
      <template v-else>
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
          v-if="canRevise"
          variant="text"
          data-test="onb-revise"
          @click="startRevision"
        >
          {{ t('onboardingFile.revise') }}
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
            :disabled="file.blocking_documents.length > 0"
            data-test="onb-validate"
            @click="act('validate', { decisions, confirm_contractual: confirmContractual })"
          >
            {{ t('onboardingFile.validate') }}
          </VBtn>
        </template>
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
