<script setup>
// ADR-778 (UX3) — « À traiter » : le centre d'action documentaire. Chaque ligne est un TRAITEMENT
// (file filtrée, vue filtrée) ; les états ne sont pas mélangés. Sources : celles des vues elles-mêmes
// (demandes, conformité, coffre de l'entreprise) + les priorités servies par les modules actifs
// (signatures) — rien n'est recalculé ici, rien n'est affiché sans le droit correspondant.
import DocumentsRequests from './_DocumentsRequests.vue'
import { $api } from '@/utils/api'
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'
import { useCompanyPermissionContext } from '@/composables/useCompanyPermissionContext'

const { t } = useI18n()
const store = useCompanyDocumentsStore()
const { can } = useCompanyPermissionContext()

const signatures = ref(0)
onMounted(async () => {
  try {
    const data = await $api('/dashboard/priorities')

    signatures.value = (data.priorities || []).find(p => p.key === 'signatures_pending')?.count || 0
  }
  catch { signatures.value = 0 }
})

const tab = (name, query = {}) => ({ name: 'company-documents-tab', params: { tab: name }, query })
const renewals = computed(() => (store.companyDocuments || []).filter(d => d.request_status && !['approved', 'cancelled'].includes(d.request_status)).length)

const rows = computed(() => [
  can('documents.manage') ? { key: 'requested', count: store.pendingRequestsCount, icon: 'tabler-hourglass', color: 'info', to: tab('people', { status: 'requested' }) } : null,
  can('documents.view') ? { key: 'expired', count: store.expiredCount, icon: 'tabler-file-alert', color: 'error', to: tab('compliance', { status: 'expired' }) } : null,
  can('documents.view') ? { key: 'expiring', count: store.expiringSoonCount, icon: 'tabler-file-time', color: 'warning', to: tab('compliance', { status: 'expiring' }) } : null,
  can('documents.view') ? { key: 'renewals', count: renewals.value, icon: 'tabler-refresh', color: 'warning', to: tab('company') } : null,
  can('documents.view') ? { key: 'missing', count: store.missingCount, icon: 'tabler-file-off', color: 'secondary', to: tab('compliance', { status: 'missing' }) } : null,
  { key: 'signatures', count: signatures.value, icon: 'tabler-signature', color: 'info', to: tab('signatures', { status: 'pending' }) },
].filter(r => r && r.count > 0))

const toReview = computed(() => (can('documents.manage') ? store.submittedRequestsCount : 0))
const nothing = computed(() => !toReview.value && !rows.value.length)
</script>

<template>
  <div data-test="documents-todo">
    <VCard
      v-if="nothing"
      data-test="documents-todo-clear"
    >
      <VCardText class="d-flex align-center gap-3">
        <VIcon
          icon="tabler-circle-check"
          color="success"
        />
        <div>
          <div class="text-body-1">
            {{ t('documents.todo.clearTitle') }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ t('documents.todo.clearHint') }}
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- À valider : la file elle-même, déjà filtrée — on valide ou refuse ici, sans changer d'écran. -->
    <div
      v-if="toReview"
      class="mb-6"
      data-test="documents-todo-review"
    >
      <h6 class="text-h6 mb-3">
        {{ t('documents.todo.review', toReview) }}
      </h6>
      <DocumentsRequests
        preset-status="submitted"
        compact
      />
    </div>

    <VCard v-if="rows.length">
      <VCardItem class="pb-2">
        <VCardTitle class="text-h6">
          {{ t('documents.todo.follow') }}
        </VCardTitle>
      </VCardItem>
      <VList
        lines="one"
        class="py-0"
      >
        <VListItem
          v-for="r in rows"
          :key="r.key"
          :to="r.to"
          :data-test="`documents-todo-${r.key}`"
        >
          <template #prepend>
            <VAvatar
              :color="r.color"
              variant="tonal"
              rounded
              size="34"
              class="me-1"
            >
              <VIcon
                :icon="r.icon"
                size="20"
              />
            </VAvatar>
          </template>
          <VListItemTitle class="text-body-1">
            {{ t(`documents.todo.rows.${r.key}`) }}
          </VListItemTitle>
          <template #append>
            <span class="text-h6 font-weight-medium me-2">{{ r.count }}</span>
            <VIcon
              icon="tabler-chevron-right"
              size="18"
              class="text-medium-emphasis"
            />
          </template>
        </VListItem>
      </VList>
    </VCard>
  </div>
</template>
