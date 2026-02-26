<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const store = usePlatformPaymentsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const ruleDialog = ref(false)
const editingRule = ref(null)
const deleteDialog = ref(false)
const deletingId = ref(null)
const actionLoading = ref(false)

const ruleForm = ref({
  method_key: '',
  provider_key: '',
  market_key: null,
  plan_key: null,
  interval: null,
  priority: 0,
  is_active: true,
})

// Preview state
const previewDialog = ref(false)
const previewForm = ref({ market_key: null, plan_key: null, interval: null })

onMounted(async () => {
  try {
    await Promise.all([
      store.fetchPaymentRules(),
      store.fetchPaymentModules(),
    ])
  }
  finally {
    isLoading.value = false
  }
})

const headers = computed(() => [
  { title: t('payments.methodKey'), key: 'method_key' },
  { title: t('payments.providerKey'), key: 'provider_key' },
  { title: t('payments.marketKey'), key: 'market_key' },
  { title: t('payments.planKey'), key: 'plan_key' },
  { title: t('payments.interval'), key: 'interval' },
  { title: t('payments.priority'), key: 'priority', width: '100px' },
  { title: t('common.status'), key: 'is_active', align: 'center', width: '100px' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '120px', sortable: false },
])

const providerOptions = computed(() =>
  store.paymentModules.map(m => ({
    title: m.name,
    value: m.provider_key,
  })),
)

const intervalOptions = [
  { title: 'Monthly', value: 'monthly' },
  { title: 'Yearly', value: 'yearly' },
]

const openCreateDialog = () => {
  editingRule.value = null
  ruleForm.value = {
    method_key: '',
    provider_key: '',
    market_key: null,
    plan_key: null,
    interval: null,
    priority: 0,
    is_active: true,
  }
  ruleDialog.value = true
}

const openEditDialog = rule => {
  editingRule.value = rule
  ruleForm.value = { ...rule }
  ruleDialog.value = true
}

const saveRule = async () => {
  actionLoading.value = true

  try {
    if (editingRule.value) {
      await store.updatePaymentRule(editingRule.value.id, ruleForm.value)
      toast(t('payments.ruleUpdated'), 'success')
    }
    else {
      await store.createPaymentRule(ruleForm.value)
      toast(t('payments.ruleCreated'), 'success')
    }
    ruleDialog.value = false
  }
  catch (error) {
    const msg = editingRule.value ? t('payments.failedToUpdateRule') : t('payments.failedToCreateRule')

    toast(error?.data?.message || msg, 'error')
  }
  finally {
    actionLoading.value = false
  }
}

const confirmDelete = id => {
  deletingId.value = id
  deleteDialog.value = true
}

const deleteRule = async () => {
  actionLoading.value = true

  try {
    await store.deletePaymentRule(deletingId.value)
    toast(t('payments.ruleDeleted'), 'success')
    deleteDialog.value = false
  }
  catch (error) {
    toast(error?.data?.message || t('payments.failedToDeleteRule'), 'error')
  }
  finally {
    actionLoading.value = false
  }
}

const openPreview = () => {
  previewForm.value = { market_key: null, plan_key: null, interval: null }
  previewDialog.value = true
}

const runPreview = async () => {
  actionLoading.value = true

  try {
    await store.previewPaymentMethods(previewForm.value)
  }
  finally {
    actionLoading.value = false
  }
}
</script>

