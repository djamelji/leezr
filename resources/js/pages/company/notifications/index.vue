<script setup>
import { useNotificationStore } from '@/core/stores/notification'
import NotificationPreferences from './_NotificationPreferences.vue'

definePage({ meta: { module: 'core.notifications' } })

const { t } = useI18n()
const router = useRouter()
const store = useNotificationStore()

const currentTab = ref('inbox')
const currentPage = ref(1)
const activeCategory = ref(null)
const unreadOnly = ref(false)

const severityColors = {
  info: 'info',
  success: 'success',
  warning: 'warning',
  error: 'error',
}

// ADR-382: categories filtered by user permissions
const allCategoryDefs = {
  billing: { icon: 'tabler-receipt-2', titleKey: 'notifications.categoryBilling' },
  documents: { icon: 'tabler-file-certificate', titleKey: 'notifications.categoryDocuments' },
  members: { icon: 'tabler-users', titleKey: 'notifications.categoryMembers' },
  modules: { icon: 'tabler-puzzle', titleKey: 'notifications.categoryModules' },
  security: { icon: 'tabler-shield-lock', titleKey: 'notifications.categorySecurity' },
  support: { icon: 'tabler-headset', titleKey: 'notifications.categorySupport' },
}

const categories = computed(() => {
  const cats = [{ title: t('notifications.categoryAll'), value: null, icon: 'tabler-bell' }]

  for (const cat of store.availableCategories) {
    const def = allCategoryDefs[cat]
    if (def) cats.push({ title: t(def.titleKey), value: cat, icon: def.icon })
  }

  return cats
})

// Reset active category if permission changes
watch(() => store.availableCategories, newCats => {
  if (activeCategory.value && !newCats.includes(activeCategory.value))
    activeCategory.value = null
})

const fetchData = () => {
  const filters = {}
  if (activeCategory.value) filters.category = activeCategory.value
  if (unreadOnly.value) filters.unread_only = true
  store.fetchPage(currentPage.value, filters)
}

onMounted(fetchData)
watch([currentPage, activeCategory, unreadOnly], fetchData)

const handleClick = n => {
  store.markRead(n.id)
  if (n.link) router.push(n.link)
}

const toggleRead = n => {
  if (n.read_at) {
    n.read_at = null
    store._unreadCount++
  }
  else {
    store.markRead(n.id)
  }
}

function timeAgo(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now - date
  const diffMin = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)

  if (diffMin < 1) return t('notifications.timeJustNow')
  if (diffMin < 60) return t('notifications.timeMinAgo', { n: diffMin })
  if (diffHours < 24) return t('notifications.timeHourAgo', { n: diffHours })
  if (diffDays < 7) return t('notifications.timeDayAgo', { n: diffDays })

  return date.toLocaleDateString()
}
</script>

