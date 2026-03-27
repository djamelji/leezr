<script setup>
import DocumentsActivityTimeline from './_DocumentsActivityTimeline.vue'
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'

const { t } = useI18n()
const store = useCompanyDocumentsStore()

const rateColor = computed(() => {
  const rate = store.complianceRate
  if (rate >= 80) return 'success'
  if (rate >= 50) return 'warning'

  return 'error'
})

const kpis = computed(() => [
  {
    title: t('companyDocuments.overview.complianceRate'),
    value: `${store.complianceRate}%`,
    icon: 'tabler-shield-check',
    color: rateColor.value,
    progress: store.complianceRate,
  },
  {
    title: t('companyDocuments.overview.missing'),
    value: store.missingCount,
    icon: 'tabler-file-off',
    color: store.missingCount > 0 ? 'error' : 'success',
  },
  {
    title: t('companyDocuments.overview.expired'),
    value: store.expiredCount,
    icon: 'tabler-file-x',
    color: store.expiredCount > 0 ? 'error' : 'success',
  },
  {
    title: t('companyDocuments.overview.pendingRequests'),
    value: store.submittedRequestsCount,
    icon: 'tabler-file-upload',
    color: store.submittedRequestsCount > 0 ? 'warning' : 'info',
  },
])

const roleColor = (rate, index) => {
  if (rate >= 80) return 'success'
  if (rate >= 50) return 'warning'

  return 'error'
}

const typeColor = (rate, index) => {
  const palette = ['primary', 'info', 'success', 'warning', 'error']
  if (rate >= 80) return 'success'
  if (rate >= 50) return palette[index % palette.length]

  return 'error'
}

const emit = defineEmits(['navigate'])
</script>

