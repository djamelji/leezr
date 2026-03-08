<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useAppToast } from '@/composables/useAppToast'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = usePlatformPaymentsStore()
const authStore = usePlatformAuthStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const statusFilter = ref('')

const canManage = computed(() => authStore.hasPermission('manage_billing'))

// ── Idempotency key generator ──────────────────────────
const generateKey = action => {
  const now = new Date()
  const ts = now.toISOString().replace(/[-:T.Z]/g, '').slice(0, 14)
  const rand = Math.random().toString(36).slice(2, 8)

  return `ui-${action}-${ts}-${rand}`
}

// ── Can-act guards ─────────────────────────────────────
const canMarkPaid = invoice => ['open', 'overdue'].includes(invoice.status)
const canVoid = invoice => ['open', 'overdue'].includes(invoice.status)
const canEditNotes = invoice => !!invoice.finalized_at || !!invoice.number
const canRefund = invoice => invoice.status === 'paid'
const canRetryPayment = invoice => invoice.status === 'overdue'
const canWriteOff = invoice => invoice.status === 'overdue'

const canForceDunning = invoice => {
  return invoice.status === 'open' || invoice.status === 'overdue'
}

const dunningTarget = invoice => {
  if (invoice.status === 'open') return 'overdue'
  if (invoice.status === 'overdue') return 'uncollectible'

  return null
}

const canIssueCreditNote = invoice => {
  return !!invoice.finalized_at && invoice.status !== 'voided'
}

// ── Headers ────────────────────────────────────────────
const headers = computed(() => {
  const cols = [
    { title: t('platformBilling.company'), key: 'company', sortable: false },
    { title: t('platformBilling.invoiceNumber'), key: 'number' },
    { title: t('platformBilling.status'), key: 'status', width: '130px' },
    { title: t('platformBilling.amount'), key: 'amount', align: 'end' },
    { title: t('platformBilling.amountDue'), key: 'amount_due', align: 'end' },
    { title: t('platformBilling.issuedAt'), key: 'issued_at' },
    { title: t('platformBilling.dueAt'), key: 'due_at' },
  ]

  if (canManage.value) {
    cols.push({ title: t('platformBilling.actions'), key: 'actions', sortable: false, width: '80px', align: 'center' })
  }

  return cols
})

const statusOptions = computed(() => [
  { title: t('platformBilling.filterAll'), value: '' },
  { title: t('platformBilling.statusOpen'), value: 'open' },
  { title: t('platformBilling.statusOverdue'), value: 'overdue' },
  { title: t('platformBilling.statusPaid'), value: 'paid' },
  { title: t('platformBilling.statusVoided'), value: 'voided' },
])

const statusColor = status => {
  const colors = { draft: 'secondary', open: 'info', overdue: 'error', paid: 'success', voided: 'warning', uncollectible: 'error' }

  return colors[status] || 'secondary'
}

const statusLabel = status => {
  const map = { draft: 'statusDraft', open: 'statusOpen', overdue: 'statusOverdue', paid: 'statusPaid', voided: 'statusVoided', uncollectible: 'statusUncollectible' }

  return t(`platformBilling.${map[status] || 'statusDraft'}`)
}

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

// ── Data loading ───────────────────────────────────────
const load = async (page = 1) => {
  isLoading.value = true
  try {
    await store.fetchAllInvoices({
      page,
      status: statusFilter.value || undefined,
    })
  }
  finally {
    isLoading.value = false
  }
}

onMounted(() => load())
watch(statusFilter, () => load(1))

// ── Error handler ──────────────────────────────────────
const handleError = error => {
  const status = error?.status || error?.statusCode
  if (status === 401 || status === 403)
    toast(t('platformBilling.errorNotAuthorized'), 'error')
  else if (status === 409)
    toast(error?.data?.message || t('platformBilling.errorConflict'), 'error')
  else
    toast(error?.data?.message || t('platformBilling.errorGeneric'), 'error')
}

