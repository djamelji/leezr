<script setup>
import { ref, computed, onMounted } from 'vue'
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'

const { toast } = useAppToast()
const { t } = useI18n()

const healthData = ref(null)
const isLoading = ref(true)
const isTesting = ref(false)
const testEmail = ref('')
const testResult = ref(null)

const fetchHealth = async () => {
  isLoading.value = true
  try {
    healthData.value = await $platformApi('/email/health')
  } catch (e) {
    toast(t('emailHealth.fetchError'), 'error')
  } finally {
    isLoading.value = false
  }
}

const sendTest = async () => {
  isTesting.value = true
  testResult.value = null
  try {
    const result = await $platformApi('/email/health/test', {
      method: 'POST',
      body: { email: testEmail.value || undefined },
    })
    testResult.value = result
    toast(t('emailHealth.testSent'), 'success')
  } catch (e) {
    testResult.value = { success: false, error: e.message || 'Unknown error' }
    toast(t('emailHealth.testFailed'), 'error')
  } finally {
    isTesting.value = false
  }
}

onMounted(fetchHealth)

// DNS status cards
const dnsCards = computed(() => {
  if (!healthData.value) return []
  const dns = healthData.value.dns

  return [
    {
      title: 'SPF',
      status: dns.spf.status,
      statusColor: dns.spf.status === 'valid' ? 'success' : dns.spf.status === 'weak' ? 'warning' : 'error',
      statusLabel: dns.spf.status === 'valid' ? t('emailHealth.valid') : dns.spf.status === 'weak' ? t('emailHealth.weak') : dns.spf.status === 'multiple' ? t('emailHealth.multiple') : t('emailHealth.missing'),
      icon: 'tabler-shield-check',
      detail: dns.spf.record || dns.spf.detail,
    },
    {
      title: 'DKIM',
      status: dns.dkim.status,
      statusColor: dns.dkim.record_exists ? 'success' : 'error',
      statusLabel: dns.dkim.record_exists ? t('emailHealth.configured') : t('emailHealth.missing'),
      icon: 'tabler-key',
      detail: dns.dkim.detail,
    },
    {
      title: 'DMARC',
      status: dns.dmarc.policy,
      statusColor: dns.dmarc.policy === 'reject' ? 'success' : dns.dmarc.policy === 'quarantine' ? 'info' : dns.dmarc.policy === 'none' ? 'warning' : 'error',
      statusLabel: dns.dmarc.policy ? `p=${dns.dmarc.policy}` : t('emailHealth.missing'),
      icon: 'tabler-shield-lock',
      detail: dns.dmarc.record,
    },
    {
      title: 'PTR',
      status: dns.ptr.status,
      statusColor: dns.ptr.status === 'configured' ? 'success' : 'error',
      statusLabel: dns.ptr.status === 'configured' ? dns.ptr.ptr_name : t('emailHealth.missing'),
      icon: 'tabler-arrows-exchange',
      detail: dns.ptr.detail,
    },
  ]
})

// Reputation cards
const reputationCards = computed(() => {
  if (!healthData.value) return []
  const rep = healthData.value.reputation

  return Object.entries(rep).map(([name, status]) => ({
    title: name.charAt(0).toUpperCase() + name.slice(1),
    status,
    statusColor: status === 'clean' ? 'success' : 'error',
    statusLabel: status === 'clean' ? t('emailHealth.clean') : t('emailHealth.listed'),
    icon: status === 'clean' ? 'tabler-circle-check' : 'tabler-alert-triangle',
  }))
})

// Overall score
const overallScore = computed(() => {
  if (!healthData.value) return { color: 'default', label: '...', percent: 0 }

  let score = 0
  const dns = healthData.value.dns
  const rep = healthData.value.reputation

  if (dns.spf.status === 'valid') score += 25
  else if (dns.spf.status === 'weak') score += 10
  if (dns.dkim.record_exists) score += 25
  if (dns.dmarc.policy === 'reject') score += 20
  else if (dns.dmarc.policy === 'quarantine') score += 15
  else if (dns.dmarc.policy === 'none') score += 5
  if (dns.ptr.status === 'configured') score += 10

  const cleanCount = Object.values(rep).filter(v => v === 'clean').length
  score += Math.round((cleanCount / Math.max(Object.keys(rep).length, 1)) * 20)

  const color = score >= 80 ? 'success' : score >= 50 ? 'warning' : 'error'
  const label = score >= 80 ? t('emailHealth.excellent') : score >= 50 ? t('emailHealth.attention') : t('emailHealth.critical')

  return { color, label, percent: score }
})
</script>

