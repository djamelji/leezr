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
  const loading = ref(false)
  const error = ref(null)

  // Computed
  const clockStatus = computed(() => {
    if (!todayClock.value) return 'not_started'

    return todayClock.value.status // idle, working, on_break, completed
  })

  const isWorking = computed(() => clockStatus.value === 'working')
  const isOnBreak = computed(() => clockStatus.value === 'on_break')
  const isCompleted = computed(() => clockStatus.value === 'completed')
  const hasStarted = computed(() => todayClock.value !== null)

  const workedMinutesToday = computed(() => {
    if (!todayClock.value) return 0
    if (todayClock.value.worked_minutes) return todayClock.value.worked_minutes

    // If still working, compute live from clock_in
    if (todayClock.value.status === 'working' && todayClock.value.clock_in) {
      const start = new Date(todayClock.value.clock_in)
      const now = new Date()
      const breakMins = todayClock.value.break_minutes || 0

      return Math.floor((now - start) / 60000) - breakMins
    }

    return 0
  })

  const breakMinutesToday = computed(() => todayClock.value?.break_minutes || 0)

  // Actions
  async function fetchProfile() {
    loading.value = true
    error.value = null
    try {
      const data = await $api('/workforce/me')

      employee.value = data.employee
      todayClock.value = data.today_clock
      leaveBalances.value = data.leave_balances || []
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
    const data = await $api('/workforce/me/clock-in', {
      method: 'POST',
      body: { source: 'web' },
    })

    await refreshToday()

    return data
  }

  async function clockOut() {
    const data = await $api('/workforce/me/clock-out', {
      method: 'POST',
    })

    await refreshToday()

    return data
  }

  async function startBreak() {
    const data = await $api('/workforce/me/break/start', {
      method: 'POST',
    })

    await refreshToday()

    return data
  }

  async function endBreak() {
    const data = await $api('/workforce/me/break/end', {
      method: 'POST',
    })

    await refreshToday()

    return data
  }

  function reset() {
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

    // Actions
    fetchProfile,
    refreshToday,
    clockIn,
    clockOut,
    startBreak,
    endBreak,
    reset,
  }
})
