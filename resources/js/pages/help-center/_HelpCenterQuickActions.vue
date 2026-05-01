<script setup>
const props = defineProps({
  groups: { type: Array, default: () => [] },
  ungroupedTopics: { type: Array, default: () => [] },
})

// Flatten all topics and pick first article from each as a quick action
const quickActions = computed(() => {
  const actions = []

  for (const group of props.groups) {
    const topics = group.published_topics || group.topics || []
    for (const topic of topics) {
      const articles = topic.top_articles || []
      for (const article of articles.slice(0, 2)) {
        actions.push({
          id: article.id,
          title: article.title,
          topicSlug: topic.slug,
          articleSlug: article.slug,
          topicTitle: topic.title,
          icon: topic.icon || 'tabler-book',
        })
      }
    }
  }

  for (const topic of props.ungroupedTopics) {
    const articles = topic.top_articles || []
    for (const article of articles.slice(0, 2)) {
      actions.push({
        id: article.id,
        title: article.title,
        topicSlug: topic.slug,
        articleSlug: article.slug,
        topicTitle: topic.title,
        icon: topic.icon || 'tabler-book',
      })
    }
  }

  // Take first 8 across all topics
  return actions.slice(0, 8)
})

function articleRoute(action) {
  return {
    name: 'help-center-topicSlug-articleSlug',
    params: { topicSlug: action.topicSlug, articleSlug: action.articleSlug },
  }
}
</script>

<template>
  <div v-if="quickActions.length">
    <h5 class="text-h5 text-center mb-5">
      {{ $t('documentation.quickActionsTitle') }}
    </h5>
    <VRow>
      <VCol
        v-for="action in quickActions"
        :key="action.id"
        cols="12"
        sm="6"
        md="3"
      >
        <VCard
          :to="articleRoute(action)"
          class="quick-action-card"
          hover
          flat
          border
        >
          <VCardText class="d-flex align-center gap-3 pa-3">
            <VAvatar
              rounded
              color="primary"
              variant="tonal"
              size="28"
            >
              <VIcon
                :icon="action.icon"
                size="16"
              />
            </VAvatar>
            <span class="text-body-2 font-weight-medium text-high-emphasis">
              {{ action.title }}
            </span>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style lang="scss" scoped>
.quick-action-card {
  transition: border-color 0.15s ease;

  &:hover {
    border-color: rgba(var(--v-theme-primary), 0.5);
  }
}
</style>
