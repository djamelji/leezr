<script setup>
// ADR-762 (DMS M5) — centre « Import de bulletins » : upload d'un lot, revue des correspondances
// (certaines / à vérifier / non identifiées), affectation manuelle, publication contrôlée.
import { computed, ref, onMounted } from 'vue'
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import { useCompanyPermissionContext } from '@/composables/useCompanyPermissionContext'

const { t } = useI18n()
const { toast } = useAppToast()

// ADR-767 : chaque action est gated par SA permission (miroir des routes serveur). Un importateur
// téléverse et suit l'état de SES lots ; la revue et la publication exigent leurs droits propres.
const { can } = useCompanyPermissionContext()
const canImport = computed(() => can('payslips.import'))
const canReview = computed(() => can('payslips.review'))
const canPublish = computed(() => can('payslips.publish'))

const batches = ref([])
const uploading = ref(false)
const reviewOpen = ref(false)
const activeBatch = ref(null)
const summary = ref(null)
const items = ref([])
const publishing = ref(false)

const fetchBatches = async () => {
  try { batches.value = (await $api('/company/payslip-imports')).batches || [] }
  catch { /* */ }
}

const upload = async e => {
  const files = e.target.files
  if (!files?.length) return
  uploading.value = true
  try {
    const fd = new FormData()

    Array.from(files).forEach(f => fd.append('files[]', f))
    await $api('/company/payslip-imports', { method: 'POST', body: fd })
    toast(t('documents.payslip.imported'), 'success')
    await fetchBatches()
  }
  catch (err) { toast(err?.data?.message || t('common.error'), 'error') }
  finally { uploading.value = false; e.target.value = '' }
}

const openReview = async batch => {
  activeBatch.value = batch
  reviewOpen.value = true
  try {
    summary.value = await $api(`/company/payslip-imports/${batch.id}`)
    items.value = (await $api(`/company/payslip-imports/${batch.id}/items`)).items || []
  }
  catch { summary.value = null; items.value = [] }
}

const publish = async () => {
  publishing.value = true
  try {
    await $api(`/company/payslip-imports/${activeBatch.value.id}/publish`, { method: 'POST' })
    toast(t('documents.payslip.published'), 'success')
    await openReview(activeBatch.value)
    await fetchBatches()
  }
  catch (e) { toast(e?.data?.message || t('common.error'), 'error') }
  finally { publishing.value = false }
}

// ADR-764 (M5.2) — éditeur de revue : exclure un segment, affecter un candidat.
const reload = async () => {
  summary.value = await $api(`/company/payslip-imports/${activeBatch.value.id}`)
  items.value = (await $api(`/company/payslip-imports/${activeBatch.value.id}/items`)).items || []
}

const excludeItem = async it => {
  try { await $api(`/company/payslip-imports/${activeBatch.value.id}/items/${it.id}/exclude`, { method: 'PUT' }); await reload() }
  catch { toast(t('common.error'), 'error') }
}

const assignCandidate = async (it, candidateId) => {
  try { await $api(`/company/payslip-imports/${activeBatch.value.id}/items/${it.id}/assign`, { method: 'PUT', body: { employee_id: candidateId } }); await reload() }
  catch { toast(t('common.error'), 'error') }
}

const reasonLabel = r => (r ? t(`documents.payslip.reason.${r}`) : '')

const confidenceColor = c => ({ certain: 'success', probable: 'warning', ambiguous: 'error', none: 'secondary' }[c] || 'secondary')
const stateColor = s => ({ matched: 'success', published: 'success', review: 'warning', excluded: 'secondary', failed: 'error', pending: 'info' }[s] || 'secondary')

onMounted(fetchBatches)
</script>

