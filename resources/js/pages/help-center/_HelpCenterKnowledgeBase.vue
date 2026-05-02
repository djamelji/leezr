<script setup>
/**
 * Knowledge Base grid — action-oriented topic cards.
 * Each card shows clickable article actions + "see all" link.
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
        <VCard class="h-100 topic-card">
          <VCardItem>
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
            <VCardTitle>{{ topic.title }}</VCardTitle>
          </VCardItem>

          <VCardText class="pt-0">
            <VList
              density="compact"
              class="topic-actions pa-0"
            >
              <VListItem
                v-for="article in topArticles(topic)"
                :key="article.id"
                :to="articleRoute(topic, article)"
                density="compact"
                class="action-item px-2"
              >
                <template #prepend>
                  <VIcon
                    icon="tabler-arrow-right"
                    size="16"
                    color="primary"
                    class="me-2"
                  />
                </template>
                <VListItemTitle class="text-body-2">
                  {{ article.title }}
                </VListItemTitle>
              </VListItem>
            </VList>

            <RouterLink
              :to="topicRoute(topic)"
              class="d-flex align-center mt-4 text-primary text-body-2 font-weight-medium text-decoration-none see-all-link"
            >
              {{ $t('documentation.seeAllArticles') }}
              <template v-if="topic.articles_count">
                &nbsp;({{ topic.articles_count }})
              </template>
              <VIcon
                icon="tabler-chevron-right"
                size="16"
                class="ms-1"
              />
            </RouterLink>
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
      <VCard class="h-100 topic-card">
        <VCardItem>
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
          <VCardTitle>{{ topic.title }}</VCardTitle>
        </VCardItem>

        <VCardText class="pt-0">
          <VList
            density="compact"
            class="topic-actions pa-0"
          >
            <VListItem
              v-for="article in topArticles(topic)"
              :key="article.id"
              :to="articleRoute(topic, article)"
              density="compact"
              class="action-item px-2"
            >
              <template #prepend>
                <VIcon
                  icon="tabler-arrow-right"
                  size="16"
                  color="primary"
                  class="me-2"
                />
              </template>
              <VListItemTitle class="text-body-2">
                {{ article.title }}
              </VListItemTitle>
            </VListItem>
          </VList>

          <RouterLink
            :to="topicRoute(topic)"
            class="d-flex align-center mt-4 text-primary text-body-2 font-weight-medium text-decoration-none see-all-link"
          >
            {{ $t('documentation.seeAllArticles') }}
            <template v-if="topic.articles_count">
              &nbsp;({{ topic.articles_count }})
            </template>
            <VIcon
              icon="tabler-chevron-right"
              size="16"
              class="ms-1"
            />
          </RouterLink>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>

<style lang="scss" scoped>
.topic-actions {
  :deep(.v-list-item) {
    min-block-size: 32px !important;
    border-radius: 6px;

    &:hover {
      background: rgba(var(--v-theme-primary), 0.08);
    }
  }
}

.see-all-link {
  &:hover {
    text-decoration: underline !important;
  }
}
</style>
