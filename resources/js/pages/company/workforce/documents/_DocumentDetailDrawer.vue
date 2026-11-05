<script setup>
// PRODUCT-UX-2 (LOT A2) — panneau documentaire UNIFIÉ : depuis une ligne du registre, toutes les
// informations et actions AUTORISÉES d'un document dans un seul contexte (identité, aperçu,
// téléchargement, état de signature, suivi, fiche collaborateur). Les actions suivent l'ÉTAT RÉEL :
// on n'affiche jamais une action indisponible. Réutilise les services existants via des événements.
const props = defineProps({
  modelValue: { type: Boolean, required: true },
  document: { type: Object, default: null },
  canRequest: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'preview', 'download', 'request-signature', 'go-signatures', 'open-fiche'])

const { t, locale } = useI18n()
const isOpen = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })
const doc = computed(() => props.document || {})

const subjectLabel = type => ({
  payroll_line: t('workforceDocuments.subjects.payrollLine'),
  employee: t('workforceDocuments.subjects.employee'),
  contract: t('workforceDocuments.subjects.contract'),
  leave_request: t('workforceDocuments.subjects.leaveRequest'),
  payroll_run: t('workforceDocuments.subjects.payrollRun'),
}[type] || type)

const sigColor = s => ({ none: 'secondary', pending: 'warning', signed: 'success', declined: 'error' }[s] || 'secondary')
const sigLabel = s => ({
  none: t('workforceDocuments.signatureStatuses.none'),
  pending: t('workforceDocuments.signatureStatuses.pending'),
  signed: t('workforceDocuments.signatureStatuses.signed'),
  declined: t('workforceDocuments.signatureStatuses.declined'),
}[s] || s)

const fmtDate = d => d ? new Date(d).toLocaleDateString(locale.value, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'

const status = computed(() => doc.value.signature_status || 'none')
const hasSignatureRequest = computed(() => ['pending', 'signed', 'declined'].includes(status.value))
</script>

<template>
  <VNavigationDrawer class="wf-scroll-drawer" v-model="isOpen" temporary location="end" width="440" :scrim="true">
    <div v-if="document" class="d-flex flex-column h-100">
      <!-- En-tête -->
      <div class="d-flex align-center justify-space-between pa-4 border-b">
        <div class="d-flex align-center gap-3">
          <VAvatar color="primary" variant="tonal" rounded size="40"><VIcon icon="tabler-file-text" /></VAvatar>
          <div>
            <div class="text-subtitle-1 font-weight-medium">{{ doc.template?.name || doc.template_code || t('workforceDocuments.spaceTitle') }}</div>
            <div class="text-caption text-medium-emphasis">{{ subjectLabel(doc.subject_type) }}</div>
          </div>
        </div>
        <VBtn icon variant="text" size="small" @click="isOpen = false"><VIcon icon="tabler-x" /></VBtn>
      </div>

      <div class="pa-4 flex-grow-1 overflow-y-auto">
        <!-- Identité -->
        <VList density="compact" class="mb-2">
          <VListItem v-if="doc.employee_name" :title="doc.employee_name" :subtitle="t('employees.fields.employee', 'Collaborateur')">
            <template #prepend><VIcon icon="tabler-user" size="20" class="me-2" /></template>
          </VListItem>
          <VListItem :title="fmtDate(doc.generated_at)" :subtitle="t('workforceDocuments.columns.generatedAt')">
            <template #prepend><VIcon icon="tabler-calendar" size="20" class="me-2" /></template>
          </VListItem>
          <VListItem :subtitle="t('workforceDocuments.columns.signatureStatus')">
            <template #prepend><VIcon icon="tabler-signature" size="20" class="me-2" /></template>
            <template #title><VChip :color="sigColor(status)" size="small" label>{{ sigLabel(status) }}</VChip></template>
          </VListItem>
        </VList>

        <VDivider class="my-3" />

        <!-- Document -->
        <div class="text-overline text-medium-emphasis mb-2">{{ t('workforceDocuments.detail.document') }}</div>
        <div class="d-flex gap-2 mb-4">
          <VBtn variant="tonal" size="small" prepend-icon="tabler-eye" @click="emit('preview', document)">{{ t('workforceDocuments.preview') }}</VBtn>
          <VBtn variant="tonal" size="small" prepend-icon="tabler-download" @click="emit('download', document)">{{ t('workforceDocuments.download') }}</VBtn>
        </div>

        <!-- Signature : actions selon l'état réel -->
        <div class="text-overline text-medium-emphasis mb-2">{{ t('workforceDocuments.detail.signature') }}</div>
        <VAlert v-if="status === 'none' && !canRequest" type="info" variant="tonal" density="compact" class="mb-2">
          {{ t('workforceDocuments.detail.noSignatureYet') }}
        </VAlert>
        <div class="d-flex flex-column gap-2">
          <VBtn
            v-if="status === 'none' && canRequest"
            color="primary"
            variant="tonal"
            size="small"
            prepend-icon="tabler-send"
            data-test="detail-request-signature"
            @click="emit('request-signature', document)"
          >
            {{ t('workforceDocuments.detail.requestSignature') }}
          </VBtn>
          <VBtn
            v-if="hasSignatureRequest"
            variant="tonal"
            size="small"
            prepend-icon="tabler-list-check"
            data-test="detail-track-signature"
            @click="emit('go-signatures', document)"
          >
            {{ t('workforceDocuments.detail.trackSignature') }}
          </VBtn>
        </div>
      </div>

      <!-- Pied : contexte collaborateur -->
      <div v-if="doc.employee_id" class="pa-4 border-t">
        <VBtn block variant="outlined" prepend-icon="tabler-user-circle" data-test="detail-open-fiche" @click="emit('open-fiche', document)">
          {{ t('workforceDocuments.detail.openFiche') }}
        </VBtn>
      </div>
    </div>
  </VNavigationDrawer>
</template>