<template>
  <div>
    <!-- KPI Cards -->
    <VRow class="card-grid card-grid-xs">
      <VCol
        v-for="kpi in kpis"
        :key="kpi.title"
        cols="12"
        sm="6"
        md="3"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-4">
            <VAvatar
              v-if="kpi.progress == null"
              :color="kpi.color"
              variant="tonal"
              rounded
              size="42"
            >
              <VIcon
                :icon="kpi.icon"
                size="24"
              />
            </VAvatar>
            <VProgressCircular
              v-else
              :model-value="kpi.progress"
              :size="48"
              :width="4"
              :color="kpi.color"
            >
              <VIcon
                :icon="kpi.icon"
                size="20"
                :color="kpi.color"
              />
            </VProgressCircular>
            <div>
              <div class="text-h5 font-weight-bold">
                {{ kpi.value }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ kpi.title }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="card-grid card-grid-lg mt-2">
      <!-- Compliance by Role (VList + VProgressLinear) -->
      <VCol
        cols="12"
        md="6"
      >
        <VCard>
          <VCardItem>
            <template #prepend>
              <VAvatar
                color="primary"
                variant="tonal"
                rounded
              >
                <VIcon icon="tabler-users-group" />
              </VAvatar>
            </template>
            <VCardTitle>{{ t('companyDocuments.overview.byRole') }}</VCardTitle>
            <VCardSubtitle>{{ t('companyDocuments.overview.byRoleHint') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VList
              v-if="store.complianceByRole.length > 0"
              class="card-list"
            >
              <VListItem
                v-for="(role, i) in store.complianceByRole"
                :key="role.role_key"
              >
                <template #prepend>
                  <VAvatar
                    rounded
                    size="34"
                    :color="roleColor(role.rate, i)"
                    variant="tonal"
                    class="me-1"
                  >
                    <VIcon
                      size="22"
                      icon="tabler-users"
                    />
                  </VAvatar>
                </template>
                <VListItemTitle class="font-weight-medium">
                  {{ role.role_name }}
                </VListItemTitle>
                <VListItemSubtitle class="me-4">
                  {{ role.member_count }} {{ t('companyDocuments.overview.members').toLowerCase() }}
                  <template v-if="role.missing > 0">
                    · <span class="text-error">{{ role.missing }} {{ t('companyDocuments.overview.missing').toLowerCase() }}</span>
                  </template>
                </VListItemSubtitle>
                <template #append>
                  <div class="d-flex align-center gap-x-4">
                    <div style="inline-size: 4.875rem;">
                      <VProgressLinear
                        :model-value="role.rate"
                        :color="roleColor(role.rate, i)"
                        height="8"
                        rounded-bar
                        rounded
                      />
                    </div>
                    <span class="text-body-2 text-medium-emphasis" style="min-inline-size: 3rem; text-align: end;">{{ role.rate }}%</span>
                  </div>
                </template>
              </VListItem>
            </VList>
            <VAlert
              v-else
              type="info"
              variant="tonal"
            >
              {{ t('companyDocuments.overview.noData') }}
            </VAlert>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Compliance by Type (VList + VProgressCircular) -->
      <VCol
        cols="12"
        md="6"
      >
        <VCard>
          <VCardItem>
            <template #prepend>
              <VAvatar
                color="info"
                variant="tonal"
                rounded
              >
                <VIcon icon="tabler-file-check" />
              </VAvatar>
            </template>
            <VCardTitle>{{ t('companyDocuments.overview.byType') }}</VCardTitle>
            <VCardSubtitle>{{ t('companyDocuments.overview.byTypeHint') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VList
              v-if="store.complianceByType.length > 0"
              class="card-list"
            >
              <VListItem
                v-for="(docType, i) in store.complianceByType"
                :key="docType.code"
              >
                <template #prepend>
                  <VProgressCircular
                    :model-value="docType.rate"
                    :size="46"
                    :width="3"
                    class="me-4"
                    :color="typeColor(docType.rate, i)"
                  >
                    <span class="text-caption text-high-emphasis font-weight-medium">
                      {{ docType.rate }}%
                    </span>
                  </VProgressCircular>
                </template>
                <VListItemTitle class="font-weight-medium mb-1 me-2">
                  {{ t(`documents.type.${docType.code}`, docType.label) }}
                </VListItemTitle>
                <VListItemSubtitle class="me-2">
                  {{ docType.valid }}/{{ docType.total }} {{ t('companyDocuments.compliance.valid').toLowerCase() }}
                  <template v-if="docType.missing > 0">
                    · <span class="text-error">{{ docType.missing }} {{ t('companyDocuments.overview.missing').toLowerCase() }}</span>
                  </template>
                </VListItemSubtitle>
              </VListItem>
            </VList>
            <VAlert
              v-else
              type="info"
              variant="tonal"
            >
              {{ t('companyDocuments.overview.noData') }}
            </VAlert>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Activity Timeline -->
    <DocumentsActivityTimeline class="mt-6" />

    <!-- Quick Actions -->
    <VCard class="mt-6">
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="warning"
            variant="tonal"
            rounded
          >
            <VIcon icon="tabler-bolt" />
          </VAvatar>
        </template>
        <VCardTitle>{{ t('companyDocuments.overview.quickActions') }}</VCardTitle>
      </VCardItem>
      <VCardText>
        <div class="d-flex flex-wrap gap-3">
          <VBtn
            v-if="store.missingCount > 0"
            variant="tonal"
            color="error"
            @click="emit('navigate', 'compliance')"
          >
            <VIcon
              icon="tabler-file-off"
              start
            />
            {{ t('companyDocuments.overview.viewMissing', { count: store.missingCount }) }}
          </VBtn>
          <VBtn
            v-if="store.submittedRequestsCount > 0"
            variant="tonal"
            color="warning"
            @click="emit('navigate', 'requests')"
          >
            <VIcon
              icon="tabler-file-upload"
              start
            />
            {{ t('companyDocuments.overview.viewPending', { count: store.submittedRequestsCount }) }}
          </VBtn>
          <VBtn
            variant="tonal"
            color="info"
            @click="emit('navigate', 'settings')"
          >
            <VIcon
              icon="tabler-file-settings"
              start
            />
            {{ t('companyDocuments.overview.manageTypes') }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>
  </div>
</template>

<style lang="scss" scoped>
.card-list {
  --v-card-list-gap: 16px;
}
</style>
