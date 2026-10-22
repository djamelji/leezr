<script setup>
/**
 * ADR-594 — "Subscriptions to verify" (tariff review). Lists subscriptions whose contracted price is
 * not reliably verified (provisional/missing) and whose renewal is therefore held. An administrator
 * (manage_billing) confirms the contracted price with proof; confirmation is audited, concurrency-safe
 * and creates no invoice. Reuses the existing Platform billing cockpit — not a parallel one.
 */
import { usePlatformPlansStore } from '@/modules/platform-admin/plans/plans.store'
import { formatMoney } from '@/utils/money'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const { toast } = useAppToast()
const store = usePlatformPlansStore()

const loading = ref(true)
const rows = ref([])
const search = ref('')

async function load() {
  loading.value = true
  try {
    rows.value = await store.fetchReviewSubscriptions()
  }
  catch { rows.value = [] }
  finally { loading.value = false }
}

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return rows.value

  return rows.value.filter(r => `${r.company} ${r.plan_key} ${r.subscription_id}`.toLowerCase().includes(q))
})

const fmt = (cents, cur) => cents == null ? '—' : formatMoney(cents, { currency: cur || 'EUR' })
const sourceColor = s => ({ provisional: 'warning', null: 'error' }[s] ?? 'error')
const sourceLabel = s => s ? t(`platformBilling.tariffReview.source.${s}`, s) : t('platformBilling.tariffReview.source.null')

// ── Confirm dialog ──
const dialog = ref(false)
const target = ref(null)
const priceEuros = ref(null)
const proof = ref('')
const saving = ref(false)

function openConfirm(row) {
  target.value = row
  priceEuros.value = row.contracted_cents != null ? row.contracted_cents / 100 : null
  proof.value = ''
  dialog.value = true
}

const proofValid = computed(() => proof.value.trim().length >= 3)
const newCents = computed(() => priceEuros.value != null ? Math.round(priceEuros.value * 100) : null)

async function confirm() {
  if (!proofValid.value || newCents.value == null) return
  saving.value = true
  try {
    // Concurrency basis captured when the dialog opened (source + cents).
    const expected = { plan_price_source: target.value.price_source, plan_price_cents: target.value.contracted_cents }
    await store.confirmContractedPrice(target.value.subscription_id, newCents.value, proof.value.trim(), expected)
    toast(t('platformBilling.tariffReview.confirmed'), 'success')
    dialog.value = false
    await load()
  }
  catch (e) {
    if (e?.status === 409) { toast(t('platformBilling.tariffReview.stale'), 'warning'); dialog.value = false; await load() }
    else { toast(e?.data?.message || t('common.error'), 'error') }
  }
  finally { saving.value = false }
}

onMounted(load)
</script>

<template>
  <div data-test="billing-tariff-review-tab">
    <VAlert type="warning" variant="tonal" density="compact" class="mb-4">
      {{ t('platformBilling.tariffReview.intro') }}
    </VAlert>

    <div class="d-flex align-center justify-space-between mb-3 gap-2 flex-wrap">
      <AppTextField
        v-model="search"
        :placeholder="t('platformBilling.tariffReview.search')"
        prepend-inner-icon="tabler-search"
        density="compact"
        style="max-inline-size: 320px;"
        data-test="tr-search"
      />
      <VBtn size="small" variant="text" prepend-icon="tabler-refresh" :loading="loading" @click="load">
        {{ t('common.refresh') }}
      </VBtn>
    </div>

    <VSkeletonLoader v-if="loading" type="table" />

    <template v-else>
      <VCard v-if="!filtered.length" data-test="tr-empty">
        <VCardText class="text-center py-8 text-success">
          <VIcon icon="tabler-circle-check" size="40" class="mb-2" />
          <div>{{ t('platformBilling.tariffReview.empty') }}</div>
        </VCardText>
      </VCard>

      <VTable v-else density="comfortable" data-test="tr-table">
        <thead>
          <tr>
            <th>Company</th>
            <th>{{ t('platformBilling.tariffReview.plan') }}</th>
            <th class="text-end">{{ t('platformBilling.tariffReview.contracted') }}</th>
            <th class="text-end">{{ t('platformBilling.tariffReview.public') }}</th>
            <th>{{ t('platformBilling.tariffReview.provenance') }}</th>
            <th>{{ t('platformBilling.tariffReview.reason') }}</th>
            <th>{{ t('platformBilling.tariffReview.nextDue') }}</th>
            <th class="text-end">{{ t('common.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in filtered" :key="r.subscription_id" :data-test="`tr-row-${r.subscription_id}`">
            <td>{{ r.company }}</td>
            <td>{{ r.plan_key }} · {{ r.interval }}</td>
            <td class="text-end">{{ fmt(r.contracted_cents, r.currency) }}</td>
            <td class="text-end text-medium-emphasis">{{ fmt(r.public_cents, r.currency) }}</td>
            <td>
              <VChip :color="sourceColor(r.price_source)" size="small" variant="tonal">{{ sourceLabel(r.price_source) }}</VChip>
            </td>
            <td class="text-caption">{{ t(`platformBilling.tariffReview.reasons.${r.review_reason}`, r.review_reason) }}</td>
            <td class="text-body-2">{{ r.next_due || '—' }}</td>
            <td class="text-end">
              <VBtn size="small" color="primary" variant="tonal" :data-test="`tr-confirm-${r.subscription_id}`" @click="openConfirm(r)">
                {{ t('platformBilling.tariffReview.confirm') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </template>

    <!-- Confirm dialog -->
    <VDialog v-model="dialog" max-width="560" data-test="tr-dialog">
      <VCard v-if="target">
        <VCardItem>
          <VCardTitle>{{ t('platformBilling.tariffReview.dialogTitle') }}</VCardTitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <div class="mb-2 text-body-2 text-medium-emphasis">{{ target.company }} · {{ target.plan_key }} · {{ target.interval }}</div>
          <div class="d-flex gap-4 mb-3">
            <div><div class="text-caption text-medium-emphasis">{{ t('platformBilling.tariffReview.contracted') }}</div><div class="text-h6">{{ fmt(target.contracted_cents, target.currency) }}</div></div>
            <div><div class="text-caption text-medium-emphasis">{{ t('platformBilling.tariffReview.public') }}</div><div class="text-h6 text-medium-emphasis">{{ fmt(target.public_cents, target.currency) }}</div></div>
          </div>
          <VAlert type="info" variant="tonal" density="compact" class="mb-3">
            {{ t('platformBilling.tariffReview.consequence') }}
          </VAlert>
          <AppTextField
            v-model.number="priceEuros"
            :label="t('platformBilling.tariffReview.confirmedPrice')"
            type="number"
            step="0.01"
            min="0"
            class="mb-3"
            data-test="tr-price"
          />
          <AppTextarea
            v-model="proof"
            :label="t('platformBilling.tariffReview.proof')"
            :placeholder="t('platformBilling.tariffReview.proofHint')"
            rows="2"
            :error="proof.length > 0 && !proofValid"
            data-test="tr-proof"
          />
          <VAlert type="warning" variant="tonal" density="compact" class="mt-3">
            {{ t('platformBilling.tariffReview.warning') }}
          </VAlert>
        </VCardText>
        <VDivider />
        <VCardActions class="justify-end">
          <VBtn variant="text" :disabled="saving" @click="dialog = false">{{ t('common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="saving" :disabled="!proofValid || newCents == null" data-test="tr-submit" @click="confirm">
            {{ t('platformBilling.tariffReview.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
