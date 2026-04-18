<script setup>
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'
import { useEmailInbox } from '@/composables/useEmailInbox'
import EmailLeftSidebar from './_EmailLeftSidebar.vue'
import EmailView from './_EmailView.vue'
import EmailCompose from './_EmailCompose.vue'

const { t } = useI18n()
const { toast } = useAppToast()

const {
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
  toggleSelectAll,
  deselectAll,

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
} = useEmailInbox()

const showCompose = ref(false)
const composePrefill = ref(null)
const isLeftSidebarOpen = ref(true)

// Event handlers
const handleBulkAction = async action => {
  const count = selectedIds.value.size
  await bulkAction(action)
  toast(t('emailInbox.bulkSuccess', { count }), 'success')
}

const handleBulkLabel = async label => {
  const count = selectedIds.value.size
  await bulkAction('label', label)
  toast(t('emailInbox.bulkSuccess', { count }), 'success')
}

const handleRefresh = async () => {
  const data = await fetchNow()
  if (data?.count > 0) {
    toast(t('emailInbox.syncSuccess', { count: data.count }), 'success')
  }
}

const handleComposeSent = async data => {
  try {
    await compose(data)
    toast(t('emailInbox.composeSent'), 'success')
    composePrefill.value = null
    await fetchThreads(pagination.value.page)
  } catch {
    toast(t('emailInbox.compose.error'), 'error')
  }
}

const handleForward = data => {
  composePrefill.value = data
  showCompose.value = true
}

const handleReplySent = async payload => {
  if (!selectedThread.value) return

  // Support both string (backward compat) and object { body, attachment_ids }
  const body = typeof payload === 'string' ? payload : payload.body
  const attachmentIds = typeof payload === 'string' ? [] : (payload.attachment_ids || [])

  try {
    await reply(selectedThread.value.id, body, attachmentIds)
    toast(t('emailInbox.replySent'), 'success')
    const { $platformApi } = await import('@/utils/platformApi')
    const data = await $platformApi(`/email/inbox/${selectedThread.value.id}`)
    selectedThread.value = data.thread
    selectedThreadMessages.value = data.messages
  } catch {
    toast(t('emailInbox.replyError'), 'error')
  }
}

const handleUploadAttachment = async (file, callback) => {
  try {
    const result = await uploadAttachment(file)

    callback({
      id: result.id,
      name: result.original_filename,
      size: result.human_size,
    })
  } catch {
    toast(t('emailInbox.compose.error'), 'error')
  }
}

const handleThreadTrash = async () => {
  if (!selectedThread.value) return
  selectedIds.value = new Set([selectedThread.value.id])
  await bulkAction('trash')
  closeThread()
}

const handleThreadStar = () => {
  if (selectedThread.value) toggleStar(selectedThread.value.id)
}

const handleThreadUnstar = () => {
  if (selectedThread.value) toggleStar(selectedThread.value.id)
}

const handleThreadUnread = async () => {
  if (!selectedThread.value) return
  selectedIds.value = new Set([selectedThread.value.id])
  await bulkAction('unread')
  closeThread()
}

const handleThreadMoveTo = async folder => {
  if (!selectedThread.value) return
  selectedIds.value = new Set([selectedThread.value.id])
  await bulkAction(folder)
  closeThread()
}

const handleThreadLabel = async label => {
  if (!selectedThread.value) return
  selectedIds.value = new Set([selectedThread.value.id])
  await bulkAction('label', label)
}

const selectAllModel = computed({
  get: () => isAllSelected.value,
  set: () => toggleSelectAll(),
})
</script>

