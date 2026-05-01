<script setup>
/**
 * Knowledge Base grid — based on Vuexy HelpCenterLandingKnowledgeBase preset.
 * Each topic card shows direct article links (actionable, clickable).
 */
defineProps({
  groups: { type: Array, default: () => [] },
  ungroupedTopics: { type: Array, default: () => [] },
})

function topicRoute(topic) {
  return { name: 'help-center-topic-slug', params: { topicSlug: topic.slug } }
}

function articleRoute(topic, article) {
  return {
    name: 'help-center-topic-slug-article-slug',
    params: { topicSlug: topic.slug, articleSlug: article.slug },
  }
}

function allTopics(group) {
  return group.published_topics || group.topics || []
}

function topArticles(topic) {
  return topic.top_articles || []
}
</script>

<template>
  <!-- Grouped topics -->
  <div
    v-for="group in groups"
    :key="group.id"
    class="mb-8"
  >
    <div class="d-flex align-center gap-x-3 mb-4">
      <VAvatar
        rounded
        color="primary"
        variant="tonal"
        size="36"
      >
        <VIcon
          :icon="group.icon || 'tabler-folder'"
          size="22"
        />
      </VAvatar>
      <h5 class="text-h5">
        {{ group.title }}
      </h5>
    </div>
    <VRow>
      <VCol
        v-for="topic in allTopics(group)"
        :key="topic.id"
        cols="12"
        sm="6"
        lg="4"
      >
        <VCard
          :title="topic.title"
          class="h-100"
        >
          <template #prepend>
            <VAvatar
              rounded
              color="primary"
              variant="tonal"
              size="32"
              class="me-1"
            >
              <VIcon
                :icon="topic.icon || 'tabler-book'"
                size="20"
              />
            </VAvatar>
          </template>

          <VCardText>
            <VList class="card-list">
              <VListItem
                v-for="article in topArticles(topic)"
                :key="article.id"
                :to="articleRoute(topic, article)"
                class="text-disabled"
              >
                <RouterLink
                  :to="articleRoute(topic, article)"
                  class="text-high-emphasis"
                >
                  <div>{{ article.title }}</div>
                </RouterLink>
                <template #append>
                  <VIcon
                    icon="tabler-chevron-right"
                    class="flip-in-rtl"
                    size="20"
                  />
                </template>
              </VListItem>
            </VList>

            <div class="mt-6">
              <RouterLink
                :to="topicRoute(topic)"
                class="text-base d-flex align-center font-weight-medium d-inline-block"
              >
                <span class="d-inline-block">
                  {{ $t('documentation.seeAllArticles') }}
                  <template v-if="topic.articles_count">
                    ({{ topic.articles_count }})
                  </template>
                </span>
                <VIcon
                  icon="tabler-arrow-right"
                  size="18"
                  class="ms-3 flip-in-rtl"
                />
              </RouterLink>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>

  <!-- Ungrouped topics -->
  <VRow v-if="ungroupedTopics.length">
    <VCol
      v-for="topic in ungroupedTopics"
      :key="topic.id"
      cols="12"
      sm="6"
      lg="4"
    >
      <VCard
        :title="topic.title"
        class="h-100"
      >
        <template #prepend>
          <VAvatar
            rounded
            color="primary"
            variant="tonal"
            size="32"
            class="me-1"
          >
            <VIcon
              :icon="topic.icon || 'tabler-book'"
              size="20"
            />
          </VAvatar>
        </template>

        <VCardText>
          <VList class="card-list">
            <VListItem
              v-for="article in topArticles(topic)"
              :key="article.id"
              :to="articleRoute(topic, article)"
              class="text-disabled"
            >
              <RouterLink
                :to="articleRoute(topic, article)"
                class="text-high-emphasis"
              >
                <div>{{ article.title }}</div>
              </RouterLink>
              <template #append>
                <VIcon
                  icon="tabler-chevron-right"
                  class="flip-in-rtl"
                  size="20"
                />
              </template>
            </VListItem>
          </VList>

          <div class="mt-6">
            <RouterLink
              :to="topicRoute(topic)"
              class="text-base d-flex align-center font-weight-medium d-inline-block"
            >
              <span class="d-inline-block">
                {{ $t('documentation.seeAllArticles') }}
                <template v-if="topic.articles_count">
                  ({{ topic.articles_count }})
                </template>
              </span>
              <VIcon
                icon="tabler-arrow-right"
                size="18"
                class="ms-3 flip-in-rtl"
              />
            </RouterLink>
          </div>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>

<style lang="scss">
.card-list {
  --v-card-list-gap: 0.5rem;
}
</style>
