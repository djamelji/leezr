<script setup>
import BillingDashboard from './_BillingDashboard.vue'
import BillingSubscriptionsTab from './_BillingSubscriptionsTab.vue'
import BillingInvoicesTab from './_BillingInvoicesTab.vue'
import BillingPaymentsTab from './_BillingPaymentsTab.vue'
import BillingDunningTab from './_BillingDunningTab.vue'
import BillingScheduledDebitsTab from './_BillingScheduledDebitsTab.vue'
import BillingCreditNotesTab from './_BillingCreditNotesTab.vue'
import BillingCouponsTab from './_BillingCouponsTab.vue'
import BillingWalletsTab from './_BillingWalletsTab.vue'
import BillingForensicsTab from './_BillingForensicsTab.vue'
import BillingGovernanceTab from './_BillingGovernanceTab.vue'
import BillingLedgerTab from './_BillingLedgerTab.vue'
import BillingRecovery from './_BillingRecovery.vue'
import BillingReconciliationTab from './_BillingReconciliationTab.vue'
import BillingTariffReviewTab from './_BillingTariffReviewTab.vue'
import AppAccessState from '@/components/app/AppAccessState.vue'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.billing',
    permission: 'view_billing',
    navActiveLink: 'platform-billing-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-billing-tab')
const router = useRouter()

const activeTab = computed({
  get: () => route.params.tab,
  set: val => router.replace({ name: 'platform-billing-tab', params: { tab: val } }),
})

// ADR-839 (M3, UXP-03 / SEC-08) — a tab is shown only with the permission its API requires (no avoidable 403)
const platformAuth = usePlatformAuthStore()

const tabs = computed(() => [
  { title: t('platformBilling.tabs.dashboard'), icon: 'tabler-layout-dashboard', tab: 'overview', group: 'overview' },
  { title: t('platformBilling.tabs.subscriptions'), icon: 'tabler-receipt', tab: 'subscriptions', group: 'subscriptions' },
  { title: t('platformBilling.tabs.invoices'), icon: 'tabler-file-invoice', tab: 'invoices', group: 'invoicing' },
  { title: t('platformBilling.tabs.payments'), icon: 'tabler-cash', tab: 'payments', group: 'invoicing' },
  { title: t('platformBilling.tabs.dunning'), icon: 'tabler-alert-triangle', tab: 'dunning', group: 'invoicing' },
  { title: t('platformBilling.tabs.scheduledDebits'), icon: 'tabler-calendar-event', tab: 'scheduled-debits', group: 'invoicing' },
  { title: t('platformBilling.tabs.creditNotes'), icon: 'tabler-receipt-refund', tab: 'credit-notes', group: 'invoicing' },
  { title: t('platformBilling.tabs.coupons'), icon: 'tabler-ticket', tab: 'coupons', group: 'offers', permission: 'manage_billing' },
  { title: t('platformBilling.tabs.wallets'), icon: 'tabler-wallet', tab: 'wallets', group: 'invoicing' },
  { title: t('platformBilling.tabs.forensics'), icon: 'tabler-history', tab: 'forensics', group: 'control' },
  { title: t('platformBilling.tabs.governance'), icon: 'tabler-shield-check', tab: 'governance', group: 'control' },
  { title: t('platformBilling.tabs.ledger'), icon: 'tabler-book', tab: 'ledger', group: 'control' },
  { title: t('platformBilling.tabs.recovery'), icon: 'tabler-first-aid-kit', tab: 'recovery', group: 'control' },
  { title: t('platformBilling.tabs.reconciliation'), icon: 'tabler-scale', tab: 'reconciliation', group: 'control' },
  { title: t('platformBilling.tabs.tariffReview'), icon: 'tabler-shield-dollar', tab: 'tariff-review', group: 'subscriptions', permission: 'manage_billing' },
].filter(item => !item.permission || platformAuth.hasPermission(item.permission)))

const tabAllowed = computed(() => tabs.value.some(item => item.tab === activeTab.value))

// ADR-842 (Leezr 4.2 M5, UXP-05) — 15 tabs at one level → 5 business groups. Same pages, same addresses
// (/platform/billing/{tab}), same permissions : a group shows only if one of its pages is allowed, and opens its
// first allowed page. The secondary row lists the pages of the current group.
const GROUPS = [
  { key: 'overview', icon: 'tabler-layout-dashboard', order: ['overview'] },
  { key: 'subscriptions', icon: 'tabler-receipt', order: ['subscriptions', 'tariff-review'] },
  { key: 'invoicing', icon: 'tabler-file-invoice', order: ['invoices', 'payments', 'scheduled-debits', 'dunning', 'credit-notes', 'wallets'] },
  { key: 'offers', icon: 'tabler-ticket', order: ['coupons'] },
  { key: 'control', icon: 'tabler-shield-search', order: ['reconciliation', 'recovery', 'forensics', 'governance', 'ledger'] },
]

