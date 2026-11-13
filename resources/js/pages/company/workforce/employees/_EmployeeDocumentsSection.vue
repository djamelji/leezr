<script setup>
// ADR-640 (W-C) + ADR-771 — documents GÉNÉRÉS du collaborateur, classés dans son dossier PAR RÉFÉRENCE
// (contrat compris : son sujet est le contrat RH, il n'apparaissait pas ici et il fallait le
// téléverser à la main). L'état de signature est celui des demandes réelles ; la version signée est
// servie au même endroit une fois la signature finalisée, l'original reste téléchargeable.
import { $api } from '@/utils/api'
import { formatDate } from '@/utils/datetime'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({ employeeId: { type: [String, Number], required: true } })
const { t } = useI18n()
const router = useRouter()
const { toast } = useAppToast()
const loading = ref(true)
const docs = ref([])
const busy = ref(null)

const SIGNATURE = {
  pending: { color: 'warning', key: 'signaturePending' },
  signed: { color: 'success', key: 'signatureSigned' },
  declined: { color: 'error', key: 'signatureDeclined' },
  expired: { color: 'secondary', key: 'signatureExpired' },
  cancelled: { color: 'secondary', key: 'signatureCancelled' },
  failed: { color: 'error', key: 'signatureFailed' },
}

onMounted(async () => {
  try { docs.value = (await $api(`/workforce/documents/by-employee/${props.employeeId}`)).documents || [] }
  catch { docs.value = [] }
  finally { loading.value = false }
})

const download = async (doc, signed = false) => {
  busy.value = `${doc.id}-${signed}`
  try {
    const blob = await $api(`/workforce/documents/${doc.id}/${signed ? 'signed' : 'download'}`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = `${doc.template_code || 'document'}-${doc.id}${signed ? '-signe' : ''}.pdf`
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 10000)
  }
  catch { toast(t('common.error'), 'error') }
  finally { busy.value = null }
}
</script>

<template>
  <div data-test="employee-generated-documents">
    <VSkeletonLoader
      v-if="loading"
      type="table-row@3"
    />
    <template v-else>
      <VTable
        v-if="docs.length"
        density="compact"
      >
        <thead>
          <tr>
            <th>{{ t('employeeFile.document') }}</th>
            <th>{{ t('employeeFile.date') }}</th>
            <th>{{ t('employeeFile.signature') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="d in docs"
            :key="d.id"
            :data-test="`generated-document-${d.id}`"
          >
            <td class="font-weight-medium">
              {{ d.label }}
            </td>
            <td>{{ formatDate(d.generated_at) }}</td>
            <td>
              <VChip
                v-if="SIGNATURE[d.signature_status]"
                :color="SIGNATURE[d.signature_status].color"
                size="x-small"
                variant="tonal"
                label
              >
                {{ t('employeeFile.' + SIGNATURE[d.signature_status].key) }}
              </VChip>
              <span
                v-else
                class="text-disabled"
              >—</span>
            </td>
            <td class="text-end text-no-wrap">
              <template v-if="d.can_download">
                <VBtn
                  v-if="d.signed.available"
                  size="small"
                  variant="tonal"
                  color="success"
                  prepend-icon="tabler-certificate"
                  class="me-2"
                  :loading="busy === `${d.id}-true`"
                  data-test="generated-document-signed"
                  @click="download(d, true)"
                >
                  {{ t('employeeFile.signedVersion') }}
                </VBtn>
                <IconBtn
                  size="small"
                  :aria-label="t('employeeFile.downloadOriginal')"
                  :title="t('employeeFile.downloadOriginal')"
                  :loading="busy === `${d.id}-false`"
                  @click="download(d)"
                >
                  <VIcon icon="tabler-download" />
                </IconBtn>
              </template>
              <span
                v-else
                class="text-caption text-medium-emphasis"
              >{{ t('employeeFile.restricted') }}</span>
            </td>
          </tr>
        </tbody>
      </VTable>
      <div
        v-else
        class="text-center text-disabled py-4"
      >
        {{ t('employeeFile.noDocuments') }}
      </div>
      <VBtn
        class="mt-3"
        variant="text"
        size="small"
        append-icon="tabler-arrow-right"
        @click="router.push('/company/documents/hr')"
      >
        {{ t('employeeFile.seeAll') }}
      </VBtn>
    </template>
  </div>
</template>
