import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const usePlatformDocumentationStore = defineStore('platformDocumentation', {
  state: () => ({
    _topics: [],
    _topicsPagination: null,
    _currentTopic: null,
    _articles: [],
    _articlesPagination: null,
    _currentArticle: null,
    _feedbackStats: [],
    _groups: [],
    _groupsPagination: null,
    _searchMisses: [],
    _loading: false,
  }),

  getters: {
    topics: s => s._topics,
    topicsPagination: s => s._topicsPagination,
    currentTopic: s => s._currentTopic,
    articles: s => s._articles,
    articlesPagination: s => s._articlesPagination,
    currentArticle: s => s._currentArticle,
    feedbackStats: s => s._feedbackStats,
    groups: s => s._groups,
    groupsPagination: s => s._groupsPagination,
    searchMisses: s => s._searchMisses,
    loading: s => s._loading,
  },

  actions: {
    async fetchTopics(params = {}) {
      this._loading = true
      try {
        const query = new URLSearchParams(params).toString()
        const data = await $api(`/platform/documentation/topics?${query}`)

        this._topics = data.data
        this._topicsPagination = {
          total: data.total,
          perPage: data.per_page,
          currentPage: data.current_page,
          lastPage: data.last_page,
        }
      }
      finally {
        this._loading = false
      }
    },

    async createTopic(payload) {
      const data = await $api('/platform/documentation/topics', {
        method: 'POST',
        body: payload,
      })

      return data
    },

    async fetchTopic(id) {
      this._loading = true
      try {
        this._currentTopic = await $api(`/platform/documentation/topics/${id}`)
      }
      finally {
        this._loading = false
      }
    },

    async updateTopic(id, payload) {
      const data = await $api(`/platform/documentation/topics/${id}`, {
        method: 'PUT',
        body: payload,
      })

      return data
    },

    async deleteTopic(id) {
      await $api(`/platform/documentation/topics/${id}`, {
        method: 'DELETE',
      })
    },

    async fetchArticles(params = {}) {
      this._loading = true
      try {
        const query = new URLSearchParams(params).toString()
        const data = await $api(`/platform/documentation/articles?${query}`)

        this._articles = data.data
        this._articlesPagination = {
          total: data.total,
          perPage: data.per_page,
          currentPage: data.current_page,
          lastPage: data.last_page,
        }
      }
      finally {
        this._loading = false
      }
    },

    async createArticle(payload) {
      const data = await $api('/platform/documentation/articles', {
        method: 'POST',
        body: payload,
      })

      return data
    },

    async fetchArticle(id) {
      this._loading = true
      try {
        const data = await $api(`/platform/documentation/articles/${id}`)

        this._currentArticle = data.article
        this._currentArticle.recent_feedbacks = data.recent_feedbacks
      }
      finally {
        this._loading = false
      }
    },

    async updateArticle(id, payload) {
      const data = await $api(`/platform/documentation/articles/${id}`, {
        method: 'PUT',
        body: payload,
      })

      return data
    },

    async deleteArticle(id) {
      await $api(`/platform/documentation/articles/${id}`, {
        method: 'DELETE',
      })
    },

    async fetchFeedbackStats() {
      this._loading = true
      try {
        this._feedbackStats = await $api('/platform/documentation/feedback-stats')
      }
      finally {
        this._loading = false
      }
    },

    // ── Groups ──────────────────────────────────
    async fetchGroups(params = {}) {
      this._loading = true
      try {
        const query = new URLSearchParams(params).toString()
        const data = await $api(`/platform/documentation/groups?${query}`)

        this._groups = data.data
        this._groupsPagination = {
          total: data.total,
          perPage: data.per_page,
          currentPage: data.current_page,
          lastPage: data.last_page,
        }
      }
      finally {
        this._loading = false
      }
    },

    async createGroup(payload) {
      return await $api('/platform/documentation/groups', {
        method: 'POST',
        body: payload,
      })
    },

    async updateGroup(id, payload) {
      return await $api(`/platform/documentation/groups/${id}`, {
        method: 'PUT',
        body: payload,
      })
    },

    async deleteGroup(id) {
      await $api(`/platform/documentation/groups/${id}`, {
        method: 'DELETE',
      })
    },

    // ── Search Misses ───────────────────────────
    async fetchSearchMisses() {
      this._loading = true
      try {
        this._searchMisses = await $api('/platform/documentation/search-misses')
      }
      finally {
        this._loading = false
      }
    },
  },
})
