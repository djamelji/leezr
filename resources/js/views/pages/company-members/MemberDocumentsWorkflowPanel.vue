<script setup>
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'
import { useDocumentHelpers } from '@/composables/useDocumentHelpers'
import { useRealtimeSubscription } from '@/core/realtime/useRealtimeSubscription'
import DocumentMandatoryChip from '@/views/shared/documents/DocumentMandatoryChip.vue'
import DocumentStateChip from '@/views/shared/documents/DocumentStateChip.vue'
import DocumentConstraintsInline from '@/views/shared/documents/DocumentConstraintsInline.vue'
import DocumentViewerDialog from '@/views/shared/documents/DocumentViewerDialog.vue'

const props = defineProps({
  memberId: {
    type: [String, Number],
    default: 0,
  },
  // ADR-758 (DMS M1.1) : base d'API alternative (ex. /company/people/employee-5) pour adresser
  // un dossier par personRef — fonctionne pour un Employee SANS compte. Défaut = membre.
  resourceBase: {
    type: String,
    default: '',
  },
  // Le workflow « demander un document » suppose un compte (user_id) → désactivable.
  supportsRequests: {
    type: Boolean,
    default: true,
  },
  canEdit: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['createCustomType'])

const { t } = useI18n()

// Base d'API : personRef si fournie, sinon le membre (rétro-compat page company-members).
const base = computed(() => props.resourceBase || `/company/members/${props.memberId}`)
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()
const { formatFileSize, fileFormatIcon, fileFormatLabel } = useDocumentHelpers()

const memberDocuments = ref([])
const documentsLoading = ref(false)
const uploadingCode = ref(null)
const reviewingCode = ref(null)
const rejectDialogVisible = ref(false)
const rejectDialogCode = ref(null)
const rejectNote = ref('')
const isViewerOpen = ref(false)
const viewerDocument = ref(null)

// ADR-192: Document request
const requestMenuVisible = ref(false)

// ADR-778 (UX3) : un seul état par pièce — la valeur consolidée par le serveur.
const requestingCode = ref(null)

// ADR-760 (M3) : personRef extrait de resourceBase (ex. /company/people/employee-5 → employee-5)
// → permet « Demander un document » même pour un Employee SANS compte (via person_ref).
const personRef = computed(() => (props.resourceBase ? props.resourceBase.split('/').pop() : null))

// Requestable doc types = those activated but without an active request.
// Autorisé dès qu'on a un personRef (sujet) OU un compte.
const requestableDocTypes = computed(() => {
  if (!props.supportsRequests && !personRef.value) return []

  return memberDocuments.value.filter(
    doc => !doc.request_status || doc.request_status === 'approved' || doc.request_status === 'rejected',
  )
})

const requestDocument = async code => {
  requestingCode.value = code
  try {
    await $api('/company/document-requests', {
      method: 'POST',
      body: personRef.value
        ? { person_ref: personRef.value, document_type_code: code }
        : { user_id: props.memberId, document_type_code: code },
    })
    toast(t('documents.requestSent'), 'success')
    requestMenuVisible.value = false
    await fetchDocuments()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    requestingCode.value = null
  }
}

const fetchDocuments = async () => {
  documentsLoading.value = true
  try {
    const data = await $api(`${base.value}/documents`)

    memberDocuments.value = data.documents || []
  }
  catch { /* silently fail — tab may not be visible */ }
  finally {
    documentsLoading.value = false
  }
}

// Refetch when the resource base changes (navigation between members / persons)
watch(() => base.value, fetchDocuments, { immediate: true })

// ADR-427: Realtime SSE — targeted AI status update per document row
useRealtimeSubscription('document.updated', envelope => {
  const payload = envelope?.payload
  if (!payload) return

  if (payload.type === 'ai.completed' || payload.type === 'ai.failed') {
    const doc = memberDocuments.value.find(d => d.upload?.id === payload.id)
    if (doc?.upload) {
      doc.upload.ai_status = payload.ai_status
      if (payload.confidence != null) doc.upload.ai_analysis = { ...(doc.upload.ai_analysis || {}), confidence: payload.confidence }
    }
  }
})

// ─── Upload confirmation dialog (ADR-406 S4) ────────────
const isUploadDialogOpen = ref(false)
const pendingUploadDoc = ref(null)
const pendingUploadFiles = ref([])
const uploadExpiresAt = ref(null)

const openUploadDialog = (doc, files) => {
  if (!files?.length) return
  pendingUploadDoc.value = doc
  pendingUploadFiles.value = [...files]
  uploadExpiresAt.value = doc.upload?.expires_at ? doc.upload.expires_at.substring(0, 10) : null
  isUploadDialogOpen.value = true
}

const cancelUploadDialog = () => {
  isUploadDialogOpen.value = false
  pendingUploadDoc.value = null
  pendingUploadFiles.value = []
  uploadExpiresAt.value = null
}

const confirmUpload = () => {
  if (pendingUploadDoc.value && pendingUploadFiles.value.length) {
    handleDocUpload(pendingUploadDoc.value.code, pendingUploadFiles.value, uploadExpiresAt.value)
  }
  isUploadDialogOpen.value = false
}

const handleDocUpload = async (code, files, expiresAt = null) => {
  if (!files?.length) return
  uploadingCode.value = code
  try {
    const formData = new FormData()

    files.forEach(f => formData.append('files[]', f))
    if (expiresAt) formData.append('expires_at', expiresAt)

    await $api(`${base.value}/documents/${code}`, {
      method: 'POST',
      body: formData,
    })
    toast(t('documents.uploaded'), 'success')
    await fetchDocuments()
  }
  catch (error) {
    toast(error?.data?.message || t('documents.failedToUpload'), 'error')
  }
  finally {
    uploadingCode.value = null
    pendingUploadDoc.value = null
    pendingUploadFiles.value = []
    uploadExpiresAt.value = null
  }
}

const handleDocDelete = async code => {
  const ok = await confirm({
    question: t('documents.deleteConfirm'),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('documents.deleted'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })
  if (!ok) return
  try {
    await $api(`${base.value}/documents/${code}`, { method: 'DELETE' })
    toast(t('documents.deleted'), 'success')
    await fetchDocuments()
  }
  catch (error) {
    toast(error?.data?.message || t('documents.failedToDelete'), 'error')
  }
}

const extendVisible = ref(false)
const extendDate = ref('')
const extendCode = ref(null)
const openExtend = code => { extendCode.value = code; extendDate.value = ''; extendVisible.value = true }
const confirmExtend = async () => { extendVisible.value = false; await handleReview(extendCode.value, 'approved', null, extendDate.value) }

const handleReview = async (code, status, note = null, extendUntil = null) => {
  reviewingCode.value = code
  try {
    await $api(`${base.value}/documents/${code}/review`, {
      method: 'PUT',
      body: { status, review_note: note, extend_until: extendUntil },
    })
    toast(t('documents.reviewSaved'), 'success')
    await fetchDocuments()
  }
  catch (error) {
    toast(error?.data?.message || t('documents.reviewFailed'), 'error')
  }
  finally {
    reviewingCode.value = null
  }
}

const openRejectDialog = code => {
  rejectDialogCode.value = code
  rejectNote.value = ''
  rejectDialogVisible.value = true
}

const confirmReject = async () => {
  rejectDialogVisible.value = false
  if (rejectDialogCode.value) {
    await handleReview(rejectDialogCode.value, 'rejected', rejectNote.value || null)
    rejectDialogCode.value = null
    rejectNote.value = ''
  }
}

const openViewer = doc => {
  viewerDocument.value = doc
  isViewerOpen.value = true
}

const viewerDownloadUrl = computed(() => {
  if (!viewerDocument.value?.upload)
    return ''

  return `${base.value}/documents/${viewerDocument.value.code}/download`
})

// ADR-760 (M3) : timeline des versions dans le viewer.
const viewerVersionsUrl = computed(() => {
  if (!viewerDocument.value?.upload)
    return ''

  return `${base.value}/documents/${viewerDocument.value.code}/versions`
})

const handleViewerApprove = async () => {
  if (!viewerDocument.value)
    return

  isViewerOpen.value = false
  await handleReview(viewerDocument.value.code, 'approved')
}

const handleViewerReject = () => {
  if (!viewerDocument.value)
    return

  isViewerOpen.value = false
  openRejectDialog(viewerDocument.value.code)
}

// ADR-426: Apply AI suggestions
const memberViewerRef = ref(null)

const handleApplySuggestions = async fields => {
  const doc = viewerDocument.value
  if (!doc?.code) return

  try {
    await $api(`${base.value}/documents/${doc.code}/apply-suggestions`, {
      method: 'POST',
      body: { fields },
    })

    memberViewerRef.value?.markApplied(fields)

    if (fields.length === 1) {
      toast(t('documents.suggestionApplied'), 'success')
    }
    else {
      toast(t('documents.suggestionsApplied'), 'success')
    }
  }
  catch {
    toast(t('common.error'), 'error')
  }
}

const handleViewerDownload = async () => {
  if (!viewerDocument.value?.upload)
    return

  const code = viewerDocument.value.code
  const fileName = viewerDocument.value.upload.file_name

  try {
    const blob = await $api(`${base.value}/documents/${code}/download`, {
      responseType: 'blob',
    })

    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = fileName
    a.click()
    URL.revokeObjectURL(url)
  }
  catch {
    toast(t('documents.failedToUpload'), 'error')
  }
}

// ADR-778 (UX3) : actions directes de la fiche — « Demander un document » ouvre le choix du type ici,
// un dépôt fait ailleurs rafraîchit la liste.
defineExpose({ openRequest: () => { if (requestableDocTypes.value.length) requestMenuVisible.value = true; return requestableDocTypes.value.length > 0 }, refresh: () => fetchDocuments() })
</script>

<template>
  <VCard>
    <!-- ADR-192: Request Document + ADR-388: Create custom type -->
    <VCardItem v-if="canEdit && !documentsLoading">
      <template #append>
        <div class="d-flex gap-2 flex-wrap justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            size="small"
            prepend-icon="tabler-file-certificate"
            @click="emit('createCustomType')"
          >
            {{ t('documents.createCustomFromMember') }}
          </VBtn>
          <VMenu
            v-if="requestableDocTypes.length"
            v-model="requestMenuVisible"
          >
            <template #activator="{ props: menuProps }">
              <VBtn
                v-bind="menuProps"
                variant="tonal"
                color="primary"
                size="small"
                prepend-icon="tabler-file-plus"
              >
                {{ t('documents.requestDocument') }}
              </VBtn>
            </template>
            <VList density="compact">
              <VListItem
                v-for="doc in requestableDocTypes"
                :key="doc.code"
                :disabled="requestingCode === doc.code"
                @click="requestDocument(doc.code)"
              >
                <VListItemTitle>{{ t(`documents.type.${doc.code}`, doc.label) }}</VListItemTitle>
              </VListItem>
            </VList>
          </VMenu>
        </div>
      </template>
    </VCardItem>

    <VCardText>
      <div
        v-if="documentsLoading"
        class="text-center pa-6"
      >
        <VProgressCircular indeterminate />
      </div>

      <!-- ADR-178/423: Actionable empty state -->
      <div
        v-else-if="memberDocuments.length === 0"
        class="text-center pa-8"
      >
        <VIcon
          icon="tabler-file-certificate"
          :size="64"
          color="disabled"
          class="mb-4"
        />
        <h6 class="text-h6 mb-2">
          {{ t('companyDocuments.emptyState.memberDocsTitle') }}
        </h6>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('companyDocuments.emptyState.memberDocsSubtitle') }}
        </p>
      </div>

      <VTable
        v-else
        class="text-no-wrap member-documents-table"
      >
        <thead>
          <tr>
            <th>{{ t('documents.title') }}</th>
            <th style="width: 120px;">
              {{ t('documents.systemMandatory') }}
            </th>
            <th>{{ t('common.status') }}</th>
            <th style="width: 220px;" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="doc in memberDocuments"
            :key="doc.code"
          >
            <td class="font-weight-medium">
              {{ t(`documents.type.${doc.code}`, doc.label) }}
              <DocumentConstraintsInline
                :max-file-size-mb="doc.max_file_size_mb"
                :accepted-types="doc.accepted_types"
              />
            </td>
            <td>
              <DocumentMandatoryChip
                :mandatory="doc.mandatory"
                :required="doc.required"
              />
            </td>
            <!-- ADR-778 (UX3) : UN état par pièce (consolidé par le serveur), pas un empilement de puces. -->
            <td>
              <DocumentStateChip
                :state="doc.compliance_status"
                :request-status="doc.request_status"
                :data-test="`document-state-${doc.code}`"
              />
              <div
                v-if="doc.upload?.expires_at"
                class="text-caption text-medium-emphasis mt-1"
              >
                {{ t('documents.expiresAt') }} {{ new Date(doc.upload.expires_at).toLocaleDateString() }}
              </div>
              <!-- ADR-819 : exigence satisfaite par une autre source compatible (ex. contrat signé dans Leezr). -->
              <div
                v-if="doc.satisfied_by"
                class="text-caption text-success mt-1 d-flex align-center gap-1"
                :data-test="`document-proof-${doc.code}`"
              >
                <VIcon
                  icon="tabler-rosette-discount-check"
                  size="14"
                />{{ doc.satisfied_by.label }}
              </div>
            </td>
            <!-- UNE action principale selon l'état ; le reste dans un menu libellé — rien n'est coupé. -->
            <td>
              <div class="d-flex gap-1 justify-end align-center">
                <VBtn
                  v-if="doc.can_review && canEdit"
                  size="small"
                  variant="tonal"
                  color="success"
                  prepend-icon="tabler-check"
                  :loading="reviewingCode === doc.code"
                  :data-test="`document-approve-${doc.code}`"
                  @click="handleReview(doc.code, 'approved')"
                >
                  {{ t('documents.approve') }}
                </VBtn>
                <VBtn
                  v-else-if="canEdit"
                  size="small"
                  variant="tonal"
                  prepend-icon="tabler-upload"
                  :loading="uploadingCode === doc.code"
                  :data-test="`document-upload-${doc.code}`"
                  @click="$refs[`fileInput_${doc.code}`]?.[0]?.click()"
                >
                  {{ doc.upload ? t('documents.replaceFile') : t('documents.upload') }}
                </VBtn>
                <input
                  :ref="`fileInput_${doc.code}`"
                  type="file"
                  hidden
                  multiple
                  :accept="doc.accepted_types.map(t => `.${t}`).join(',')"
                  @change="openUploadDialog(doc, $event.target.files); $event.target.value = ''"
                >
                <IconBtn
                  v-if="doc.upload || (doc.can_review && canEdit)"
                  size="small"
                  :aria-label="t('documents.moreActions')"
                  :data-test="`document-more-${doc.code}`"
                >
                  <VIcon icon="tabler-dots-vertical" />
                  <VMenu
                    activator="parent"
                    location="bottom end"
                  >
                    <VList
                      density="compact"
                      min-width="220"
                    >
                      <VListItem
                        v-if="doc.upload"
                        prepend-icon="tabler-eye"
                        @click="openViewer(doc)"
                      >
                        <VListItemTitle>{{ t('documents.preview') }}</VListItemTitle>
                      </VListItem>
                      <!-- ADR-757 (DMS M1): téléchargement gated par la policy de confidentialité -->
                      <VListItem
                        v-if="doc.upload && doc.can_download !== false"
                        prepend-icon="tabler-download"
                        :href="`/api${base}/documents/${doc.code}/download`"
                        target="_blank"
                      >
                        <VListItemTitle>{{ t('documents.download') }}</VListItemTitle>
                      </VListItem>
                      <VListItem
                        v-else-if="doc.upload && doc.can_download === false"
                        prepend-icon="tabler-lock"
                        disabled
                      >
                        <VListItemTitle>{{ t('documents.confidentialRestricted') }}</VListItemTitle>
                      </VListItem>
                      <VListItem
                        v-if="doc.can_review && canEdit"
                        prepend-icon="tabler-upload"
                        @click="$refs[`fileInput_${doc.code}`]?.[0]?.click()"
                      >
                        <VListItemTitle>{{ t('documents.replaceFile') }}</VListItemTitle>
                      </VListItem>
                      <VListItem
                        v-if="canEdit && doc.upload?.expires_at && ['requested', 'submitted', 'in_review', 'to_replace', 'rejected'].includes(doc.request_status)"
                        prepend-icon="tabler-calendar-plus"
                        data-test="document-extend"
                        @click="openExtend(doc.code)"
                      >
                        <VListItemTitle>{{ t('documents.extend') }}</VListItemTitle>
                      </VListItem>
                      <VListItem
                        v-if="doc.can_review && canEdit"
                        prepend-icon="tabler-x"
                        base-color="error"
                        @click="openRejectDialog(doc.code)"
                      >
                        <VListItemTitle>{{ t('documents.reject') }}</VListItemTitle>
                      </VListItem>
                      <VListItem
                        v-if="doc.upload && canEdit"
                        prepend-icon="tabler-trash"
                        base-color="error"
                        @click="handleDocDelete(doc.code)"
                      >
                        <VListItemTitle>{{ t('documents.delete') }}</VListItemTitle>
                      </VListItem>
                    </VList>
                  </VMenu>
                </IconBtn>
              </div>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCardText>
  </VCard>

  <!-- Reject Dialog -->
  <!-- ADR-773 — explicit, audited extension of the SAME document (no new version) -->
  <VDialog
    v-model="extendVisible"
    max-width="460"
  >
    <VCard :title="t('documents.extendTitle')">
      <VCardText>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('documents.extendHint') }}
        </p>
        <AppTextField
          v-model="extendDate"
          type="date"
          :label="t('documents.extendUntil')"
          data-test="document-extend-until"
        />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          variant="text"
          @click="extendVisible = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :disabled="!extendDate"
          data-test="document-extend-confirm"
          @click="confirmExtend"
        >
          {{ t('documents.extend') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VDialog
    v-model="rejectDialogVisible"
    max-width="500"
  >
    <VCard>
      <VCardTitle>{{ t('documents.reject') }}</VCardTitle>
      <VCardText>
        <AppTextField
          v-model="rejectNote"
          :label="t('documents.reviewNote')"
          :placeholder="t('documents.reviewNotePlaceholder')"
          type="textarea"
          rows="3"
        />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          color="secondary"
          variant="tonal"
          @click="rejectDialogVisible = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="error"
          @click="confirmReject"
        >
          {{ t('documents.reject') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- Upload confirmation dialog (ADR-406 S4) -->
  <VDialog
    v-model="isUploadDialogOpen"
    max-width="500"
  >
    <VCard>
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="primary"
            variant="tonal"
            rounded
          >
            <VIcon icon="tabler-upload" />
          </VAvatar>
        </template>
        <VCardTitle>{{ t('documents.confirmUpload') }}</VCardTitle>
        <VCardSubtitle>{{ t(`documents.type.${pendingUploadDoc?.code}`, pendingUploadDoc?.label) }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <div v-if="pendingUploadFiles.length" class="mb-4">
          <div
            v-for="(f, idx) in pendingUploadFiles"
            :key="idx"
            class="d-flex align-center gap-2 mb-1"
          >
            <VIcon icon="tabler-file" size="20" />
            <span class="text-body-1">{{ f.name }}</span>
            <span class="text-body-2 text-disabled">{{ formatFileSize(f.size) }}</span>
            <VBtn
              icon
              variant="text"
              size="x-small"
              color="error"
              @click="pendingUploadFiles.splice(idx, 1); if (!pendingUploadFiles.length) cancelUploadDialog()"
            >
              <VIcon icon="tabler-x" size="14" />
            </VBtn>
          </div>
          <div class="text-body-2 text-disabled mt-2">
            {{ t('documents.totalFiles', { count: pendingUploadFiles.length }) }}
          </div>
        </div>
        <VAlert
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ t('documents.autoMergeHint') }}
        </VAlert>
        <template v-if="pendingUploadDoc?.requires_expiration">
          <AppDateTimePicker
            v-model="uploadExpiresAt"
            :label="t('documents.expirationDate')"
            :hint="t('documents.expirationDateHint')"
            persistent-hint
            :rules="[v => !!v || t('documents.expirationDate') + ' ' + t('common.required')]"
            clearable
          />
          <VAlert
            type="warning"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            {{ t('documents.requiresExpirationHint') }}
          </VAlert>
        </template>
      </VCardText>
      <VCardActions class="justify-end">
        <VBtn
          variant="tonal"
          @click="cancelUploadDialog"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :disabled="!pendingUploadFiles.length || (pendingUploadDoc?.requires_expiration && !uploadExpiresAt)"
          :loading="uploadingCode === pendingUploadDoc?.code"
          @click="confirmUpload"
        >
          {{ t('documents.upload') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- Document Viewer -->
  <DocumentViewerDialog
    ref="memberViewerRef"
    v-model:is-dialog-visible="isViewerOpen"
    :document="viewerDocument?.upload ? { code: viewerDocument.code, label: viewerDocument.label, file_name: viewerDocument.upload.file_name, file_size_bytes: viewerDocument.upload.file_size_bytes, mime_type: viewerDocument.upload.mime_type, ocr_text: viewerDocument.upload.ocr_text, ai_analysis: viewerDocument.upload.ai_analysis, ai_insights: viewerDocument.upload.ai_insights, ai_suggestions: viewerDocument.upload.ai_suggestions, ai_status: viewerDocument.upload.ai_status } : null"
    :download-url="viewerDownloadUrl"
    :versions-url="viewerVersionsUrl"
    :can-review="!!(viewerDocument?.can_review && canEdit)"
    :review-status="viewerDocument?.request_status"
    :review-note="viewerDocument?.request_review_note"
    @approve="handleViewerApprove"
    @reject="handleViewerReject"
    @download="handleViewerDownload"
    @apply-suggestions="handleApplySuggestions"
  />

  <ConfirmDialogComponent />
</template>

<style lang="scss">
/* ADR-778 (UX3) — sur petit écran, une pièce = un bloc : intitulé, état, actions. Rien n'est hors écran. */
@media (max-width: 599.98px) {
  .member-documents-table {
    thead { display: none; }

    tbody tr {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px 12px;
      padding-block: 12px;
      border-block-end: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
    }

    tbody td {
      display: block;
      block-size: auto !important;
      border: 0 !important;
      padding: 0 !important;
      white-space: normal;
    }

    tbody td:first-child { flex: 1 1 100%; }
    tbody td:last-child { margin-inline-start: auto; }
  }
}
</style>
