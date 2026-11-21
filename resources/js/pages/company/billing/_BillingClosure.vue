<script setup>
import { $api } from '@/utils/api'
import { formatMoney } from '@/utils/money'
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'
import BillingCancelDialog from './_BillingCancelDialog.vue'

// ADR-845 (Leezr 4.2 M8) — « Data & end of contract » : understand the effects, get a full export of the data,
// check what is still open, cancel or undo a scheduled cancellation. Server : /company/lifecycle, /company/data-exports
// (owner only), existing Billing cancel endpoints. Nothing here deletes data.
const { t, locale } = useI18n()
const store = useCompanyBillingStore()

const life = ref(null)
const loading = ref(true)
const error = ref('')
const passwordOpen = ref(false)
const password = ref('')
const passwordError = ref('')
const requesting = ref(false)
const cancelOpen = ref(false)
const cancelling = ref(false)
const cancelPreview = ref(null)
const undoing = ref(false)
const message = ref(null)
let poll = null

const date = d => (d ? new Date(d).toLocaleDateString(locale.value, { day: 'numeric', month: 'long', year: 'numeric' }) : '—')
const dateTime = d => (d ? new Date(d).toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' }) : '—')
const size = b => (b ? (b > 1048576 ? `${(b / 1048576).toFixed(1)} Mo` : `${Math.max(1, Math.round(b / 1024))} Ko`) : '')
const STATE_COLOR = { active: 'success', trial: 'info', 'pending_payment': 'warning', 'payment_overdue': 'error', 'cancellation_scheduled': 'warning', ended: 'secondary', none: 'secondary' }
const EXPORT_COLOR = { pending: 'info', running: 'info', ready: 'success', failed: 'error', expired: 'secondary', revoked: 'secondary' }

const load = async () => {
  try {
    life.value = await $api('/company/lifecycle')
    error.value = ''
  }
  catch { error.value = t('companyClosure.loadError') }
  finally { loading.value = false }
  const busy = (life.value?.exports || []).some(e => ['pending', 'running'].includes(e.status))

  clearTimeout(poll)
  if (busy) poll = setTimeout(load, 4000) // the archive is built in the background
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(poll))

const requestExport = async () => {
  requesting.value = true
  passwordError.value = ''
  try {
    await $api('/company/data-exports', { method: 'POST', body: { password: password.value } })
    passwordOpen.value = false
    password.value = ''
    message.value = { type: 'success', text: t('companyClosure.export.requested') }
    await load()
  }
  catch (e) {
    const status = e?.status || e?.response?.status

    passwordError.value = status === 429 ? t('companyClosure.export.tooMany') : (Object.values(e?.data?.errors || {}).flat()[0] || e?.data?.message || t('companyClosure.genericError'))
  }
  finally { requesting.value = false }
}

const download = async exp => {
  try {
    const blob = await $api(`/company/data-exports/${exp.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = `export-leezr-${exp.requested_at?.slice(0, 10) || 'donnees'}.zip`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
    await load()
  }
  catch (e) {
    message.value = { type: 'error', text: e?.status === 410 || e?.response?.status === 410 ? t('companyClosure.export.expiredLink') : t('companyClosure.genericError') }
    await load()
  }
}

const openCancel = async () => {
  cancelPreview.value = await $api('/billing/subscription/cancel-preview').catch(() => null)
  cancelOpen.value = true
}

const confirmCancel = async () => {
  cancelling.value = true
  try {
    await store.cancelSubscription()
    cancelOpen.value = false
    message.value = { type: 'success', text: t('companyClosure.cancel.done') }
    await load()
  }
  catch (e) { message.value = { type: 'error', text: e?.data?.message || t('companyClosure.genericError') } }
  finally { cancelling.value = false }
}

const undoCancel = async () => {
  undoing.value = true
  try {
    life.value = { ...life.value, ...(await $api('/billing/subscription/undo-cancel', { method: 'POST' })) }
    message.value = { type: 'success', text: t('companyClosure.cancel.undone') }
    await load()
  }
  catch (e) { message.value = { type: 'error', text: e?.data?.message || t('companyClosure.genericError') } }
  finally { undoing.value = false }
}
</script>

<template>
  <div data-test="company-closure">
    <VSkeletonLoader
      v-if="loading"
      type="card"
    />
    <VAlert
      v-else-if="error"
      type="error"
      variant="tonal"
    >
      {{ error }}
    </VAlert>
    <template v-else-if="life">
      <VAlert
        v-if="message"
        :type="message.type"
        variant="tonal"
        closable
        class="mb-4"
        data-test="closure-message"
        @click:close="message = null"
      >
        {{ message.text }}
      </VAlert>

      <VRow>
        <!-- 1–2 : subscription, effects and dates -->
        <VCol
          cols="12"
          md="6"
        >
          <VCard class="h-100">
            <VCardItem>
              <VCardTitle>{{ t('companyClosure.subscription.title') }}</VCardTitle>
            </VCardItem>
            <VCardText>
              <VChip
                :color="STATE_COLOR[life.commercial.state]"
                label
                class="mb-3"
                data-test="closure-state"
              >
                {{ t(`companyClosure.states.${life.commercial.state}`) }}
              </VChip>
              <VList density="compact">
                <VListItem
                  :title="t('companyClosure.subscription.plan')"
                  :subtitle="life.commercial.plan ? `${life.commercial.plan} · ${t(`companyClosure.interval.${life.commercial.interval}`)}` : '—'"
                />
                <VListItem
                  :title="life.commercial.state === 'cancellation_scheduled' ? t('companyClosure.subscription.endsOn') : t('companyClosure.subscription.periodEnd')"
                  :subtitle="date(life.commercial.period_end)"
                />
                <VListItem
                  v-if="life.commercial.trial_ends_at"
                  :title="t('companyClosure.subscription.trialEnds')"
                  :subtitle="date(life.commercial.trial_ends_at)"
                />
              </VList>
              <div class="text-subtitle-2 mt-3 mb-1">
                {{ t('companyClosure.effects.title') }}
              </div>
              <ul
                class="text-body-2 ps-5"
                data-test="closure-effects"
              >
                <li>{{ t('companyClosure.effects.date', { date: date(life.commercial.period_end) }) }}</li>
                <li>{{ t('companyClosure.effects.access') }}</li>
                <li>{{ t('companyClosure.effects.data') }}</li>
                <li>{{ life.data.retention_policy ? t('companyClosure.effects.retentionDefined') : t('companyClosure.effects.retentionUndefined') }}</li>
              </ul>
            </VCardText>
            <VCardActions class="flex-wrap gap-2 px-6 pb-5">
              <VBtn
                v-if="life.commercial.can_cancel"
                color="error"
                variant="tonal"
                prepend-icon="tabler-calendar-x"
                data-test="closure-cancel"
                @click="openCancel"
              >
                {{ t('companyClosure.cancel.button') }}
              </VBtn>
              <VBtn
                v-if="life.commercial.can_undo_cancel"
                color="primary"
                prepend-icon="tabler-arrow-back-up"
                :loading="undoing"
                data-test="closure-undo-cancel"
                @click="undoCancel"
              >
                {{ t('companyClosure.cancel.undo') }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VCol>

        <!-- 4 : what is still open -->
        <VCol
          cols="12"
          md="6"
        >
          <VCard class="h-100">
            <VCardItem>
              <VCardTitle>{{ t('companyClosure.obligations.title') }}</VCardTitle>
              <VCardSubtitle class="text-wrap">
                {{ t('companyClosure.obligations.hint') }}
              </VCardSubtitle>
            </VCardItem>
            <VCardText data-test="closure-obligations">
              <VList density="compact">
                <VListItem
                  :prepend-icon="life.obligations.unpaid_invoices ? 'tabler-alert-triangle' : 'tabler-circle-check'"
                  :base-color="life.obligations.unpaid_invoices ? 'warning' : 'success'"
                  :title="life.obligations.unpaid_invoices ? t('companyClosure.obligations.unpaid', { n: life.obligations.unpaid_invoices, amount: formatMoney(life.obligations.unpaid_amount_cents) }) : t('companyClosure.obligations.noUnpaid')"
                  :to="life.obligations.unpaid_invoices ? { name: 'company-billing-tab', params: { tab: 'invoices' } } : undefined"
                />
                <VListItem
                  :prepend-icon="life.obligations.payroll_runs_open ? 'tabler-alert-triangle' : 'tabler-circle-check'"
                  :base-color="life.obligations.payroll_runs_open ? 'warning' : 'success'"
                  :title="life.obligations.payroll_runs_open ? t('companyClosure.obligations.payroll', { n: life.obligations.payroll_runs_open }) : t('companyClosure.obligations.noPayroll')"
                />
                <VListItem
                  prepend-icon="tabler-file-zip"
                  :base-color="life.data.latest_export?.status === 'ready' ? 'success' : 'warning'"
                  :title="life.data.latest_export?.status === 'ready' ? t('companyClosure.obligations.exportReady') : t('companyClosure.obligations.exportAdvised')"
                />
              </VList>
            </VCardText>
          </VCard>
        </VCol>

        <!-- 3 : full data export -->
        <VCol cols="12">
          <VCard data-test="closure-export">
            <VCardItem>
              <VCardTitle>{{ t('companyClosure.export.title') }}</VCardTitle>
              <VCardSubtitle class="text-wrap">
                {{ t('companyClosure.export.hint') }}
              </VCardSubtitle>
              <template
                v-if="life.can_export"
                #append
              >
                <VBtn
                  color="primary"
                  prepend-icon="tabler-file-zip"
                  data-test="closure-export-request"
                  @click="passwordOpen = true"
                >
                  {{ t('companyClosure.export.request') }}
                </VBtn>
              </template>
            </VCardItem>
            <VCardText>
              <VAlert
                v-if="!life.can_export"
                type="info"
                variant="tonal"
                data-test="closure-export-owner-only"
              >
                {{ t('companyClosure.export.ownerOnly') }}
              </VAlert>
              <VTable
                v-else-if="life.exports.length"
                density="comfortable"
              >
                <thead>
                  <tr>
                    <th>{{ t('companyClosure.export.requestedAt') }}</th>
                    <th>{{ t('companyClosure.export.status') }}</th>
                    <th>{{ t('companyClosure.export.content') }}</th>
                    <th>{{ t('companyClosure.export.expiresAt') }}</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="e in life.exports"
                    :key="e.id"
                    :data-test="`closure-export-${e.id}`"
                  >
                    <td>
                      {{ dateTime(e.requested_at) }}<div class="text-caption text-medium-emphasis">
                        {{ e.requested_by }}
                      </div>
                    </td>
                    <td>
                      <VChip
                        size="small"
                        :color="EXPORT_COLOR[e.status]"
                        :data-test="`closure-export-status-${e.id}`"
                      >
                        {{ t(`companyClosure.export.statuses.${e.status}`) }}
                      </VChip>
                      <div
                        v-if="e.errors?.length"
                        class="text-caption text-warning"
                      >
                        {{ t('companyClosure.export.partial', { parts: e.errors.map(x => x.part).join(', ') }) }}
                      </div>
                    </td>
                    <td class="text-body-2">
                      {{ e.files.length ? t('companyClosure.export.files', { n: e.files.length }) : '—' }} <span class="text-medium-emphasis">{{ size(e.size_bytes) }}</span>
                    </td>
                    <td>{{ e.status === 'ready' ? dateTime(e.expires_at) : '—' }}</td>
                    <td class="text-end">
                      <VBtn
                        v-if="e.status === 'ready'"
                        size="small"
                        color="primary"
                        prepend-icon="tabler-download"
                        :data-test="`closure-export-download-${e.id}`"
                        @click="download(e)"
                      >
                        {{ t('companyClosure.export.download') }}
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
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- password re-confirmation before a full export -->
    <VDialog
      v-model="passwordOpen"
      max-width="480"
    >
      <VCard :title="t('companyClosure.export.confirmTitle')">
        <VCardText>
          <p class="text-body-2 mb-4">
            {{ t('companyClosure.export.confirmText') }}
          </p>
          <AppTextField
            v-model="password"
            type="password"
            autocomplete="current-password"
            :label="t('companyClosure.export.password')"
            :error-messages="passwordError"
            data-test="closure-export-password"
            @keyup.enter="requestExport"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            color="secondary"
            @click="passwordOpen = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="requesting"
            :disabled="!password"
            data-test="closure-export-confirm"
            @click="requestExport"
          >
            {{ t('companyClosure.export.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <BillingCancelDialog
      v-model:is-visible="cancelOpen"
      :is-cancelling="cancelling"
      :cancel-preview="cancelPreview"
      @confirm="confirmCancel"
    />
  </div>
</template>