<template>
  <div
    class="email-app-layout"
    :style="{ height: $vuetify.display.mdAndUp ? 'calc(100vh - 200px)' : 'calc(100vh - 220px)', minHeight: '500px' }"
  >
    <!-- Left sidebar -->
    <div
      class="email-left-sidebar h-100"
      :class="$vuetify.display.mdAndUp ? 'd-block' : 'd-none'"
    >
      <EmailLeftSidebar
        :folder-counts="folderCounts"
        :current-folder="currentFolder"
        :current-label="currentLabel"
        :folders="FOLDERS"
        :labels="LABELS"
        @update:current-folder="changeFolder"
        @update:current-label="changeLabel"
        @compose="showCompose = true"
      />
    </div>

    <!-- Center: thread list -->
    <div class="email-content-area h-100 d-flex flex-column">
      <!-- Top action bar -->
      <div class="d-flex align-center gap-2 px-4 py-2" style="min-height: 56px;">
        <!-- Mobile: hamburger -->
        <IconBtn
          v-if="$vuetify.display.smAndDown"
          @click="isLeftSidebarOpen = !isLeftSidebarOpen"
        >
          <VIcon icon="tabler-menu-2" />
        </IconBtn>

        <VTextField
          v-model="searchQuery"
          density="default"
          :placeholder="t('emailInbox.searchPlaceholder')"
          class="email-search flex-grow-1"
        >
          <template #prepend-inner>
            <VIcon
              icon="tabler-search"
              size="24"
              class="me-1 text-medium-emphasis"
            />
          </template>
        </VTextField>

        <IconBtn
          :loading="isSyncing"
          @click="handleRefresh"
        >
          <VIcon
            icon="tabler-refresh"
            size="22"
          />
          <VTooltip
            activator="parent"
            location="top"
          >
            {{ t('emailInbox.refresh') }}
          </VTooltip>
        </IconBtn>

        <IconBtn>
          <VIcon
            icon="tabler-dots-vertical"
            size="22"
          />
        </IconBtn>
      </div>

      <VDivider />

      <!-- Action bar: select all + bulk actions -->
      <div class="py-2 px-4 d-flex align-center gap-x-1">
        <VCheckbox
          :model-value="selectAllModel"
          :indeterminate="isIndeterminate"
          density="compact"
          hide-details
          class="flex-shrink-0"
          @update:model-value="toggleSelectAll"
        />

        <div
          class="w-100 d-flex align-center action-bar-actions gap-x-1"
          :style="{ visibility: hasSelection ? undefined : 'hidden' }"
        >
          <span class="text-body-2 text-disabled me-2">
            {{ selectedIds.size }}
          </span>

          <IconBtn @click="handleBulkAction('trash')">
            <VIcon
              icon="tabler-trash"
              size="22"
            />
            <VTooltip
              activator="parent"
              location="top"
            >
              {{ t('emailInbox.moveToTrash') }}
            </VTooltip>
          </IconBtn>

          <IconBtn @click="handleBulkAction('read')">
            <VIcon
              icon="tabler-mail-opened"
              size="22"
            />
            <VTooltip
              activator="parent"
              location="top"
            >
              {{ t('emailInbox.markRead') }}
            </VTooltip>
          </IconBtn>

          <IconBtn @click="handleBulkAction('star')">
            <VIcon
              icon="tabler-star"
              size="22"
            />
            <VTooltip
              activator="parent"
              location="top"
            >
              {{ t('emailInbox.star') }}
            </VTooltip>
          </IconBtn>

          <!-- Move to menu -->
          <IconBtn>
            <VIcon
              icon="tabler-folder"
              size="22"
            />
            <VMenu activator="parent">
              <VList density="compact">
                <VListItem @click="handleBulkAction('inbox')">
                  <template #prepend>
                    <VIcon
                      icon="tabler-mail"
                      class="me-2"
                      size="20"
                    />
                  </template>
                  <VListItemTitle>{{ t('emailInbox.folders.inbox') }}</VListItemTitle>
                </VListItem>
                <VListItem @click="handleBulkAction('spam')">
                  <template #prepend>
                    <VIcon
                      icon="tabler-alert-octagon"
                      class="me-2"
                      size="20"
                    />
                  </template>
                  <VListItemTitle>{{ t('emailInbox.folders.spam') }}</VListItemTitle>
                </VListItem>
                <VListItem @click="handleBulkAction('trash')">
                  <template #prepend>
                    <VIcon
                      icon="tabler-trash"
                      class="me-2"
                      size="20"
                    />
                  </template>
                  <VListItemTitle>{{ t('emailInbox.folders.trash') }}</VListItemTitle>
                </VListItem>
              </VList>
            </VMenu>
          </IconBtn>

          <!-- Label menu -->
          <IconBtn>
            <VIcon
              icon="tabler-tag"
              size="22"
            />
            <VMenu activator="parent">
              <VList density="compact">
                <VListItem
                  v-for="label in LABELS"
                  :key="label.title"
                  @click.stop="handleBulkLabel(label.title)"
                >
                  <template #prepend>
                    <VBadge
                      inline
                      :color="label.color"
                      dot
                    />
                  </template>
                  <VListItemTitle class="ms-2 text-capitalize">
                    {{ label.title }}
                  </VListItemTitle>
                </VListItem>
              </VList>
            </VMenu>
          </IconBtn>
        </div>
      </div>

      <VDivider />

      <!-- Thread list -->
      <PerfectScrollbar
        tag="ul"
        :options="{ wheelPropagation: false }"
        class="email-list flex-grow-1"
      >
        <!-- Loading -->
        <li
          v-if="isLoading && threads.length === 0"
          class="d-flex justify-center align-center pa-8"
        >
          <VProgressCircular indeterminate />
        </li>

        <!-- Empty state -->
        <li
          v-else-if="!isLoading && threads.length === 0"
          class="py-4 px-5 text-center"
        >
          <VIcon
            icon="tabler-mail-off"
            size="48"
            class="text-disabled mb-2"
          />
          <div class="text-h6 text-disabled">
            {{ t('emailInbox.empty') }}
          </div>
          <div class="text-body-2 text-disabled mt-1">
            {{ t('emailInbox.emptyDescription') }}
          </div>
        </li>

        <!-- Threads -->
        <template v-else>
          <li
            v-for="thread in threads"
            :key="thread.id"
            class="email-item d-flex align-center pa-4 gap-2 cursor-pointer"
            :class="[{ 'email-read': thread.unread_count === 0 }]"
            @click="openThread(thread)"
          >
            <VCheckbox
              :model-value="selectedIds.has(thread.id)"
              class="flex-shrink-0"
              @click.stop
              @update:model-value="toggleSelect(thread.id)"
            />

            <IconBtn
              :color="thread.is_starred ? 'warning' : 'default'"
              @click.stop="toggleStar(thread.id)"
            >
              <VIcon
                :icon="thread.is_starred ? 'tabler-star-filled' : 'tabler-star'"
                size="22"
              />
            </IconBtn>

            <div class="d-flex flex-column flex-grow-1 overflow-hidden">
              <div class="d-flex align-center gap-1">
                <h6
                  class="text-h6 text-truncate"
                  :class="{ 'font-weight-regular': thread.unread_count === 0 }"
                >
                  {{ thread.folder === 'sent' ? t('emailInbox.toPrefix') : '' }}{{ thread.participant_name || thread.participant_email }}
                </h6>
                <VChip
                  v-if="thread.last_message?.direction === 'sent'"
                  size="x-small"
                  color="info"
                  variant="tonal"
                  label
                  class="flex-shrink-0"
                >
                  <VIcon icon="tabler-send" size="10" class="me-1" />
                  {{ t('emailInbox.sent') }}
                </VChip>
              </div>
              <span class="text-body-2 text-truncate">{{ thread.subject }}</span>
            </div>

            <VSpacer />

            <div class="email-meta d-flex align-center gap-2">
              <VIcon
                v-for="label in (thread.labels || [])"
                :key="label"
                icon="tabler-circle-filled"
                size="10"
                :color="resolveLabelColor(label)"
              />

              <VChip
                v-if="thread.unread_count > 0"
                color="primary"
                size="x-small"
                label
              >
                {{ thread.unread_count }}
              </VChip>

              <span class="text-sm text-disabled">
                {{ formatTime(thread.last_message_at) }}
              </span>
            </div>

            <div class="email-actions d-none">
              <IconBtn @click.stop="toggleStar(thread.id)">
                <VIcon
                  :icon="thread.is_starred ? 'tabler-star-filled' : 'tabler-star'"
                  size="22"
                />
              </IconBtn>
              <IconBtn @click.stop="selectedIds = new Set([thread.id]); handleBulkAction('trash')">
                <VIcon
                  icon="tabler-trash"
                  size="22"
                />
              </IconBtn>
              <IconBtn @click.stop="selectedIds = new Set([thread.id]); handleBulkAction('read')">
                <VIcon
                  icon="tabler-mail-opened"
                  size="22"
                />
              </IconBtn>
            </div>
          </li>
        </template>
      </PerfectScrollbar>

      <!-- Pagination -->
      <div
        v-if="pagination.lastPage > 1"
        class="d-flex justify-center pa-4"
      >
        <VPagination
          :model-value="pagination.page"
          :length="pagination.lastPage"
          :total-visible="5"
          density="compact"
          @update:model-value="fetchThreads"
        />
      </div>
    </div>

    <!-- Right drawer: thread detail (only in DOM when needed) -->
    <EmailView
      v-if="selectedThread"
      :thread="selectedThread"
      :messages="selectedThreadMessages"
      :thread-meta="threadMeta"
      :labels="LABELS"
      @close="closeThread"
      @refresh="fetchThreads(pagination.page)"
      @navigated="navigateThread"
      @trash="handleThreadTrash"
      @star="handleThreadStar"
      @unstar="handleThreadUnstar"
      @unread="handleThreadUnread"
      @move-to="handleThreadMoveTo"
      @label="handleThreadLabel"
      @reply-sent="handleReplySent"
      @upload-attachment="handleUploadAttachment"
      @forward="handleForward"
    />

    <!-- Compose dialog -->
    <EmailCompose
      v-model="showCompose"
      :prefill="composePrefill"
      @sent="handleComposeSent"
    />
  </div>
