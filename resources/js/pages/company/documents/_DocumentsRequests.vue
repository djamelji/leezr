<script setup>
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useMembersStore } from '@/modules/company/members/members.store'
import { useAuthStore } from '@/core/stores/auth'
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'

const { t } = useI18n()
const store = useCompanyDocumentsStore()
const settingsStore = useCompanySettingsStore()
const membersStore = useMembersStore()
const auth = useAuthStore()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()

const canManage = computed(() => auth.hasPermission('documents.manage'))

// ─── Filters ────────────────────────────────────────────
const searchQuery = ref('')
const filterStatus = ref(null)
const filterDocType = ref(null)

const statusFilterItems = computed(() => [
  { title: t('companyDocuments.requests.status_requested'), value: 'requested' },
  { title: t('companyDocuments.requests.status_submitted'), value: 'submitted' },
])

const docTypeFilterItems = computed(() => {
  const types = new Map()

  store.requests.forEach(r => {
    if (r.document_type?.code && !types.has(r.document_type.code)) {
      types.set(r.document_type.code, {
        title: t(`documents.type.${r.document_type.code}`, r.document_type.label),
        value: r.document_type.code,
      })
    }
  })

  return [...types.values()]
})

const filteredRequests = computed(() => {
  let items = store.requests

  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase()

    items = items.filter(r => {
      const name = `${r.user?.first_name || ''} ${r.user?.last_name || ''}`.toLowerCase()
      const email = (r.user?.email || '').toLowerCase()

      return name.includes(q) || email.includes(q)
    })
  }

  if (filterStatus.value) {
    items = items.filter(r => r.status === filterStatus.value)
  }

  if (filterDocType.value) {
    items = items.filter(r => r.document_type?.code === filterDocType.value)
  }

  return items
})

// ─── Request queue table ────────────────────────────────
const headers = computed(() => [
  { title: t('companyDocuments.requests.member'), key: 'user', sortable: false },
  { title: t('companyDocuments.requests.role'), key: 'role', sortable: false },
  { title: t('companyDocuments.requests.documentType'), key: 'document_type', sortable: false },
  { title: t('companyDocuments.requests.status'), key: 'status', sortable: false },
  { title: t('companyDocuments.requests.requestedAt'), key: 'requested_at', sortable: false },
  { title: t('companyDocuments.requests.actions'), key: 'actions', sortable: false },
])

const statusColor = status => {
  switch (status) {
    case 'requested': return 'info'
    case 'submitted': return 'warning'
    case 'approved': return 'success'
    case 'rejected': return 'error'
    case 'cancelled': return 'secondary'
    default: return 'secondary'
  }
}

const statusLabel = status => {
  return t(`companyDocuments.requests.status_${status}`, status)
}

