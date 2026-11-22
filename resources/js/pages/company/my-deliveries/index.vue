<script setup>
import AppPageHeader from '@/components/app/AppPageHeader.vue'
import { formatDate } from '@/utils/datetime'
import { useAuthStore } from '@/core/stores/auth'
import { useDeliveryStore } from '@/modules/logistics-shipments/stores/delivery.store'

definePage({ meta: { module: 'logistics_shipments', surface: 'operations', permission: 'shipments.view_own' } })

const { t } = useI18n()
const deliveryStore = useDeliveryStore()
const auth = useAuthStore()
const isLoading = ref(true)
const loadError = ref(false)
const statusFilter = ref('todo')
const busy = ref(null)
const message = ref(null)

// ADR-822 — un livreur travaille depuis son téléphone : filtres en puces, une carte par livraison,
// l'étape suivante directement sur la carte (même contrôle serveur : affectée à moi + manage_status).
const FILTERS = ['todo', 'in_transit', 'delivered', 'all']
const FILTER_STATUS = { todo: 'planned', in_transit: 'in_transit', delivered: 'delivered', all: undefined }
const NEXT = { planned: { status: 'in_transit', icon: 'tabler-truck', color: 'warning', key: 'deliveries.startTransit' }, in_transit: { status: 'delivered', icon: 'tabler-check', color: 'success', key: 'deliveries.markDelivered' } }
const canAct = computed(() => auth.hasPermission('shipments.manage_status'))
const STATUS_COLOR = { draft: 'secondary', planned: 'info', in_transit: 'warning', delivered: 'success', canceled: 'error' }
const STATUS_KEY = { draft: 'statusDraft', planned: 'statusPlanned', in_transit: 'statusInTransit', delivered: 'statusDelivered', canceled: 'statusCanceled' }
const statusLabel = st => STATUS_KEY[st] ? t(`deliveries.${STATUS_KEY[st]}`) : st

const loadDeliveries = async (page = 1) => {
  isLoading.value = true
  loadError.value = false
  try {
    await deliveryStore.fetchDeliveries({ page, status: FILTER_STATUS[statusFilter.value] })
  }
  catch { loadError.value = true }
  finally { isLoading.value = false }
}

const advance = async d => {
  const next = NEXT[d.status]
  if (!next)
    return
  busy.value = d.id
  message.value = null
  try {
    await deliveryStore.updateStatus(d.id, next.status)
    message.value = { type: 'success', text: t('deliveries.statusChangedTo', { status: statusLabel(next.status) }) }

    // ADR-843 (M6) — follow the delivery : once started it moves to « En cours », where its next step
    // (« Livrée ») is one tap away, instead of vanishing from « À faire » (the driver had to change filter).
    const follow = next.status === 'in_transit' && statusFilter.value === 'todo' ? 'in_transit' : null
    if (follow)
      statusFilter.value = follow // the watcher reloads
    else
      await loadDeliveries(deliveryStore.pagination.current_page)
  }
  catch (e) { message.value = { type: 'error', text: e?.data?.message || t('deliveries.failedToChangeStatus') } }
  finally { busy.value = null }
}

onMounted(() => loadDeliveries())
watch(statusFilter, () => loadDeliveries(1))
</script>

<template>
  <div>
    <AppPageHeader
      :eyebrow="t('l7.family.logistics')"
      :title="t('deliveries.title')"
      :description="t('deliveries.subtitle')"
    />

    <VChipGroup
      v-model="statusFilter"
      mandatory
      selected-class="text-primary"
      class="mb-4"
      data-test="deliveries-filter"
    >
      <VChip
        v-for="f in FILTERS"
        :key="f"
        :value="f"
        label
        variant="tonal"
        :data-test="`deliveries-filter-${f}`"
      >
        {{ t(`deliveries.filter.${f}`) }}
      </VChip>
    </VChipGroup>

    <VAlert
      v-if="message"
      :type="message.type"
      variant="tonal"
      closable
      class="mb-4"
      @click:close="message = null"
    >
      {{ message.text }}
    </VAlert>

    <VProgressLinear
      v-if="isLoading"
      indeterminate
      class="mb-4"
    />

    <VAlert
      v-else-if="loadError"
      type="error"
      variant="tonal"
    >
      {{ t('deliveries.loadError') }}
      <template #append>
        <VBtn
          variant="text"
          @click="loadDeliveries()"
        >
          {{ t('common.retry') }}
        </VBtn>
      </template>
    </VAlert>

    <VCard
      v-else-if="!deliveryStore.deliveries.length"
      class="text-center pa-8"
      data-test="deliveries-empty"
    >
      <VAvatar
        size="56"
        color="primary"
        variant="tonal"
        class="mb-3"
      >
        <VIcon
          icon="tabler-truck-delivery"
          size="28"
        />
      </VAvatar>
      <div class="text-h6 mb-1">
        {{ t(`deliveries.empty.${statusFilter}`) }}
      </div>
      <div class="text-body-2 text-medium-emphasis">
        {{ t('deliveries.emptyHint') }}
      </div>
    </VCard>

    <VRow v-else>
      <VCol
        v-for="d in deliveryStore.deliveries"
        :key="d.id"
        cols="12"
        md="6"
        lg="4"
      >
        <VCard
          class="h-100 d-flex flex-column"
          :data-test="`delivery-card-${d.id}`"
        >
          <VCardItem>
            <VCardTitle class="d-flex align-center gap-2">
              <RouterLink
                :to="{ name: 'company-my-deliveries-id', params: { id: d.id } }"
                class="text-truncate text-high-emphasis"
              >
                {{ d.reference }}
              </RouterLink>
              <VSpacer />
              <VChip
                :color="STATUS_COLOR[d.status]"
                size="small"
                label
              >
                {{ statusLabel(d.status) }}
              </VChip>
            </VCardTitle>
            <VCardSubtitle>
              <VIcon
                icon="tabler-calendar"
                size="14"
                class="me-1"
              />{{ d.scheduled_at ? formatDate(d.scheduled_at) : t('shipments.notScheduled') }}
            </VCardSubtitle>
          </VCardItem>
          <VCardText class="flex-grow-1">
            <div class="d-flex gap-2 align-start">
              <VIcon
                icon="tabler-map-pin"
                size="18"
                color="success"
              />
              <span class="text-body-1">{{ d.destination_address || t('shipments.detail.notProvided') }}</span>
            </div>
          </VCardText>
          <VCardActions class="px-4 pb-4">
            <VBtn
              variant="text"
              :to="{ name: 'company-my-deliveries-id', params: { id: d.id } }"
            >
              {{ t('deliveries.open') }}
            </VBtn>
            <VSpacer />
            <VBtn
              v-if="canAct && NEXT[d.status]"
              :color="NEXT[d.status].color"
              variant="elevated"
              :prepend-icon="NEXT[d.status].icon"
              :loading="busy === d.id"
              :data-test="`delivery-advance-${d.id}`"
              @click="advance(d)"
            >
              {{ t(NEXT[d.status].key) }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <div
      v-if="deliveryStore.pagination.last_page > 1"
      class="d-flex justify-center mt-6"
    >
      <VPagination
        :model-value="deliveryStore.pagination.current_page"
        :length="deliveryStore.pagination.last_page"
        :total-visible="5"
        @update:model-value="loadDeliveries"
      />
    </div>
  </div>
</template>
