<script setup>
definePage({ meta: { surface: 'structure', module: 'core.modules' } })

import { useAuthStore } from '@/core/stores/auth'
import { useModuleStore } from '@/core/stores/module'

const { t } = useI18n()
const auth = useAuthStore()
const moduleStore = useModuleStore()

const isLoading = ref(true)
const togglingKey = ref(null)
const errorMessage = ref('')

// Quote dialog state
const quoteDialog = ref(false)
const quoteLoading = ref(false)
const quoteData = ref(null)
const quoteModuleKey = ref(null)
const quoteModuleName = ref('')

const canManage = computed(() => auth.roleLevel === 'management')

onMounted(async () => {
  try {
    await moduleStore.fetchModules()
  }
  finally {
    isLoading.value = false
  }
})

const formatAmount = cents => {
  return (cents / 100).toFixed(2)
}

const toggleModule = async module => {
  errorMessage.value = ''

  // Enabling an addon module → fetch quote first
  if (!module.is_enabled_for_company && module.pricing_mode === 'addon') {
    quoteModuleKey.value = module.key
    quoteModuleName.value = module.name
    quoteLoading.value = true
    quoteDialog.value = true
    quoteData.value = null

    try {
      quoteData.value = await moduleStore.fetchQuote([module.key])
    }
    catch (error) {
      quoteDialog.value = false
      errorMessage.value = error?.data?.message || t('companyModules.failedToToggle')
    }
    finally {
      quoteLoading.value = false
    }

    return
  }

  // Direct toggle (disable or enable non-addon)
  await doToggle(module.key, module.is_enabled_for_company)
}

const confirmQuoteEnable = async () => {
  quoteDialog.value = false
  if (quoteModuleKey.value) {
    await doToggle(quoteModuleKey.value, false)
  }
}

const doToggle = async (key, isCurrentlyEnabled) => {
  togglingKey.value = key
  errorMessage.value = ''

  try {
    if (isCurrentlyEnabled) {
      await moduleStore.disableModule(key)
    }
    else {
      await moduleStore.enableModule(key)
    }
  }
  catch (error) {
    errorMessage.value = error?.data?.message || t('companyModules.failedToToggle')
  }
  finally {
    togglingKey.value = null
  }
}

const isToggleDisabled = module => {
  if (!canManage.value)
    return true
  if (togglingKey.value === module.key)
    return true
  if (!module.is_enabled_globally)
    return true
  if (module.type === 'core')
    return true
  if (!module.is_entitled)
    return true

  return false
}

const entitlementChip = module => {
  if (module.type === 'core')
    return { text: t('companyModules.coreChip'), color: 'primary' }
  if (!module.is_entitled) {
    if (module.entitlement_reason === 'plan_required')
      return { text: t('companyModules.requiresPlan', { plan: module.min_plan }), color: 'warning' }
    if (module.entitlement_reason === 'incompatible_jobdomain')
      return { text: t('companyModules.notAvailable'), color: 'error' }

    return { text: t('companyModules.notAvailable'), color: 'secondary' }
  }
  if (module.entitlement_source === 'jobdomain')
    return { text: t('companyModules.includedChip'), color: 'success' }

  return null
}

const headers = computed(() => [
  { title: t('companyModules.module'), key: 'name' },
  { title: t('common.description'), key: 'description', sortable: false },
  { title: '', key: 'entitlement', align: 'center', width: '140px', sortable: false },
  { title: t('common.status'), key: 'status', align: 'center', width: '100px', sortable: false },
  { title: '', key: 'actions', align: 'center', width: '120px', sortable: false },
])
</script>

