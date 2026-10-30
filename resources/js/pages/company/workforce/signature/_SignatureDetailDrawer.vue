<script setup>
// W-SIGNATURE-1 (ADR-651) — détail d'une demande : signataires, chronologie de preuves, actions.
import { useSignatureStore } from '@/modules/company/workforce/signature.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'
import { fetchPdfBlob, downloadBlob } from '@/utils/pdf'
import PdfViewerDialog from '@/components/PdfViewerDialog.vue'

const props = defineProps({
  isDrawerOpen: { type: Boolean, default: false },
  requestId: { type: Number, default: null },
})
const emit = defineEmits(['update:isDrawerOpen', 'changed'])

const { t } = useI18n()
const store = useSignatureStore()
const { toast } = useAppToast()
const { can } = useCan()

const loading = ref(false)
const req = ref(null)
const events = ref([])
const acting = ref(false)

const STATUS_COLOR = {
  draft: 'secondary', pending: 'warning', partially_signed: 'info', completed: 'success',
  declined: 'error', cancelled: 'secondary', expired: 'secondary', failed: 'error',
}
const signerColor = s => (s === 'signed' ? 'success' : s === 'declined' ? 'error' : s === 'invited' ? 'info' : 'secondary')

const load = async () => {
  if (!props.requestId) return
  loading.value = true
  events.value = []
  verifyResult.value = null
  try {
    req.value = await store.fetchOne(props.requestId)
    if (can('workforce_signature.view_evidence')) {
      try {
        const ev = await store.fetchEvidence(props.requestId)
        events.value = ev.events ?? []
      } catch { /* pas de droit preuves */ }
    }
  } finally { loading.value = false }
}

watch(() => [props.isDrawerOpen, props.requestId], ([open]) => { if (open) load() })

const isActive = computed(() => req.value && !['completed', 'declined', 'cancelled', 'expired', 'failed'].includes(req.value.status))
const fmt = iso => (iso ? new Date(iso).toLocaleString() : '—')

const doCancel = async () => {
  acting.value = true
  try {
    await store.cancel(props.requestId, null)
    toast(t('signature.toasts.cancelled'), 'success')
    emit('changed'); await load()
  } catch (e) { toast(e.response?._data?.message || t('common.error'), 'error') } finally { acting.value = false }
}
const downloading = ref(false)
const viewerOpen = ref(false)
const downloadPath = computed(() => `/workforce/signature/${props.requestId}/download`)

// Visualiser → visionneuse intégrée (valide que c'est bien un PDF, sinon message clair).
const openViewer = () => { viewerOpen.value = true }

// Vérification cryptographique indépendante (rapport honnête).
const verifying = ref(false)
const verifyResult = ref(null)
const runVerify = async () => {
  verifying.value = true
  verifyResult.value = null
  try {
    const r = await store.verifySignature(props.requestId)
    verifyResult.value = r
    if (!r.has_seal) toast(t('signature.verify.noSeal'), 'info')
    else if (r.overall_valid) toast(t('signature.verify.toastValid'), 'success')
    else toast(t('signature.verify.toastInvalid'), 'error')
  } catch (e) {
    toast(e?.message || t('common.error'), 'error')
  } finally { verifying.value = false }
}

// Télécharger → util unifié (en-têtes auth + X-Company-Id, validation du contenu).
const triggerDownload = async () => {
  downloading.value = true
  try {
    const blob = await fetchPdfBlob(downloadPath.value)
    downloadBlob(blob, `document-signe-${props.requestId}.pdf`)
  } catch (e) {
    toast(e?.message || t('common.error'), 'error')
  } finally { downloading.value = false }
}

