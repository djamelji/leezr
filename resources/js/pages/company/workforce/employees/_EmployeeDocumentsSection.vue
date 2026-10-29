<script setup>
// ADR-640 (W-C) — documents du collaborateur (lazy). Téléchargement AUTHENTIFIÉ (W-D), jamais file_path brut.
import { $api } from '@/utils/api'
import { formatDate } from '@/utils/datetime'
import { useWorkforceDocumentsStore } from '@/modules/company/workforce/workforce-documents.store'
const props = defineProps({ employeeId: { type: [String, Number], required: true } })
const { t } = useI18n()
const router = useRouter()
const store = useWorkforceDocumentsStore()
const loading = ref(true)
const docs = ref([])
onMounted(async () => {
  try {
    const d = await $api(`/workforce/documents?subject_type=employee&subject_id=${props.employeeId}`)
    docs.value = d.data ?? d ?? []
  } catch { /* */ } finally { loading.value = false }
})
const download = async doc => {
  try {
    const blob = await store.downloadDocument(doc.id)
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a'); a.href = url; a.download = `${doc.template_code || 'document'}-${doc.id}.pdf`; a.click()
    setTimeout(() => URL.revokeObjectURL(url), 10000)
  } catch { /* */ }
}
</script>
<template>
  <div>
    <VSkeletonLoader v-if="loading" type="table-row@3" />
    <template v-else>
      <VTable v-if="docs.length" density="compact">
        <thead><tr><th>{{ t('employeeFile.document') }}</th><th>{{ t('employeeFile.date') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="d in docs" :key="d.id">
            <td>{{ d.template_code || d.subject_type }}</td>
            <td>{{ formatDate(d.generated_at || d.created_at) }}</td>
            <td class="text-end"><IconBtn size="small" :title="t('employeeFile.download')" @click="download(d)"><VIcon icon="tabler-download" /></IconBtn></td>
          </tr>
        </tbody>
      </VTable>
      <div v-else class="text-center text-disabled py-4">{{ t('employeeFile.noDocuments') }}</div>
      <VBtn class="mt-3" variant="text" size="small" append-icon="tabler-arrow-right" @click="router.push('/company/workforce/documents')">{{ t('employeeFile.seeAll') }}</VBtn>
    </template>
  </div>
</template>
