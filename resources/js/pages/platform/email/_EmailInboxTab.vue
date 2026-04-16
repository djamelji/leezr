<script setup>
import ComposeDialog from './inbox/_ComposeDialog.vue'
import { $platformApi } from '@/utils/platformApi'

const { t } = useI18n()
const router = useRouter()
const { toast } = useAppToast()

// State
const threads = ref([])
const stats = ref({ total_unread: 0, open: 0, closed: 0 })
const currentPage = ref(1)
const lastPage = ref(1)
const total = ref(0)
const isLoading = ref(true)
const search = ref('')
const statusFilter = ref('')
const showUnreadOnly = ref(false)
const isComposeOpen = ref(false)

// Fetch threads
const fetchThreads = async () => {
  isLoading.value = true
  try {
    const params = new URLSearchParams()
    params.set('page', currentPage.value)
    if (search.value) params.set('search', search.value)
    if (statusFilter.value) params.set('status', statusFilter.value)
    if (showUnreadOnly.value) params.set('unread', '1')

    const data = await $platformApi(`/email/inbox?${params}`)
    threads.value = data.data
    stats.value = data.stats
    currentPage.value = data.current_page
    lastPage.value = data.last_page
    total.value = data.total
  } catch (e) {
    toast(t('emailInbox.fetchError'), 'error')
  } finally {
    isLoading.value = false
  }
}

// Watchers
watch([search, statusFilter, showUnreadOnly], () => {
  currentPage.value = 1
  fetchThreads()
})

const openThread = thread => {
  router.push({ name: 'platform-email-inbox-id', params: { id: thread.id } })
}

const formatTime = dateStr => {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now - date
  const diffDays = Math.floor(diffMs / 86400000)

  if (diffDays === 0) return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  if (diffDays === 1) return t('emailInbox.yesterday')
  if (diffDays < 7) return date.toLocaleDateString('fr-FR', { weekday: 'short' })
  return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' })
}

const statusColor = status => {
  if (status === 'open') return 'success'
  if (status === 'closed') return 'default'
  return 'warning'
}

const onComposeSent = () => {
  toast(t('emailInbox.composeSent'), 'success')
  fetchThreads()
}

onMounted(fetchThreads)
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center flex-wrap gap-4 mb-6">
      <div>
        <p class="text-body-1 mb-0">
          {{ t('emailInbox.subtitle') }}
        </p>
      </div>
      <VSpacer />
      <VBtn
        color="primary"
        prepend-icon="tabler-pencil"
        @click="isComposeOpen = true"
      >
        {{ t('emailInbox.newEmail') }}
      </VBtn>
    </div>

    <!-- Stats cards -->
    <VRow class="card-grid card-grid-xs mb-6">
      <VCol cols="12" sm="4">
        <VCard>
          <VCardText class="d-flex align-center gap-4">
            <VAvatar color="primary" variant="tonal" rounded>
              <VIcon icon="tabler-inbox" />
            </VAvatar>
            <div>
              <div class="text-h5">{{ stats.open }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ t('emailInbox.stats.open') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard>
          <VCardText class="d-flex align-center gap-4">
            <VAvatar color="error" variant="tonal" rounded>
              <VIcon icon="tabler-mail" />
            </VAvatar>
            <div>
              <div class="text-h5">{{ stats.total_unread }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ t('emailInbox.stats.unread') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard>
          <VCardText class="d-flex align-center gap-4">
            <VAvatar color="secondary" variant="tonal" rounded>
              <VIcon icon="tabler-check" />
            </VAvatar>
            <div>
              <div class="text-h5">{{ stats.closed }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ t('emailInbox.stats.closed') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters + Thread list -->
    <VCard>
      <VCardText class="d-flex align-center flex-wrap gap-4">
        <AppTextField
          v-model="search"
          :placeholder="t('emailInbox.searchPlaceholder')"
          prepend-inner-icon="tabler-search"
          density="compact"
          style="max-inline-size: 300px;"
          clearable
        />
        <AppSelect
          v-model="statusFilter"
          :items="[
            { title: t('emailInbox.filters.all'), value: '' },
            { title: t('emailInbox.filters.open'), value: 'open' },
            { title: t('emailInbox.filters.closed'), value: 'closed' },
            { title: t('emailInbox.filters.archived'), value: 'archived' },
          ]"
          density="compact"
          style="max-inline-size: 160px;"
        />
        <VCheckbox
          v-model="showUnreadOnly"
          :label="t('emailInbox.filters.unreadOnly')"
          density="compact"
        />
        <VSpacer />
        <IconBtn @click="fetchThreads">
          <VIcon icon="tabler-refresh" />
        </IconBtn>
      </VCardText>

      <VDivider />

      <VSkeletonLoader
        v-if="isLoading"
        type="list-item-three-line, list-item-three-line, list-item-three-line"
      />

      <VList v-else-if="threads.length" class="py-0">
        <template v-for="(thread, i) in threads" :key="thread.id">
          <VListItem
            class="cursor-pointer"
            :class="{ 'bg-light-primary': thread.unread_count > 0 }"
            @click="openThread(thread)"
          >
            <template #prepend>
              <VAvatar
                :color="thread.unread_count > 0 ? 'primary' : 'secondary'"
                variant="tonal"
                size="40"
              >
                <VIcon :icon="thread.unread_count > 0 ? 'tabler-mail' : 'tabler-mail-opened'" />
              </VAvatar>
            </template>

            <VListItemTitle class="d-flex align-center gap-2">
              <span class="text-truncate" :class="{ 'font-weight-bold': thread.unread_count > 0 }">
                {{ thread.subject }}
              </span>
              <VChip :color="statusColor(thread.status)" size="x-small" label>
                {{ thread.status }}
              </VChip>
              <VBadge v-if="thread.unread_count > 0" :content="thread.unread_count" color="error" inline />
            </VListItemTitle>

            <VListItemSubtitle class="d-flex align-center gap-2 mt-1">
              <span class="text-body-2">{{ thread.participant_name || thread.participant_email }}</span>
              <template v-if="thread.company">
                <VIcon icon="tabler-building" size="14" />
                <span class="text-body-2">{{ thread.company.name }}</span>
              </template>
              <span v-if="thread.last_message" class="text-body-2 text-truncate text-medium-emphasis">
                — {{ thread.last_message.body_text }}
              </span>
            </VListItemSubtitle>

            <template #append>
              <div class="d-flex flex-column align-end gap-1">
                <span class="text-caption text-disabled">{{ formatTime(thread.last_message_at) }}</span>
                <span class="text-caption text-disabled">{{ thread.message_count }} {{ t('emailInbox.messages') }}</span>
              </div>
            </template>
          </VListItem>
          <VDivider v-if="i < threads.length - 1" />
        </template>
      </VList>

      <div v-else class="pa-12 text-center">
        <VIcon icon="tabler-inbox-off" size="64" class="text-disabled mb-4" />
        <p class="text-h6 text-disabled">{{ t('emailInbox.empty') }}</p>
        <VBtn variant="tonal" prepend-icon="tabler-pencil" @click="isComposeOpen = true">
          {{ t('emailInbox.newEmail') }}
        </VBtn>
      </div>

      <VDivider v-if="lastPage > 1" />
      <VCardText v-if="lastPage > 1" class="d-flex justify-center">
        <VPagination v-model="currentPage" :length="lastPage" :total-visible="5" @update:model-value="fetchThreads" />
      </VCardText>
    </VCard>

    <ComposeDialog :is-visible="isComposeOpen" @close="isComposeOpen = false" @sent="onComposeSent" />
  </div>
</template>
