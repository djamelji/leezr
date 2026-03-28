import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

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
    async fetchTasks() {
      this._loading = true
      try {
        const data = await $platformApi('/automations')

        this._summary = data.summary
        this._schedulerHealth = data.scheduler_health
        this._tasks = data.tasks
      }
      finally {
        this._loading = false
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

        // Refresh after short delay to let job start processing
        setTimeout(() => this.fetchTasks(), 2000)

        return data
      }
      finally {
        this._runningTask = null
      }
    },
  },
})
