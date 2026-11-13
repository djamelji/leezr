<script setup>
// ADR-769 (M7 LOT 3) — « Mes signatures ». Liste les demandes où la personne connectée est
// signataire (propriété de la ligne signataire, aucune permission de gestion). Actions réellement
// supportées par le moteur : ouvrir la cérémonie de signature EXISTANTE (/sign/…), télécharger le
// document signé. Une demande gérée par un prestataire externe n'a PAS d'action dans Leezr.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const { toast } = useAppToast()

const rows = ref([])
const loading = ref(true)
const failed = ref(false)
const busy = ref(null)
const filter = ref('all')

const STATE = {
  to_sign: { color: 'warning', icon: 'tabler-writing-sign' },
  in_progress: { color: 'info', icon: 'tabler-hourglass' },
  signed: { color: 'success', icon: 'tabler-circle-check' },
  declined: { color: 'error', icon: 'tabler-circle-x' },
  expired: { color: 'secondary', icon: 'tabler-clock-off' },
  cancelled: { color: 'secondary', icon: 'tabler-ban' },
  failed: { color: 'error', icon: 'tabler-alert-triangle' },
}
const meta = s => STATE[s] || { color: 'secondary', icon: 'tabler-file' }

const load = async () => {
  loading.value = true
  failed.value = false
  try { rows.value = (await $api('/workforce/signature/me')).signatures || [] }
  catch { failed.value = true; rows.value = [] }
  finally { loading.value = false }
}

onMounted(load)

const filters = computed(() => {
  const present = [...new Set(rows.value.map(r => r.state))]

  return ['all', ...Object.keys(STATE).filter(k => present.includes(k))]
})
const visible = computed(() => filter.value === 'all' ? rows.value : rows.value.filter(r => r.state === filter.value))
const fmt = iso => iso ? new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }) : null

// Ouvre la cérémonie existante : un lien à usage unique est émis pour MA ligne signataire.
const open = async row => {
  busy.value = row.id
  try {
    const { url } = await $api(`/workforce/signature/me/${row.id}/open`, { method: 'POST' })

    window.location.assign(url)
  }
  catch (e) {
    toast(Object.values(e?.data?.errors || {})[0]?.[0] || e?.data?.message || t('common.error'), 'error')
    busy.value = null
  }
}

const download = async row => {
  busy.value = row.id
  try {
    const blob = await $api(`/workforce/signature/me/${row.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = `${row.document_label || 'document'}-signe.pdf`
    a.click()
    URL.revokeObjectURL(url)
  }
  catch { toast(t('common.error'), 'error') }
  finally { busy.value = null }
}
</script>

<template>
  <div data-test="my-signatures">
    <VSkeletonLoader
      v-if="loading"
      type="table-row@4"
    />
    <VAlert
      v-else-if="failed"
      type="error"
      variant="tonal"
    >
      {{ t('mySpace.loadError') }}
      <template #append>
        <VBtn
          size="small"
          variant="text"
          @click="load"
        >
          {{ t('common.retry') }}
        </VBtn>
      </template>
    </VAlert>

    <div
      v-else-if="!rows.length"
      class="text-center text-medium-emphasis py-10"
    >
      <VIcon
        icon="tabler-writing-sign-off"
        size="44"
        class="text-disabled mb-3"
      />
      <div class="text-body-1 mb-1">
        {{ t('mySpace.signatures.emptyTitle') }}
      </div>
      <div class="text-body-2">
        {{ t('mySpace.signatures.emptyHint') }}
      </div>
    </div>

    <template v-else>
      <VChipGroup
        v-model="filter"
        mandatory
        selected-class="text-primary"
        class="mb-4"
      >
        <VChip
          v-for="f in filters"
          :key="f"
          :value="f"
          size="small"
          label
          filter
        >
          {{ f === 'all' ? t('common.all') : t('mySpace.signatures.state.' + f) }}
        </VChip>
      </VChipGroup>

      <VList
        lines="two"
        border
        rounded
        class="py-0"
      >
        <template
          v-for="(row, i) in visible"
          :key="row.id"
        >
          <VDivider v-if="i > 0" />
          <VListItem :data-test="`my-signature-${row.id}`">
            <template #prepend>
              <VAvatar
                :color="meta(row.state).color"
                variant="tonal"
                rounded
              >
                <VIcon :icon="meta(row.state).icon" />
              </VAvatar>
            </template>
            <VListItemTitle class="font-weight-medium">
              {{ row.document_label }}
            </VListItemTitle>
            <VListItemSubtitle>
              <span v-if="row.state === 'to_sign' && row.expires_at">{{ t('mySpace.signatures.before', { date: fmt(row.expires_at) }) }}</span>
              <span v-else-if="row.acted_at">{{ t('mySpace.signatures.actedOn', { date: fmt(row.acted_at) }) }}</span>
              <span v-else-if="row.sent_at">{{ t('mySpace.signatures.sentOn', { date: fmt(row.sent_at) }) }}</span>
              <span v-if="row.handled_by_provider && row.state === 'to_sign'"> · {{ t('mySpace.signatures.external') }}</span>
            </VListItemSubtitle>
            <template #append>
              <div class="d-flex align-center flex-wrap justify-end gap-2">
                <VChip
                  :color="meta(row.state).color"
                  size="small"
                  variant="tonal"
                  label
                  :data-test="`my-signature-state-${row.id}`"
                >
                  {{ t('mySpace.signatures.state.' + row.state) }}
                </VChip>
                <VBtn
                  v-if="row.can_sign"
                  color="primary"
                  size="small"
                  prepend-icon="tabler-writing-sign"
                  :loading="busy === row.id"
                  data-test="my-signature-open"
                  @click="open(row)"
                >
                  {{ t('mySpace.signatures.sign') }}
                </VBtn>
                <VBtn
                  v-if="row.can_download"
                  variant="tonal"
                  size="small"
                  prepend-icon="tabler-download"
                  :loading="busy === row.id"
                  @click="download(row)"
                >
                  {{ t('common.download') }}
                </VBtn>
              </div>
            </template>
          </VListItem>
        </template>
      </VList>
    </template>
  </div>
</template>
