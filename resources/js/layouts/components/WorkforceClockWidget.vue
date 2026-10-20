<script setup>
/**
 * WorkforceClockWidget — compact navbar clock-in/out widget.
 *
 * Displays current clock status + worked time in the company navbar.
 * Dropdown menu provides clock-in, clock-out, break start/end actions.
 * Only renders when the user has an employee record (controlled by parent).
 */
import { useWorkforceClockStore } from '@/core/stores/workforce-clock'

const { t, locale } = useI18n()
const route = useRoute()
const store = useWorkforceClockStore()

// Don't render on platform pages (no /workforce/me API there)
const isPlatform = computed(() => !!route.meta?.platform)

const isMenuOpen = ref(false)

// The store derives worked time LIVE from the server clock_in via a shared 1s ticker — no
// local counter here (the old local ref reset to 0 on every refresh, the confirmed bug).
let refreshInterval = null

const displayTime = computed(() => {
  const mins = store.workedMinutesToday
  const h = Math.floor(mins / 60)
  const m = mins % 60

  return `${h}h${String(m).padStart(2, '0')}`
})

const breakDisplay = computed(() => {
  const mins = store.breakMinutesToday
  if (!mins) return null
  const h = Math.floor(mins / 60)
  const m = mins % 60

  return h > 0 ? `${h}h${String(m).padStart(2, '0')}` : `${m}min`
})

// mm:ss formatter for the live break countdown.
function fmtSeconds(s) {
  if (s === null || s === undefined) return '—'
  const m = Math.floor(s / 60)
  const sec = s % 60

  return `${m}:${String(sec).padStart(2, '0')}`
}
const breakElapsedLabel = computed(() => fmtSeconds(store.currentBreakSeconds))
const breakRemainingLabel = computed(() => fmtSeconds(store.breakRemainingSeconds))
const breakResumeLabel = computed(() => {
  const d = store.breakResumeAt
  if (!d) return null

  return d.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
})

const arrivalTime = computed(() => {
  const clockIn = store.todayClock?.clock_in
  if (!clockIn) return null
  const d = new Date(clockIn)

  return d.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
})

const statusColor = computed(() => {
  switch (store.clockStatus) {
    case 'working': return 'success'
    case 'on_break': return 'warning'
    case 'completed': return 'info'
    default: return 'secondary'
  }
})

const statusIcon = computed(() => {
  switch (store.clockStatus) {
    case 'working': return 'tabler-player-play'
    case 'on_break': return 'tabler-coffee'
    case 'completed': return 'tabler-circle-check'
    default: return 'tabler-clock-hour-4'
  }
})

const statusLabel = computed(() => {
  switch (store.clockStatus) {
    case 'working': return t('workforceClock.working')
    case 'on_break': return t('workforceClock.onBreak')
    case 'completed': return t('workforceClock.completed')
    default: return t('workforceClock.notStarted')
  }
})

onMounted(async () => {
  if (isPlatform.value) return
  await store.fetchProfile()

  // Reconcile with the server periodically (drift correction + break completion). The live
  // display between reconciliations is driven by the store's 1s ticker from clock_in.
  refreshInterval = setInterval(() => {
    if (store.isWorking || store.isOnBreak) store.fetchProfile()
  }, 60000)
})

onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval)
})

// Action handler with loading state
const actionLoading = ref(false)

async function handleAction(actionFn) {
  actionLoading.value = true
  try {
    await actionFn()
  }
  catch (e) {
    console.error('[workforce-clock] action failed:', e)
  }
  finally {
    actionLoading.value = false
  }
}
</script>

