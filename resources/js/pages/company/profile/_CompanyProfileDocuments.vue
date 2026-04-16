<script setup>
import CompanyDocumentsVault from '@/views/pages/company-settings/CompanyDocumentsVault.vue'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAuthStore } from '@/core/stores/auth'

const { t } = useI18n()
const settingsStore = useCompanySettingsStore()
const auth = useAuthStore()

const canEdit = computed(() => auth.hasPermission('settings.manage'))

// ─── Vault ──────────────────────────────────────────────
const companyDocuments = computed(() => settingsStore.companyDocuments)
const hasDocuments = computed(() => companyDocuments.value.length > 0)

const uploadedCount = computed(() =>
  companyDocuments.value.filter(d => d.upload).length,
)

const storageInfo = computed(() => settingsStore.company?.storage || null)

const activeCompanyCount = computed(() => {
  const docs = settingsStore.documentActivations?.company_documents || []

  return docs.filter(d => d.enabled).length
})

const emit = defineEmits(['openTypesDrawer', 'openCreateDrawer'])
</script>

<template>
  <div>
    <!-- Storage Usage -->
    <VCard v-if="storageInfo">
      <VCardItem>
        <template #prepend>
          <VAvatar
            :color="storageInfo.warning ? 'error' : 'primary'"
            variant="tonal"
            rounded
          >
            <VIcon icon="tabler-database" />
          </VAvatar>
        </template>
        <VCardTitle>{{ t('companySettings.storageTitle') }}</VCardTitle>
        <VCardSubtitle>{{ storageInfo.used_display }} / {{ storageInfo.limit_display }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VAlert
          v-if="storageInfo.blocked"
          type="error"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ t('companySettings.storageBlocked') }}
        </VAlert>
        <VAlert
          v-else-if="storageInfo.warning"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ t('companySettings.storageWarning') }}
        </VAlert>

        <VProgressLinear
          :model-value="storageInfo.percentage"
          :color="storageInfo.blocked ? 'error' : storageInfo.warning ? 'warning' : 'primary'"
          height="8"
          rounded
        />
        <div class="d-flex justify-space-between mt-2">
          <span class="text-body-2 text-medium-emphasis">{{ storageInfo.percentage }}%</span>
          <span class="text-body-2 text-medium-emphasis">{{ storageInfo.limit_display }}</span>
        </div>
      </VCardText>
    </VCard>

    <!-- Company Documents Vault -->
    <VCard :class="storageInfo ? 'mt-6' : ''">
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
        <VCardTitle>{{ t('companyProfile.companyVault') }}</VCardTitle>
        <VCardSubtitle>{{ t('companyProfile.companyVaultHint') }}</VCardSubtitle>
        <template #append>
          <div class="d-flex align-center gap-2">
            <VBtn
              v-if="canEdit"
              v-can="'settings.manage'"
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
            <VBtn
              v-can="'settings.manage'"
              variant="tonal"
              color="info"
              size="small"
              @click="emit('openTypesDrawer')"
            >
              <VIcon
                icon="tabler-file-settings"
                size="18"
                start
              />
              {{ t('companyProfile.manageTypes') }}
            </VBtn>
          </div>
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
          <VChip
            variant="tonal"
            color="primary"
          >
            <VIcon
              icon="tabler-file-settings"
              size="14"
              start
            />
            {{ t('documents.summaryActiveCompany', { count: activeCompanyCount }) }}
          </VChip>
        </div>

        <CompanyDocumentsVault
          v-if="hasDocuments"
          :documents="companyDocuments"
          :can-edit="canEdit"
          @refresh="settingsStore.fetchCompanyDocuments()"
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
  </div>
</template>
