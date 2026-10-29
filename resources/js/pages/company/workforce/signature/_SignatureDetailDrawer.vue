<script setup>
// W-SIGNATURE-1 (ADR-651) — détail d'une demande : signataires, chronologie de preuves, actions.
import { useSignatureStore } from '@/modules/company/workforce/signature.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'

const props = defineProps({
  isDrawerOpen: { type: Boolean, default: false },
  requestId: { type: Number, default: null },
})
const emit = defineEmits(['update:isDrawerOpen', 'changed'])

const { t } = useI18n()
const store = useSignatureStore()
const { toast } = useAppToast()
const { can } = useCan()

const loading = ref(false)
const req = ref(null)
const events = ref([])
const acting = ref(false)

const STATUS_COLOR = {
  draft: 'secondary', pending: 'warning', partially_signed: 'info', completed: 'success',
  declined: 'error', cancelled: 'secondary', expired: 'secondary', failed: 'error',
}
const signerColor = s => (s === 'signed' ? 'success' : s === 'declined' ? 'error' : s === 'invited' ? 'info' : 'secondary')

const load = async () => {
  if (!props.requestId) return
  loading.value = true
  events.value = []
  try {
    req.value = await store.fetchOne(props.requestId)
    if (can('workforce_signature.view_evidence')) {
      try {
        const ev = await store.fetchEvidence(props.requestId)
        events.value = ev.events ?? []
      } catch { /* pas de droit preuves */ }
    }
  } finally { loading.value = false }
}

watch(() => [props.isDrawerOpen, props.requestId], ([open]) => { if (open) load() })

const isActive = computed(() => req.value && !['completed', 'declined', 'cancelled', 'expired', 'failed'].includes(req.value.status))
const fmt = iso => (iso ? new Date(iso).toLocaleString() : '—')

const doCancel = async () => {
  acting.value = true
  try {
    await store.cancel(props.requestId, null)
    toast(t('signature.toasts.cancelled'), 'success')
    emit('changed'); await load()
  } catch (e) { toast(e.response?._data?.message || t('common.error'), 'error') } finally { acting.value = false }
}
const doRemind = async () => {
  acting.value = true
  try {
    const r = await store.remind(props.requestId)
    toast(t('signature.toasts.reminded', { count: r.reminded ?? 0 }), 'success')
  } catch (e) { toast(e.response?._data?.message || t('common.error'), 'error') } finally { acting.value = false }
}
</script>

<template>
  <VNavigationDrawer
    temporary
    location="end"
    :model-value="isDrawerOpen"
    width="460"
    @update:model-value="emit('update:isDrawerOpen', $event)"
  >
    <div class="d-flex align-center justify-space-between pa-4">
      <h5 class="text-h5">
        {{ t('signature.detail.title') }}
      </h5>
      <VBtn
        icon
        variant="text"
        size="small"
        color="default"
        @click="emit('update:isDrawerOpen', false)"
      >
        <VIcon icon="tabler-x" />
      </VBtn>
    </div>
    <VDivider />

    <div v-if="loading" class="pa-6 text-center">
      <VProgressCircular indeterminate color="primary" />
    </div>

    <div v-else-if="req" class="pa-4">
      <div class="d-flex align-center justify-space-between mb-3">
        <div class="font-weight-medium">
          {{ req.document_label || t('signature.unknownDocument') }}
        </div>
        <VChip :color="STATUS_COLOR[req.status]" size="small" label>
          {{ t(`signature.status.${req.status}`) }}
        </VChip>
      </div>

      <VList density="compact" class="mb-2">
        <VListItem :title="req.provider" :subtitle="t('signature.columns.provider')" />
        <VListItem :title="`${t(`signature.level.${req.signature_level}`)} · ${t(`signature.order.${req.signing_order}`)}`" :subtitle="t('signature.detail.levelOrder')" />
        <VListItem :title="fmt(req.expires_at)" :subtitle="t('signature.detail.expires')" />
        <VListItem v-if="req.certified !== undefined" :subtitle="t('signature.detail.certification')">
          <template #title>
            <VChip size="x-small" :color="req.certified ? 'success' : 'warning'" variant="tonal">
              {{ req.certified ? t('signature.certified') : t('signature.uncertified') }}
            </VChip>
          </template>
        </VListItem>
      </VList>

      <div class="text-caption text-medium-emphasis mb-2">
        {{ t('signature.detail.signers') }}
      </div>
      <VList density="compact" class="mb-3">
        <VListItem v-for="s in req.signers" :key="s.id">
          <template #prepend>
            <VChip size="x-small" label class="me-2">{{ s.order }}</VChip>
          </template>
          <VListItemTitle>{{ s.name }}</VListItemTitle>
          <VListItemSubtitle>{{ t(`signature.role.${s.role}`) }} · {{ s.email }}</VListItemSubtitle>
          <template #append>
            <VChip size="x-small" :color="signerColor(s.status)" variant="tonal">
              {{ t(`signature.signerStatus.${s.status}`) }}
            </VChip>
          </template>
        </VListItem>
      </VList>

      <!-- Chronologie des preuves -->
      <template v-if="events.length">
        <div class="text-caption text-medium-emphasis mb-2">
          {{ t('signature.detail.timeline') }}
        </div>
        <VTimeline
          side="end"
          align="start"
          density="compact"
          truncate-line="both"
          class="mb-4"
        >
          <VTimelineItem
            v-for="(e, i) in events"
            :key="i"
            size="x-small"
            dot-color="primary"
          >
            <div class="d-flex justify-space-between">
              <span class="font-weight-medium">{{ t(`signature.event.${e.type}`) }}</span>
              <span class="text-caption text-medium-emphasis">{{ fmt(e.occurred_at) }}</span>
            </div>
            <div v-if="e.verification_result" class="text-caption">
              {{ t('signature.detail.verification') }}: {{ e.verification_result }}
            </div>
          </VTimelineItem>
        </VTimeline>
      </template>

      <!-- Actions -->
      <div class="d-flex flex-wrap gap-2">
        <VBtn
          v-if="req.has_signed_document"
          :href="store.downloadSignedUrl(req.id)"
          target="_blank"
          prepend-icon="tabler-download"
          size="small"
        >
          {{ t('signature.detail.download') }}
        </VBtn>
        <VBtn
          v-if="isActive && can('workforce_signature.manage')"
          variant="tonal"
          size="small"
          prepend-icon="tabler-bell"
          :loading="acting"
          @click="doRemind"
        >
          {{ t('signature.detail.remind') }}
        </VBtn>
        <VBtn
          v-if="isActive && can('workforce_signature.manage')"
          variant="tonal"
          color="error"
          size="small"
          prepend-icon="tabler-ban"
          :loading="acting"
          @click="doCancel"
        >
          {{ t('signature.detail.cancel') }}
        </VBtn>
      </div>
    </div>
  </VNavigationDrawer>
</template>
