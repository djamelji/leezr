<script setup>
import { $platformApi } from '@/utils/platformApi'

const { t } = useI18n()

const isLoading = ref(true)
const data = ref(null)
const days = ref(30)

const load = async () => {
  isLoading.value = true
  try {
    data.value = await $platformApi(`/usage/overview?days=${days.value}`)
  }
  catch {
    // silent
  }
  finally {
    isLoading.value = false
  }
}

const formatNumber = n => {
  if (n >= 1000000)
    return `${(n / 1000000).toFixed(1)}M`
  if (n >= 1000)
    return `${(n / 1000).toFixed(1)}K`

  return String(n)
}

watch(days, load)
onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4">
          {{ t('usageMonitoring.title') }}
        </h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('usageMonitoring.subtitle') }}
        </p>
      </div>
      <div class="d-flex align-center gap-3">
        <AppSelect
          v-model="days"
          :items="[{ title: '7 jours', value: 7 }, { title: '30 jours', value: 30 }, { title: '90 jours', value: 90 }]"
          style="inline-size: 140px"
          density="compact"
        />
        <VBtn
          variant="tonal"
          size="small"
          :loading="isLoading"
          @click="load"
        >
          <VIcon
            start
            icon="tabler-refresh"
            size="18"
          />
          {{ t('common.refresh') }}
        </VBtn>
      </div>
    </div>

    <VSkeletonLoader
      v-if="isLoading && !data"
      type="card, card, card"
    />

    <template v-if="data">
      <!-- KPI Cards -->
      <VRow class="card-grid card-grid-xs mb-6">
        <VCol
          cols="6"
          md="2"
        >
          <VCard>
            <VCardText class="text-center">
              <VIcon
                icon="tabler-brain"
                size="28"
                class="text-primary mb-1"
              />
              <div class="text-h5">
                {{ formatNumber(data.totals.ai_requests) }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('usageMonitoring.aiRequests') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol
          cols="6"
          md="2"
        >
          <VCard>
            <VCardText class="text-center">
              <VIcon
                icon="tabler-coins"
                size="28"
                class="text-warning mb-1"
              />
              <div class="text-h5">
                {{ formatNumber(data.totals.ai_tokens) }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('usageMonitoring.aiTokens') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol
          cols="6"
          md="2"
        >
          <VCard>
            <VCardText class="text-center">
              <VIcon
                icon="tabler-mail"
                size="28"
                class="text-info mb-1"
              />
              <div class="text-h5">
                {{ formatNumber(data.totals.emails_sent) }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('usageMonitoring.emailsSent') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol
          cols="6"
          md="2"
        >
          <VCard>
            <VCardText class="text-center">
              <VIcon
                icon="tabler-users"
                size="28"
                class="text-success mb-1"
              />
              <div class="text-h5">
                {{ data.totals.total_members }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('usageMonitoring.totalMembers') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol
          cols="6"
          md="2"
        >
          <VCard>
            <VCardText class="text-center">
              <VIcon
                icon="tabler-database"
                size="28"
                class="text-secondary mb-1"
              />
              <div class="text-h5">
                {{ data.totals.total_storage_gb }} GB
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('usageMonitoring.storage') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- Top AI Consumers -->
      <VCard class="mb-6">
        <VCardTitle>{{ t('usageMonitoring.topAiConsumers') }}</VCardTitle>
        <VTable
          v-if="data.top_ai_companies?.length"
          density="compact"
        >
          <thead>
            <tr>
              <th>{{ t('common.company') }}</th>
              <th class="text-end">
                {{ t('usageMonitoring.aiRequests') }}
              </th>
              <th class="text-end">
                {{ t('usageMonitoring.aiTokens') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="company in data.top_ai_companies"
              :key="company.company_id"
            >
              <td>{{ company.company_name }}</td>
              <td class="text-end">
                {{ formatNumber(company.ai_requests) }}
              </td>
              <td class="text-end">
                {{ formatNumber(company.ai_tokens) }}
              </td>
            </tr>
          </tbody>
        </VTable>
        <VCardText
          v-else
          class="text-center text-medium-emphasis"
        >
          {{ t('usageMonitoring.noData') }}
        </VCardText>
      </VCard>

      <!-- Daily Trend -->
      <VCard v-if="data.daily_trend?.length">
        <VCardTitle>{{ t('usageMonitoring.dailyTrend') }}</VCardTitle>
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('common.date') }}</th>
              <th class="text-end">
                {{ t('usageMonitoring.aiRequests') }}
              </th>
              <th class="text-end">
                {{ t('usageMonitoring.emailsSent') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="day in data.daily_trend"
              :key="day.date"
            >
              <td>{{ day.date }}</td>
              <td class="text-end">
                {{ day.ai_requests }}
              </td>
              <td class="text-end">
                {{ day.emails_sent }}
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>
