<script setup>
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'
import { useNotificationStore } from '@/core/stores/notification'

const { t } = useI18n()
const router = useRouter()
const store = useNotificationStore()
const isMenuOpen = ref(false)

// ADR-347: Eagerly fetch recent when boot reveals unread notifications,
// so the badge dot reflects existing unread state immediately.
function maybeFetchRecent() {
  if (store.unreadCount > 0 && store._notifications.length === 0) {
    store.fetchRecent()
  }
}

if (store._loaded) {
  maybeFetchRecent()
}
else {
  watchOnce(() => store._loaded, () => maybeFetchRecent())
}

// React to polling-driven unreadCount changes (platform has no SSE)
watch(
  () => store.unreadCount,
  count => {
    if (count > 0 && store._notifications.length === 0 && !store._loading) {
      store.fetchRecent()
    }
  },
)

// Map store notifications to display format
const mappedNotifications = computed(() =>
  store.recentNotifications.map(n => ({
    id: n.id,
    title: n.title,
    subtitle: n.body || '',
    time: timeAgo(n.created_at),
    isSeen: !!n.read_at,
    icon: n.icon || 'tabler-bell',
    color: n.severity || 'info',
    link: n.link,
  })),
)

const totalUnseen = computed(() =>
  mappedNotifications.value.filter(n => !n.isSeen).length,
)

// Badge dot only shows after toasts have flown into the bell
const hasUnseen = computed(() => store.badgeUnreadCount > 0)

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

  return date.toLocaleDateString('fr-FR')
}

const handleNotificationClick = notification => {
  store.markRead(notification.id)
  isMenuOpen.value = false

  if (notification.link) {
    router.push(notification.link)
  }
}

const toggleReadUnread = (isSeen, id) => {
  if (isSeen) {
    const n = store._notifications.find(n => n.id === id)
    if (n) {
      n.read_at = null
      store._unreadCount++
    }
  }
  else {
    store.markRead(id)
  }
}

const markAllReadOrUnread = () => {
  if (hasUnseen.value) {
    store.markAllRead()
  }
  else {
    // Mark all as unread (local only)
    store._notifications.forEach(n => {
      if (n.read_at) {
        n.read_at = null
        store._unreadCount++
      }
    })
  }
}

const handleRemove = id => {
  store.dismiss(id)
}

const viewAll = () => {
  isMenuOpen.value = false

  const route = store._platformMode
    ? { name: 'platform-notifications' }
    : { name: 'company-notifications' }

  router.push(route)
}
</script>

<template>
  <IconBtn id="notification-btn">
    <VBadge
      :model-value="hasUnseen"
      color="error"
      dot
      offset-x="2"
      offset-y="3"
    >
      <VIcon icon="tabler-bell" />
    </VBadge>

    <VMenu
      v-model="isMenuOpen"
      activator="parent"
      width="380px"
      location="bottom end"
      offset="12px"
      :close-on-content-click="false"
    >
      <VCard class="d-flex flex-column">
        <!-- Header -->
        <VCardItem class="notification-section">
          <VCardTitle class="text-h6">
            {{ t('notifications.title') }}
          </VCardTitle>

          <template #append>
            <VChip
              v-show="hasUnseen"
              size="small"
              color="primary"
              class="me-2"
            >
              {{ totalUnseen }} {{ t('notifications.new') }}
            </VChip>
            <IconBtn
              v-show="mappedNotifications.length"
              size="34"
              @click="markAllReadOrUnread"
            >
              <VIcon
                size="20"
                color="high-emphasis"
                :icon="hasUnseen ? 'tabler-mail-opened' : 'tabler-mail'"
              />

              <VTooltip
                activator="parent"
                location="start"
              >
                {{ hasUnseen ? t('notifications.markAllRead') : t('notifications.markAllUnread') }}
              </VTooltip>
            </IconBtn>
          </template>
        </VCardItem>

        <VDivider />

        <!-- Notifications list -->
        <PerfectScrollbar
          :options="{ wheelPropagation: false }"
          style="max-block-size: 23.75rem;"
        >
          <VList class="notification-list rounded-0 py-0">
            <template
              v-for="(notification, index) in mappedNotifications"
              :key="notification.id"
            >
              <VDivider v-if="index > 0" />
              <VListItem
                link
                lines="one"
                min-height="66px"
                class="list-item-hover-class"
                @click="handleNotificationClick(notification)"
              >
                <div class="d-flex align-start gap-3">
                  <VAvatar
                    :color="notification.color"
                    variant="tonal"
                  >
                    <VIcon :icon="notification.icon" />
                  </VAvatar>

                  <div>
                    <p class="text-sm font-weight-medium mb-1">
                      {{ notification.title }}
                    </p>
                    <p
                      class="text-body-2 mb-2"
                      style="letter-spacing: 0.4px !important; line-height: 18px;"
                    >
                      {{ notification.subtitle }}
                    </p>
                    <p
                      class="text-sm text-disabled mb-0"
                      style="letter-spacing: 0.4px !important; line-height: 18px;"
                    >
                      {{ notification.time }}
                    </p>
                  </div>
                  <VSpacer />

                  <div class="d-flex flex-column align-end">
                    <VIcon
                      size="10"
                      icon="tabler-circle-filled"
                      :color="!notification.isSeen ? 'primary' : '#a8aaae'"
                      :class="notification.isSeen ? 'visible-in-hover' : ''"
                      class="mb-2"
                      @click.stop="toggleReadUnread(notification.isSeen, notification.id)"
                    />

                    <VIcon
                      size="20"
                      icon="tabler-x"
                      class="visible-in-hover"
                      @click.stop="handleRemove(notification.id)"
                    />
                  </div>
                </div>
              </VListItem>
            </template>

            <VListItem
              v-show="!mappedNotifications.length"
              class="text-center text-medium-emphasis"
              style="block-size: 56px;"
            >
              <VListItemTitle>{{ t('notifications.noNotifications') }}</VListItemTitle>
            </VListItem>
          </VList>
        </PerfectScrollbar>

        <VDivider />

        <!-- Footer -->
        <VCardText class="pa-4">
          <VBtn
            block
            size="small"
            @click="viewAll"
          >
            {{ t('notifications.viewAll') }}
          </VBtn>
        </VCardText>
      </VCard>
    </VMenu>
  </IconBtn>
</template>

<style lang="scss">
.notification-section {
  padding-block: 0.75rem;
  padding-inline: 1rem;
}

.list-item-hover-class {
  .visible-in-hover {
    display: none;
  }

  &:hover {
    .visible-in-hover {
      display: block;
    }
  }
}

.notification-list.v-list {
  .v-list-item {
    border-radius: 0 !important;
    margin: 0 !important;
    padding-block: 0.75rem !important;
  }
}
</style>
