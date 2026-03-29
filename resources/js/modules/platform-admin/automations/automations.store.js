import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

let _pollTimer = null

export const usePlatformAutomationsStore = defineStore('platformAutomations', {
  state: () => ({
    _summary: null,
    _schedulerHealth: null,
    _tasks: [],
    _runs: [],
    _runsPagination: { current_page: 1, last_page: 1, total: 0 },
    _loading: false,
    _runsLoading: false,
    _runningTask: null,
  }),

  getters: {
    summary: s => s._summary,
    schedulerHealth: s => s._schedulerHealth,
    tasks: s => s._tasks,
    runs: s => s._runs,
    runsPagination: s => s._runsPagination,
    loading: s => s._loading,
    runsLoading: s => s._runsLoading,
    runningTask: s => s._runningTask,
  },

  actions: {
    // ADR-431b: silent mode — skeleton only on first load, polling passes { silent: true }
    async fetchTasks({ silent = false } = {}) {
      if (!silent) this._loading = true
      try {
        const data = await $platformApi('/automations')

        this._summary = data.summary
        this._schedulerHealth = data.scheduler_health
        this._mergeTasks(data.tasks ?? [])

        // ADR-431: Auto-stop polling when no tasks are running
        const hasRunning = this._tasks.some(t => t.last_run?.status === 'running')
        if (!hasRunning) {
          this._stopRunningPoll()
        }
      }
      finally {
        if (!silent) this._loading = false
      }
    },

    /**
     * ADR-431b: Smart merge — only trigger reactivity when data actually changed.
     * Compares via JSON.stringify before Object.assign to avoid unnecessary re-renders.
     */
    _mergeTasks(incoming) {
      if (this._tasks.length === 0) {
        this._tasks = incoming

        return
      }

      const existingByName = new Map(this._tasks.map(t => [t.name, t]))

      const merged = incoming.map(item => {
        const existing = existingByName.get(item.name)
        if (existing) {
          if (JSON.stringify(existing) !== JSON.stringify(item)) {
            Object.assign(existing, item)
          }

          return existing
        }

        return item
      })

      if (merged.length !== this._tasks.length || merged.some((t, i) => t.name !== this._tasks[i]?.name)) {
        this._tasks = merged
      }
    },

    async fetchRuns(task, page = 1) {
      this._runsLoading = true
      try {
        const data = await $platformApi(`/automations/runs?task=${encodeURIComponent(task)}&page=${page}`)

        this._runs = data.data
        this._runsPagination = {
          current_page: data.current_page,
          last_page: data.last_page,
          total: data.total,
        }
      }
      finally {
        this._runsLoading = false
      }
    },

    async runTask(task) {
      this._runningTask = task
      try {
        const data = await $platformApi('/automations/run', {
          method: 'POST',
          body: { task },
        })

        // ADR-431: Start polling every 5s until no tasks are running
        this._startRunningPoll()

        return data
      }
      finally {
        this._runningTask = null
      }
    },

    /**
     * ADR-431: Poll every 5s while tasks are running.
     * Stops automatically when no task has status "running".
     * Double safety with SSE domain events.
     */
    _startRunningPoll() {
      if (_pollTimer) return
      _pollTimer = setInterval(() => this.fetchTasks({ silent: true }), 5000)
    },

    _stopRunningPoll() {
      if (_pollTimer) {
        clearInterval(_pollTimer)
        _pollTimer = null
      }
    },
  },
})
