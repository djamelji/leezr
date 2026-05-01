<script setup>
defineProps({
  groups: { type: Array, default: () => [] },
  ungroupedTopics: { type: Array, default: () => [] },
})

function topicRoute(topic) {
  return { name: 'help-center-topicSlug', params: { topicSlug: topic.slug } }
}

function articleRoute(topic, article) {
  return { name: 'help-center-topicSlug-articleSlug', params: { topicSlug: topic.slug, articleSlug: article.slug } }
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
    <VRow class="card-grid card-grid-sm">
      <VCol
        v-for="topic in allTopics(group)"
        :key="topic.id"
        cols="12"
        sm="6"
        lg="4"
      >
        <VCard>
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
            <template #append>
              <VChip
                v-if="topic.articles_count !== undefined"
                size="small"
                color="primary"
                variant="tonal"
              >
                {{ topic.articles_count }}
              </VChip>
            </template>
          </VCardItem>
          <VCardText class="pt-0">
            <!-- Direct article links — actionable -->
            <VList
              v-if="topArticles(topic).length"
              density="compact"
              class="pa-0 topic-article-list"
            >
              <VListItem
                v-for="article in topArticles(topic)"
                :key="article.id"
                :to="articleRoute(topic, article)"
                class="px-0 text-high-emphasis"
                density="compact"
              >
                <template #prepend>
                  <VIcon
                    icon="tabler-arrow-right"
                    size="14"
                    color="primary"
                    class="me-2"
                  />
                </template>
                <VListItemTitle class="text-body-2">
                  {{ article.title }}
                </VListItemTitle>
              </VListItem>
            </VList>

            <!-- See all link -->
            <RouterLink
              v-if="topic.articles_count > topArticles(topic).length"
              :to="topicRoute(topic)"
              class="text-caption d-flex align-center font-weight-medium text-primary mt-2"
            >
              {{ $t('documentation.seeAllArticles') }} ({{ topic.articles_count }})
              <VIcon
                icon="tabler-chevron-right"
                size="14"
                class="ms-1 flip-in-rtl"
              />
            </RouterLink>
            <RouterLink
              v-else-if="!topArticles(topic).length"
              :to="topicRoute(topic)"
              class="text-body-2 d-flex align-center font-weight-medium"
            >
              {{ $t('documentation.seeAllArticles') }}
              <VIcon
                icon="tabler-arrow-right"
                size="18"
                class="ms-2 flip-in-rtl"
              />
            </RouterLink>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>

  <!-- Ungrouped topics -->
  <VRow
    v-if="ungroupedTopics.length"
    class="card-grid card-grid-sm"
  >
    <VCol
      v-for="topic in ungroupedTopics"
      :key="topic.id"
      cols="12"
      sm="6"
      lg="4"
    >
      <VCard>
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
          <template #append>
            <VChip
              v-if="topic.articles_count !== undefined"
              size="small"
              color="primary"
              variant="tonal"
            >
              {{ topic.articles_count }}
            </VChip>
          </template>
        </VCardItem>
        <VCardText class="pt-0">
          <VList
            v-if="topArticles(topic).length"
            density="compact"
            class="pa-0 topic-article-list"
          >
            <VListItem
              v-for="article in topArticles(topic)"
              :key="article.id"
              :to="articleRoute(topic, article)"
              class="px-0 text-high-emphasis"
              density="compact"
            >
              <template #prepend>
                <VIcon
                  icon="tabler-arrow-right"
                  size="14"
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
            v-if="topic.articles_count > topArticles(topic).length"
            :to="topicRoute(topic)"
            class="text-caption d-flex align-center font-weight-medium text-primary mt-2"
          >
            {{ $t('documentation.seeAllArticles') }} ({{ topic.articles_count }})
            <VIcon
              icon="tabler-chevron-right"
              size="14"
              class="ms-1 flip-in-rtl"
            />
          </RouterLink>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>

<style lang="scss" scoped>
.topic-article-list {
  :deep(.v-list-item) {
    min-block-size: 28px !important;
  }
}
</style>
