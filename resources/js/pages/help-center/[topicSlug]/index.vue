<script setup>
import { useHelpCenter } from '@/composables/useHelpCenter'
import HelpCenterFooter from '../_HelpCenterFooter.vue'
import HelpCenterHeader from '../_HelpCenterHeader.vue'

definePage({
  meta: {
    layout: 'blank',
    public: true,
  },
})

const route = useRoute()
const { topic, loading, fetchTopic } = useHelpCenter()

onMounted(() => fetchTopic(route.params.topicSlug))

watch(() => route.params.topicSlug, slug => {
  if (slug) fetchTopic(slug)
})

function articleRoute(article) {
  return {
    name: 'help-center-topicSlug-articleSlug',
    params: { topicSlug: route.params.topicSlug, articleSlug: article.slug },
  }
}
</script>

<template>
  <div class="bg-surface help-center-topic">
    <HelpCenterHeader />

    <VContainer>
      <div
        v-if="topic"
        class="topic-section"
      >
        <VBreadcrumbs
          class="px-0 pb-2 pt-0 help-center-breadcrumbs"
          :items="[
            { title: $t('documentation.publicTitle'), to: { name: 'help-center' }, class: 'text-primary' },
            { title: topic.topic?.title },
          ]"
        />

        <div class="d-flex align-center gap-x-4 mb-2">
          <VAvatar
            rounded
            color="primary"
            variant="tonal"
            size="48"
          >
            <VIcon
              :icon="topic.topic?.icon || 'tabler-book'"
              size="28"
            />
          </VAvatar>
          <div>
            <h4 class="text-h4">
              {{ topic.topic?.title }}
            </h4>
            <p
              v-if="topic.topic?.description"
              class="text-body-1 text-medium-emphasis mb-0"
            >
              {{ topic.topic?.description }}
            </p>
          </div>
        </div>

        <!-- Article count -->
        <p class="text-caption text-medium-emphasis mb-4">
          {{ topic.articles?.length || 0 }} {{ $t('documentation.articles') }}
        </p>

        <!-- Article cards — actionable format -->
        <VRow
          v-if="topic.articles?.length"
          class="card-grid card-grid-xs"
        >
          <VCol
            v-for="article in topic.articles"
            :key="article.id"
            cols="12"
            sm="6"
          >
            <VCard
              :to="articleRoute(article)"
              hover
              class="article-card"
            >
              <VCardText class="d-flex align-start gap-3">
                <VIcon
                  icon="tabler-file-text"
                  size="20"
                  color="primary"
                  class="mt-1 flex-shrink-0"
                />
                <div>
                  <p class="text-body-1 font-weight-medium text-high-emphasis mb-1">
                    {{ article.title }}
                  </p>
                  <p
                    v-if="article.excerpt"
                    class="text-body-2 text-medium-emphasis mb-0 article-excerpt"
                  >
                    {{ article.excerpt }}
                  </p>
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <div
          v-else
          class="text-center py-10"
        >
          <VIcon
            icon="tabler-file-off"
            size="48"
            color="disabled"
            class="mb-4"
          />
          <p class="text-body-1 text-medium-emphasis">
            {{ $t('documentation.noArticles') }}
          </p>
        </div>
      </div>

      <div
        v-else-if="loading"
        class="text-center py-16"
      >
        <VProgressCircular
          indeterminate
          color="primary"
        />
      </div>
    </VContainer>

    <HelpCenterFooter />
  </div>
</template>

<style lang="scss" scoped>
.topic-section {
  margin-block: 6rem 3rem;
}

.article-card {
  transition: border-color 0.15s ease;

  &:hover {
    border-color: rgba(var(--v-theme-primary), 0.3);
  }
}

.article-excerpt {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>

<style lang="scss">
.help-center-breadcrumbs {
  &.v-breadcrumbs {
    .v-breadcrumbs-item {
      padding: 0 !important;

      &.v-breadcrumbs-item--disabled {
        opacity: 0.9;
      }
    }
  }
}
</style>