<template>
  <div>
    <VTabs v-model="currentTab">
      <VTab value="inbox">
        <VIcon
          icon="tabler-bell"
          class="me-2"
        />
        {{ t('notifications.inbox') }}
        <VChip
          v-if="store.unreadCount > 0"
          color="error"
          size="x-small"
          class="ms-2"
        >
          {{ store.unreadCount }}
        </VChip>
      </VTab>
      <VTab value="preferences">
        <VIcon
          icon="tabler-settings"
          class="me-2"
        />
        {{ t('notifications.preferences') }}
      </VTab>
    </VTabs>

    <VWindow v-model="currentTab">
      <!-- 👉 Tab: Inbox -->
      <VWindowItem value="inbox">
        <!-- Header Card -->
        <VCard class="mt-4 mb-4">
          <VCardItem>
            <template #prepend>
              <VAvatar
                color="primary"
                variant="tonal"
              >
                <VIcon icon="tabler-bell" />
              </VAvatar>
            </template>

            <VCardTitle>{{ t('notifications.notificationCenter') }}</VCardTitle>
            <VCardSubtitle>{{ t('notifications.notificationCenterDesc') }}</VCardSubtitle>

            <template #append>
              <VBtn
                v-if="store.unreadCount > 0"
                variant="tonal"
                size="small"
                @click="store.markAllRead()"
              >
                <VIcon
                  icon="tabler-mail-opened"
                  size="18"
                  class="me-1"
                />
                {{ t('notifications.markAllRead') }}
              </VBtn>
            </template>
          </VCardItem>

          <!-- Filters -->
          <VCardText class="pb-4">
            <div class="d-flex gap-2 flex-wrap">
              <VChip
                v-for="cat in categories"
                :key="cat.value ?? 'all'"
                :color="activeCategory === cat.value ? 'primary' : undefined"
                :variant="activeCategory === cat.value ? 'elevated' : 'outlined'"
                size="small"
                @click="activeCategory = cat.value; currentPage = 1"
              >
                <VIcon
                  :icon="cat.icon"
                  size="16"
                  class="me-1 d-sm-inline-flex"
                />
                <span class="d-none d-sm-inline">{{ cat.title }}</span>
              </VChip>
            </div>
          </VCardText>
        </VCard>

        <!-- Timeline Card -->
        <VCard>
          <VCardItem>
            <template #prepend>
              <VIcon
                icon="tabler-list-details"
                size="24"
                color="high-emphasis"
                class="me-1"
              />
            </template>

            <VCardTitle>
              {{ t('notifications.inbox') }}
              <VChip
                v-if="store.unreadCount > 0"
                color="error"
                size="small"
                class="ms-2"
              >
                {{ store.unreadCount }}
              </VChip>
            </VCardTitle>

            <template #append>
              <div class="d-flex align-center gap-2">
                <VSwitch
                  v-model="unreadOnly"
                  :label="t('notifications.unreadOnly')"
                  hide-details
                  density="compact"
                />
                <IconBtn @click="fetchData">
                  <VIcon icon="tabler-refresh" />
                </IconBtn>
              </div>
            </template>
          </VCardItem>

          <VCardText>
            <!-- Empty state -->
            <div
              v-if="store.notifications.length === 0 && !store.loading"
              class="text-center py-12"
            >
              <VIcon
                icon="tabler-bell-off"
                size="48"
                color="disabled"
                class="mb-4"
              />
              <h6 class="text-h6 text-disabled">
                {{ t('notifications.empty') }}
              </h6>
            </div>

            <!-- Loading -->
            <div
              v-else-if="store.loading"
              class="text-center py-12"
            >
              <VProgressCircular indeterminate />
            </div>

            <!-- 👉 Timeline -->
            <VTimeline
              v-else
              side="end"
              align="start"
              line-inset="8"
              truncate-line="start"
              density="compact"
            >
              <VTimelineItem
                v-for="n in store.notifications"
                :key="n.id"
                :dot-color="severityColors[n.severity] || 'info'"
                size="x-small"
              >
                <!-- 👉 Clickable block -->
                <div
                  class="notif-timeline-item cursor-pointer rounded pa-3 mb-1"
                  :class="{ 'notif-unread': !n.read_at }"
                  @click="handleClick(n)"
                >
                  <!-- Header -->
                  <div class="d-flex justify-space-between align-center gap-2 flex-wrap mb-1">
                    <span
                      class="app-timeline-title"
                      :class="{ 'font-weight-bold': !n.read_at }"
                    >
                      {{ n.title }}
                    </span>
                    <span class="app-timeline-meta">{{ timeAgo(n.created_at) }}</span>
                  </div>

                  <!-- Content -->
                  <p
                    v-if="n.body"
                    class="app-timeline-text mb-1"
                  >
                    {{ n.body }}
                  </p>

                  <!-- Badges -->
                  <div class="d-flex align-center gap-2 mt-2">
                    <VChip
                      v-if="!n.read_at"
                      color="primary"
                      size="x-small"
                      variant="flat"
                    >
                      {{ t('notifications.newSingle') }}
                    </VChip>

                    <VSpacer />

                    <IconBtn
                      size="x-small"
                      @click.stop="toggleRead(n)"
                    >
                      <VIcon
                        :icon="n.read_at ? 'tabler-mail' : 'tabler-mail-opened'"
                        size="16"
                      />
                      <VTooltip
                        activator="parent"
                        location="top"
                      >
                        {{ n.read_at ? t('notifications.markUnread') : t('notifications.markRead') }}
                      </VTooltip>
                    </IconBtn>

                    <IconBtn
                      size="x-small"
                      @click.stop="store.dismiss(n.id)"
                    >
                      <VIcon
                        icon="tabler-x"
                        size="16"
                      />
                      <VTooltip
                        activator="parent"
                        location="top"
                      >
                        {{ t('notifications.dismiss') }}
                      </VTooltip>
                    </IconBtn>
                  </div>
                </div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>

          <!-- Pagination -->
          <template v-if="store.pagination && store.pagination.lastPage > 1">
            <VDivider />
            <VCardActions class="justify-center">
              <VPagination
                v-model="currentPage"
                :length="store.pagination.lastPage"
                :total-visible="5"
                density="compact"
              />
            </VCardActions>
          </template>
        </VCard>
      </VWindowItem>

      <!-- 👉 Tab: Preferences -->
      <VWindowItem value="preferences">
        <NotificationPreferences class="mt-4" />
      </VWindowItem>
    </VWindow>
  </div>
</template>

<style lang="scss" scoped>
.notif-timeline-item {
  transition: background-color 0.15s ease;

  &:hover {
    background-color: rgba(var(--v-theme-on-surface), var(--v-hover-opacity));
  }

  &.notif-unread {
    background-color: rgba(var(--v-theme-primary), 0.04);

    &:hover {
      background-color: rgba(var(--v-theme-primary), 0.08);
    }
  }
}
</style>