// W-SIGNATURE-13 — export du dossier de preuves (JSON vérifiable, sans secret).
const exporting = ref(false)
const exportEvidence = async () => {
  exporting.value = true
  try {
    const pkg = await store.fetchEvidencePackage(props.requestId)
    const blob = new Blob([JSON.stringify(pkg, null, 2)], { type: 'application/json' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `dossier-preuves-${props.requestId}.json`
    a.click()
    URL.revokeObjectURL(a.href)
  } catch (e) {
    toast(e.response?._data?.message || e?.message || t('common.error'), 'error')
  } finally { exporting.value = false }
}

const doRemind = async () => {
  acting.value = true
  try {
    const r = await store.remind(props.requestId)
    toast(t('signature.toasts.reminded', { count: r.reminded ?? 0 }), 'success')
  } catch (e) { toast(e.response?._data?.message || t('common.error'), 'error') } finally { acting.value = false }
}
</script>

<template>
  <VNavigationDrawer
    temporary
    location="end"
    :model-value="isDrawerOpen"
    width="460"
    @update:model-value="emit('update:isDrawerOpen', $event)"
  >
    <div class="d-flex align-center justify-space-between pa-4">
      <h5 class="text-h5">
        {{ t('signature.detail.title') }}
      </h5>
      <VBtn
        icon
        variant="text"
        size="small"
        color="default"
        @click="emit('update:isDrawerOpen', false)"
      >
        <VIcon icon="tabler-x" />
      </VBtn>
    </div>
    <VDivider />

    <div v-if="loading" class="pa-6 text-center">
      <VProgressCircular indeterminate color="primary" />
    </div>

    <div
      v-else-if="req"
      class="d-flex flex-column"
      style="max-block-size: calc(100vh - 65px);"
    >
      <!-- Corps scrollable -->
      <div
        class="flex-grow-1 pa-4"
        style="overflow-y: auto; min-block-size: 0;"
      >
      <div class="d-flex align-center justify-space-between mb-3">
        <div class="font-weight-medium">
          {{ req.document_label || t('signature.unknownDocument') }}
        </div>
        <VChip :color="STATUS_COLOR[req.status]" size="small" label>
          {{ t(`signature.status.${req.status}`) }}
        </VChip>
      </div>

      <VList density="compact" class="mb-2">
        <VListItem :title="req.provider" :subtitle="t('signature.columns.provider')" />
        <VListItem :title="`${t(`signature.level.${req.signature_level}`)} · ${t(`signature.order.${req.signing_order}`)}`" :subtitle="t('signature.detail.levelOrder')" />
        <VListItem :title="fmt(req.expires_at)" :subtitle="t('signature.detail.expires')" />
        <VListItem v-if="req.certified !== undefined" :subtitle="t('signature.detail.certification')">
          <template #title>
            <VChip size="x-small" :color="req.certified ? 'success' : 'warning'" variant="tonal">
              {{ req.certified ? t('signature.certified') : t('signature.uncertified') }}
            </VChip>
          </template>
        </VListItem>
      </VList>

      <div class="text-caption text-medium-emphasis mb-2">
        {{ t('signature.detail.signers') }}
      </div>
      <VList density="compact" class="mb-3">
        <VListItem v-for="s in req.signers" :key="s.id">
          <template #prepend>
            <VChip size="x-small" label class="me-2">{{ s.order }}</VChip>
          </template>
          <VListItemTitle>{{ s.name }}</VListItemTitle>
          <VListItemSubtitle>{{ t(`signature.role.${s.role}`) }} · {{ s.email }}</VListItemSubtitle>
          <template #append>
            <VChip size="x-small" :color="signerColor(s.status)" variant="tonal">
              {{ t(`signature.signerStatus.${s.status}`) }}
            </VChip>
          </template>
        </VListItem>
      </VList>

      <!-- Chronologie des preuves -->
      <template v-if="events.length">
        <div class="text-caption text-medium-emphasis mb-2">
          {{ t('signature.detail.timeline') }}
        </div>
        <VTimeline
          side="end"
          align="start"
          density="compact"
          truncate-line="both"
          class="mb-4"
        >
          <VTimelineItem
            v-for="(e, i) in events"
            :key="i"
            size="x-small"
            dot-color="primary"
          >
            <div class="d-flex justify-space-between">
              <span class="font-weight-medium">{{ t(`signature.event.${e.type}`) }}</span>
              <span class="text-caption text-medium-emphasis">{{ fmt(e.occurred_at) }}</span>
            </div>
            <div v-if="e.verification_result" class="text-caption">
              {{ t('signature.detail.verification') }}: {{ e.verification_result }}
            </div>
          </VTimelineItem>
        </VTimeline>
      </template>

      <!-- Résultat de vérification cryptographique (états DISTINCTS, pas un simple badge « Certifié ») -->
      <VCard
        v-if="verifyResult"
        variant="outlined"
        class="mt-2"
      >
        <VCardText class="py-3">
          <div class="text-caption text-medium-emphasis mb-2">
            {{ t('signature.verify.title') }}
          </div>
          <template v-if="verifyResult.has_seal">
            <div class="d-flex flex-column gap-1">
              <VChip size="small" color="primary" variant="tonal" label>
                <VIcon :icon="verifyResult.embedded_signature ? 'tabler-file-certificate' : 'tabler-certificate'" start size="14" />
                {{ verifyResult.embedded_signature ? t('signature.verify.padesEmbedded') : t('signature.verify.cmsDetached') }}
                <template v-if="verifyResult.verified_by"> · {{ verifyResult.verified_by }}</template>
              </VChip>
              <VChip
                size="small"
                :color="verifyResult.overall_valid ? 'success' : 'error'"
                variant="tonal"
                label
              >
                <VIcon :icon="verifyResult.overall_valid ? 'tabler-check' : 'tabler-x'" start size="14" />
                {{ verifyResult.overall_valid ? t('signature.verify.cryptoValid') : t('signature.verify.cryptoInvalid') }}
              </VChip>
              <VChip
                size="small"
                :color="verifyResult.document_intact ? 'success' : 'error'"
                variant="tonal"
                label
              >
                <VIcon :icon="verifyResult.document_intact ? 'tabler-check' : 'tabler-x'" start size="14" />
                {{ verifyResult.document_intact ? t('signature.verify.intact') : t('signature.verify.modified') }}
              </VChip>
              <VChip
                size="small"
                :color="verifyResult.certificate?.recognized_trust_chain ? 'success' : 'warning'"
                variant="tonal"
                label
              >
                <VIcon icon="tabler-certificate-off" start size="14" />
                {{ verifyResult.certificate?.recognized_trust_chain ? t('signature.verify.certRecognized') : t('signature.verify.certNotRecognized') }}
              </VChip>
            </div>

            <!-- §8 — nombre de signatures / révisions -->
            <div v-if="verifyResult.signature_count" class="text-caption text-medium-emphasis mt-2">
              {{ t('signature.verify.signatureCount') }} : {{ verifyResult.signature_count }}
            </div>

            <!-- §8 — rapport PAR SIGNATAIRE (certificats individuels, identité DÉCLARÉE) -->
            <div v-if="verifyResult.signer_certificates?.length" class="mt-3">
              <div class="text-caption font-weight-medium mb-1">
                {{ t('signature.verify.signersTitle') }}
              </div>
              <VCard
                v-for="(c, i) in verifyResult.signer_certificates"
                :key="i"
                variant="outlined"
                class="mb-2"
              >
                <VCardText class="py-2 px-3">
                  <div class="d-flex align-center justify-space-between flex-wrap gap-1">
                    <div class="text-body-2 font-weight-medium">
                      {{ c.signer_name }}
                      <span class="text-caption text-medium-emphasis">· {{ t(`signature.role.${c.role}`) }}</span>
                    </div>
                    <div class="d-flex gap-1 flex-wrap">
                      <VChip size="x-small" :color="c.internal_chain_valid ? 'success' : 'warning'" variant="tonal" label>
                        {{ c.internal_chain_valid ? t('signature.verify.chainOk') : t('signature.verify.chainUnknown') }}
                      </VChip>
                      <VChip v-if="c.revoked" size="x-small" color="error" variant="tonal" label>
                        {{ t('signature.verify.revoked') }}
                      </VChip>
                      <VChip size="x-small" color="warning" variant="tonal" label>
                        {{ t('signature.verify.identityDeclared') }}
                      </VChip>
                    </div>
                  </div>
                  <div class="text-caption text-medium-emphasis mt-1">
                    {{ t('signature.verify.serial') }}: {{ (c.serial || '').slice(0, 16) }}… ·
                    SHA-256: {{ (c.fingerprint_sha256 || '').slice(0, 16) }}…
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t('signature.verify.validity') }}: {{ c.not_before?.slice(0, 10) }} → {{ c.not_after?.slice(0, 10) }}
                  </div>
                </VCardText>
              </VCard>
            </div>

            <VAlert type="info" variant="tonal" density="compact" class="mt-2">
              {{ t('signature.verify.trustNote') }}
            </VAlert>
          </template>
          <VAlert v-else type="info" variant="tonal" density="compact">
            {{ verifyResult.message || t('signature.verify.noSeal') }}
          </VAlert>
        </VCardText>
      </VCard>
      </div>
      <!-- fin corps scrollable -->

      <VDivider />

      <!-- Footer d'actions — TOUJOURS visible (ne défile pas) -->
      <div class="pa-4 d-flex flex-wrap gap-2">
        <template v-if="req.has_signed_document">
          <VBtn
            variant="tonal"
            size="small"
            prepend-icon="tabler-eye"
            @click="openViewer"
          >
            {{ t('signature.detail.view') }}
          </VBtn>
          <VBtn
            size="small"
            prepend-icon="tabler-download"
            :loading="downloading"
            @click="triggerDownload"
          >
            {{ t('signature.detail.download') }}
          </VBtn>
          <VBtn
            v-if="can('workforce_signature.view_evidence')"
            variant="tonal"
            size="small"
            color="secondary"
            prepend-icon="tabler-file-zip"
            :loading="exporting"
            @click="exportEvidence"
          >
            {{ t('signature.detail.exportEvidence') }}
          </VBtn>
          <VBtn
            variant="tonal"
            size="small"
            color="info"
            prepend-icon="tabler-shield-check"
            :loading="verifying"
            @click="runVerify"
          >
            {{ t('signature.verify.action') }}
          </VBtn>
        </template>
        <VBtn
          v-if="isActive && can('workforce_signature.manage')"
          variant="tonal"
          size="small"
          prepend-icon="tabler-bell"
          :loading="acting"
          @click="doRemind"
        >
          {{ t('signature.detail.remind') }}
        </VBtn>
        <VBtn
          v-if="isActive && can('workforce_signature.manage')"
          variant="tonal"
          color="error"
          size="small"
          prepend-icon="tabler-ban"
          :loading="acting"
          @click="doCancel"
        >
          {{ t('signature.detail.cancel') }}
        </VBtn>
      </div>
    </div>

    <PdfViewerDialog
      v-model="viewerOpen"
      :path="downloadPath"
      :title="req?.document_label || t('signature.detail.title')"
      :filename="`document-signe-${requestId}.pdf`"
    />
  </VNavigationDrawer>
</template>
