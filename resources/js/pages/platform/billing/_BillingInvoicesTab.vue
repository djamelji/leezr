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
  const colors = { draft: 'secondary', open: 'info', overdue: 'error', paid: 'success', voided: 'warning' }

  return colors[status] || 'secondary'
}

const statusLabel = status => {
  const map = { draft: 'statusDraft', open: 'statusOpen', overdue: 'statusOverdue', paid: 'statusPaid', voided: 'statusVoided' }

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

// ── Confirm dialog state ───────────────────────────────
const confirmDialog = ref(false)
const confirmAction = ref(null) // 'markPaid' | 'void'
const confirmInvoice = ref(null)

const confirmTitle = computed(() => {
  if (confirmAction.value === 'markPaid')
    return t('platformBilling.confirmMarkPaidTitle')

  return t('platformBilling.confirmVoidTitle')
})

const confirmBody = computed(() => {
  const number = confirmInvoice.value?.number || '—'

  if (confirmAction.value === 'markPaid')
    return t('platformBilling.confirmMarkPaidBody', { number })

  return t('platformBilling.confirmVoidBody', { number })
})

const openConfirm = (action, invoice) => {
  confirmAction.value = action
  confirmInvoice.value = invoice
  confirmDialog.value = true
}

const handleError = error => {
  const status = error?.status || error?.statusCode
  if (status === 401 || status === 403)
    toast(t('platformBilling.errorNotAuthorized'), 'error')
  else if (status === 409)
    toast(error?.data?.message || t('platformBilling.errorConflict'), 'error')
  else
    toast(error?.data?.message || t('platformBilling.errorGeneric'), 'error')
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
    else {
      await store.voidInvoice(invoice.id, generateKey('void'))
      toast(t('platformBilling.voidSuccess'), 'success')
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
          <span class="font-weight-medium">
            {{ item.company?.name || '—' }}
          </span>
        </template>

        <template #item.number="{ item }">
          {{ item.number }}
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

  <!-- ═══ Confirm Dialog (mark-paid / void) ═══ -->
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
          :color="confirmAction === 'void' ? 'error' : 'primary'"
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
</template>
