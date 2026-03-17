import { ref } from 'vue'
import axios from 'axios'

export function useHelpCenter() {
  const data = ref(null)
  const topic = ref(null)
  const article = ref(null)
  const searchResults = ref([])
  const hasSupportModule = ref(false)
  const isAuthenticated = ref(false)
  const loading = ref(false)

  async function fetchLanding() {
    loading.value = true
    try {
      const { data: res } = await axios.get('/api/help-center')

      data.value = res
      isAuthenticated.value = res.audience !== 'public'
    }
    finally {
      loading.value = false
    }
  }

  async function fetchTopic(slug) {
    loading.value = true
    try {
      const { data: res } = await axios.get(`/api/help-center/topic/${slug}`)

      topic.value = res
    }
    finally {
      loading.value = false
    }
  }

  async function fetchArticle(topicSlug, articleSlug) {
    loading.value = true
    try {
      const { data: res } = await axios.get(`/api/help-center/article/${topicSlug}/${articleSlug}`)

      article.value = res
      isAuthenticated.value = !!res.feedback?.user_feedback !== undefined
    }
    finally {
      loading.value = false
    }
  }

  async function search(q) {
    if (!q || q.length < 2) {
      searchResults.value = []
      hasSupportModule.value = false

      return
    }
    try {
      const { data: res } = await axios.get('/api/help-center/search', { params: { q } })

      searchResults.value = res.results
      hasSupportModule.value = res.has_support_module ?? false
    }
    catch {
      searchResults.value = []
    }
  }

  async function submitFeedback(articleId, payload) {
    const { data: res } = await axios.post(`/api/help-center/article/${articleId}/feedback`, payload)

    // Update local article feedback
    if (article.value && article.value.article?.id === articleId) {
      article.value.feedback.user_feedback = {
        helpful: res.helpful,
        comment: res.comment,
      }
      if (res.helpful) {
        article.value.feedback.helpful_count++
      }
      else {
        article.value.feedback.not_helpful_count++
      }
    }

    return res
  }

  return {
    data,
    topic,
    article,
    searchResults,
    hasSupportModule,
    isAuthenticated,
    loading,
    fetchLanding,
    fetchTopic,
    fetchArticle,
    search,
    submitFeedback,
  }
}
