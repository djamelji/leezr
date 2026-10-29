<script setup>
// W-SIGNATURE-1 (ADR-651) — RH → Signatures : tableau de bord, liste, détail, assistant de demande.
definePage({
  meta: { surface: 'hr', module: 'workforce_signature', permission: 'workforce_signature.view' },
})

import { useSignatureStore } from '@/modules/company/workforce/signature.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'
import SignatureWizard from './_SignatureWizard.vue'
import SignatureDetailDrawer from './_SignatureDetailDrawer.vue'

const { t } = useI18n()
const store = useSignatureStore()
const { toast } = useAppToast()
const { can } = useCan()

const STATUS_META = {
  draft: { color: 'secondary', icon: 'tabler-pencil' },
  pending: { color: 'warning', icon: 'tabler-clock' },
  partially_signed: { color: 'info', icon: 'tabler-progress' },
  completed: { color: 'success', icon: 'tabler-circle-check' },
  declined: { color: 'error', icon: 'tabler-x' },
  cancelled: { color: 'secondary', icon: 'tabler-ban' },
  expired: { color: 'secondary', icon: 'tabler-clock-off' },
  failed: { color: 'error', icon: 'tabler-alert-triangle' },
}
const statusColor = s => STATUS_META[s]?.color ?? 'secondary'
const statusIcon = s => STATUS_META[s]?.icon ?? 'tabler-point'
const statusLabel = s => t(`signature.status.${s}`)

const TABS = [
  { key: '', label: 'signature.tabs.all' },
  { key: 'pending', label: 'signature.tabs.pending' },
  { key: 'partially_signed', label: 'signature.tabs.inProgress' },
  { key: 'completed', label: 'signature.tabs.completed' },
  { key: 'declined', label: 'signature.tabs.declined' },
  { key: 'expired', label: 'signature.tabs.expired' },
]
const currentTab = ref('')
const search = ref('')

const headers = [
  { title: t('signature.columns.document'), key: 'document_label', sortable: false },
  { title: t('signature.columns.signers'), key: 'signers', sortable: false },
  { title: t('signature.columns.provider'), key: 'provider', sortable: false },
  { title: t('signature.columns.status'), key: 'status', sortable: false },
  { title: t('signature.columns.created'), key: 'created_at', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' },
]

const load = async () => {
  await store.fetchList({ status: currentTab.value || undefined, search: search.value || undefined })
}

watch(currentTab, load)
watchDebounced(search, load, { debounce: 350 })
onMounted(load)

const fmtDate = iso => (iso ? new Date(iso).toLocaleDateString() : '—')

// Détail
const detailId = ref(null)
const detailOpen = ref(false)
const openDetail = id => { detailId.value = id; detailOpen.value = true }

// Assistant
const wizardOpen = ref(false)
const onCreated = () => { wizardOpen.value = false; toast(t('signature.toasts.created'), 'success'); load() }
</script>

<template>
  <div>
    <div class="d-flex flex-wrap justify-space-between align-center gap-4 mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ t('signature.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ t('signature.subtitle') }}
        </p>
      </div>
      <VBtn
        v-if="can('workforce_signature.request')"
        prepend-icon="tabler-plus"
        @click="wizardOpen = true"
      >
        {{ t('signature.actions.new') }}
      </VBtn>
    </div>

    <VCard>
      <VTabs
        v-model="currentTab"
        class="v-tabs-pill"
        show-arrows
      >
        <VTab
          v-for="tab in TABS"
          :key="tab.key"
          :value="tab.key"
        >
          {{ t(tab.label) }}
        </VTab>
      </VTabs>

      <VCardText>
        <AppTextField
          v-model="search"
          :placeholder="t('signature.searchPlaceholder')"
          prepend-inner-icon="tabler-search"
          density="compact"
          clearable
          style="max-inline-size: 320px;"
          class="mb-4"
        />

        <VDataTable
          :headers="headers"
          :items="store.items"
          :loading="store.loading"
          :items-per-page="15"
          density="comfortable"
          class="text-no-wrap"
          @click:row="(_, { item }) => openDetail(item.id)"
        >
          <template #item.document_label="{ item }">
            <div class="d-flex align-center gap-2">
              <VIcon
                icon="tabler-file-text"
                size="18"
                class="text-medium-emphasis"
              />
              <span class="font-weight-medium">{{ item.document_label || t('signature.unknownDocument') }}</span>
              <VChip
                v-if="item.certified"
                size="x-small"
                color="success"
                variant="tonal"
              >
                {{ t('signature.certified') }}
              </VChip>
            </div>
          </template>

          <template #item.signers="{ item }">
            <div class="d-flex align-center gap-1">
              <VChip
                v-for="s in item.signers"
                :key="s.id"
                size="x-small"
                :color="s.status === 'signed' ? 'success' : (s.status === 'declined' ? 'error' : 'default')"
                variant="tonal"
              >
                {{ s.name }}
              </VChip>
            </div>
          </template>

          <template #item.provider="{ item }">
            <span class="text-body-2">{{ item.provider }}</span>
            <div class="text-caption text-medium-emphasis">
              {{ t(`signature.level.${item.signature_level}`) }} · {{ t(`signature.order.${item.signing_order}`) }}
            </div>
          </template>

          <template #item.status="{ item }">
            <VChip
              :color="statusColor(item.status)"
              size="small"
              label
            >
              <VIcon
                :icon="statusIcon(item.status)"
                start
                size="14"
              />
              {{ statusLabel(item.status) }}
            </VChip>
          </template>

          <template #item.created_at="{ item }">
            <span class="text-body-2">{{ fmtDate(item.created_at) }}</span>
          </template>

          <template #item.actions="{ item }">
            <VBtn
              icon
              variant="text"
              size="small"
              color="default"
              @click.stop="openDetail(item.id)"
            >
              <VIcon icon="tabler-eye" />
            </VBtn>
          </template>

          <template #no-data>
            <div class="text-center py-8 text-medium-emphasis">
              <VIcon
                icon="tabler-signature"
                size="40"
                class="mb-2"
              />
              <div>{{ t('signature.empty') }}</div>
            </div>
          </template>
        </VDataTable>
      </VCardText>
    </VCard>

    <SignatureWizard
      v-model:is-dialog-visible="wizardOpen"
      @created="onCreated"
    />

    <SignatureDetailDrawer
      v-model:is-drawer-open="detailOpen"
      :request-id="detailId"
      @changed="load"
    />
  </div>
</template>
