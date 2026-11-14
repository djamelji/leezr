<script setup>
import CompanyDocumentsVault from '@/views/pages/company-settings/CompanyDocumentsVault.vue'
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'
import { useAuthStore } from '@/core/stores/auth'
import { useAppToast } from '@/composables/useAppToast'
import { $api } from '@/utils/api'

const { t } = useI18n()
const store = useCompanyDocumentsStore()
const auth = useAuthStore()

const canEdit = computed(() => auth.hasPermission('documents.manage'))
const canConfigure = computed(() => auth.hasPermission('documents.configure'))

const companyDocuments = computed(() => store.companyDocuments)
const hasDocuments = computed(() => companyDocuments.value.length > 0)

const uploadedCount = computed(() =>
  companyDocuments.value.filter(d => d.upload).length,
)

const emit = defineEmits(['openCreateDrawer'])

// ADR-772 — obligations portées par l'ENTREPRISE : une demande de renouvellement interne suit le même
// circuit que pour une personne (à fournir → soumis → validé / refusé), sur le coffre de l'entreprise.
const { toast } = useAppToast()
const renewals = computed(() => companyDocuments.value.filter(d => d.request_status && d.request_status !== 'approved' && d.request_status !== 'cancelled'))
const reviewing = ref(null)
const RENEWAL = {
  requested: { color: 'warning', key: 'requested' },
  to_replace: { color: 'warning', key: 'requested' },
  rejected: { color: 'error', key: 'rejected' },
  submitted: { color: 'info', key: 'submitted' },
  in_review: { color: 'info', key: 'submitted' },
}
const extendVisible = ref(false)
const extendDate = ref('')
const extendDoc = ref(null)
const openExtend = doc => { extendDoc.value = doc; extendDate.value = ''; extendVisible.value = true }
const confirmExtend = async () => { extendVisible.value = false; await review(extendDoc.value, 'approved', extendDate.value) }
const review = async (doc, status, extendUntil = null) => {
  reviewing.value = `${doc.code}-${status}`
  try {
    await $api(`/company/documents/${doc.code}/review`, { method: 'PUT', body: { status, extend_until: extendUntil } })
    toast(t('documents.reviewSaved'), 'success')
    await store.fetchCompanyDocuments()
  }
  catch (e) { toast(Object.values(e?.data?.errors || {})[0]?.[0] || e?.data?.message || t('documents.reviewFailed'), 'error') }
  finally { reviewing.value = null }
}
</script>

<template>
  <VSkeletonLoader
    v-if="store.loading.vault"
    type="table"
  />
  <VCard v-else>
    <VCardItem>
      <template #prepend>
        <VAvatar
          color="success"
          variant="tonal"
          rounded
        >
          <VIcon icon="tabler-folder" />
        </VAvatar>
      </template>
      <VCardTitle>{{ t('companyDocuments.vault.title') }}</VCardTitle>
      <VCardSubtitle>{{ t('companyDocuments.vault.hint') }}</VCardSubtitle>
      <template #append>
        <VBtn
          v-if="canConfigure"
          v-can="'documents.configure'"
          variant="tonal"
          color="success"
          size="small"
          @click="emit('openCreateDrawer')"
        >
          <VIcon
            icon="tabler-plus"
            size="18"
            start
          />
          {{ t('companyProfile.createType') }}
        </VBtn>
      </template>
    </VCardItem>
    <VCardText>
      <div
        v-if="hasDocuments"
        class="d-flex flex-wrap gap-2 mb-4"
      >
        <VChip
          variant="tonal"
          color="success"
        >
          <VIcon
            icon="tabler-upload"
            size="14"
            start
          />
          {{ t('documents.summaryUploaded', { count: uploadedCount }) }}
        </VChip>
      </div>

      <VList
        v-if="renewals.length"
        lines="two"
        border
        rounded
        class="py-0 mb-4"
        data-test="company-renewals"
      >
        <VListItem
          v-for="(doc, i) in renewals"
          :key="doc.code"
          :class="{ 'border-t': i > 0 }"
          :data-test="`company-renewal-${doc.code}`"
        >
          <VListItemTitle class="font-weight-medium">
            {{ doc.label }}
          </VListItemTitle>
          <VListItemSubtitle>
            {{ t('companyDocuments.renewal.' + (RENEWAL[doc.request_status]?.key || 'requested') + 'Hint') }}
            <span v-if="doc.request_review_note"> — {{ doc.request_review_note }}</span>
          </VListItemSubtitle>
          <template #append>
            <div class="d-flex align-center flex-wrap justify-end gap-2">
              <VChip
                :color="RENEWAL[doc.request_status]?.color || 'warning'"
                size="small"
                variant="tonal"
                label
              >
                {{ t('companyDocuments.renewal.' + (RENEWAL[doc.request_status]?.key || 'requested')) }}
              </VChip>
              <VBtn
                v-if="canEdit && doc.upload?.expires_at"
                size="small"
                variant="text"
                data-test="company-renewal-extend"
                @click="openExtend(doc)"
              >
                {{ t('documents.extend') }}
              </VBtn>
              <template v-if="doc.can_review && canEdit">
                <VBtn
                  size="small"
                  color="success"
                  variant="tonal"
                  :loading="reviewing === `${doc.code}-approved`"
                  data-test="company-renewal-approve"
                  @click="review(doc, 'approved')"
                >
                  {{ t('documents.approve') }}
                </VBtn>
                <VBtn
                  size="small"
                  color="error"
                  variant="tonal"
                  :loading="reviewing === `${doc.code}-rejected`"
                  @click="review(doc, 'rejected')"
                >
                  {{ t('documents.reject') }}
                </VBtn>
              </template>
            </div>
          </template>
        </VListItem>
      </VList>

      <CompanyDocumentsVault
        v-if="hasDocuments"
        :documents="companyDocuments"
        :can-edit="canEdit"
        @refresh="store.fetchCompanyDocuments()"
      />

      <VAlert
        v-else
        type="info"
        variant="tonal"
      >
        {{ t('companyProfile.noCompanyDocuments') }}
      </VAlert>
    </VCardText>
  </VCard>

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
</template>
