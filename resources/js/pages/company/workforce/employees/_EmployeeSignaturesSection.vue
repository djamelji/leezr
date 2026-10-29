<script setup>
// W-SIGNATURE-1 (ADR-651) — intégration fiche collaborateur : demandes de signature liées
// (contrat, attestations…) avec statut + date de signature + téléchargement du document signé.
import { useSignatureStore } from '@/modules/company/workforce/signature.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({ employeeId: { type: [String, Number], required: true } })

const { t } = useI18n()
const store = useSignatureStore()
const { toast } = useAppToast()

const loading = ref(true)
const items = ref([])
const downloadingId = ref(null)

const STATUS_COLOR = {
  draft: 'secondary', pending: 'warning', partially_signed: 'info', completed: 'success',
  declined: 'error', cancelled: 'secondary', expired: 'secondary', failed: 'error',
}

const load = async () => {
  loading.value = true
  try { items.value = await store.fetchByEmployee(props.employeeId) }
  catch { /* module inactif ou sans droit : section masquée */ }
  finally { loading.value = false }
}
onMounted(load)

const signedAt = r => {
  const ev = r // present() n'expose pas signed_at directement ; on s'appuie sur completed_at
  return r.completed_at ? new Date(r.completed_at).toLocaleDateString() : null
}

const download = async (r, save = true) => {
  downloadingId.value = r.id
  try {
    const blob = await store.downloadSigned(r.id)
    const url = URL.createObjectURL(blob)
    if (save) { const a = document.createElement('a'); a.href = url; a.download = `document-signe-${r.id}.pdf`; a.click() }
    else { window.open(url, '_blank') }
    setTimeout(() => URL.revokeObjectURL(url), 10000)
  } catch (e) { toast(e.response?._data?.message || t('common.error'), 'error') } finally { downloadingId.value = null }
}
</script>

<template>
  <VCard variant="outlined">
    <VCardItem>
      <template #prepend>
        <VIcon icon="tabler-signature" color="primary" />
      </template>
      <VCardTitle class="text-subtitle-1">
        {{ t('employeeFile.signatures.title') }}
      </VCardTitle>
    </VCardItem>
    <VCardText>
      <VProgressLinear v-if="loading" indeterminate color="primary" />
      <div v-else-if="!items.length" class="text-center text-disabled py-3">
        {{ t('employeeFile.signatures.empty') }}
      </div>
      <VList v-else lines="two" class="py-0">
        <VListItem v-for="r in items" :key="r.id">
          <VListItemTitle>{{ r.document_label || t('signature.unknownDocument') }}</VListItemTitle>
          <VListItemSubtitle>
            {{ r.signers?.length || 0 }} {{ t('employeeFile.signatures.signers') }}
            <template v-if="signedAt(r)"> · {{ t('employeeFile.signatures.signedOn') }} {{ signedAt(r) }}</template>
          </VListItemSubtitle>
          <template #append>
            <div class="d-flex align-center gap-2">
              <VChip :color="STATUS_COLOR[r.status]" size="small" label>
                {{ t(`signature.status.${r.status}`) }}
              </VChip>
              <IconBtn
                v-if="r.has_signed_document"
                size="small"
                :loading="downloadingId === r.id"
                :title="t('signature.detail.download')"
                @click="download(r, true)"
              >
                <VIcon icon="tabler-download" />
              </IconBtn>
            </div>
          </template>
        </VListItem>
      </VList>
    </VCardText>
  </VCard>
</template>
