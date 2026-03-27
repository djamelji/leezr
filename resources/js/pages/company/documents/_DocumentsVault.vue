<script setup>
import CompanyDocumentsVault from '@/views/pages/company-settings/CompanyDocumentsVault.vue'
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'
import { useAuthStore } from '@/core/stores/auth'

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
</script>

<template>
  <VCard>
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
</template>