<template>
  <VCard>
    <VCardItem>
      <VCardTitle>{{ t('documents.payslip.title') }}</VCardTitle>
      <VCardSubtitle>{{ t('documents.payslip.subtitle') }}</VCardSubtitle>
      <template #append>
        <VBtn
          v-if="canImport"
          color="primary"
          prepend-icon="tabler-upload"
          :loading="uploading"
          @click="$refs.fileInput.click()"
        >
          {{ t('documents.payslip.import') }}
        </VBtn>
        <input
          ref="fileInput"
          type="file"
          accept=".pdf,.zip"
          multiple
          hidden
          @change="upload"
        >
      </template>
    </VCardItem>

    <VCardText>
      <VTable v-if="batches.length">
        <thead>
          <tr>
            <th>#</th>
            <th>{{ t('documents.payslip.status') }}</th>
            <th>{{ t('documents.payslip.detected') }}</th>
            <th>{{ t('documents.payslip.publishedCount') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="b in batches"
            :key="b.id"
          >
            <td>{{ b.id }}</td>
            <td>
              <VChip
                size="small"
                :color="stateColor(b.status)"
              >
                {{ t(`documents.payslip.state.${b.status}`) }}
              </VChip>
            </td>
            <td>{{ b.items_total }}</td>
            <td>{{ b.items_published }}</td>
            <td class="text-end">
              <VBtn
                v-if="canReview"
                size="small"
                variant="text"
                @click="openReview(b)"
              >
                {{ t('documents.payslip.review') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
      <div
        v-else
        class="text-body-2 text-medium-emphasis py-6 text-center"
      >
        {{ t('documents.payslip.empty') }}
      </div>
    </VCardText>

    <!-- Revue -->
    <VDialog
      v-model="reviewOpen"
      max-width="860"
    >
      <VCard v-if="summary">
        <VCardItem>
          <VCardTitle>{{ t('documents.payslip.reviewTitle', { id: activeBatch?.id }) }}</VCardTitle>
          <template #append>
            <VBtn
              v-if="canPublish"
              color="primary"
              :loading="publishing"
              :disabled="(summary.counts.matched || 0) === 0"
              prepend-icon="tabler-send"
              @click="publish"
            >
              {{ t('documents.payslip.publish') }}
            </VBtn>
          </template>
        </VCardItem>
        <VCardText>
          <div class="d-flex flex-wrap gap-2 mb-4">
            <VChip
              size="small"
              color="success"
            >
              {{ t('documents.payslip.counts.certain') }}: {{ summary.counts.certain }}
            </VChip>
            <VChip
              size="small"
              color="warning"
            >
              {{ t('documents.payslip.counts.review') }}: {{ summary.counts.review }}
            </VChip>
            <VChip
              size="small"
              color="error"
            >
              {{ t('documents.payslip.counts.unidentified') }}: {{ summary.counts.unidentified }}
            </VChip>
            <VChip
              size="small"
              color="success"
              variant="outlined"
            >
              {{ t('documents.payslip.counts.published') }}: {{ summary.counts.published }}
            </VChip>
          </div>

          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('documents.payslip.page') }}</th>
                <th>{{ t('documents.payslip.matricule') }}</th>
                <th>{{ t('documents.dueDate') }}</th>
                <th>{{ t('documents.payslip.matched') }}</th>
                <th>{{ t('documents.payslip.confidence') }}</th>
                <th>{{ t('documents.payslip.statusCol') }}</th>
                <th>{{ t('documents.payslip.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="it in items"
                :key="it.id"
              >
                <td>{{ it.page_range }}</td>
                <td>{{ it.extracted_matricule || '—' }}</td>
                <td>{{ it.extracted_period || '—' }}</td>
                <td>{{ it.matched_employee || '—' }}</td>
                <td>
                  <VChip
                    size="x-small"
                    :color="confidenceColor(it.confidence)"
                  >
                    {{ t(`documents.payslip.conf.${it.confidence}`) }}
                  </VChip>
                  <!-- ADR-764 (M5.2) : explication de l'incertitude -->
                  <div
                    v-if="it.review_reason"
                    class="text-caption text-medium-emphasis mt-1"
                  >
                    {{ reasonLabel(it.review_reason) }}
                  </div>
                </td>
                <td>
                  <VChip
                    size="x-small"
                    :color="stateColor(it.state)"
                  >
                    {{ t(`documents.payslip.itemState.${it.state}`) }}
                  </VChip>
                </td>
                <td>
                  <div
                    v-if="it.state === 'review'"
                    class="d-flex align-center gap-1 flex-wrap"
                  >
                    <!-- Affectation d'un candidat (ambigu/probable) -->
                    <VBtn
                      v-for="c in it.candidates"
                      :key="c.id"
                      size="x-small"
                      variant="tonal"
                      color="success"
                      @click="assignCandidate(it, c.id)"
                    >
                      {{ c.name }}
                    </VBtn>
                    <VBtn
                      :aria-label="$t('common.close')"
                      size="x-small"
                      variant="text"
                      color="error"
                      icon="tabler-x"
                      @click="excludeItem(it)"
                    />
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn @click="reviewOpen = false">
            {{ t('common.close') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>
