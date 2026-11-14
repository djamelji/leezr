<script setup>
// ADR-778 (UX3) — recherche documentaire TRANSVERSALE. Tout est exécuté côté serveur
// (`GET /company/documents/search`) : périmètre de l'entreprise, règles d'accès de chaque source,
// pagination. Le navigateur ne reçoit que la page affichée et des métadonnées non sensibles.
import { $api } from '@/utils/api'
import { formatDate } from '@/utils/datetime'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({ term: { type: String, default: '' } })
const emit = defineEmits(['close'])

const { t, te } = useI18n()
const router = useRouter()
const { toast } = useAppToast()

const filters = ref({ source: null, status: null, from: '', to: '', expires_before: '' })
const page = ref(1)
const state = ref('idle') // idle | loading | ready | error
const rows = ref([])
const meta = ref({ total: 0, per_page: 20, truncated: false, sources: [] })
const busy = ref(null)

const SOURCES = ['company', 'person', 'generated']
const STATUSES = ['valid', 'expiring', 'expired', 'signed', 'pending_signature']
const STATUS_COLOR = { valid: 'success', expiring: 'warning', expired: 'error', signed: 'success', pending_signature: 'warning', generated: 'secondary' }
const sourceItems = computed(() => SOURCES.map(s => ({ value: s, title: t(`documents.search.source.${s}`) })))
const statusItems = computed(() => STATUSES.map(s => ({ value: s, title: t(`documents.search.status.${s}`) })))
const pages = computed(() => Math.max(1, Math.ceil(meta.value.total / meta.value.per_page)))
const label = r => (r.type_code && te(`documents.type.${r.type_code}`) ? t(`documents.type.${r.type_code}`) : r.name)

let timer = null
const run = () => {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    state.value = 'loading'
    try {
      const query = { q: props.term || undefined, page: page.value }

      for (const [k, v] of Object.entries(filters.value)) if (v) query[k] = v
      const data = await $api('/company/documents/search', { query })

      rows.value = data.data || []
      meta.value = data.meta || meta.value
      state.value = 'ready'
    }
    catch { state.value = 'error' }
  }, 300)
}

watch(() => props.term, () => { page.value = 1; run() }, { immediate: true })
watch(filters, () => { page.value = 1; run() }, { deep: true })
watch(page, run)

const download = async r => {
  busy.value = r.key
  try {
    const blob = await $api(r.download, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = `${label(r)}`.replace(/[^\p{L}\p{N} _.-]/gu, '').trim() || 'document'
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 10000)
  }
  catch { toast(t('common.error'), 'error') }
  finally { busy.value = null }
}
</script>

<template>
  <VCard data-test="documents-search">
    <VCardText class="d-flex flex-wrap align-center gap-3 pb-2">
      <AppSelect
        v-model="filters.source"
        :items="sourceItems"
        :placeholder="t('documents.search.sourceLabel')"
        clearable
        density="compact"
        style="max-inline-size: 200px;"
        data-test="documents-search-source"
      />
      <AppSelect
        v-model="filters.status"
        :items="statusItems"
        :placeholder="t('documents.search.statusLabel')"
        clearable
        density="compact"
        style="max-inline-size: 200px;"
        data-test="documents-search-status"
      />
      <VMenu :close-on-content-click="false">
        <template #activator="{ props: menu }">
          <VBtn
            v-bind="menu"
            variant="tonal"
            color="secondary"
            size="small"
            prepend-icon="tabler-adjustments-horizontal"
            data-test="documents-search-more"
          >
            {{ t('documents.search.moreFilters') }}
          </VBtn>
        </template>
        <VCard min-width="280">
          <VCardText class="d-flex flex-column gap-3">
            <AppTextField
              v-model="filters.from"
              type="date"
              :label="t('documents.search.from')"
            />
            <AppTextField
              v-model="filters.to"
              type="date"
              :label="t('documents.search.to')"
            />
            <AppTextField
              v-model="filters.expires_before"
              type="date"
              :label="t('documents.search.expiresBefore')"
            />
          </VCardText>
        </VCard>
      </VMenu>
      <VSpacer />
      <span
        v-if="state === 'ready'"
        class="text-body-2 text-medium-emphasis"
        data-test="documents-search-count"
      >{{ t('documents.search.count', meta.total) }}</span>
      <VBtn
        variant="text"
        size="small"
        data-test="documents-search-close"
        @click="emit('close')"
      >
        {{ t('documents.search.close') }}
      </VBtn>
    </VCardText>

    <VSkeletonLoader
      v-if="state === 'loading' && !rows.length"
      type="table-row@4"
    />
    <VCardText v-else-if="state === 'error'">
      <VAlert
        type="warning"
        variant="tonal"
        density="compact"
      >
        {{ t('documents.search.error') }}
      </VAlert>
    </VCardText>
    <VCardText
      v-else-if="state === 'ready' && !rows.length"
      class="text-body-1 text-medium-emphasis"
      data-test="documents-search-empty"
    >
      {{ t('documents.search.empty') }}
    </VCardText>
    <VList
      v-else
      lines="two"
      class="py-0"
    >
      <VListItem
        v-for="r in rows"
        :key="r.key"
        :data-test="`documents-search-row-${r.source}`"
        @click="router.push(r.open)"
      >
        <template #prepend>
          <VIcon
            :icon="r.source === 'company' ? 'tabler-building' : r.source === 'generated' ? 'tabler-file-certificate' : 'tabler-file-text'"
            class="me-2"
          />
        </template>
        <VListItemTitle class="text-body-1">
          {{ label(r) }}
        </VListItemTitle>
        <VListItemSubtitle>
          {{ r.person ? r.person.name : t('documents.deposit.company') }} · {{ t(`documents.search.source.${r.source}`) }} · {{ formatDate(r.date) }}<span v-if="r.expires_at"> · {{ t('documents.expiresAt') }} {{ formatDate(r.expires_at) }}</span>
        </VListItemSubtitle>
        <template #append>
          <VChip
            :color="STATUS_COLOR[r.status] || 'secondary'"
            size="small"
            variant="tonal"
            label
            class="me-2"
          >
            {{ t(`documents.search.status.${r.status}`) }}
          </VChip>
          <IconBtn
            v-if="r.download"
            size="small"
            :loading="busy === r.key"
            :aria-label="t('documents.download')"
            :title="t('documents.download')"
            data-test="documents-search-download"
            @click.stop="download(r)"
          >
            <VIcon icon="tabler-download" />
          </IconBtn>
        </template>
      </VListItem>
    </VList>

    <VCardText
      v-if="state === 'ready' && (pages > 1 || meta.truncated)"
      class="d-flex align-center flex-wrap gap-3"
    >
      <span
        v-if="meta.truncated"
        class="text-body-2 text-medium-emphasis"
      >{{ t('documents.search.truncated') }}</span>
      <VSpacer />
      <VPagination
        v-if="pages > 1"
        v-model="page"
        :length="pages"
        :total-visible="5"
        density="comfortable"
      />
    </VCardText>
  </VCard>
</template>