<template>
  <!-- Only render on company pages when employee record exists -->
  <template v-if="!isPlatform && store.employee">
    <IconBtn
      id="workforce-clock-btn"
      data-test="wfc-widget-btn"
    >
      <VBadge
        :color="statusColor"
        dot
        offset-x="2"
        offset-y="3"
      >
        <VIcon icon="tabler-clock-hour-4" />
      </VBadge>

      <VMenu
        v-model="isMenuOpen"
        activator="parent"
        width="320px"
        location="bottom end"
        offset="12px"
        :close-on-content-click="false"
      >
        <VCard class="d-flex flex-column">
          <!-- Header -->
          <VCardItem>
            <VCardTitle class="text-h6">
              {{ t('workforceClock.title') }}
            </VCardTitle>

            <template #append>
              <VChip
                :color="statusColor"
                size="small"
                variant="tonal"
              >
                <VIcon
                  :icon="statusIcon"
                  size="14"
                  start
                />
                {{ statusLabel }}
              </VChip>
            </template>
          </VCardItem>

          <VDivider />

          <!-- Time display -->
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-3">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  {{ t('workforceClock.workedToday') }}
                </div>
                <div
                  class="text-h4 font-weight-bold"
                  data-test="wfc-worked-time"
                >
                  {{ displayTime }}
                </div>
              </div>
              <VAvatar
                :color="statusColor"
                variant="tonal"
                size="48"
              >
                <VIcon
                  :icon="statusIcon"
                  size="24"
                />
              </VAvatar>
            </div>

            <!-- Arrival time -->
            <div
              v-if="arrivalTime"
              class="d-flex align-center gap-2 text-body-2 text-medium-emphasis mb-1"
            >
              <VIcon
                icon="tabler-login"
                size="16"
              />
              {{ t('workforceClock.arrival') }}: {{ arrivalTime }}
            </div>

            <!-- Break time (if any) -->
            <div
              v-if="breakDisplay"
              class="d-flex align-center gap-2 text-body-2 text-medium-emphasis"
            >
              <VIcon
                icon="tabler-coffee"
                size="16"
              />
              {{ t('workforceClock.breakTime') }}: {{ breakDisplay }}
            </div>
          </VCardText>

          <VDivider />

          <!-- Action buttons -->
          <VCardText class="d-flex flex-column gap-2">
            <!-- Not started: Clock In -->
            <VBtn
              v-if="store.clockStatus === 'not_started'"
              color="success"
              block
              :loading="actionLoading"
              prepend-icon="tabler-player-play"
              @click="handleAction(store.clockIn)"
              data-test="wfc-clock-in"
            >
              {{ t('workforceClock.clockIn') }}
            </VBtn>

            <!-- Working: Break + Clock Out -->
            <template v-else-if="store.isWorking">
              <VBtn
                color="warning"
                variant="tonal"
                block
                :loading="actionLoading"
                prepend-icon="tabler-coffee"
                @click="handleAction(store.startBreak)"
                data-test="wfc-start-break"
              >
                {{ t('workforceClock.startBreak') }}
              </VBtn>
              <VBtn
                color="error"
                variant="tonal"
                block
                :loading="actionLoading"
                prepend-icon="tabler-player-stop"
                @click="handleAction(store.clockOut)"
              >
                {{ t('workforceClock.clockOut') }}
              </VBtn>
            </template>

            <!-- On break: countdown + resume -->
            <template v-else-if="store.isOnBreak">
              <div
                class="rounded pa-3 mb-1"
                :class="store.breakTargetReached ? 'bg-warning-subtle' : 'bg-surface'"
                style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));"
                data-test="wfc-break-panel"
              >
                <div class="d-flex justify-space-between text-body-2 text-medium-emphasis">
                  <span>{{ t('workforceClock.breakElapsed') }}</span>
                  <span class="font-weight-bold text-high-emphasis" data-test="wfc-break-elapsed">{{ breakElapsedLabel }}</span>
                </div>

                <template v-if="store.hasBreakTarget">
                  <div class="d-flex justify-space-between text-body-2 text-medium-emphasis mt-1">
                    <span>{{ t('workforceClock.breakTarget') }}</span>
                    <span>{{ fmtSeconds(store.breakTargetSeconds) }}</span>
                  </div>
                  <div class="d-flex justify-space-between text-body-2 mt-1">
                    <span class="text-medium-emphasis">{{ store.breakTargetReached ? t('workforceClock.breakOvertime') : t('workforceClock.breakRemaining') }}</span>
                    <span
                      class="font-weight-bold"
                      :class="store.breakTargetReached ? 'text-warning' : 'text-success'"
                      data-test="wfc-break-remaining"
                    >
                      {{ store.breakTargetReached ? '+' + fmtSeconds(store.breakOvershootSeconds) : breakRemainingLabel }}
                    </span>
                  </div>
                  <VProgressLinear
                    :model-value="store.breakProgress"
                    :color="store.breakTargetReached ? 'warning' : 'primary'"
                    height="6"
                    rounded
                    class="my-2"
                  />
                  <div
                    v-if="breakResumeLabel"
                    class="text-caption text-medium-emphasis d-flex align-center gap-1"
                  >
                    <VIcon icon="tabler-clock" size="13" />
                    {{ t('workforceClock.resumeAt') }} {{ breakResumeLabel }}
                    <VChip
                      v-if="store.breakTargetReached"
                      size="x-small"
                      color="warning"
                      variant="tonal"
                      class="ms-auto"
                    >
                      {{ t('workforceClock.breakTargetReached') }}
                    </VChip>
                  </div>
                </template>
                <div
                  v-else
                  class="text-caption text-medium-emphasis mt-1"
                >
                  {{ t('workforceClock.noBreakTarget') }}
                </div>
              </div>

              <VAlert
                v-if="store.breakBelowMinimum && !store.canResumeNow"
                type="warning"
                variant="tonal"
                density="compact"
                class="mb-1 text-caption"
              >
                {{ t('workforceClock.breakMinBlocked', { min: Math.round((store.breakMinSeconds || 0) / 60) }) }}
              </VAlert>
              <VAlert
                v-else-if="store.breakBelowMinimum && store.earlyResumePolicy === 'warn'"
                type="info"
                variant="tonal"
                density="compact"
                class="mb-1 text-caption"
              >
                {{ t('workforceClock.breakMinWarning', { min: Math.round((store.breakMinSeconds || 0) / 60) }) }}
              </VAlert>

              <VBtn
                color="success"
                block
                :loading="actionLoading"
                :disabled="store.breakBelowMinimum && !store.canResumeNow"
                prepend-icon="tabler-player-play"
                data-test="wfc-end-break"
                @click="handleAction(store.endBreak)"
              >
                {{ t('workforceClock.endBreak') }}
              </VBtn>
            </template>

            <!-- Completed: no actions, just display -->
            <div
              v-else-if="store.isCompleted"
              class="text-center text-body-2 text-medium-emphasis pa-2"
            >
              <VIcon
                icon="tabler-circle-check"
                color="info"
                size="20"
                class="me-1"
              />
              {{ t('workforceClock.completed') }}
            </div>
          </VCardText>
        </VCard>
      </VMenu>
    </IconBtn>
  </template>
</template>