</template>

<style lang="scss">
@use "@styles/variables/vuetify";
@use "@core-scss/base/mixins";

.email-app-layout {
  position: relative;
  display: flex;
  overflow: hidden;
  border-radius: vuetify.$card-border-radius;

  @include mixins.elevation(vuetify.$card-elevation);

  $sel-email-app-layout: &;

  @at-root {
    .skin--bordered {
      @include mixins.bordered-skin($sel-email-app-layout);
    }
  }
}

.email-left-sidebar {
  flex-shrink: 0;
  inline-size: 260px;
  border-inline-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgb(var(--v-theme-surface));
}

.email-content-area {
  position: relative;
  flex-grow: 1;
  min-inline-size: 0;
}

// Remove border from search field (Vuexy pattern)
.email-search {
  .v-field__outline {
    display: none;
  }

  .v-field__field {
    .v-field__input {
      font-size: 0.9375rem !important;
      line-height: 1.375rem !important;
    }
  }
}

.email-list {
  white-space: nowrap;
  list-style: none;
  padding: 0;
  margin: 0;

  .email-item {
    block-size: 4.375rem;
    transition: all 0.2s ease-in-out;
    will-change: transform, box-shadow;

    &.email-read {
      background-color: rgba(var(--v-theme-on-surface), var(--v-hover-opacity));
    }

    & + .email-item {
      border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    }
  }

  .email-item .email-meta {
    display: flex;
  }

  .email-item:hover {
    transform: translateY(-2px);

    @include mixins.elevation(4);

    @media screen and (min-width: 1280px) {
      .email-actions {
        display: block !important;
      }

      .email-meta {
        display: none;
      }
    }

    + .email-item {
      border-color: transparent;
    }
  }
}
</style>