// ── Confirm dialog state (mark-paid / void / retry / writeoff) ──
const confirmDialog = ref(false)
const confirmAction = ref(null) // 'markPaid' | 'void' | 'retry' | 'writeOff'
const confirmInvoice = ref(null)

const confirmTitle = computed(() => {
  const map = {
    markPaid: t('platformBilling.confirmMarkPaidTitle'),
    void: t('platformBilling.confirmVoidTitle'),
    retry: t('platformBilling.dialogs.retry.title'),
    writeOff: t('platformBilling.dialogs.writeOff.title'),
  }

  return map[confirmAction.value] || ''
})

const confirmBody = computed(() => {
  const number = confirmInvoice.value?.number || '—'
  const map = {
    markPaid: t('platformBilling.confirmMarkPaidBody', { number }),
    void: t('platformBilling.confirmVoidBody', { number }),
    retry: t('platformBilling.dialogs.retry.body', { number }),
    writeOff: t('platformBilling.dialogs.writeOff.body', { number }),
  }

  return map[confirmAction.value] || ''
})

const confirmColor = computed(() => {
  if (confirmAction.value === 'void' || confirmAction.value === 'writeOff')
    return 'error'

  if (confirmAction.value === 'retry')
    return 'warning'

  return 'primary'
})

const openConfirm = (action, invoice) => {
  confirmAction.value = action
  confirmInvoice.value = invoice
  confirmDialog.value = true
}

const handleConfirm = async confirmed => {
  confirmDialog.value = false
  if (!confirmed || !confirmInvoice.value) return

  const invoice = confirmInvoice.value
  const action = confirmAction.value

  try {
    if (action === 'markPaid') {
      await store.markPaidOffline(invoice.id, generateKey('mark-paid'))
      toast(t('platformBilling.markPaidSuccess'), 'success')
    }
    else if (action === 'void') {
      await store.voidInvoice(invoice.id, generateKey('void'))
      toast(t('platformBilling.voidSuccess'), 'success')
    }
    else if (action === 'retry') {
      await store.retryInvoicePayment(invoice.id, { idempotency_key: generateKey('retry') })
      toast(t('platformBilling.toasts.retrySuccess'), 'success')
    }
    else if (action === 'writeOff') {
      await store.writeOffInvoice(invoice.id, { idempotency_key: generateKey('writeoff') })
      toast(t('platformBilling.toasts.writeOffSuccess'), 'success')
    }
  }
  catch (error) {
    handleError(error)
  }
}

// ── Notes dialog state ─────────────────────────────────
const notesDialog = ref(false)
const notesInvoice = ref(null)
const notesText = ref('')
const notesSaving = ref(false)

const openNotes = invoice => {
  notesInvoice.value = invoice
  notesText.value = invoice.notes || ''
  notesDialog.value = true
}

const saveNotes = async () => {
  if (!notesInvoice.value || notesSaving.value) return
  notesSaving.value = true

  try {
    await store.updateInvoiceNotes(notesInvoice.value.id, notesText.value || null)
    toast(t('platformBilling.notesSuccess'), 'success')
    notesDialog.value = false
  }
  catch (error) {
    handleError(error)
  }
  finally {
    notesSaving.value = false
  }
}

// ── Refund dialog state ────────────────────────────────
const refundDialog = ref(false)
const refundInvoice = ref(null)
const refundAmount = ref(0)
const refundReason = ref('')
const refundSaving = ref(false)

const openRefund = invoice => {
  refundInvoice.value = invoice
  refundAmount.value = 0
  refundReason.value = ''
  refundDialog.value = true
}

const refundValid = computed(() => {
  return refundAmount.value > 0 && refundReason.value.trim().length > 0
})

