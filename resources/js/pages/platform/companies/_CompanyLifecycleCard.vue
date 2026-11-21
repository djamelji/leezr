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
const EXPORT_COLOR = { 'pending': 'info', 'running': 'info', 'ready': 'success', 'partial': 'warning', 'failed': 'error', 'expired': 'secondary', 'revoked': 'secondary', 'awaiting_approval': 'info', 'rejected': 'secondary' }
const AVAILABLE = ['ready', 'partial']

// ADR-846 — exceptional procedure (owner unavailable) : request, approval by ANOTHER operator, never a download here
const exOpen = ref(false)
const exForm = ref({ 'recipient_user_id': null, 'justification': '', 'authority_check': '' })
const exErrors = ref({})
const exBusy = ref(false)
const notice = ref('')

const requestExceptional = async () => {
  exBusy.value = true
  exErrors.value = {}
  try {
    await $platformApi(`/companies/${props.companyId}/data-exports/exceptional`, { method: 'POST', body: exForm.value })
    exOpen.value = false
    exForm.value = { 'recipient_user_id': null, 'justification': '', 'authority_check': '' }
    await load()
  }
  catch (e) { exErrors.value = e?.data?.errors || { justification: [e?.data?.message || t('companyClosure.genericError')] } }
  finally { exBusy.value = false }
}

const decide = async (e, action) => {
  busy.value = `${action}-${e.id}`
  notice.value = ''
  try { await $platformApi(`/companies/${props.companyId}/data-exports/${e.id}/${action}`, { method: 'POST' }) }
  catch (err) { notice.value = err?.data?.message || Object.values(err?.data?.errors || {}).flat()[0] || t('companyClosure.genericError') }
  finally { busy.value = null; await load() }
}

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
                <div
                  v-if="e.missing?.length"
                  class="text-caption text-warning"
                  :data-testid="`lifecycle-missing-${e.id}`"
                >
                  {{ t('platformLifecycle.missing', { parts: e.missing.map(m => `${m.part} (${m.reason})`).join(', ') }) }}
                </div>
                <div
                  v-if="e.origin === 'platform_exceptional'"
                  class="text-caption text-medium-emphasis"
                >
                  {{ t('platformLifecycle.exceptional.row', { name: e.recipient || '—' }) }}<template v-if="e.justification">
                    — {{ e.justification }}
                  </template>
                </div>
              </td>
              <td>{{ e.download_count }}</td>
              <td>{{ AVAILABLE.includes(e.status) ? dt(e.expires_at) : '—' }}</td>
              <td class="text-end">
                <template v-if="life.exceptional && e.status === 'awaiting_approval'">
                  <VBtn
                    size="small"
                    color="primary"
                    class="me-1"
                    :disabled="e.requested_by_platform_user_id === life.exceptional.operator_id"
                    :loading="busy === `approve-${e.id}`"
                    :data-testid="`lifecycle-approve-${e.id}`"
                    @click="decide(e, 'approve')"
                  >
                    {{ t('platformLifecycle.exceptional.approve') }}
                  </VBtn>
                  <VBtn
                    size="small"
                    variant="tonal"
                    color="secondary"
                    :loading="busy === `reject-${e.id}`"
                    :data-testid="`lifecycle-reject-${e.id}`"
                    @click="decide(e, 'reject')"
                  >
                    {{ t('platformLifecycle.exceptional.reject') }}
                  </VBtn>
                </template>
                <VBtn
                  v-if="canManage && AVAILABLE.includes(e.status)"
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

        <VAlert
          v-if="notice"
          type="warning"
          variant="tonal"
          class="mt-4"
          data-testid="lifecycle-notice"
        >
          {{ notice }}
        </VAlert>

        <!-- ADR-846 : exceptional export when the owner is unavailable -->
        <div
          v-if="life.exceptional"
          class="mt-6"
          data-testid="lifecycle-exceptional"
        >
          <div class="d-flex align-center flex-wrap gap-2">
            <div class="text-subtitle-1 me-auto">
              {{ t('platformLifecycle.exceptional.title') }}
            </div>
            <VBtn
              size="small"
              variant="tonal"
              prepend-icon="tabler-shield-lock"
              data-testid="lifecycle-exceptional-open"
              @click="exOpen = true"
            >
              {{ t('platformLifecycle.exceptional.request') }}
            </VBtn>
          </div>
          <p class="text-body-2 text-medium-emphasis mt-2 mb-0">
            {{ t('platformLifecycle.exceptional.hint') }}
          </p>
          <VAlert
            v-if="!life.exceptional.execution_enabled"
            type="info"
            variant="tonal"
            density="compact"
            class="mt-2"
            data-testid="lifecycle-exceptional-disabled"
          >
            {{ t('platformLifecycle.exceptional.disabled') }}
          </VAlert>
        </div>
      </template>
    </VCardText>

    <VDialog
      v-model="exOpen"
      max-width="560"
    >
      <VCard :title="t('platformLifecycle.exceptional.request')">
        <VCardText class="d-flex flex-column gap-4">
          <AppSelect
            v-model="exForm.recipient_user_id"
            :items="(life?.exceptional?.recipients || []).map(r => ({ value: r.id, title: `${r.name} · ${r.email}${r.owner ? ' (propriétaire)' : ''}` }))"
            :label="t('platformLifecycle.exceptional.recipient')"
            :error-messages="exErrors.recipient_user_id"
            data-testid="lifecycle-exceptional-recipient"
          />
          <AppTextarea
            v-model="exForm.justification"
            :label="t('platformLifecycle.exceptional.justification')"
            :hint="t('platformLifecycle.exceptional.justificationHint', { n: life?.exceptional?.min_justification_chars })"
            persistent-hint
            rows="3"
            :error-messages="exErrors.justification"
            data-testid="lifecycle-exceptional-justification"
          />
          <AppTextarea
            v-model="exForm.authority_check"
            :label="t('platformLifecycle.exceptional.authority')"
            :hint="t('platformLifecycle.exceptional.authorityHint')"
            persistent-hint
            rows="2"
            :error-messages="exErrors.authority_check"
            data-testid="lifecycle-exceptional-authority"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            color="secondary"
            @click="exOpen = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="exBusy"
            data-testid="lifecycle-exceptional-submit"
            @click="requestExceptional"
          >
            {{ t('platformLifecycle.exceptional.submit') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>
