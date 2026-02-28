<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const store = usePlatformPaymentsStore()
const authStore = usePlatformAuthStore()
const { toast } = useAppToast()

const canManage = computed(() => authStore.hasPermission('manage_billing'))

// ── Company selector ───────────────────────────────────
const companyId = ref('')

// ── Reconciliation ─────────────────────────────────────
const reconcileResult = ref(null)
const reconcileLoading = ref(false)

const runReconcile = async () => {
  reconcileLoading.value = true
  reconcileResult.value = null
  try {
    const data = await store.runReconcile({
      company_id: companyId.value || undefined,
      dry_run: true,
    })

    reconcileResult.value = data
    toast(t('platformBilling.governance.reconcileComplete'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformBilling.errorGeneric'), 'error')
  }
  finally {
    reconcileLoading.value = false
  }
}

const driftHeaders = [
  { title: t('platformBilling.governance.driftType'), key: 'type', sortable: false },
  { title: t('platformBilling.governance.providerPaymentId'), key: 'provider_payment_id', sortable: false },
  { title: t('platformBilling.governance.details'), key: 'details', sortable: false },
]

// ── Financial Periods ──────────────────────────────────
const periodsLoading = ref(false)

const loadPeriods = async () => {
  if (!companyId.value) return
  periodsLoading.value = true
  try {
    await store.fetchFinancialPeriods(companyId.value)
  }
  finally {
    periodsLoading.value = false
  }
}

const periodHeaders = [
  { title: t('platformBilling.governance.startDate'), key: 'start_date' },
  { title: t('platformBilling.governance.endDate'), key: 'end_date' },
  { title: t('platformBilling.governance.status'), key: 'is_closed', width: '120px' },
  { title: t('platformBilling.governance.closedAt'), key: 'closed_at' },
]

// Close period dialog
const closePeriodDialog = ref(false)
const periodStartDate = ref('')
const periodEndDate = ref('')
const closePeriodSaving = ref(false)

const closePeriodValid = computed(() => {
  return periodStartDate.value && periodEndDate.value && periodEndDate.value >= periodStartDate.value
})

const submitClosePeriod = async () => {
  if (!closePeriodValid.value || !companyId.value || closePeriodSaving.value) return
  closePeriodSaving.value = true
  try {
    await store.closeFinancialPeriod({
      company_id: Number(companyId.value),
      start_date: periodStartDate.value,
      end_date: periodEndDate.value,
    })

    toast(t('platformBilling.governance.periodClosed'), 'success')
    closePeriodDialog.value = false
    periodStartDate.value = ''
    periodEndDate.value = ''
  }
  catch (error) {
    toast(error?.data?.message || t('platformBilling.errorGeneric'), 'error')
  }
  finally {
    closePeriodSaving.value = false
  }
}

// ── Financial Freeze ───────────────────────────────────
const freezeState = ref(false)
const freezeLoading = ref(false)

const toggleFreeze = async () => {
  if (!companyId.value || freezeLoading.value) return
  freezeLoading.value = true
  try {
    const data = await store.toggleFinancialFreeze(Number(companyId.value), !freezeState.value)

    freezeState.value = data.financial_freeze
    const key = data.financial_freeze
      ? 'platformBilling.governance.freezeEnabled'
      : 'platformBilling.governance.freezeDisabled'

    toast(t(key), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformBilling.errorGeneric'), 'error')
  }
  finally {
    freezeLoading.value = false
  }
}

// ── Drift History ──────────────────────────────────────
const driftLoading = ref(false)

const loadDriftHistory = async () => {
  if (!companyId.value) return
  driftLoading.value = true
  try {
    await store.fetchDriftHistory(companyId.value)
  }
  finally {
    driftLoading.value = false
  }
}

// ── Load freeze state ────────────────────────────────────
const loadFreezeState = async () => {
  if (!companyId.value) return
  try {
    const data = await store.fetchFreezeState(companyId.value)

    freezeState.value = data.financial_freeze ?? false
  }
  catch {
    freezeState.value = false
  }
}

// ── Load all on company change ─────────────────────────
watch(companyId, val => {
  if (val) {
    loadPeriods()
    loadDriftHistory()
    loadFreezeState()
  }
})

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}
</script>

<template>
  <div>
    <!-- Company selector -->
    <VCard class="mb-6">
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model="companyId"
              :label="t('platformBilling.governance.companyId')"
              type="number"
              :placeholder="t('platformBilling.governance.companyIdPlaceholder')"
              density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- ═══ Reconciliation ═══ -->
    <VCard class="mb-6">
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-arrows-diff"
          class="me-2"
        />
        {{ t('platformBilling.governance.reconciliation') }}
      </VCardTitle>
      <VCardText>
        <div class="d-flex align-center gap-4 mb-4">
          <VBtn
            v-if="canManage"
            :loading="reconcileLoading"
            color="primary"
            variant="tonal"
            @click="runReconcile"
          >
            <VIcon
              icon="tabler-refresh"
              class="me-1"
            />
            {{ t('platformBilling.governance.runReconcile') }}
          </VBtn>
          <VChip
            v-if="reconcileResult"
            :color="reconcileResult.summary?.total > 0 ? 'error' : 'success'"
          >
            {{ reconcileResult.summary?.total || 0 }} {{ t('platformBilling.governance.driftsFound') }}
          </VChip>
        </div>

        <template v-if="reconcileResult?.drifts?.length">
          <h6 class="text-h6 mb-3">
            {{ t('platformBilling.governance.driftsByType') }}
          </h6>
          <VTable
            density="compact"
            class="mb-4"
          >
            <thead>
              <tr>
                <th>{{ t('platformBilling.governance.driftType') }}</th>
                <th class="text-end">
                  {{ t('platformBilling.governance.count') }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(count, type) in reconcileResult.summary?.by_type"
                :key="type"
              >
                <td>
                  <VChip
                    size="small"
                    color="warning"
                  >
                    {{ type }}
                  </VChip>
                </td>
                <td class="text-end">
                  {{ count }}
                </td>
              </tr>
            </tbody>
          </VTable>

          <VDataTable
            :headers="driftHeaders"
            :items="reconcileResult.drifts"
            density="compact"
            :items-per-page="10"
          >
            <template #item.type="{ item }">
              <VChip
                size="small"
                color="error"
              >
                {{ item.type }}
              </VChip>
            </template>
            <template #item.details="{ item }">
              <code class="text-caption">{{ JSON.stringify(item.details || {}) }}</code>
            </template>
          </VDataTable>
        </template>

        <div
          v-else-if="reconcileResult && !reconcileResult.drifts?.length"
          class="text-center pa-4 text-disabled"
        >
          <VIcon
            icon="tabler-check"
            size="32"
            class="mb-2"
          />
          <p class="text-body-1 mb-0">
            {{ t('platformBilling.governance.noDrifts') }}
          </p>
        </div>
      </VCardText>
    </VCard>

    <!-- ═══ Financial Periods ═══ -->
    <VCard class="mb-6">
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-calendar-event"
          class="me-2"
        />
        {{ t('platformBilling.governance.accountingPeriods') }}
        <VSpacer />
        <VBtn
          v-if="canManage && companyId"
          size="small"
          color="primary"
          variant="tonal"
          @click="closePeriodDialog = true"
        >
          <VIcon
            icon="tabler-lock"
            class="me-1"
          />
          {{ t('platformBilling.governance.closePeriod') }}
        </VBtn>
      </VCardTitle>
      <VCardText>
        <div
          v-if="!companyId"
          class="text-center pa-4 text-disabled"
        >
          {{ t('platformBilling.governance.selectCompanyFirst') }}
        </div>
        <VDataTable
          v-else
          :headers="periodHeaders"
          :items="store.financialPeriods"
          :loading="periodsLoading"
          density="compact"
          :items-per-page="10"
        >
          <template #item.start_date="{ item }">
            {{ formatDate(item.start_date) }}
          </template>
          <template #item.end_date="{ item }">
            {{ formatDate(item.end_date) }}
          </template>
          <template #item.is_closed="{ item }">
            <VChip
              :color="item.is_closed ? 'error' : 'success'"
              size="small"
            >
              {{ item.is_closed ? t('platformBilling.governance.closed') : t('platformBilling.governance.open') }}
            </VChip>
          </template>
          <template #item.closed_at="{ item }">
            {{ formatDate(item.closed_at) }}
          </template>
        </VDataTable>
      </VCardText>
    </VCard>

    <!-- ═══ Financial Freeze ═══ -->
    <VCard class="mb-6">
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-snowflake"
          class="me-2"
        />
        {{ t('platformBilling.governance.financialFreeze') }}
      </VCardTitle>
      <VCardText>
        <div
          v-if="!companyId"
          class="text-center pa-4 text-disabled"
        >
          {{ t('platformBilling.governance.selectCompanyFirst') }}
        </div>
        <template v-else>
          <VAlert
            v-if="freezeState"
            type="error"
            variant="tonal"
            class="mb-4"
          >
            {{ t('platformBilling.governance.freezeWarning') }}
          </VAlert>
          <VSwitch
            v-if="canManage"
            :model-value="freezeState"
            :label="freezeState ? t('platformBilling.governance.frozen') : t('platformBilling.governance.unfrozen')"
            :loading="freezeLoading"
            color="error"
            @update:model-value="toggleFreeze"
          />
          <div
            v-else
            class="d-flex align-center gap-2"
          >
            <VChip :color="freezeState ? 'error' : 'success'">
              {{ freezeState ? t('platformBilling.governance.frozen') : t('platformBilling.governance.unfrozen') }}
            </VChip>
          </div>
        </template>
      </VCardText>
    </VCard>

    <!-- ═══ Drift History ═══ -->
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-history"
          class="me-2"
        />
        {{ t('platformBilling.governance.driftHistory') }}
      </VCardTitle>
      <VCardText>
        <div
          v-if="!companyId"
          class="text-center pa-4 text-disabled"
        >
          {{ t('platformBilling.governance.selectCompanyFirst') }}
        </div>
        <div
          v-else-if="store.driftHistory.length === 0 && !driftLoading"
          class="text-center pa-4 text-disabled"
        >
          {{ t('platformBilling.governance.noDriftHistory') }}
        </div>
        <VTable
          v-else
          density="compact"
        >
          <thead>
            <tr>
              <th>{{ t('platformBilling.governance.date') }}</th>
              <th>{{ t('platformBilling.governance.severity') }}</th>
              <th>{{ t('platformBilling.governance.details') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="drift in store.driftHistory"
              :key="drift.id"
            >
              <td>{{ formatDate(drift.created_at) }}</td>
              <td>
                <VChip
                  :color="drift.severity === 'critical' ? 'error' : 'warning'"
                  size="small"
                >
                  {{ drift.severity }}
                </VChip>
              </td>
              <td>
                <code class="text-caption">{{ JSON.stringify(drift.metadata || {}).slice(0, 120) }}</code>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
    </VCard>

    <!-- ═══ Close Period Dialog ═══ -->
    <VDialog
      v-model="closePeriodDialog"
      max-width="480"
    >
      <VCard>
        <VCardTitle class="pa-6 pb-2">
          {{ t('platformBilling.governance.closePeriodTitle') }}
        </VCardTitle>
        <VCardText class="pa-6">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="periodStartDate"
                :label="t('platformBilling.governance.startDate')"
                type="date"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="periodEndDate"
                :label="t('platformBilling.governance.endDate')"
                type="date"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-6 pt-0">
          <VSpacer />
          <VBtn
            variant="tonal"
            color="secondary"
            @click="closePeriodDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            :loading="closePeriodSaving"
            :disabled="!closePeriodValid"
            @click="submitClosePeriod"
          >
            {{ t('platformBilling.governance.closePeriod') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
