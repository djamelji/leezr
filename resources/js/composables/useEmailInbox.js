/**
 * useEmailInbox — composable for the full email inbox app (ADR-453).
 *
 * Manages state for folders, threads, selection, bulk actions,
 * compose, reply, and real-time SSE updates.
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { $platformApi } from '@/utils/platformApi'
import { useRealtimeSubscription } from '@/core/realtime/useRealtimeSubscription'

// Labels definition (from Vuexy preset)
const LABELS = [
  { title: 'personal', color: 'success' },
  { title: 'company', color: 'primary' },
  { title: 'important', color: 'warning' },
  { title: 'private', color: 'error' },
]

// Folders definition
const FOLDERS = [
  { key: 'inbox', icon: 'tabler-mail' },
  { key: 'sent', icon: 'tabler-send' },
  { key: 'draft', icon: 'tabler-edit' },
  { key: 'starred', icon: 'tabler-star' },
  { key: 'spam', icon: 'tabler-alert-octagon' },
  { key: 'trash', icon: 'tabler-trash' },
]

export function useEmailInbox() {
  const { t, locale } = useI18n()

  // State
  const threads = ref([])
  const selectedThread = ref(null)
  const selectedThreadMessages = ref([])
  const selectedIds = ref(new Set())
  const currentFolder = ref('inbox')
  const searchQuery = ref('')
  const currentLabel = ref(null)
  const folderCounts = ref({ inbox: 0, sent: 0, draft: 0, starred: 0, spam: 0, trash: 0 })
  const isLoading = ref(false)
  const isSyncing = ref(false)
  const pagination = ref({ page: 1, lastPage: 1, total: 0 })

  // Computed
  const isAllSelected = computed(() =>
    threads.value.length > 0 && selectedIds.value.size === threads.value.length,
  )
  const isIndeterminate = computed(() =>
    selectedIds.value.size > 0 && selectedIds.value.size < threads.value.length,
  )
  const hasSelection = computed(() => selectedIds.value.size > 0)

  const threadMeta = computed(() => {
    if (!selectedThread.value) return { hasPrevious: false, hasNext: false }
    const idx = threads.value.findIndex(t => t.id === selectedThread.value.id)

    return {
      hasPrevious: idx > 0,
      hasNext: idx < threads.value.length - 1,
    }
  })

  // ── API ────────────────────────────────────────────

  async function fetchThreads(page = 1) {
    isLoading.value = true
    try {
      const params = new URLSearchParams()
      params.set('page', page)
      params.set('folder', currentFolder.value)
      if (currentFolder.value === 'starred') params.set('starred', '1')
      if (currentLabel.value) params.set('label', currentLabel.value)
      if (searchQuery.value) params.set('search', searchQuery.value)

      const data = await $platformApi(`/email/inbox?${params}`)
      threads.value = data.data
      folderCounts.value = data.folder_counts || folderCounts.value
      pagination.value = {
        page: data.current_page,
        lastPage: data.last_page,
        total: data.total,
      }
    } catch {
      // handled by caller
    } finally {
      isLoading.value = false
    }
  }

  async function fetchThread(id) {
    try {
      const data = await $platformApi(`/email/inbox/${id}`)
      selectedThread.value = data.thread
      selectedThreadMessages.value = data.messages

      // Update thread in list as read
      const idx = threads.value.findIndex(t => t.id === id)
      if (idx !== -1) {
        threads.value[idx].unread_count = 0
      }
    } catch {
      selectedThread.value = null
      selectedThreadMessages.value = []
    }
  }

  async function bulkAction(action, label = null) {
    const ids = [...selectedIds.value]
    if (ids.length === 0) return

    try {
      await $platformApi('/email/inbox/bulk', {
        method: 'POST',
        body: { ids, action, label },
      })
      deselectAll()
      await fetchThreads(pagination.value.page)
    } catch {
      // toast handled by caller
    }
  }

  async function compose(data) {
    return await $platformApi('/email/inbox/compose', {
      method: 'POST',
      body: data,
    })
  }

  async function reply(threadId, body, attachmentIds = []) {
    return await $platformApi(`/email/inbox/${threadId}/reply`, {
      method: 'POST',
      body: { body, attachment_ids: attachmentIds },
    })
  }

  async function uploadAttachment(file) {
    const formData = new FormData()
    formData.append('file', file)

    return await $platformApi('/email/inbox/attachments', {
      method: 'POST',
      body: formData,
      rawBody: true,
    })
  }

  // ── Drafts ──────────────────────────────────────────

  async function saveDraft(data) {
    return await $platformApi('/email/inbox/draft', {
      method: 'POST',
      body: data,
    })
  }

  async function updateDraft(draftId, data) {
    return await $platformApi(`/email/inbox/draft/${draftId}`, {
      method: 'PUT',
      body: data,
    })
  }

  async function sendDraft(draftId, data) {
    return await $platformApi(`/email/inbox/draft/${draftId}/send`, {
      method: 'POST',
      body: data,
    })
  }

  async function deleteDraft(draftId) {
    return await $platformApi(`/email/inbox/draft/${draftId}`, {
      method: 'DELETE',
    })
  }

  // ── Contacts ──────────────────────────────────────

  async function searchContacts(query) {
    return await $platformApi(`/email/contacts?q=${encodeURIComponent(query)}`)
  }

  async function fetchNow() {
    isSyncing.value = true
    try {
      const data = await $platformApi('/email/inbox/fetch-now', { method: 'POST' })
      if (data.count > 0) {
        await fetchThreads(pagination.value.page)
      }

      return data
    } finally {
      isSyncing.value = false
    }
  }

  // ── Selection ──────────────────────────────────────

  function toggleSelect(id) {
    const set = new Set(selectedIds.value)
    if (set.has(id)) set.delete(id)
    else set.add(id)
    selectedIds.value = set
  }

  function selectAll() {
    selectedIds.value = new Set(threads.value.map(t => t.id))
  }

  function deselectAll() {
    selectedIds.value = new Set()
  }

  function toggleSelectAll() {
    if (isAllSelected.value) deselectAll()
    else selectAll()
  }

  // ── Navigation ─────────────────────────────────────

  function openThread(thread) {
    fetchThread(thread.id)
  }

  function closeThread() {
    selectedThread.value = null
    selectedThreadMessages.value = []
  }

  function navigateThread(direction) {
    const idx = threads.value.findIndex(t => t.id === selectedThread.value?.id)
    if (idx === -1) return

    const nextIdx = direction === 'previous' ? idx - 1 : idx + 1
    if (nextIdx >= 0 && nextIdx < threads.value.length) {
      openThread(threads.value[nextIdx])
    }
  }

  async function toggleStar(id) {
    const thread = threads.value.find(t => t.id === id)
    if (!thread) return

    const action = thread.is_starred ? 'unstar' : 'star'
    try {
      await $platformApi('/email/inbox/bulk', {
        method: 'POST',
        body: { ids: [id], action },
      })
      thread.is_starred = !thread.is_starred
      // Update folder counts
      folderCounts.value.starred += thread.is_starred ? 1 : -1
    } catch {
      // silent
    }
  }

  // ── Folder change ──────────────────────────────────

  function changeFolder(folder) {
    currentFolder.value = folder
    currentLabel.value = null
    closeThread()
    deselectAll()
  }

  function changeLabel(label) {
    currentLabel.value = label
    currentFolder.value = 'inbox' // reset folder when filtering by label
    closeThread()
    deselectAll()
  }

  // ── Helpers ────────────────────────────────────────

  function resolveLabelColor(label) {
    return LABELS.find(l => l.title === label)?.color || 'default'
  }

  function formatTime(dateStr) {
    if (!dateStr) return ''
    const date = new Date(dateStr)
    const now = new Date()
    const diffMs = now - date
    const diffDays = Math.floor(diffMs / 86400000)
    const loc = locale.value || 'fr-FR'

    if (diffDays === 0) return date.toLocaleTimeString(loc, { hour: '2-digit', minute: '2-digit' })
    if (diffDays === 1) return t('emailInbox.yesterday')
    if (diffDays < 7) return date.toLocaleDateString(loc, { weekday: 'short' })

    return date.toLocaleDateString(loc, { day: '2-digit', month: 'short' })
  }

  // ── Watchers ───────────────────────────────────────

  // Folder/label → fetch immédiat
  watch([currentFolder, currentLabel], () => {
    pagination.value.page = 1
    fetchThreads(1)
  })

  // Search → debounce 400ms
  let searchTimeout = null

  watch(searchQuery, () => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
      pagination.value.page = 1
      fetchThreads(1)
    }, 400)
  })

  // ── Polling + SSE ──────────────────────────────────

  let pollInterval = null

  function startPolling() {
    pollInterval = setInterval(() => fetchThreads(pagination.value.page), 30000)
  }

  function stopPolling() {
    if (pollInterval) {
      clearInterval(pollInterval)
      pollInterval = null
    }
  }

  // SSE subscription — auto-refresh on email.updated
  useRealtimeSubscription('email.updated', () => {
    fetchThreads(pagination.value.page)
  })

  // Lifecycle
  onMounted(() => {
    fetchThreads()
    startPolling()
  })

  onUnmounted(() => {
    stopPolling()
  })

  return {
    // Constants
    LABELS,
    FOLDERS,

    // State
    threads,
    selectedThread,
    selectedThreadMessages,
    selectedIds,
    currentFolder,
    currentLabel,
    searchQuery,
    folderCounts,
    isLoading,
    isSyncing,
    pagination,

    // Computed
    isAllSelected,
    isIndeterminate,
    hasSelection,
    threadMeta,

    // API
    fetchThreads,
    fetchThread,
    bulkAction,
    compose,
    reply,
    uploadAttachment,
    fetchNow,
    saveDraft,
    updateDraft,
    sendDraft,
    deleteDraft,
    searchContacts,

    // Selection
    toggleSelect,
    selectAll,
    deselectAll,
    toggleSelectAll,

    // Navigation
    openThread,
    closeThread,
    navigateThread,
    toggleStar,
    changeFolder,
    changeLabel,

    // Helpers
    resolveLabelColor,
    formatTime,
  }
}
