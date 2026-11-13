<script setup>
// ADR-769 (M7 LOT 3) — « Mes bulletins » (self-service M5.1/M5.2 conservé). Chaque personne ne voit
// QUE ses bulletins : la propriété du sujet décide côté serveur (jamais `documents.view`). Classés
// par année puis période ; rectificatifs (version > 1), provenance et état « retiré » visibles.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const { toast } = useAppToast()

const payslips = ref([])
const loading = ref(true)
const failed = ref(false)
const downloading = ref(null)

const load = async () => {
  loading.value = true
  failed.value = false
  try { payslips.value = (await $api('/profile/payslips')).payslips || [] }
  catch { failed.value = true; payslips.value = [] }
  finally { loading.value = false }
}

onMounted(load)

const years = computed(() => {
  const groups = new Map()
  for (const p of payslips.value) {
    const y = p.year || String(p.period).slice(0, 4)
    if (!groups.has(y)) groups.set(y, [])
    groups.get(y).push(p)
  }

  return [...groups.entries()].sort((a, b) => b[0].localeCompare(a[0])).map(([year, items]) => ({ year, items }))
})

const periodLabel = period => {
  const [y, m] = String(period).split('-')
  if (!m) return period

  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
}

const download = async p => {
  downloading.value = p.id
  try {
    const blob = await $api(`/profile/payslips/${p.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = `bulletin-${p.period}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  }
  catch { toast(t('common.error'), 'error') }
  finally { downloading.value = null }
}
</script>

<template>
  <div data-test="my-payslips">
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
      v-else-if="!years.length"
      class="text-center text-medium-emphasis py-10"
    >
      <VIcon
        icon="tabler-receipt-off"
        size="44"
        class="text-disabled mb-3"
      />
      <div class="text-body-1 mb-1">
        {{ t('mySpace.payslips.emptyTitle') }}
      </div>
      <div class="text-body-2">
        {{ t('mySpace.payslips.emptyHint') }}
      </div>
    </div>

    <template v-else>
      <div
        v-for="group in years"
        :key="group.year"
        class="mb-6"
      >
        <div class="text-overline text-medium-emphasis mb-2">
          {{ group.year }}
        </div>
        <VList
          lines="two"
          border
          rounded
          class="py-0"
        >
          <template
            v-for="(p, i) in group.items"
            :key="p.id"
          >
            <VListItem
              :data-test="`my-payslip-${p.id}`"
              :class="{ 'border-t': i > 0 }"
            >
              <template #prepend>
                <VAvatar
                  :color="p.status === 'revoked' ? 'error' : 'primary'"
                  variant="tonal"
                  rounded
                >
                  <VIcon icon="tabler-receipt" />
                </VAvatar>
              </template>
              <VListItemTitle class="text-capitalize font-weight-medium">
                {{ periodLabel(p.period) }}
                <VChip
                  v-if="p.version_no > 1"
                  size="x-small"
                  color="info"
                  variant="tonal"
                  label
                  class="ms-2"
                >
                  {{ t('mySpace.payslips.corrected', { n: p.version_no }) }}
                </VChip>
              </VListItemTitle>
              <VListItemSubtitle>
                {{ p.source === 'external_import' ? t('documents.payslip.external') : t('documents.payslip.generated') }}
              </VListItemSubtitle>
              <template #append>
                <VChip
                  v-if="p.status === 'revoked'"
                  size="small"
                  color="error"
                  variant="tonal"
                  label
                >
                  {{ t('documents.payslip.revoked') }}
                </VChip>
                <VBtn
                  v-else-if="p.can_download"
                  variant="tonal"
                  size="small"
                  prepend-icon="tabler-download"
                  :loading="downloading === p.id"
                  @click="download(p)"
                >
                  {{ t('common.download') }}
                </VBtn>
              </template>
            </VListItem>
          </template>
        </VList>
      </div>
    </template>
  </div>
</template>
