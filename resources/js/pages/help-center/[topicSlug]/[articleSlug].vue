<script setup>
import DOMPurify from 'dompurify'
import { useHelpCenter } from '@/composables/useHelpCenter'
import { useReturnNavigation } from '@/composables/useReturnNavigation'
import ArticleFeedback from '../_ArticleFeedback.vue'
import HelpCenterHeader from '../_HelpCenterHeader.vue'

definePage({
  meta: {
    layout: 'blank',
    public: true,
  },
})

const route = useRoute()
const { article, loading, fetchArticle, submitFeedback } = useHelpCenter()
const { isAuthenticated } = useReturnNavigation()

onMounted(() => fetchArticle(route.params.topicSlug, route.params.articleSlug))

watch(
  () => [route.params.topicSlug, route.params.articleSlug],
  ([ts, as]) => {
    if (ts && as) fetchArticle(ts, as)
  },
)

const lastUpdated = computed(() => {
  if (!article.value?.article?.updated_at) return ''
  const d = new Date(article.value.article.updated_at)

  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
})

const sanitizedContent = computed(() => {
  return DOMPurify.sanitize(article.value?.article?.content ?? '')
})
</script>

<template>
  <!-- eslint-disable vue/no-v-html -->
  <div class="bg-surface help-center-article">
    <HelpCenterHeader />

    <VContainer>
      <div
        v-if="article"
        class="article-section"
      >
        <VRow>
          <VCol
            cols="12"
            md="8"
          >
            <VBreadcrumbs
              class="px-0 pb-2 pt-0 help-center-breadcrumbs"
              :items="[
                { title: $t('documentation.publicTitle'), to: { name: 'help-center' }, class: 'text-primary' },
                { title: article.topic?.title, to: { name: 'help-center-topicSlug', params: { topicSlug: route.params.topicSlug } }, class: 'text-primary' },
                { title: article.article?.title },
              ]"
            />
            <h4 class="text-h4 mb-2">
              {{ article.article?.title }}
            </h4>
            <div class="text-body-2 text-medium-emphasis">
              {{ $t('documentation.lastUpdated', { date: lastUpdated }) }}
            </div>
            <VDivider class="my-6" />
            <div
              class="mb-6 text-body-1 article-content"
              v-html="sanitizedContent"
            />

            <!-- Feedback widget -->
            <ArticleFeedback
              :helpful-count="article.feedback?.helpful_count || 0"
              :not-helpful-count="article.feedback?.not_helpful_count || 0"
              :user-feedback="article.feedback?.user_feedback"
              :is-authenticated="isAuthenticated"
              @submit="payload => submitFeedback(article.article?.id, payload)"
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <div>
              <h5
                class="text-h5 px-6 py-2 mb-4 rounded"
                style="background: rgba(var(--v-theme-on-surface), var(--v-hover-opacity));"
              >
                {{ $t('documentation.articlesInSection') }}
              </h5>
              <VList class="card-list">
                <VListItem
                  v-for="sibling in article.siblings"
                  :key="sibling.id"
                  :to="{
                    name: 'help-center-topicSlug-articleSlug',
                    params: { topicSlug: route.params.topicSlug, articleSlug: sibling.slug },
                  }"
                  link
                  class="text-disabled"
                >
                  <template #append>
                    <VIcon
                      icon="tabler-chevron-right"
                      class="flip-in-rtl"
                      size="20"
                    />
                  </template>
                  <div class="text-body-1 text-high-emphasis">
                    {{ sibling.title }}
                  </div>
                </VListItem>
              </VList>
            </div>
          </VCol>
        </VRow>
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
  </div>
</template>

<style lang="scss" scoped>
.article-section {
  margin-block: 6rem 3rem;
}

.card-list {
  --v-card-list-gap: 1rem;
}

.article-content {
  :deep(img) {
    border-radius: 0.5rem;
    max-inline-size: 100%;
  }

  :deep(h2),
  :deep(h3) {
    margin-block: 1.5rem 0.75rem;
  }

  :deep(p) {
    margin-block-end: 1rem;
  }
}
</style>

<style lang="scss">
@media (max-width: 960px) and (min-width: 600px) {
  .help-center-article {
    .v-container {
      padding-inline: 2rem !important;
    }
  }
}

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