// ─── Review actions (S1.2) ──────────────────────────────
const handleApprove = async request => {
  const ok = await confirm({
    question: t('companyDocuments.requests.confirmApprove'),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('companyDocuments.requests.approvedSuccess'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })

  if (!ok) return
  await handleReview(request, 'approved')
}

const isRejectDialogVisible = ref(false)
const rejectRequest = ref(null)
const rejectNote = ref('')

const openRejectDialog = request => {
  rejectRequest.value = request
  rejectNote.value = ''
  isRejectDialogVisible.value = true
}

const submitReject = async () => {
  if (!rejectRequest.value) return
  await handleReview(rejectRequest.value, 'rejected', rejectNote.value || null)
  isRejectDialogVisible.value = false
  rejectRequest.value = null
  rejectNote.value = ''
}

const handleReview = async (request, status, note = null) => {
  try {
    const membershipId = request.user?.membership_id

    if (!membershipId) {
      toast(t('companyDocuments.requests.noMembership'), 'error')

      return
    }

    await $api(`/company/members/${membershipId}/documents/${request.document_type.code}/review`, {
      method: 'PUT',
      body: { status, review_note: note },
    })

    toast(t(`companyDocuments.requests.${status}Success`), 'success')
    await store.fetchRequests()
  }
  catch (error) {
    toast(error.message || t('companyDocuments.requests.reviewFailed'), 'error')
  }
}

// ─── Cancel request (S1.3) ──────────────────────────────
const handleCancel = async request => {
  const ok = await confirm({
    question: t('companyDocuments.requests.cancelConfirm'),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('companyDocuments.requests.cancelSuccess'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })

  if (!ok) return

  try {
    await store.cancelRequest(request.id)
    toast(t('companyDocuments.requests.cancelSuccess'), 'success')
  }
  catch (error) {
    toast(error.message || t('common.error'), 'error')
  }
}

// ─── Remind request (S1.4) ──────────────────────────────
const handleRemind = async request => {
  try {
    await store.remindRequest(request.id)
    toast(t('companyDocuments.requests.remindSuccess'), 'success')
  }
  catch (error) {
    toast(error.message || t('common.error'), 'error')
  }
}

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

// ─── Batch request dialog ───────────────────────────────
const isRequestDialogVisible = ref(false)
const isRequestLoading = ref(false)

const requestForm = ref({
  scope: 'all',
  company_role_ids: [],
  user_ids: [],
  document_type_code: null,
})

const roleItems = computed(() => {
  return settingsStore.roles.map(r => ({ title: r.name, value: r.id }))
})

const memberItems = computed(() => {
  return membersStore.members.map(m => ({
    title: `${m.user.first_name} ${m.user.last_name}`,
    value: m.user.id,
    subtitle: m.company_role?.name || '',
  }))
})

const docTypeItems = computed(() => {
  return (store.documentActivations.company_user_documents || [])
    .filter(d => d.enabled)
    .map(d => ({ title: t(`documents.type.${d.code}`, d.label), value: d.code }))
})

const canSubmit = computed(() => {
  if (!requestForm.value.document_type_code) return false
  if (requestForm.value.scope === 'role' && requestForm.value.company_role_ids.length === 0) return false
  if (requestForm.value.scope === 'member' && requestForm.value.user_ids.length === 0) return false

  return true
})

watch(() => requestForm.value.scope, () => {
  requestForm.value.company_role_ids = []
  requestForm.value.user_ids = []
})

const openRequestDialog = async () => {
  requestForm.value = { scope: 'all', company_role_ids: [], user_ids: [], document_type_code: null }
  if (settingsStore.roles.length === 0) await settingsStore.fetchCompanyRoles({ silent: true })
  if (membersStore.members.length === 0) await membersStore.fetchMembers()
  isRequestDialogVisible.value = true
}

const submitRequest = async () => {
  isRequestLoading.value = true
  try {
    const result = await $api('/company/document-requests/batch', {
      method: 'POST',
      body: requestForm.value,
    })

    toast(t('companyDocuments.requests.batchSuccess', { created: result.created, skipped: result.skipped }), 'success')
    isRequestDialogVisible.value = false
    await store.fetchRequests()
  }
  catch (error) {
    toast(error.message || t('companyDocuments.requests.batchFailed'), 'error')
  }
  finally {
    isRequestLoading.value = false
  }
}
</script>

<template>
  <VCard>
    <VCardItem>
      <template #prepend>
        <VAvatar
          color="warning"
          variant="tonal"
          rounded
        >
          <VIcon icon="tabler-file-text" />
        </VAvatar>
      </template>
      <VCardTitle>{{ t('companyDocuments.requests.title') }}</VCardTitle>
      <VCardSubtitle>{{ t('companyDocuments.requests.hint') }}</VCardSubtitle>

      <template
        v-if="canManage"
        #append
      >
        <VBtn
          variant="tonal"
          color="primary"
          prepend-icon="tabler-send"
          @click="openRequestDialog"
        >
          {{ t('companyDocuments.requests.batchRequest') }}
        </VBtn>
      </template>
    </VCardItem>

    <!-- Filters -->
    <VCardText>
      <VRow>
        <VCol
          cols="12"
          sm="4"
        >
          <AppTextField
            v-model="searchQuery"
            :placeholder="t('companyDocuments.requests.searchPlaceholder')"
            prepend-inner-icon="tabler-search"
            clearable
            density="compact"
          />
        </VCol>
        <VCol
          cols="6"
          sm="4"
        >
          <AppSelect
            v-model="filterStatus"
            :placeholder="t('companyDocuments.requests.filterStatus')"
            :items="statusFilterItems"
            clearable
            density="compact"
          />
        </VCol>
        <VCol
          cols="6"
          sm="4"
        >
          <AppSelect
            v-model="filterDocType"
            :placeholder="t('companyDocuments.requests.filterDocType')"
            :items="docTypeFilterItems"
            clearable
            density="compact"
          />
        </VCol>
      </VRow>
    </VCardText>

    <VDivider />

    <VDataTable
      :headers="headers"
      :items="filteredRequests"
      :items-per-page="10"
      :no-data-text="t('companyDocuments.requests.empty')"
      class="text-no-wrap"
    >
        <template #item.user="{ item }">
          <div class="d-flex align-center gap-2 py-2">
            <VAvatar
              size="32"
              color="primary"
              variant="tonal"
            >
              <span class="text-body-2">
                {{ item.user?.first_name?.[0] }}{{ item.user?.last_name?.[0] }}
              </span>
            </VAvatar>
            <div>
              <div class="text-body-1">
                {{ item.user?.first_name }} {{ item.user?.last_name }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ item.user?.email }}
              </div>
            </div>
          </div>
        </template>

        <template #item.role="{ item }">
          <VChip
            v-if="item.role"
            size="small"
            variant="tonal"
          >
            {{ item.role.name }}
          </VChip>
          <span
            v-else
            class="text-disabled"
          >—</span>
        </template>

        <template #item.document_type="{ item }">
          {{ t(`documents.type.${item.document_type?.code}`, item.document_type?.label) }}
        </template>

        <template #item.status="{ item }">
          <VChip
            size="small"
            :color="statusColor(item.status)"
          >
            {{ statusLabel(item.status) }}
          </VChip>
        </template>

        <template #item.requested_at="{ item }">
          {{ formatDate(item.requested_at) }}
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <!-- Approve (submitted only) -->
            <VBtn
              v-if="item.status === 'submitted' && canManage"
              icon
              variant="text"
              size="small"
              color="success"
              :title="t('documents.approve')"
              @click="handleApprove(item)"
            >
              <VIcon icon="tabler-check" />
            </VBtn>

            <!-- Reject (submitted only) -->
            <VBtn
              v-if="item.status === 'submitted' && canManage"
              icon
              variant="text"
              size="small"
              color="error"
              :title="t('documents.reject')"
              @click="openRejectDialog(item)"
            >
              <VIcon icon="tabler-x" />
            </VBtn>

            <!-- Remind (requested only) -->
            <VBtn
              v-if="item.status === 'requested' && canManage"
              icon
              variant="text"
              size="small"
              color="warning"
              :title="t('companyDocuments.requests.remind')"
              @click="handleRemind(item)"
            >
              <VIcon icon="tabler-bell-ringing" />
            </VBtn>

            <!-- Cancel (requested only) -->
            <VBtn
              v-if="item.status === 'requested' && canManage"
              icon
              variant="text"
              size="small"
              color="secondary"
              :title="t('companyDocuments.requests.cancelRequest')"
              @click="handleCancel(item)"
            >
              <VIcon icon="tabler-trash" />
            </VBtn>
          </div>
        </template>
    </VDataTable>
  </VCard>

  <!-- Reject Dialog (S1.2) -->
  <VDialog
    v-model="isRejectDialogVisible"
    max-width="500"
  >
    <VCard>
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="error"
            variant="tonal"
            rounded
          >
            <VIcon icon="tabler-x" />
          </VAvatar>
        </template>
        <VCardTitle>{{ t('companyDocuments.requests.rejectDialogTitle') }}</VCardTitle>
        <VCardSubtitle>
          {{ rejectRequest?.user?.first_name }} {{ rejectRequest?.user?.last_name }}
          — {{ t(`documents.type.${rejectRequest?.document_type?.code}`, rejectRequest?.document_type?.label) }}
        </VCardSubtitle>
      </VCardItem>
      <VCardText>
        <AppTextarea
          v-model="rejectNote"
          :label="t('companyDocuments.requests.rejectNoteLabel')"
          :placeholder="t('companyDocuments.requests.rejectNotePlaceholder')"
          rows="3"
        />
      </VCardText>
      <VCardActions class="justify-end">
        <VBtn
          variant="tonal"
          @click="isRejectDialogVisible = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="error"
          @click="submitReject"
        >
          {{ t('companyDocuments.requests.rejectConfirmBtn') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- Batch Request Dialog -->
  <VDialog
    v-model="isRequestDialogVisible"
    max-width="600"
  >
    <VCard>
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="primary"
            variant="tonal"
            rounded
          >
            <VIcon icon="tabler-send" />
          </VAvatar>
        </template>
        <VCardTitle>{{ t('companyDocuments.requests.batchRequest') }}</VCardTitle>
        <VCardSubtitle>{{ t('companyDocuments.requests.batchHint') }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VRow>
          <VCol cols="12">
            <VRadioGroup
              v-model="requestForm.scope"
              :label="t('companyDocuments.requests.scopeLabel')"
              inline
            >
              <VRadio
                :label="t('companyDocuments.requests.scopeAll')"
                value="all"
              />
              <VRadio
                :label="t('companyDocuments.requests.scopeRole')"
                value="role"
              />
              <VRadio
                :label="t('companyDocuments.requests.scopeMember')"
                value="member"
              />
            </VRadioGroup>
          </VCol>
          <VCol
            v-if="requestForm.scope === 'role'"
            cols="12"
          >
            <AppSelect
              v-model="requestForm.company_role_ids"
              :label="t('companyDocuments.requests.selectRoles')"
              :items="roleItems"
              multiple
              chips
              closable-chips
            />
          </VCol>
          <VCol
            v-if="requestForm.scope === 'member'"
            cols="12"
          >
            <AppAutocomplete
              v-model="requestForm.user_ids"
              :label="t('companyDocuments.requests.selectMembers')"
              :items="memberItems"
              multiple
              chips
              closable-chips
            />
          </VCol>
          <VCol cols="12">
            <AppSelect
              v-model="requestForm.document_type_code"
              :label="t('companyDocuments.requests.selectDocType')"
              :items="docTypeItems"
            />
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions class="justify-end">
        <VBtn
          variant="tonal"
          @click="isRequestDialogVisible = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="isRequestLoading"
          :disabled="!canSubmit"
          @click="submitRequest"
        >
          {{ t('companyDocuments.requests.sendBatch') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- Confirm dialog -->
  <ConfirmDialogComponent />
</template>