const groups = computed(() => GROUPS
  .map(g => ({ ...g, title: t(`platformBilling.groups.${g.key}.title`), hint: t(`platformBilling.groups.${g.key}.hint`), pages: g.order.map(k => tabs.value.find(item => item.tab === k)).filter(Boolean) }))
  .filter(g => g.pages.length))

const activeGroup = computed(() => groups.value.find(g => g.pages.some(p => p.tab === activeTab.value)) || null)
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4">
          {{ t('platformBilling.title') }}
        </h4>
        <p class="text-body-1 mb-0">
          {{ t('platformBilling.subtitle') }}
        </p>
      </div>
      <VBtn
        variant="tonal"
        color="secondary"
        prepend-icon="tabler-settings"
        :to="{ name: 'platform-settings-tab', params: { tab: 'billing' } }"
      >
        {{ t('platformBilling.tabs.settings') }}
      </VBtn>
    </div>

    <!-- ADR-842 : business groups (primary) → pages of the group (secondary) -->
    <VTabs
      :model-value="activeGroup?.key"
      class="v-tabs-pill"
      :mandatory="false"
      show-arrows
      data-testid="billing-groups"
    >
      <VTab
        v-for="g in groups"
        :key="g.key"
        :value="g.key"
        :data-testid="`billing-group-${g.key}`"
        @click="g.key !== activeGroup?.key && router.push({ name: 'platform-billing-tab', params: { tab: g.pages[0].tab } })"
      >
        <VIcon
          size="20"
          start
          :icon="g.icon"
        />
        {{ g.title }}
      </VTab>
    </VTabs>

    <div
      v-if="activeGroup"
      class="mt-4"
    >
      <p
        class="text-body-2 text-medium-emphasis mb-2"
        data-testid="billing-group-hint"
      >
        {{ activeGroup.hint }}
      </p>
      <VTabs
        v-if="activeGroup.pages.length > 1"
        v-model="activeTab"
        density="compact"
        :mandatory="false"
        show-arrows
        data-testid="billing-pages"
      >
        <VTab
          v-for="item in activeGroup.pages"
          :key="item.tab"
          :value="item.tab"
          :to="{ name: 'platform-billing-tab', params: { tab: item.tab } }"
          :data-testid="`billing-page-${item.tab}`"
        >
          <VIcon
            size="18"
            start
            :icon="item.icon"
          />
          {{ item.title }}
        </VTab>
      </VTabs>
      <VBtn
        v-if="activeGroup.key === 'offers' && platformAuth.hasPermission('manage_plans')"
        variant="text"
        size="small"
        prepend-icon="tabler-arrow-right"
        :to="{ name: 'platform-catalog-tab', params: { tab: 'plans' } }"
        data-testid="billing-offers-plans-link"
      >
        {{ t('platformBilling.groups.offers.plansLink') }}
      </VBtn>
    </div>

    <AppAccessState
      v-if="!tabAllowed"
      compact
      :title="t('platformForbidden.tabTitle')"
      :message="t('platformForbidden.message')"
      :hint="t('platformForbidden.hint')"
      :show-back="false"
      :actions="[{ label: t('platformForbidden.firstTab'), to: { name: 'platform-billing-tab', params: { tab: 'overview' } }, testid: 'access-state-first-tab' }]"
      testid="platform-tab-forbidden"
    />

    <VWindow
      v-else
      v-model="activeTab"
      class="mt-6 disable-tab-transition"
      :touch="false"
    >
      <VWindowItem value="overview">
        <BillingDashboard @switch-tab="tab => router.replace({ name: 'platform-billing-tab', params: { tab } })" />
      </VWindowItem>

      <VWindowItem value="subscriptions">
        <BillingSubscriptionsTab />
      </VWindowItem>

      <VWindowItem value="invoices">
        <BillingInvoicesTab />
      </VWindowItem>

      <VWindowItem value="payments">
        <BillingPaymentsTab />
      </VWindowItem>

      <VWindowItem value="dunning">
        <BillingDunningTab />
      </VWindowItem>

      <VWindowItem value="scheduled-debits">
        <BillingScheduledDebitsTab />
      </VWindowItem>

      <VWindowItem value="credit-notes">
        <BillingCreditNotesTab />
      </VWindowItem>

      <VWindowItem value="coupons">
        <BillingCouponsTab />
      </VWindowItem>

      <VWindowItem value="wallets">
        <BillingWalletsTab />
      </VWindowItem>

      <VWindowItem value="forensics">
        <BillingForensicsTab />
      </VWindowItem>

      <VWindowItem value="governance">
        <BillingGovernanceTab />
      </VWindowItem>

      <VWindowItem value="ledger">
        <BillingLedgerTab />
      </VWindowItem>

      <VWindowItem value="recovery">
        <BillingRecovery @switch-tab="tab => router.replace({ name: 'platform-billing-tab', params: { tab } })" />
      </VWindowItem>

      <VWindowItem value="reconciliation">
        <BillingReconciliationTab />
      </VWindowItem>

      <VWindowItem value="tariff-review">
        <BillingTariffReviewTab />
      </VWindowItem>
    </VWindow>
  </div>
</template>
