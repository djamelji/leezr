<script setup>
import { $platformApi } from '@/utils/platformApi'
import { formatMoney } from '@/utils/money'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

// ADR-845 (Leezr 4.2 M8) — Platform supervision of a company's end of relationship : commercial / operational state,
// open obligations, full data exports (status, size, expiry — never the archive) and revocation (manage_companies).
const props = defineProps({ companyId: { type: [Number, String], required: true } })
const { t, locale } = useI18n()
const auth = usePlatformAuthStore()
const canManage = computed(() => auth.hasPermission('manage_companies'))
const life = ref(null)
const busy = ref(null)
const error = ref('')

const dt = d => (d ? new Date(d).toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' }) : '—')
const STATE_COLOR = { active: 'success', trial: 'info', 'pending_payment': 'warning', 'payment_overdue': 'error', 'cancellation_scheduled': 'warning', ended: 'secondary', none: 'secondary' }
const EXPORT_COLOR = { pending: 'info', running: 'info', ready: 'success', failed: 'error', expired: 'secondary', revoked: 'secondary' }

const load = async () => {
  try { life.value = await $platformApi(`/companies/${props.companyId}/lifecycle`); error.value = '' }
  catch { error.value = t('companyClosure.loadError') }
}

onMounted(load)

const revoke = async e => {
  busy.value = e.id
  try { await $platformApi(`/companies/${props.companyId}/data-exports/${e.id}/revoke`, { method: 'POST' }); await load() }
  catch { error.value = t('companyClosure.genericError') }
  finally { busy.value = null }
}
</script>

<template>
  <VCard
    class="mb-6"
    data-testid="company-lifecycle"
  >
    <VCardItem>
      <VCardTitle>{{ t('platformLifecycle.title') }}</VCardTitle>
      <VCardSubtitle class="text-wrap">
        {{ t('platformLifecycle.hint') }}
      </VCardSubtitle>
    </VCardItem>
    <VCardText>
      <VAlert
        v-if="error"
        type="error"
        variant="tonal"
        class="mb-3"
      >
        {{ error }}
      </VAlert>
      <template v-if="life">
        <div class="d-flex flex-wrap gap-2 mb-4">
          <VChip
            :color="STATE_COLOR[life.commercial.state]"
            label
            data-testid="lifecycle-commercial"
          >
            {{ t('platformLifecycle.commercial') }} : {{ t(`companyClosure.states.${life.commercial.state}`) }}
          </VChip>
          <VChip
            :color="life.operational.state === 'suspended' ? 'error' : 'success'"
            label
            data-testid="lifecycle-operational"
          >
            {{ t('platformLifecycle.operational') }} : {{ t(`platformLifecycle.operationalStates.${life.operational.state}`) }}
          </VChip>
          <VChip
            label
            color="secondary"
          >
            {{ t('platformLifecycle.retention') }} : {{ life.data.retention_policy ? t('platformLifecycle.retentionDefined') : t('platformLifecycle.retentionUndefined') }}
          </VChip>
        </div>
        <div
          class="text-body-2 mb-4"
          data-testid="lifecycle-obligations"
        >
          {{ t('platformLifecycle.obligations', { n: life.obligations.unpaid_invoices, amount: formatMoney(life.obligations.unpaid_amount_cents), p: life.obligations.payroll_runs_open }) }}
          <span v-if="life.commercial.period_end"> · {{ t('platformLifecycle.periodEnd', { date: life.commercial.period_end }) }}</span>
        </div>
        <VTable
          v-if="life.exports.length"
          density="compact"
        >
          <thead>
            <tr>
              <th>{{ t('companyClosure.export.requestedAt') }}</th>
              <th>{{ t('companyClosure.export.status') }}</th>
              <th>{{ t('platformLifecycle.downloads') }}</th>
              <th>{{ t('companyClosure.export.expiresAt') }}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="e in life.exports"
              :key="e.id"
              :data-testid="`lifecycle-export-${e.id}`"
            >
              <td>{{ dt(e.requested_at) }} <span class="text-caption text-medium-emphasis">{{ e.requested_by }}</span></td>
              <td>
                <VChip
                  size="small"
                  :color="EXPORT_COLOR[e.status]"
                >
                  {{ t(`companyClosure.export.statuses.${e.status}`) }}
                </VChip>
                <span
                  v-if="e.errors?.length"
                  class="text-caption text-warning ms-1"
                >{{ t('companyClosure.export.partial', { parts: e.errors.map(x => x.part).join(', ') }) }}</span>
              </td>
              <td>{{ e.download_count }}</td>
              <td>{{ e.status === 'ready' ? dt(e.expires_at) : '—' }}</td>
              <td class="text-end">
                <VBtn
                  v-if="canManage && e.status === 'ready'"
                  size="small"
                  variant="tonal"
                  color="error"
                  :loading="busy === e.id"
                  :data-testid="`lifecycle-revoke-${e.id}`"
                  @click="revoke(e)"
                >
                  {{ t('platformLifecycle.revoke') }}
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
        <div
          v-else
          class="text-body-2 text-medium-emphasis"
        >
          {{ t('companyClosure.export.none') }}
        </div>
      </template>
    </VCardText>
  </VCard>
</template>
