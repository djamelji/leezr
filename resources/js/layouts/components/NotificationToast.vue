<script setup>
import { useNotificationStore } from '@/core/stores/notification'

const { t } = useI18n()
const router = useRouter()
const store = useNotificationStore()

const toastRef = ref(null)
const containerRef = ref(null)
const isFlying = ref(false)

const currentToast = computed(() => store._toastQueue[0] ?? null)

const severityColors = {
  info: 'info',
  success: 'success',
  warning: 'warning',
  error: 'error',
}

const flyToBell = () => {
  const toast = currentToast.value
  if (isFlying.value || !toast) return
  isFlying.value = true

  const toastId = toast._toastId
  const el = toastRef.value
  const bell = document.getElementById('notification-btn')

  // Unlock overflow so the card can fly toward the bell
  if (containerRef.value) containerRef.value.style.overflow = 'visible'

  if (el && bell) {
    const toastRect = el.getBoundingClientRect()
    const bellRect = bell.getBoundingClientRect()

    const targetX = bellRect.left + bellRect.width / 2
    const targetY = bellRect.top + bellRect.height / 2
    const originX = toastRect.right - 24
    const originY = toastRect.top + toastRect.height / 2

    const dx = targetX - originX
    const dy = targetY - originY

    el.style.transformOrigin = 'right center'

    void el.offsetHeight

    el.style.transition = 'transform 0.45s cubic-bezier(0.6, 0, 1, 0.4), opacity 0.45s cubic-bezier(0.6, 0, 1, 0.4)'
    el.style.transform = `translate(${dx}px, ${dy}px) scale(0)`
    el.style.opacity = '0'

    setTimeout(() => {
      store._dismissToast(toastId)
      isFlying.value = false
      if (containerRef.value) containerRef.value.style.overflow = ''

      if (bell) {
        bell.classList.add('bell-pulse')
        setTimeout(() => bell.classList.remove('bell-pulse'), 600)
      }
    }, 470)
  }
  else {
    store._dismissToast(toastId)
    isFlying.value = false
    if (containerRef.value) containerRef.value.style.overflow = ''
  }
}

const handleClick = () => {
  flyToBell()

  const route = store._platformMode
    ? { name: 'platform-notifications' }
    : { name: 'company-notifications' }

  setTimeout(() => router.push(route), 400)
}

// Auto-dismiss after 6s
watch(currentToast, toast => {
  if (toast && !toast._timerSet) {
    toast._timerSet = true
    setTimeout(() => flyToBell(), 6000)
  }
})
</script>

<template>
  <div
    ref="containerRef"
    class="notification-toast-container"
  >
    <Transition
      name="toast"
      appear
    >
      <div
        v-if="currentToast"
        :key="currentToast._toastId"
        ref="toastRef"
        class="notification-toast"
        @click="handleClick"
      >
        <div class="d-flex align-center gap-3 notification-toast-inner">
          <VAvatar
            :color="currentToast._count > 1 ? 'info' : (severityColors[currentToast.severity] || 'info')"
            variant="tonal"
            size="30"
          >
            <VIcon
              :icon="currentToast._count > 1 ? 'tabler-bell' : (currentToast.icon || 'tabler-bell')"
              size="16"
            />
          </VAvatar>

          <div class="flex-grow-1 overflow-hidden">
            <p class="text-body-2 font-weight-medium mb-0 text-truncate">
              {{ currentToast._count > 1 ? t('notifications.newCount', { n: currentToast._count }) : currentToast.title }}
            </p>
            <p
              v-if="currentToast._count === 1 && currentToast.body"
              class="text-caption text-medium-emphasis mb-0 text-truncate"
            >
              {{ currentToast.body }}
            </p>
          </div>

          <IconBtn
            size="x-small"
            class="flex-shrink-0"
            @click.stop="flyToBell"
          >
            <VIcon
              icon="tabler-x"
              size="14"
            />
          </IconBtn>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style lang="scss" scoped>
.notification-toast-container {
  position: absolute;
  z-index: 1;
  overflow: hidden;
  inline-size: 380px;
  max-inline-size: 50vw;
  block-size: 100%;
  inset-block-start: 0;
  inset-inline-end: 0;
  pointer-events: none;
}

.notification-toast {
  pointer-events: auto;
  cursor: pointer;
  block-size: 100%;
  overflow: hidden;
  background: rgb(var(--v-theme-surface));
  will-change: transform, opacity;
}

.notification-toast-inner {
  block-size: 100%;
  padding-inline: 16px;
}

// Slide in from the right edge (clipped by overflow:hidden)
.toast-enter-active {
  animation: toast-slide-in 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes toast-slide-in {
  0% {
    transform: translateX(100%);
  }

  100% {
    transform: translateX(0);
  }
}
</style>

<style lang="scss">
// Bell pulse after toast absorption (global)
@keyframes bell-pulse {
  0% {
    transform: scale(1);
  }

  25% {
    transform: scale(1.35);
  }

  50% {
    transform: scale(0.9);
  }

  75% {
    transform: scale(1.15);
  }

  100% {
    transform: scale(1);
  }
}

#notification-btn.bell-pulse {
  animation: bell-pulse 0.6s ease;
}
</style>
