<script setup>
/**
 * ADR-588 — Tariff migration wizard (Platform). Explicit, audited migration of SELECTED
 * subscriptions to a new contracted price. Preview has no financial effect; apply is backend-gated
 * (manage_billing) + idempotent + audited. Multi-currency totals are never summed together.
 */
import { usePlatformPlansStore } from '@/modules/platform-admin/plans/plans.store'
import { formatMoney } from '@/utils/money'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  planKey: { type: String, required: true },
  interval: { type: String, default: 'monthly' },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const { toast } = useAppToast()
const store = usePlatformPlansStore()

const step = ref(1)
const loading = ref(false)
const subscriptions = ref([])
const selected = ref([])
const newPriceEuros = ref(null)
const timing = ref('immediate')
const effectiveAt = ref(null)
const preview = ref(null)
const result = ref(null)

const newPriceCents = computed(() => Math.round((newPriceEuros.value || 0) * 100))
const fmt = (cents, cur) => formatMoney(cents ?? 0, { currency: cur || 'EUR' })

const isOpen = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })

watch(isOpen, async open => {
  if (open) {
    step.value = 1
    selected.value = []
    newPriceEuros.value = null
    timing.value = 'immediate'
    effectiveAt.value = null
    preview.value = null
    result.value = null
    loading.value = true
    try {
      subscriptions.value = await store.fetchMigrationSubscriptions(props.planKey, props.interval)
    }
    catch { subscriptions.value = [] }
    finally { loading.value = false }
  }
})

async function goPreview() {
  loading.value = true
  try {
    preview.value = await store.previewMigration(selected.value, newPriceCents.value)
    step.value = 3
  }
  catch { toast(t('common.error'), 'error') }
  finally { loading.value = false }
}

async function confirmApply() {
  loading.value = true
  try {
    result.value = await store.applyMigration(selected.value, newPriceCents.value, timing.value, timing.value === 'at_renewal' ? effectiveAt.value : null)
    step.value = 5
    toast(t('plans.migration.applied'), 'success')
  }
  catch (e) {
    toast(e?.data?.message || t('common.error'), 'error')
  }
  finally { loading.value = false }
}

const headers = [
  { title: '', key: 'select', sortable: false, width: 48 },
  { title: 'Company', key: 'company' },
  { title: 'Devise', key: 'currency' },
  { title: 'Contractuel', key: 'contracted_cents', align: 'end' },
  { title: 'Public', key: 'public_cents', align: 'end' },
]

function toggle(id) {
  const i = selected.value.indexOf(id)
  if (i === -1) selected.value.push(id)
  else selected.value.splice(i, 1)
}
</script>

