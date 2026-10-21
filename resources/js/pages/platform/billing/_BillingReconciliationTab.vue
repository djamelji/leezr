<script setup>
/**
 * Platform billing — Anomalies & reconciliation (ADR-583/586). Read-only detection & investigation:
 * ledger↔documents reconciliation, anomalies (gaps, write-offs, VAT split, unreconciled payments),
 * and AR aging by due date. NO automatic financial correction — a gap stays visible, never masked.
 */
import { $api } from '@/utils/api'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()

const loading = ref(true)
const recon = ref(null)
const aging = ref(null)

async function load() {
  loading.value = true
  try {
    [recon.value, aging.value] = await Promise.all([
      $api('/platform/billing/reconciliation'),
      $api('/platform/billing/aging'),
    ])
  }
  catch { recon.value = null; aging.value = null }
  finally { loading.value = false }
}

const severityColor = s => ({ error: 'error', warning: 'warning', info: 'info' }[s] || 'secondary')
function money(cents, currency) { return formatMoney(cents, { currency: currency || 'EUR' }) }

onMounted(load)
</script>

<template>
  <div data-test="billing-reconciliation-tab">
    <VAlert type="info" variant="tonal" density="compact" class="mb-4">
      {{ t('platformBilling.reconciliation.intro') }}
    </VAlert>

    <VSkeletonLoader v-if="loading" type="table" />

    <template v-else>
      <!-- Per-currency reconciliation (ledger vs documents) -->
      <VCard class="mb-4" data-test="reconciliation-summary">
        <VCardItem><VCardTitle>{{ t('platformBilling.reconciliation.summaryTitle') }}</VCardTitle></VCardItem>
        <VDivider />
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('platformBilling.reconciliation.currency') }}</th>
              <th class="text-end">{{ t('platformBilling.reconciliation.ledgerAr') }}</th>
              <th class="text-end">{{ t('platformBilling.reconciliation.invoicesOutstanding') }}</th>
              <th class="text-end">{{ t('platformBilling.reconciliation.gap') }}</th>
              <th class="text-end">{{ t('platformBilling.reconciliation.ledgerCash') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in recon?.by_currency || []" :key="c.currency">
              <td>{{ c.currency }}</td>
              <td class="text-end">{{ money(c.ledger_ar, c.currency) }}</td>
              <td class="text-end">{{ money(c.invoices_outstanding, c.currency) }}</td>
              <td class="text-end" :class="c.gap_ar_vs_invoices === 0 ? 'text-success' : 'text-error'">{{ money(c.gap_ar_vs_invoices, c.currency) }}</td>
              <td class="text-end">{{ money(c.ledger_cash, c.currency) }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>

      <!-- Anomalies -->
      <VCard class="mb-4" data-test="reconciliation-anomalies">
        <VCardItem>
          <VCardTitle class="d-flex align-center justify-space-between">
            <span>{{ t('platformBilling.reconciliation.anomaliesTitle') }}</span>
            <VChip size="small" :color="(recon?.anomalies?.length || 0) > 0 ? 'warning' : 'success'" variant="tonal">
              {{ recon?.anomalies?.length || 0 }}
            </VChip>
          </VCardTitle>
        </VCardItem>
        <VDivider />
        <VTable v-if="(recon?.anomalies?.length || 0) > 0" density="compact">
          <thead>
            <tr>
              <th>{{ t('platformBilling.reconciliation.severity') }}</th>
              <th>{{ t('platformBilling.reconciliation.type') }}</th>
              <th>{{ t('platformBilling.reconciliation.currency') }}</th>
              <th class="text-end">{{ t('platformBilling.reconciliation.amount') }}</th>
              <th>{{ t('platformBilling.reconciliation.detail') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(a, i) in recon.anomalies" :key="i" :data-test="`anomaly-${a.type}`">
              <td><VChip size="x-small" :color="severityColor(a.severity)" variant="tonal">{{ a.severity }}</VChip></td>
              <td><code>{{ a.type }}</code></td>
              <td>{{ a.currency || '—' }}</td>
              <td class="text-end">{{ a.gap_cents != null ? money(a.gap_cents, a.currency) : (a.amount_cents != null ? money(a.amount_cents, a.currency) : (a.amount_due_cents != null ? money(a.amount_due_cents, a.currency) : '—')) }}</td>
              <td class="text-caption">
                {{ a.detail }}
                <template v-if="a.invoice_id"> · <RouterLink :to="{ name: 'platform-billing-invoices-id', params: { id: a.invoice_id } }">#{{ a.number || a.invoice_id }}</RouterLink></template>
              </td>
            </tr>
          </tbody>
        </VTable>
        <VCardText v-else class="text-success">{{ t('platformBilling.reconciliation.noAnomalies') }}</VCardText>
      </VCard>

      <!-- AR aging -->
      <VCard data-test="reconciliation-aging">
        <VCardItem><VCardTitle>{{ t('platformBilling.reconciliation.agingTitle', { date: aging?.as_of }) }}</VCardTitle></VCardItem>
        <VDivider />
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('platformBilling.reconciliation.currency') }}</th>
              <th class="text-end">{{ t('platformBilling.reconciliation.notDue') }}</th>
              <th class="text-end">0–30</th>
              <th class="text-end">31–60</th>
              <th class="text-end">61–90</th>
              <th class="text-end">90+</th>
              <th class="text-end">{{ t('platformBilling.reconciliation.netRecoverable') }}</th>
              <th class="text-end">{{ t('platformBilling.reconciliation.writtenOff') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in aging?.by_currency || []" :key="c.currency">
              <td>{{ c.currency }}</td>
              <td class="text-end">{{ money(c.buckets.not_due, c.currency) }}</td>
              <td class="text-end">{{ money(c.buckets.d0_30, c.currency) }}</td>
              <td class="text-end">{{ money(c.buckets.d31_60, c.currency) }}</td>
              <td class="text-end">{{ money(c.buckets.d61_90, c.currency) }}</td>
              <td class="text-end text-error">{{ money(c.buckets.d90_plus, c.currency) }}</td>
              <td class="text-end text-primary" :data-test="`aging-net-${c.currency}`">{{ money(c.net_recoverable_cents, c.currency) }}</td>
              <td class="text-end text-warning">{{ money(c.written_off_cents, c.currency) }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>
