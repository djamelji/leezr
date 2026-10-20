/**
 * WorkforceClockStore — employee self-service clock widget.
 *
 * Powers the compact navbar clock-in/out widget.
 * Fetches employee profile + today's clock status from /workforce/me endpoints.
 * Only active when the current user is an employee in the current company.
 */
import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useWorkforceClockStore = defineStore('workforce-clock', () => {
  // State
  const employee = ref(null)
  const todayClock = ref(null)
  const leaveBalances = ref([])
  const breakRules = ref(null) // resolved by the backend WorkforceRuleResolver (never Vue constants)
  const loading = ref(false)
  const error = ref(null)

  // ── Single reactive clock — SERVER is the source of truth ───────────────────
  // The server owns every transition + timestamp (clock_in, break start/end, total_*
  // minutes). The frontend only derives a smooth live display from those timestamps via a
  // 1s ticker, so the counters survive refresh / navigation / reconnection (they are always
  // recomputed from clock_in, never from an in-memory JS counter). A single shared store
  // model powers both the navbar widget and the /me page — no concurrent counters.
  const now = ref(Date.now())
  let ticker = null

  function stopTicker() {
    if (ticker) { clearInterval(ticker); ticker = null }
  }

  function syncTicker(status) {
    const active = status === 'working' || status === 'on_break'
    if (active && !ticker) {
      now.value = Date.now()
      ticker = setInterval(() => { now.value = Date.now() }, 1000)
    } else if (!active) {
      stopTicker()
    }
  }

  // Computed
  const clockStatus = computed(() => {
    if (!todayClock.value) return 'not_started'

    return todayClock.value.status // idle, working, on_break, completed
  })

  const isWorking = computed(() => clockStatus.value === 'working')
  const isOnBreak = computed(() => clockStatus.value === 'on_break')
  const isCompleted = computed(() => clockStatus.value === 'completed')
  const hasStarted = computed(() => todayClock.value !== null)

  const clockInMs = computed(() => (todayClock.value?.clock_in ? new Date(todayClock.value.clock_in).getTime() : null))
  const clockOutMs = computed(() => (todayClock.value?.clock_out ? new Date(todayClock.value.clock_out).getTime() : null))

  // Start timestamp of an in-progress break (break row with no end_at), if any.
  const ongoingBreakStartMs = computed(() => {
    const b = (todayClock.value?.breaks || []).find(x => x.start_at && !x.end_at)

    return b ? new Date(b.start_at).getTime() : null
  })

  // Completed breaks (server truth, in seconds).
  const completedBreakSeconds = computed(() => (todayClock.value?.total_break_minutes || 0) * 60)

  // Live elapsed seconds of the current break (0 when not on break).
  const currentBreakSeconds = computed(() => {
    if (clockStatus.value === 'on_break' && ongoingBreakStartMs.value) {
      return Math.max(0, Math.floor((now.value - ongoingBreakStartMs.value) / 1000))
    }

    return 0
  })

  // Total break seconds today (completed + ongoing).
  const breakSeconds = computed(() => completedBreakSeconds.value + currentBreakSeconds.value)

  // ── Break countdown (driven by the server-resolved break rules) ─────────────
  const hasBreakTarget = computed(() => {
    const t = breakRules.value?.target_duration_minutes

    return t !== null && t !== undefined && Number(t) > 0
  })
  const breakTargetSeconds = computed(() => (hasBreakTarget.value ? Number(breakRules.value.target_duration_minutes) * 60 : null))
  const breakMinSeconds = computed(() => {
    const m = breakRules.value?.min_duration_minutes

    return m !== null && m !== undefined ? Number(m) * 60 : null
  })
  // Remaining to the target (0 once reached); null when no target configured.
  const breakRemainingSeconds = computed(() => {
    if (!hasBreakTarget.value) return null

    return Math.max(0, breakTargetSeconds.value - currentBreakSeconds.value)
  })
  // Overshoot past the target (0 before reaching it); null when no target.
  const breakOvershootSeconds = computed(() => {
    if (!hasBreakTarget.value) return null

    return Math.max(0, currentBreakSeconds.value - breakTargetSeconds.value)
  })
  const breakTargetReached = computed(() => hasBreakTarget.value && currentBreakSeconds.value >= breakTargetSeconds.value)
  // Progress 0..100 toward the target (capped at 100); null when no target.
  const breakProgress = computed(() => {
    if (!hasBreakTarget.value || breakTargetSeconds.value === 0) return null

    return Math.min(100, Math.round((currentBreakSeconds.value / breakTargetSeconds.value) * 100))
  })
  // Indicative resume time (break start + target), as a Date; null when no target or not on break.
  const breakResumeAt = computed(() => {
    if (!hasBreakTarget.value || ongoingBreakStartMs.value === null) return null

    return new Date(ongoingBreakStartMs.value + breakTargetSeconds.value * 1000)
  })
  // Can the employee resume now given the min-duration + early-resume policy?
  const earlyResumePolicy = computed(() => breakRules.value?.early_resume_policy ?? 'warn')
  const breakBelowMinimum = computed(() => breakMinSeconds.value !== null && currentBreakSeconds.value < breakMinSeconds.value)
  const canResumeNow = computed(() => {
    if (!breakBelowMinimum.value) return true

    return earlyResumePolicy.value === 'allow' || earlyResumePolicy.value === 'warn'
  })

  // Effective worked seconds. Completed → server total_worked_minutes (authoritative).
  // Working → (now − clock_in) − completed breaks. On break → frozen at break start.
  const workedSeconds = computed(() => {
    const tc = todayClock.value
    if (!tc || clockInMs.value === null) return 0
    if (tc.status === 'completed') return (tc.total_worked_minutes || 0) * 60

    const until = tc.status === 'on_break' && ongoingBreakStartMs.value ? ongoingBreakStartMs.value : now.value

    return Math.max(0, Math.floor((until - clockInMs.value) / 1000) - completedBreakSeconds.value)
  })

  // Total elapsed since clock-in (work + breaks), stops at clock_out when completed.
  const elapsedSeconds = computed(() => {
    if (clockInMs.value === null) return 0
    const end = clockStatus.value === 'completed' && clockOutMs.value !== null ? clockOutMs.value : now.value

    return Math.max(0, Math.floor((end - clockInMs.value) / 1000))
  })

  // Backward-compatible minute getters (navbar + pages).
  const workedMinutesToday = computed(() => Math.floor(workedSeconds.value / 60))
  const breakMinutesToday = computed(() => Math.floor(breakSeconds.value / 60))

  // Keep the ticker in sync with the live status.
  watch(clockStatus, syncTicker, { immediate: true })

  // Actions
  async function fetchProfile() {
    loading.value = true
    error.value = null
    try {
      const data = await $api('/workforce/me')

      employee.value = data.employee
      todayClock.value = data.today_clock
      leaveBalances.value = data.leave_balances || []
      breakRules.value = data.break_rules || null
    }
    catch (e) {
      if (e.response?.status === 404 || e.status === 404 || e.statusCode === 404) {
        // User is not an employee in this company — widget should hide
        employee.value = null
      }
      else {
        error.value = e.message
      }
    }
    finally {
      loading.value = false
    }
  }

  async function refreshToday() {
    try {
      const data = await $api('/workforce/me/today')

      // Take the latest entry as "today's clock"
      const entries = data.entries || []
      const latest = entries.length > 0 ? entries[entries.length - 1] : null

      todayClock.value = latest
    }
    catch {
      // Silently fail — don't disturb the user
    }
  }

  async function clockIn() {
    // ANO-008: backend accepts source ∈ {manual, mobile, kiosk}. The web self-service
    // clock-in is a manual entry — 'web' was rejected (422), breaking clock-in entirely.
    const data = await $api('/workforce/me/clock-in', {
      method: 'POST',
      body: { source: 'manual' },
    })

    await fetchProfile()

    return data
  }

  async function clockOut() {
    const data = await $api('/workforce/me/clock-out', {
      method: 'POST',
    })

    await fetchProfile()

    return data
  }

  async function startBreak() {
    const data = await $api('/workforce/me/break/start', {
      method: 'POST',
    })

    await fetchProfile()

    return data
  }

  async function endBreak() {
    const data = await $api('/workforce/me/break/end', {
      method: 'POST',
    })

    await fetchProfile()

    return data
  }

  // ANO-007: self-service leave request (open to any active employee via /workforce/me).
  async function createLeave({ leaveTypeId, dateFrom, dateTo, daysCountHundredths, reason }) {
    const data = await $api('/workforce/me/leaves', {
      method: 'POST',
      body: {
        leave_type_id: leaveTypeId,
        date_from: dateFrom,
        date_to: dateTo,
        days_count_hundredths: daysCountHundredths,
        reason: reason || null,
      },
    })

    await fetchProfile()

    return data
  }

  function reset() {
    stopTicker()
    employee.value = null
    todayClock.value = null
    leaveBalances.value = []
    loading.value = false
    error.value = null
  }

  return {
    // State
    employee,
    todayClock,
    leaveBalances,
    loading,
    error,

    // Computed
    clockStatus,
    isWorking,
    isOnBreak,
    isCompleted,
    hasStarted,
    workedMinutesToday,
    breakMinutesToday,
    // Live server-truth derived counters (seconds precision)
    workedSeconds,
    breakSeconds,
    currentBreakSeconds,
    elapsedSeconds,
    clockInMs,
    ongoingBreakStartMs,
    // Break rules + countdown (resolver-driven)
    breakRules,
    hasBreakTarget,
    breakTargetSeconds,
    breakMinSeconds,
    breakRemainingSeconds,
    breakOvershootSeconds,
    breakTargetReached,
    breakProgress,
    breakResumeAt,
    earlyResumePolicy,
    breakBelowMinimum,
    canResumeNow,

    // Actions
    fetchProfile,
    refreshToday,
    clockIn,
    clockOut,
    startBreak,
    endBreak,
    createLeave,
    reset,
  }
})