const submitRefund = async () => {
  if (!refundInvoice.value || refundSaving.value || !refundValid.value) return
  refundSaving.value = true

  try {
    await store.refundInvoice(refundInvoice.value.id, {
      amount: refundAmount.value,
      reason: refundReason.value.trim(),
      idempotency_key: generateKey('refund'),
    })

    toast(t('platformBilling.toasts.refundSuccess'), 'success')
    refundDialog.value = false
  }
  catch (error) {
    handleError(error)
  }
  finally {
    refundSaving.value = false
  }
}

// ── Dunning transition dialog state ────────────────────
const dunningDialog = ref(false)
const dunningInvoice = ref(null)
const dunningSaving = ref(false)

const openDunning = invoice => {
  dunningInvoice.value = invoice
  dunningDialog.value = true
}

const submitDunning = async () => {
  if (!dunningInvoice.value || dunningSaving.value) return
  const target = dunningTarget(dunningInvoice.value)
  if (!target) return

  dunningSaving.value = true

  try {
    await store.forceDunningTransition(dunningInvoice.value.id, {
      target_status: target,
      idempotency_key: generateKey('dunning'),
    })

    toast(t('platformBilling.toasts.dunningSuccess'), 'success')
    dunningDialog.value = false
  }
  catch (error) {
    handleError(error)
  }
  finally {
    dunningSaving.value = false
  }
}

// ── Credit note dialog state ───────────────────────────
const creditNoteDialog = ref(false)
const creditNoteInvoice = ref(null)
const creditNoteAmount = ref(0)
const creditNoteReason = ref('')
const creditNoteApplyWallet = ref(false)
const creditNoteSaving = ref(false)

const openCreditNote = invoice => {
  creditNoteInvoice.value = invoice
  creditNoteAmount.value = 0
  creditNoteReason.value = ''
  creditNoteApplyWallet.value = false
  creditNoteDialog.value = true
}

const creditNoteValid = computed(() => {
  return creditNoteAmount.value > 0 && creditNoteReason.value.trim().length > 0
})

const submitCreditNote = async () => {
  if (!creditNoteInvoice.value || creditNoteSaving.value || !creditNoteValid.value) return
  creditNoteSaving.value = true

  try {
    await store.issueManualCreditNote(creditNoteInvoice.value.id, {
      amount: creditNoteAmount.value,
      reason: creditNoteReason.value.trim(),
      apply_to_wallet: creditNoteApplyWallet.value,
      idempotency_key: generateKey('credit-note'),
    })

    toast(t('platformBilling.toasts.creditNoteSuccess'), 'success')
    creditNoteDialog.value = false
  }
  catch (error) {
    handleError(error)
  }
  finally {
    creditNoteSaving.value = false
  }
}
</script>