<template>
  <div>
    <VAlert
      v-if="errorMessage"
      type="error"
      class="mb-4"
      closable
      @click:close="errorMessage = ''"
    >
      {{ errorMessage }}
    </VAlert>

    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-puzzle"
          class="me-2"
        />
        {{ t('companyModules.title') }}
      </VCardTitle>
      <VCardSubtitle>
        {{ t('companyModules.subtitle') }}
      </VCardSubtitle>

      <VDataTable
        :headers="headers"
        :items="moduleStore.modules"
        :loading="isLoading"
        item-value="key"
        :items-per-page="-1"
        hide-default-footer
      >
        <!-- Module name with icon -->
        <template #item.name="{ item }">
          <div class="d-flex align-center gap-x-3 py-2">
            <VAvatar
              size="32"
              :color="item.type === 'core' ? 'primary' : 'info'"
              variant="tonal"
            >
              <VIcon
                :icon="item.icon_name || 'tabler-puzzle'"
                size="18"
              />
            </VAvatar>
            <div>
              <span
                class="text-body-1 font-weight-medium"
                :class="item.is_entitled ? 'text-high-emphasis' : 'text-disabled'"
              >
                {{ item.name }}
              </span>
              <VChip
                v-if="!item.is_enabled_globally"
                size="x-small"
                color="warning"
                class="ms-2"
              >
                {{ t('companyModules.unavailable') }}
              </VChip>
            </div>
          </div>
        </template>

        <!-- Description -->
        <template #item.description="{ item }">
          <span
            class="text-body-2"
            :class="{ 'text-disabled': !item.is_entitled }"
          >
            {{ item.description || '—' }}
          </span>
        </template>

        <!-- Entitlement badge -->
        <template #item.entitlement="{ item }">
          <VChip
            v-if="entitlementChip(item)"
            :color="entitlementChip(item).color"
            size="small"
            variant="tonal"
          >
            {{ entitlementChip(item).text }}
          </VChip>
        </template>

        <!-- Status toggle -->
        <template #item.status="{ item }">
          <VSwitch
            :model-value="item.type === 'core' ? true : item.is_enabled_for_company"
            :disabled="isToggleDisabled(item)"
            :loading="togglingKey === item.key"
            color="primary"
            hide-details
            @update:model-value="toggleModule(item)"
          />
        </template>

        <!-- Configure action -->
        <template #item.actions="{ item }">
          <VBtn
            v-if="item.is_active"
            size="small"
            variant="tonal"
            :to="{ name: 'company-modules-key', params: { key: item.key } }"
          >
            {{ t('companyModules.configure') }}
          </VBtn>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            {{ t('companyModules.noModulesAvailable') }}
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Quote confirmation dialog -->
    <VDialog
      v-model="quoteDialog"
      max-width="480"
      persistent
    >
      <VCard>
        <VCardTitle class="d-flex align-center pt-5 px-6">
          <VIcon
            icon="tabler-receipt"
            class="me-2"
          />
          {{ t('companyModules.quoteTitle', { module: quoteModuleName }) }}
        </VCardTitle>

        <VCardText class="px-6">
          <div
            v-if="quoteLoading"
            class="text-center py-4"
          >
            <VProgressCircular indeterminate />
          </div>

          <template v-else-if="quoteData">
            <!-- Billable lines -->
            <div
              v-for="line in quoteData.lines"
              :key="line.key"
              class="d-flex justify-space-between align-center py-1"
            >
              <span class="text-body-1">{{ line.title }}</span>
              <span class="text-body-1 font-weight-medium">
                {{ formatAmount(line.amount) }} {{ quoteData.currency }}/{{ t('companyModules.quoteMonth') }}
              </span>
            </div>

            <VDivider class="my-3" />

            <!-- Total -->
            <div class="d-flex justify-space-between align-center">
              <span class="text-body-1 font-weight-bold">{{ t('common.total') }}</span>
              <span class="text-body-1 font-weight-bold">
                {{ formatAmount(quoteData.total) }} {{ quoteData.currency }}/{{ t('companyModules.quoteMonth') }}
              </span>
            </div>

            <!-- Included modules -->
            <div
              v-if="quoteData.included.length"
              class="mt-4"
            >
              <span class="text-body-2 text-medium-emphasis">
                {{ t('companyModules.quoteIncludes') }}
              </span>
              <div class="d-flex flex-wrap gap-1 mt-1">
                <VChip
                  v-for="inc in quoteData.included"
                  :key="inc.key"
                  size="small"
                  variant="tonal"
                  color="info"
                >
                  {{ inc.title }}
                </VChip>
              </div>
            </div>
          </template>
        </VCardText>

        <VCardActions class="px-6 pb-5">
          <VSpacer />
          <VBtn
            color="secondary"
            variant="tonal"
            @click="quoteDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            variant="elevated"
            :disabled="quoteLoading || !quoteData"
            @click="confirmQuoteEnable"
          >
            {{ t('companyModules.quoteConfirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
