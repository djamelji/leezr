<script setup>
// W-SIGNATURE-1 (ADR-651) — RH → Signatures : tableau de bord, liste, détail, assistant de demande.
// ADR-802 — vue embarquée par l'espace Documents (`/company/documents/signatures`) ; l'ancienne route redirige.

import { useSignatureStore } from '@/modules/company/workforce/signature.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'
import SignatureWizard from './_SignatureWizard.vue'
import SignatureDetailDrawer from './_SignatureDetailDrawer.vue'
import { useDisplay } from 'vuetify'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import AppStatStrip from '@/components/app/AppStatStrip.vue'

// PRODUCT-UX-2 (LOT A) — `embedded` : masque l'en-tête de page quand la vue est montée comme
// onglet de l'espace Documents unifié (évite les doublons de titre). Logique 100 % réutilisée.
defineProps({ embedded: { type: Boolean, default: false } })

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
const currentTab = ref(typeof useRoute().query.status === 'string' ? useRoute().query.status : '') // ADR-777 : lien filtré depuis l'accueil
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

const { locale } = useI18n()
const fmtDate = iso => (iso ? new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

// ADR-862 (L7 closure) — the provider in words (« Leezr (interne) », not « internal ») ; the new-request action sits
// with the search when the view is embedded (no lone button row) ; the supervision reads as a strip of indicators.
const { xs } = useDisplay()
const providerLabel = p => t(`l7.docs.signatures.provider.${p}`, String(p || '—'))

// Détail
const detailId = ref(null)
const detailOpen = ref(false)
const openDetail = id => { detailId.value = id; detailOpen.value = true }

// Assistant
const wizardOpen = ref(false)
const onCreated = () => { wizardOpen.value = false; toast(t('signature.toasts.created'), 'success'); load() }

// W-SIGNATURE-12 — supervision opérationnelle (métriques agrégées, lecture seule, gated view_evidence)
const supervision = ref(null)
const canSupervise = computed(() => can('workforce_signature.view_evidence'))
const supervisionStats = computed(() => {
  const s = supervision.value
  if (!s) return []

  return [
    { key: 'pending', label: t('l7.docs.signatures.pending'), value: s.requests.pending, icon: 'tabler-clock', tone: s.requests.pending ? 'warning' : 'muted' },
    { key: 'expired', label: t('l7.docs.signatures.expired'), value: s.requests.expired, icon: 'tabler-clock-off', tone: 'muted' },
    { key: 'failed', label: t('l7.docs.signatures.failed'), value: s.requests.failed, icon: 'tabler-alert-triangle', tone: s.requests.failed ? 'error' : 'muted' },
    { key: 'reconcile', label: t('l7.docs.signatures.reconcile'), value: s.operational.needs_reconciliation, icon: 'tabler-arrows-exchange', tone: s.operational.needs_reconciliation ? 'error' : 'success', help: t('l7.docs.signatures.reconcileHelp') },
    { key: 'certs', label: t('l7.docs.signatures.certs'), value: s.certificates.issued, icon: 'tabler-certificate', tone: 'info', help: t('l7.docs.signatures.certsHelp', { revoked: s.certificates.revoked, preps: s.operational.expired_preparations }) },
  ]
})
onMounted(async () => {
  if (canSupervise.value) {
    try { supervision.value = await store.fetchSupervision() } catch { /* non bloquant */ }
  }
})
</script>

<template>
  <div>
    <div
      v-if="!embedded"
      class="d-flex flex-wrap justify-space-between align-center gap-4 mb-6"
    >
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

    <!-- W-SIGNATURE-12 — supervision opérationnelle (agrégée, non sensible) -->
    <AppStatStrip
      v-if="canSupervise && supervision"
      :items="supervisionStats"
      class="mb-6"
      data-test="signature-supervision"
    />

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
        <div class="d-flex flex-wrap align-center gap-3 mb-4">
          <AppTextField
            v-model="search"
            :placeholder="t('signature.searchPlaceholder')"
            :aria-label="t('signature.searchPlaceholder')"
            prepend-inner-icon="tabler-search"
            density="compact"
            clearable
            style="max-inline-size: 320px; min-inline-size: 200px;"
          />
          <VSpacer />
          <VBtn
            v-if="embedded && can('workforce_signature.request')"
            prepend-icon="tabler-plus"
            data-test="signature-new"
            @click="wizardOpen = true"
          >
            {{ t('signature.actions.new') }}
          </VBtn>
        </div>

        <!-- phone : one line per request (document, state, signers, date) ; touch opens the detail -->
        <template v-if="xs">
          <AppEmptyState
            v-if="!store.loading && !store.items.length"
            compact
            icon="tabler-signature"
            :title="t('l7.docs.signatures.emptyTitle')"
            :text="t('l7.docs.signatures.emptyText')"
          />
          <VList
            v-else
            class="py-0 mx-n4"
            data-test="signature-list"
          >
            <template
              v-for="(item, i) in store.items"
              :key="item.id"
            >
              <VDivider v-if="i" />
              <VListItem
                class="py-3"
                @click="openDetail(item.id)"
              >
                <div class="d-flex align-center justify-space-between gap-2">
                  <span class="font-weight-medium text-truncate">{{ item.document_label || t('signature.unknownDocument') }}</span>
                  <VChip
                    :color="statusColor(item.status)"
                    size="small"
                    label
                  >
                    {{ statusLabel(item.status) }}
                  </VChip>
                </div>
                <div class="text-body-2 text-medium-emphasis mt-1 text-truncate">
                  {{ (item.signers || []).map(s => s.name).join(', ') }} · {{ fmtDate(item.created_at) }}
                </div>
              </VListItem>
            </template>
          </VList>
        </template>
        <VDataTable
          v-else
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
            <div class="d-flex flex-wrap align-center gap-1">
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
            <span class="text-body-2">{{ providerLabel(item.provider) }}</span>
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
              :aria-label="$t('common.view')"
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
            <AppEmptyState
              compact
              icon="tabler-signature"
              :title="t('l7.docs.signatures.emptyTitle')"
              :text="t('l7.docs.signatures.emptyText')"
            />
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