<template>
  <VCard>
    <VCardTitle class="d-flex align-center">
      <VIcon
        icon="tabler-file-invoice"
        class="me-2"
      />
      {{ t('platformBilling.tabs.invoices') }}
      <VSpacer />
      <AppSelect
        v-model="statusFilter"
        :items="statusOptions"
        density="compact"
        style="max-inline-size: 160px;"
      />
    </VCardTitle>

    <VCardText class="pa-0">
      <VSkeletonLoader
        v-if="isLoading && store.allInvoices.length === 0"
        type="table"
      />

      <div
        v-else-if="store.allInvoices.length === 0 && !isLoading"
        class="text-center pa-6 text-disabled"
      >
        <VIcon
          icon="tabler-file-invoice"
          size="48"
          class="mb-2"
        />
        <p class="text-body-1">
          {{ t('platformBilling.noInvoices') }}
        </p>
      </div>

      <VDataTable
        v-else
        :headers="headers"
        :items="store.allInvoices"
        :loading="isLoading"
        :items-per-page="store.allInvoicesPagination.per_page"
        hide-default-footer
      >
        <template #item.company="{ item }">
          <RouterLink
            v-if="item.company?.id"
            :to="{ path: `/platform/companies/${item.company.id}`, query: { tab: 'billing' } }"
            class="font-weight-medium text-high-emphasis text-decoration-none"
          >
            {{ item.company.name }}
          </RouterLink>
          <span v-else class="font-weight-medium">—</span>
        </template>

        <template #item.number="{ item }">
          <RouterLink
            :to="`/platform/billing/invoices/${item.id}`"
            class="text-body-1 font-weight-medium text-primary text-decoration-none"
          >
            {{ item.number }}
          </RouterLink>
        </template>

        <template #item.status="{ item }">
          <VChip
            :color="statusColor(item.status)"
            size="small"
          >
            {{ statusLabel(item.status) }}
          </VChip>
        </template>

        <template #item.amount="{ item }">
          {{ formatMoney(item.amount, { currency: item.currency }) }}
        </template>

        <template #item.amount_due="{ item }">
          <span :class="item.amount_due > 0 ? 'text-error' : 'text-success'">
            {{ formatMoney(item.amount_due, { currency: item.currency }) }}
          </span>
        </template>

        <template #item.issued_at="{ item }">
          {{ formatDate(item.issued_at) }}
        </template>

        <template #item.due_at="{ item }">
          {{ formatDate(item.due_at) }}
        </template>

        <!-- Actions column (manage_billing only) -->
        <template
          v-if="canManage"
          #item.actions="{ item }"
        >
          <VMenu>
            <template #activator="{ props }">
              <VBtn
                v-bind="props"
                icon="tabler-dots-vertical"
                variant="text"
                size="small"
                :loading="store.isMutationLoading(item.id)"
                :disabled="store.isMutationLoading(item.id)"
                @click.stop
              />
            </template>
            <VList density="compact">
              <!-- Basic actions (D2b) -->
              <VListItem
                :disabled="!canMarkPaid(item)"
                @click="openConfirm('markPaid', item)"
              >
                <template #prepend>
                  <VIcon
                    icon="tabler-cash"
                    size="20"
                  />
                </template>
                <VListItemTitle>{{ t('platformBilling.actionMarkPaid') }}</VListItemTitle>
              </VListItem>

              <VListItem
                :disabled="!canVoid(item)"
                @click="openConfirm('void', item)"
              >
                <template #prepend>
                  <VIcon
                    icon="tabler-file-off"
                    size="20"
                  />
                </template>
                <VListItemTitle>{{ t('platformBilling.actionVoid') }}</VListItemTitle>
              </VListItem>

              <VListItem
                :disabled="!canEditNotes(item)"
                @click="openNotes(item)"
              >
                <template #prepend>
                  <VIcon
                    icon="tabler-notes"
                    size="20"
                  />
                </template>
                <VListItemTitle>{{ t('platformBilling.actionEditNotes') }}</VListItemTitle>
              </VListItem>

              <VDivider class="my-1" />

              <!-- Advanced actions (D4a) -->
              <VListItem
                :disabled="!canRefund(item)"
                @click="openRefund(item)"
              >
                <template #prepend>
                  <VIcon
                    icon="tabler-receipt-refund"
                    size="20"
                  />
                </template>
                <VListItemTitle>{{ t('platformBilling.advancedActions.refund') }}</VListItemTitle>
              </VListItem>

              <VListItem
                :disabled="!canRetryPayment(item)"
                @click="openConfirm('retry', item)"
              >
                <template #prepend>
                  <VIcon
                    icon="tabler-refresh"
                    size="20"
                  />
                </template>
                <VListItemTitle>{{ t('platformBilling.advancedActions.retryPayment') }}</VListItemTitle>
              </VListItem>

              <VListItem
                :disabled="!canForceDunning(item)"
                @click="openDunning(item)"
              >
                <template #prepend>
                  <VIcon
                    icon="tabler-arrow-right"
                    size="20"
                  />
                </template>
                <VListItemTitle>{{ t('platformBilling.advancedActions.forceDunning') }}</VListItemTitle>
              </VListItem>

              <VListItem
                :disabled="!canIssueCreditNote(item)"
                @click="openCreditNote(item)"
              >
                <template #prepend>
                  <VIcon
                    icon="tabler-credit-card-refund"
                    size="20"
                  />
                </template>
                <VListItemTitle>{{ t('platformBilling.advancedActions.creditNote') }}</VListItemTitle>
              </VListItem>

              <VListItem
                :disabled="!canWriteOff(item)"
                @click="openConfirm('writeOff', item)"
              >
                <template #prepend>
                  <VIcon
                    icon="tabler-file-x"
                    size="20"
                    color="error"
                  />
                </template>
                <VListItemTitle class="text-error">
                  {{ t('platformBilling.advancedActions.writeOff') }}
                </VListItemTitle>
              </VListItem>
            </VList>
          </VMenu>
        </template>

        <template #bottom>
          <VDivider />
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
            <span class="text-body-2 text-disabled">
              {{ t('platformBilling.invoiceCount', { count: store.allInvoicesPagination.total }) }}
            </span>
            <VPagination
              v-if="store.allInvoicesPagination.last_page > 1"
              :model-value="store.allInvoicesPagination.current_page"
              :length="store.allInvoicesPagination.last_page"
              :total-visible="5"
              @update:model-value="load"
            />
          </div>
        </template>
      </VDataTable>
    </VCardText>
  </VCard>

  <!-- ═══ Confirm Dialog (mark-paid / void / retry / writeoff) ═══ -->
  <VDialog
    v-model="confirmDialog"
    max-width="480"
  >
    <VCard>
      <VCardTitle class="text-h6 pa-6 pb-2">
        {{ confirmTitle }}
      </VCardTitle>
      <VCardText class="pa-6 pt-2">
        <p class="text-body-1 mb-0">
          {{ confirmBody }}
        </p>
      </VCardText>
      <VCardActions class="pa-6 pt-0">
        <VSpacer />
        <VBtn
          variant="tonal"
          color="secondary"
          @click="handleConfirm(false)"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          :color="confirmColor"
          @click="handleConfirm(true)"
        >
          {{ t('common.confirm') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ═══ Notes Dialog ═══ -->
  <VDialog
    v-model="notesDialog"
    max-width="500"
  >
    <VCard>
      <VCardTitle class="pa-6 pb-2">
        {{ t('platformBilling.notesDialogTitle') }}
      </VCardTitle>
      <VCardSubtitle class="px-6">
        {{ t('platformBilling.notesDialogSubtitle', { number: notesInvoice?.number || '—' }) }}
      </VCardSubtitle>
      <VCardText class="pa-6">
        <AppTextarea
          v-model="notesText"
          :placeholder="t('platformBilling.notesPlaceholder')"
          rows="4"
          counter="2000"
          maxlength="2000"
        />
      </VCardText>
      <VCardActions class="pa-6 pt-0">
        <VSpacer />
        <VBtn
          variant="tonal"
          color="secondary"
          @click="notesDialog = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="notesSaving"
          @click="saveNotes"
        >
          {{ t('common.save') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ═══ Refund Dialog (D4a) ═══ -->
  <VDialog
    v-model="refundDialog"
    max-width="500"
  >
    <VCard>
      <VCardTitle class="pa-6 pb-2">
        {{ t('platformBilling.dialogs.refund.title') }}
      </VCardTitle>
      <VCardSubtitle class="px-6">
        {{ t('platformBilling.dialogs.refund.subtitle', { number: refundInvoice?.number || '—' }) }}
      </VCardSubtitle>
      <VCardText class="pa-6">
        <VRow>
          <VCol cols="12">
            <AppTextField
              v-model.number="refundAmount"
              :label="t('platformBilling.dialogs.refund.amount')"
              type="number"
              :min="1"
              :rules="[v => v > 0 || t('platformBilling.dialogs.refund.amountRequired')]"
            />
          </VCol>
          <VCol cols="12">
            <AppTextarea
              v-model="refundReason"
              :label="t('platformBilling.dialogs.refund.reason')"
              rows="3"
              counter="500"
              maxlength="500"
              :rules="[v => !!v?.trim() || t('platformBilling.dialogs.refund.reasonRequired')]"
            />
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions class="pa-6 pt-0">
        <VSpacer />
        <VBtn
          variant="tonal"
          color="secondary"
          @click="refundDialog = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="warning"
          :loading="refundSaving"
          :disabled="!refundValid"
          @click="submitRefund"
        >
          {{ t('platformBilling.dialogs.refund.confirm') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ═══ Dunning Transition Dialog (D4a) ═══ -->
  <VDialog
    v-model="dunningDialog"
    max-width="480"
  >
    <VCard>
      <VCardTitle class="pa-6 pb-2">
        {{ t('platformBilling.dialogs.dunning.title') }}
      </VCardTitle>
      <VCardText class="pa-6 pt-2">
        <p class="text-body-1 mb-4">
          {{ t('platformBilling.dialogs.dunning.body', { number: dunningInvoice?.number || '—' }) }}
        </p>
        <VChip
          color="info"
          class="me-2"
        >
          {{ statusLabel(dunningInvoice?.status) }}
        </VChip>
        <VIcon icon="tabler-arrow-right" />
        <VChip
          :color="dunningTarget(dunningInvoice) === 'uncollectible' ? 'error' : 'warning'"
          class="ms-2"
        >
          {{ statusLabel(dunningTarget(dunningInvoice)) }}
        </VChip>
      </VCardText>
      <VCardActions class="pa-6 pt-0">
        <VSpacer />
        <VBtn
          variant="tonal"
          color="secondary"
          @click="dunningDialog = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          :color="dunningTarget(dunningInvoice) === 'uncollectible' ? 'error' : 'warning'"
          :loading="dunningSaving"
          @click="submitDunning"
        >
          {{ t('platformBilling.dialogs.dunning.confirm') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ═══ Credit Note Dialog (D4a) ═══ -->
  <VDialog
    v-model="creditNoteDialog"
    max-width="500"
  >
    <VCard>
      <VCardTitle class="pa-6 pb-2">
        {{ t('platformBilling.dialogs.creditNote.title') }}
      </VCardTitle>
      <VCardSubtitle class="px-6">
        {{ t('platformBilling.dialogs.creditNote.subtitle', { number: creditNoteInvoice?.number || '—' }) }}
      </VCardSubtitle>
      <VCardText class="pa-6">
        <VRow>
          <VCol cols="12">
            <AppTextField
              v-model.number="creditNoteAmount"
              :label="t('platformBilling.dialogs.creditNote.amount')"
              type="number"
              :min="1"
              :rules="[v => v > 0 || t('platformBilling.dialogs.creditNote.amountRequired')]"
            />
          </VCol>
          <VCol cols="12">
            <AppTextarea
              v-model="creditNoteReason"
              :label="t('platformBilling.dialogs.creditNote.reason')"
              rows="3"
              counter="500"
              maxlength="500"
              :rules="[v => !!v?.trim() || t('platformBilling.dialogs.creditNote.reasonRequired')]"
            />
          </VCol>
          <VCol cols="12">
            <VSwitch
              v-model="creditNoteApplyWallet"
              :label="t('platformBilling.dialogs.creditNote.applyToWallet')"
              color="primary"
              density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions class="pa-6 pt-0">
        <VSpacer />
        <VBtn
          variant="tonal"
          color="secondary"
          @click="creditNoteDialog = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="creditNoteSaving"
          :disabled="!creditNoteValid"
          @click="submitCreditNote"
        >
          {{ t('platformBilling.dialogs.creditNote.confirm') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