<template>
  <div>
    <!-- Loading -->
    <VProgressLinear v-if="isLoading" indeterminate class="mb-4" />

    <template v-if="healthData">
      <!-- Overall Score -->
      <VCard class="mb-6">
        <VCardItem>
          <VCardTitle>{{ t('emailHealth.title') }}</VCardTitle>
          <VCardSubtitle>{{ t('emailHealth.subtitle') }}</VCardSubtitle>
          <template #append>
            <VBtn
              variant="tonal"
              size="small"
              :loading="isLoading"
              @click="fetchHealth"
            >
              <VIcon icon="tabler-refresh" size="18" class="me-1" />
              {{ t('common.refresh') }}
            </VBtn>
          </template>
        </VCardItem>
        <VCardText>
          <div class="d-flex align-center gap-4">
            <VProgressCircular
              :model-value="overallScore.percent"
              :color="overallScore.color"
              :size="80"
              :width="8"
            >
              <span class="text-body-1 font-weight-medium">{{ overallScore.percent }}%</span>
            </VProgressCircular>
            <div>
              <VChip :color="overallScore.color" size="small" class="mb-1">
                {{ overallScore.label }}
              </VChip>
              <p class="text-body-2 text-medium-emphasis mb-0">
                {{ t('emailHealth.scoreDescription') }}
              </p>
            </div>
          </div>
        </VCardText>
      </VCard>

      <!-- DNS Authentication -->
      <h6 class="text-h6 mb-4">{{ t('emailHealth.dnsAuthentication') }}</h6>
      <VRow class="card-grid card-grid-sm mb-6">
        <VCol
          v-for="card in dnsCards"
          :key="card.title"
          cols="12"
          sm="6"
          md="3"
        >
          <VCard>
            <VCardText class="d-flex align-center gap-3">
              <VAvatar
                :color="card.statusColor"
                variant="tonal"
                rounded
                size="42"
              >
                <VIcon :icon="card.icon" size="26" />
              </VAvatar>
              <div class="flex-grow-1">
                <div class="text-body-1 font-weight-medium">{{ card.title }}</div>
                <VChip :color="card.statusColor" size="x-small" label>
                  {{ card.statusLabel }}
                </VChip>
              </div>
            </VCardText>
            <VCardText v-if="card.detail" class="pt-0">
              <p class="text-caption text-medium-emphasis mb-0 text-truncate" :title="card.detail">
                {{ card.detail }}
              </p>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- IP Reputation -->
      <h6 class="text-h6 mb-4">{{ t('emailHealth.ipReputation') }}</h6>
      <VRow class="card-grid card-grid-xs mb-6">
        <VCol
          v-for="card in reputationCards"
          :key="card.title"
          cols="6"
          sm="3"
        >
          <VCard>
            <VCardText class="text-center">
              <VAvatar
                :color="card.statusColor"
                variant="tonal"
                rounded
                size="42"
                class="mb-2"
              >
                <VIcon :icon="card.icon" size="24" />
              </VAvatar>
              <div class="text-body-2 font-weight-medium">{{ card.title }}</div>
              <VChip :color="card.statusColor" size="x-small" label class="mt-1">
                {{ card.statusLabel }}
              </VChip>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- Stats -->
      <h6 class="text-h6 mb-4">{{ t('emailHealth.sendingStats') }}</h6>
      <VRow class="card-grid card-grid-xs mb-6">
        <VCol cols="6" sm="3">
          <VCard>
            <VCardText class="text-center">
              <div class="text-h4 text-primary">{{ healthData.stats.sent_24h }}</div>
              <div class="text-caption">{{ t('emailHealth.sent24h') }}</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="6" sm="3">
          <VCard>
            <VCardText class="text-center">
              <div class="text-h4 text-primary">{{ healthData.stats.sent_7d }}</div>
              <div class="text-caption">{{ t('emailHealth.sent7d') }}</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="6" sm="3">
          <VCard>
            <VCardText class="text-center">
              <div class="text-h4" :class="healthData.stats.failed_24h > 0 ? 'text-error' : 'text-success'">
                {{ healthData.stats.failed_24h }}
              </div>
              <div class="text-caption">{{ t('emailHealth.failed24h') }}</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="6" sm="3">
          <VCard>
            <VCardText class="text-center">
              <div class="text-h4" :class="healthData.stats.failed_7d > 0 ? 'text-error' : 'text-success'">
                {{ healthData.stats.failed_7d }}
              </div>
              <div class="text-caption">{{ t('emailHealth.failed7d') }}</div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- SMTP Status -->
      <VRow class="mb-6">
        <VCol cols="12" md="6">
          <VCard>
            <VCardItem>
              <VCardTitle>{{ t('emailHealth.smtpConnection') }}</VCardTitle>
            </VCardItem>
            <VCardText>
              <VChip
                :color="healthData.smtp.configured ? 'success' : 'error'"
                size="small"
              >
                <VIcon
                  :icon="healthData.smtp.configured ? 'tabler-circle-check' : 'tabler-circle-x'"
                  size="16"
                  class="me-1"
                />
                {{ healthData.smtp.configured ? t('emailHealth.connected') : t('emailHealth.disconnected') }}
              </VChip>
              <p class="text-caption text-medium-emphasis mt-2 mb-0">
                {{ healthData.smtp.message }}
              </p>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Test Deliverability -->
        <VCol cols="12" md="6">
          <VCard>
            <VCardItem>
              <VCardTitle>{{ t('emailHealth.testTitle') }}</VCardTitle>
            </VCardItem>
            <VCardText>
              <div class="d-flex gap-2 align-center">
                <AppTextField
                  v-model="testEmail"
                  :placeholder="t('emailHealth.testPlaceholder')"
                  density="compact"
                  type="email"
                  class="flex-grow-1"
                />
                <VBtn
                  :loading="isTesting"
                  color="primary"
                  @click="sendTest"
                >
                  {{ t('emailHealth.sendTest') }}
                </VBtn>
              </div>
              <VAlert
                v-if="testResult"
                :type="testResult.success ? 'success' : 'error'"
                variant="tonal"
                density="compact"
                class="mt-3"
              >
                <template v-if="testResult.success">
                  {{ t('emailHealth.testSuccess', { email: testResult.recipient }) }}
                </template>
                <template v-else>
                  {{ testResult.error }}
                </template>
              </VAlert>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
