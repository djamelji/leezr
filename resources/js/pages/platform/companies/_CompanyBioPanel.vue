<script setup>
import { formatDate } from '@/utils/datetime'
import { formatMoney } from '@/utils/money'

const props = defineProps({
  company: { type: Object, required: true },
  plan: { type: Object, default: null },
  billing: { type: Object, default: null },
  owner: { type: Object, default: null },
  membersCount: { type: Number, default: 0 },
  incompleteProfilesCount: { type: Number, default: 0 },
  actionLoading: { type: Boolean, default: false },
})

const emit = defineEmits(['suspend', 'reactivate'])

const { t } = useI18n()

const statusColor = computed(() => {
  const map = { active: 'success', suspended: 'error', trialing: 'warning' }

  return map[props.company.status] || 'secondary'
})

const planColor = computed(() => {
  const map = { starter: 'secondary', pro: 'primary', business: 'warning' }

  return map[props.company.plan_key] || 'primary'
})

const walletDisplay = computed(() => {
  if (!props.billing) return null
  const balance = props.billing.wallet_balance ?? 0
  const currency = props.billing.currency ?? 'EUR'

  return formatMoney(balance, { currency })
})

const subscriptionStatus = computed(() => {
  if (!props.billing?.subscription) return null

  return props.billing.subscription.status
})
</script>

<template>
  <VCard class="mb-4">
    <VCardText class="text-center pt-8 pb-4">
      <!-- Avatar -->
      <VAvatar
        size="100"
        color="primary"
        variant="tonal"
        class="mb-4"
      >
        <span class="text-h3">{{ company.name?.charAt(0)?.toUpperCase() }}</span>
      </VAvatar>

      <!-- Name + Slug -->
      <h4 class="text-h4 mb-1">
        {{ company.name }}
      </h4>
      <code class="text-body-2">{{ company.slug }}</code>

      <!-- Status + Plan chips -->
      <div class="d-flex justify-center gap-2 mt-3">
        <VChip
          :color="statusColor"
          size="small"
        >
          {{ company.status }}
        </VChip>
        <VChip
          :color="planColor"
          size="small"
          variant="tonal"
        >
          {{ plan?.name || company.plan_key }}
        </VChip>
      </div>
    </VCardText>

    <VDivider />

    <!-- Key Metrics -->
    <VCardText>
      <div class="d-flex justify-space-around text-center mb-2">
        <div>
          <h6 class="text-h6">
            {{ membersCount }}
          </h6>
          <span class="text-body-2 text-disabled">{{ t('platformCompanyDetail.bio.members') }}</span>
        </div>
        <div>
          <h6 class="text-h6">
            {{ billing?.invoices?.length ?? 0 }}
          </h6>
          <span class="text-body-2 text-disabled">{{ t('platformCompanyDetail.bio.invoices') }}</span>
        </div>
      </div>
    </VCardText>

    <VDivider />

    <!-- Details List -->
    <VCardText>
      <VList density="compact" class="pa-0">
        <VListItem v-if="owner">
          <template #prepend>
            <VIcon icon="tabler-user-star" size="20" class="me-2" />
          </template>
          <VListItemTitle class="text-body-2 text-disabled">
            {{ t('platformCompanyDetail.bio.owner') }}
          </VListItemTitle>
          <VListItemSubtitle class="text-body-1">
            {{ owner.name }}
            <a :href="`mailto:${owner.email}`" class="text-primary text-caption ms-1">
              {{ owner.email }}
            </a>
          </VListItemSubtitle>
        </VListItem>

        <VListItem>
          <template #prepend>
            <VIcon icon="tabler-map-pin" size="20" class="me-2" />
          </template>
          <VListItemTitle class="text-body-2 text-disabled">
            {{ t('platformCompanyDetail.bio.market') }}
          </VListItemTitle>
          <VListItemSubtitle class="text-body-1">
            {{ company.market_key || '—' }}
          </VListItemSubtitle>
        </VListItem>

        <VListItem>
          <template #prepend>
            <VIcon icon="tabler-briefcase" size="20" class="me-2" />
          </template>
          <VListItemTitle class="text-body-2 text-disabled">
            {{ t('platformCompanyDetail.bio.jobdomain') }}
          </VListItemTitle>
          <VListItemSubtitle class="text-body-1">
            <template v-if="company.jobdomains?.length">
              <VChip
                v-for="jd in company.jobdomains"
                :key="jd.id"
                size="x-small"
                color="info"
                variant="tonal"
                class="me-1"
              >
                {{ jd.label }}
              </VChip>
            </template>
            <span v-else>—</span>
          </VListItemSubtitle>
        </VListItem>

        <VListItem>
          <template #prepend>
            <VIcon icon="tabler-calendar" size="20" class="me-2" />
          </template>
          <VListItemTitle class="text-body-2 text-disabled">
            {{ t('platformCompanyDetail.bio.created') }}
          </VListItemTitle>
          <VListItemSubtitle class="text-body-1">
            {{ formatDate(company.created_at) }}
          </VListItemSubtitle>
        </VListItem>

        <VListItem v-if="subscriptionStatus">
          <template #prepend>
            <VIcon icon="tabler-receipt" size="20" class="me-2" />
          </template>
          <VListItemTitle class="text-body-2 text-disabled">
            {{ t('platformCompanyDetail.bio.subscription') }}
          </VListItemTitle>
          <VListItemSubtitle>
            <VChip
              size="x-small"
              :color="subscriptionStatus === 'active' ? 'success' : subscriptionStatus === 'trialing' ? 'warning' : 'error'"
            >
              {{ subscriptionStatus }}
            </VChip>
          </VListItemSubtitle>
        </VListItem>

        <VListItem v-if="walletDisplay">
          <template #prepend>
            <VIcon icon="tabler-wallet" size="20" class="me-2" />
          </template>
          <VListItemTitle class="text-body-2 text-disabled">
            {{ t('platformCompanyDetail.bio.wallet') }}
          </VListItemTitle>
          <VListItemSubtitle class="text-body-1 text-success font-weight-medium">
            {{ walletDisplay }}
          </VListItemSubtitle>
        </VListItem>

        <VListItem v-if="incompleteProfilesCount > 0">
          <template #prepend>
            <VIcon icon="tabler-alert-triangle" size="20" class="me-2" color="warning" />
          </template>
          <VListItemTitle class="text-body-2 text-disabled">
            {{ t('platformCompanyDetail.bio.profiles') }}
          </VListItemTitle>
          <VListItemSubtitle>
            <VChip size="x-small" color="error" variant="tonal">
              {{ t('platformCompanyDetail.incompleteProfiles', { count: incompleteProfilesCount }) }}
            </VChip>
          </VListItemSubtitle>
        </VListItem>
      </VList>
    </VCardText>

    <VDivider />

    <!-- Actions -->
    <VCardActions class="justify-center pa-4">
      <VBtn
        v-if="company.status === 'active'"
        color="warning"
        variant="tonal"
        :loading="actionLoading"
        block
        @click="emit('suspend')"
      >
        <VIcon icon="tabler-ban" class="me-1" />
        {{ t('companies.suspend') }}
      </VBtn>
      <VBtn
        v-else
        color="success"
        variant="tonal"
        :loading="actionLoading"
        block
        @click="emit('reactivate')"
      >
        <VIcon icon="tabler-check" class="me-1" />
        {{ t('companies.reactivate') }}
      </VBtn>
    </VCardActions>
  </VCard>
</template>