<template>
  <VCard>
    <VCardTitle class="d-flex align-center">
      <VIcon
        icon="tabler-list-check"
        class="me-2"
      />
      {{ t('payments.tabs.rules') }}
      <VSpacer />
      <VBtn
        size="small"
        class="me-2"
        variant="tonal"
        @click="openPreview"
      >
        {{ t('payments.previewMethods') }}
      </VBtn>
      <VBtn
        size="small"
        @click="openCreateDialog"
      >
        {{ t('payments.addRule') }}
      </VBtn>
    </VCardTitle>

    <VDataTable
      :headers="headers"
      :items="store.paymentRules"
      :loading="isLoading"
      :items-per-page="-1"
      hide-default-footer
      hover
    >
      <template #item.market_key="{ item }">
        {{ item.market_key || t('payments.allMarkets') }}
      </template>

      <template #item.plan_key="{ item }">
        {{ item.plan_key || t('payments.allPlans') }}
      </template>

      <template #item.interval="{ item }">
        {{ item.interval || t('payments.allIntervals') }}
      </template>

      <template #item.is_active="{ item }">
        <VChip
          :color="item.is_active ? 'success' : 'secondary'"
          size="small"
        >
          {{ item.is_active ? t('payments.statusActive') : t('common.disabled') }}
        </VChip>
      </template>

      <template #item.actions="{ item }">
        <div class="d-flex gap-1 justify-center">
          <VBtn
            icon
            size="small"
            variant="text"
            @click="openEditDialog(item)"
          >
            <VIcon icon="tabler-pencil" />
          </VBtn>
          <VBtn
            icon
            size="small"
            variant="text"
            color="error"
            @click="confirmDelete(item.id)"
          >
            <VIcon icon="tabler-trash" />
          </VBtn>
        </div>
      </template>

      <template #no-data>
        <div class="text-center pa-6 text-disabled">
          {{ t('payments.noRules') }}
        </div>
      </template>
    </VDataTable>
  </VCard>

  <!-- Create/Edit Rule Dialog -->
  <VDialog
    v-model="ruleDialog"
    max-width="500"
  >
    <VCard>
      <VCardTitle class="pt-5 px-6">
        {{ editingRule ? t('payments.editRule') : t('payments.addRule') }}
      </VCardTitle>

      <VCardText class="px-6">
        <VRow>
          <VCol cols="12">
            <AppTextField
              v-model="ruleForm.method_key"
              :label="t('payments.methodKey')"
              placeholder="card, sepa_debit, manual..."
            />
          </VCol>
          <VCol cols="12">
            <VSelect
              v-model="ruleForm.provider_key"
              :items="providerOptions"
              :label="t('payments.providerKey')"
            />
          </VCol>
          <VCol cols="6">
            <AppTextField
              v-model="ruleForm.market_key"
              :label="t('payments.marketKey')"
              :placeholder="t('payments.allMarkets')"
            />
          </VCol>
          <VCol cols="6">
            <AppTextField
              v-model="ruleForm.plan_key"
              :label="t('payments.planKey')"
              :placeholder="t('payments.allPlans')"
            />
          </VCol>
          <VCol cols="6">
            <VSelect
              v-model="ruleForm.interval"
              :items="intervalOptions"
              :label="t('payments.interval')"
              clearable
            />
          </VCol>
          <VCol cols="6">
            <AppTextField
              v-model.number="ruleForm.priority"
              :label="t('payments.priority')"
              type="number"
              min="0"
            />
          </VCol>
          <VCol cols="12">
            <VSwitch
              v-model="ruleForm.is_active"
              :label="t('common.active')"
            />
          </VCol>
        </VRow>
      </VCardText>

      <VCardActions class="px-6 pb-5">
        <VSpacer />
        <VBtn
          color="secondary"
          variant="tonal"
          @click="ruleDialog = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="actionLoading"
          @click="saveRule"
        >
          {{ t('common.save') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- Delete Confirmation -->
  <VDialog
    v-model="deleteDialog"
    max-width="400"
  >
    <VCard>
      <VCardTitle class="pt-5 px-6">
        {{ t('payments.deleteRule') }}
      </VCardTitle>
      <VCardText class="px-6">
        {{ t('payments.confirmDeleteRule') }}
      </VCardText>
      <VCardActions class="px-6 pb-5">
        <VSpacer />
        <VBtn
          color="secondary"
          variant="tonal"
          @click="deleteDialog = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="error"
          :loading="actionLoading"
          @click="deleteRule"
        >
          {{ t('common.delete') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- Preview Dialog -->
  <VDialog
    v-model="previewDialog"
    max-width="600"
  >
    <VCard>
      <VCardTitle class="pt-5 px-6">
        {{ t('payments.previewTitle') }}
      </VCardTitle>

      <VCardText class="px-6">
        <VRow class="mb-4">
          <VCol cols="4">
            <AppTextField
              v-model="previewForm.market_key"
              :label="t('payments.marketKey')"
              :placeholder="t('payments.allMarkets')"
            />
          </VCol>
          <VCol cols="4">
            <AppTextField
              v-model="previewForm.plan_key"
              :label="t('payments.planKey')"
              :placeholder="t('payments.allPlans')"
            />
          </VCol>
          <VCol cols="4">
            <VSelect
              v-model="previewForm.interval"
              :items="intervalOptions"
              :label="t('payments.interval')"
              clearable
            />
          </VCol>
        </VRow>

        <VBtn
          class="mb-4"
          :loading="actionLoading"
          @click="runPreview"
        >
          {{ t('payments.previewMethods') }}
        </VBtn>

        <VTable
          v-if="store.previewMethods.length"
          density="compact"
        >
          <thead>
            <tr>
              <th>{{ t('payments.methodKey') }}</th>
              <th>{{ t('payments.providerKey') }}</th>
              <th>{{ t('payments.priority') }}</th>
              <th>{{ t('payments.specificity') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="method in store.previewMethods"
              :key="method.method_key"
            >
              <td>{{ method.method_key }}</td>
              <td>{{ method.provider_key }}</td>
              <td>{{ method.priority }}</td>
              <td>{{ method.specificity }}</td>
            </tr>
          </tbody>
        </VTable>

        <div
          v-else-if="!actionLoading"
          class="text-center text-disabled pa-4"
        >
          {{ t('payments.noPreviewResults') }}
        </div>
      </VCardText>

      <VCardActions class="px-6 pb-5">
        <VSpacer />
        <VBtn
          color="secondary"
          variant="tonal"
          @click="previewDialog = false"
        >
          {{ t('common.close') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