<template>
  <VDialog
    v-model="isOpen"
    max-width="780"
    scrollable
    data-test="tariff-migration-wizard"
  >
    <VCard>
      <VCardItem>
        <VCardTitle class="d-flex align-center gap-2">
          <VIcon icon="tabler-transfer" color="primary" />
          {{ t('plans.migration.title') }}
        </VCardTitle>
      </VCardItem>
      <VDivider />

      <VCardText style="min-height: 320px;">
        <VSkeletonLoader v-if="loading" type="table" />

        <!-- Step 1 — selection -->
        <div v-else-if="step === 1" data-test="wiz-step-select">
          <p class="text-body-2 text-medium-emphasis mb-3">{{ t('plans.migration.selectHint') }}</p>
          <VAlert v-if="!subscriptions.length" type="info" variant="tonal">{{ t('plans.migration.noSubs') }}</VAlert>
          <VTable v-else density="compact">
            <thead>
              <tr>
                <th v-for="h in headers" :key="h.key" :class="h.align === 'end' ? 'text-end' : ''">{{ h.title }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in subscriptions" :key="s.subscription_id" :data-test="`wiz-sub-${s.subscription_id}`">
                <td><VCheckbox :model-value="selected.includes(s.subscription_id)" hide-details density="compact" @update:model-value="toggle(s.subscription_id)" /></td>
                <td>
                  {{ s.company }}
                  <VChip v-if="s.scheduled_migration" size="x-small" color="warning" variant="tonal" class="ms-1">{{ t('plans.migration.scheduledTag') }}</VChip>
                </td>
                <td>{{ s.currency }}</td>
                <td class="text-end">{{ fmt(s.contracted_cents, s.currency) }}</td>
                <td class="text-end text-medium-emphasis">{{ fmt(s.public_cents, s.currency) }}</td>
              </tr>
            </tbody>
          </VTable>
        </div>

        <!-- Step 2 — new price + timing -->
        <div v-else-if="step === 2" data-test="wiz-step-price">
          <AppTextField
            v-model.number="newPriceEuros"
            :label="t('plans.migration.newPrice')"
            type="number"
            step="0.01"
            min="0"
            data-test="wiz-new-price"
          />
          <VRadioGroup v-model="timing" class="mt-3" inline>
            <VRadio :label="t('plans.migration.immediate')" value="immediate" />
            <VRadio :label="t('plans.migration.atRenewal')" value="at_renewal" />
          </VRadioGroup>
          <AppDateTimePicker
            v-if="timing === 'at_renewal'"
            v-model="effectiveAt"
            :label="t('plans.migration.effectiveAt')"
            :config="{ enableTime: false }"
          />
        </div>

        <!-- Step 3 — preview (no financial effect) -->
        <div v-else-if="step === 3 && preview" data-test="wiz-step-preview">
          <VAlert type="info" variant="tonal" density="compact" class="mb-3">{{ t('plans.migration.noEffect') }}</VAlert>
          <div v-for="c in preview.by_currency" :key="c.currency" class="mb-2" :data-test="`wiz-preview-${c.currency}`">
            <VChip size="small" variant="tonal" class="me-2">{{ c.currency }}</VChip>
            {{ c.count }} {{ t('plans.migration.subs') }} · {{ t('plans.migration.totalDelta') }}
            <strong :class="c.total_delta_cents > 0 ? 'text-error' : (c.total_delta_cents < 0 ? 'text-success' : '')">{{ fmt(c.total_delta_cents, c.currency) }}</strong>
          </div>
          <VTable density="compact" class="mt-2">
            <thead><tr><th>Company</th><th class="text-end">{{ t('plans.migration.from') }}</th><th class="text-end">{{ t('plans.migration.to') }}</th><th class="text-end">Δ</th></tr></thead>
            <tbody>
              <tr v-for="l in preview.lines" :key="l.subscription_id">
                <td>{{ l.company }}</td>
                <td class="text-end">{{ fmt(l.current_contracted_cents, l.currency) }}</td>
                <td class="text-end text-primary">{{ fmt(l.new_cents, l.currency) }}</td>
                <td class="text-end" :class="l.delta_cents > 0 ? 'text-error' : (l.delta_cents < 0 ? 'text-success' : '')">{{ fmt(l.delta_cents, l.currency) }}</td>
              </tr>
            </tbody>
          </VTable>
        </div>

        <!-- Step 5 — result -->
        <div v-else-if="step === 5 && result" data-test="wiz-step-result">
          <VAlert type="success" variant="tonal">
            {{ t('plans.migration.resultSummary', { migrated: result.migrated, scheduled: result.scheduled, skipped: result.skipped }) }}
          </VAlert>
        </div>
      </VCardText>

      <VDivider />
      <VCardActions class="justify-space-between">
        <VBtn variant="text" :disabled="loading" @click="isOpen = false">{{ step === 5 ? t('common.close') : t('common.cancel') }}</VBtn>
        <div class="d-flex gap-2">
          <VBtn v-if="step === 2 || step === 3" variant="tonal" :disabled="loading" @click="step -= 1">{{ t('common.back') }}</VBtn>
          <VBtn v-if="step === 1" color="primary" :disabled="!selected.length" data-test="wiz-next-1" @click="step = 2">{{ t('common.next') }} ({{ selected.length }})</VBtn>
          <VBtn v-if="step === 2" color="primary" :disabled="!newPriceCents" :loading="loading" data-test="wiz-next-2" @click="goPreview">{{ t('plans.migration.preview') }}</VBtn>
          <VBtn v-if="step === 3" color="warning" :loading="loading" data-test="wiz-confirm" @click="confirmApply">{{ t('plans.migration.confirm') }}</VBtn>
        </div>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
