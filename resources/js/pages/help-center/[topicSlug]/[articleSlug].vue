<script setup>
import DOMPurify from 'dompurify'
import { useHelpCenter } from '@/composables/useHelpCenter'
import { useReturnNavigation } from '@/composables/useReturnNavigation'
import ArticleFeedback from '../_ArticleFeedback.vue'
import HelpCenterHeader from '../_HelpCenterHeader.vue'
import HelpCenterFooter from '../_HelpCenterFooter.vue'

definePage({
  meta: {
    layout: 'blank',
    public: true,
  },
})

const { t } = useI18n()
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

// Auto-generate TOC from h2 headings in content
const tableOfContents = computed(() => {
  const content = article.value?.article?.content ?? ''
  const regex = /<h2[^>]*>(.*?)<\/h2>/gi
  const toc = []
  let match

  while ((match = regex.exec(content)) !== null) {
    const text = match[1].replace(/<[^>]*>/g, '').trim()
    const id = text.toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')

    toc.push({ id, text })
  }

  return toc
})

// Next action = next sibling
const nextArticle = computed(() => {
  const siblings = article.value?.siblings || []
  const current = article.value?.article
  if (!current || !siblings.length) return null

  for (const s of siblings) {
    if (s.sort_order !== undefined && current.sort_order !== undefined) {
      if (s.sort_order > current.sort_order) return s
    }
  }

  return siblings[0] || null
})

// Related articles = other siblings (max 3)
const relatedArticles = computed(() => {
  const siblings = article.value?.siblings || []

  return siblings.slice(0, 3)
})

function siblingRoute(sibling) {
  return {
    name: 'help-center-topic-slug-article-slug',
    params: { topicSlug: route.params.topicSlug, articleSlug: sibling.slug },
  }
}

function supportRoute() {
  return isAuthenticated.value ? { name: 'company-support' } : { name: 'login' }
}

function scrollToHeading(id) {
  const el = document.getElementById(id)
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// Inject IDs into h2 headings after render
const contentRef = ref(null)

function injectHeadingIds() {
  if (!contentRef.value) return
  const headings = contentRef.value.querySelectorAll('h2')

  headings.forEach(h => {
    const text = h.textContent.trim()
    const id = text.toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')

    h.id = id
  })
}

watch(sanitizedContent, () => {
  nextTick(injectHeadingIds)
})

onMounted(() => {
  nextTick(injectHeadingIds)
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
                { title: article.topic?.title, to: { name: 'help-center-topic-slug', params: { topicSlug: route.params.topicSlug } }, class: 'text-primary' },
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

            <!-- Article content -->
            <div
              ref="contentRef"
              class="mb-6 text-body-1 article-content"
              v-html="sanitizedContent"
            />

            <!-- Next Action -->
            <VCard
              v-if="nextArticle"
              flat
              border
              class="mb-4"
            >
              <VCardText class="d-flex align-center justify-space-between flex-wrap gap-3">
                <div>
                  <p class="text-overline text-primary mb-1">
                    {{ t('helpCenter.nextAction') }}
                  </p>
                  <p class="text-body-1 font-weight-medium mb-0">
                    {{ nextArticle.title }}
                  </p>
                </div>
                <VBtn
                  color="primary"
                  variant="tonal"
                  :to="siblingRoute(nextArticle)"
                >
                  {{ t('helpCenter.goToAction') }}
                  <VIcon
                    icon="tabler-arrow-right"
                    class="ms-2"
                  />
                </VBtn>
              </VCardText>
            </VCard>

            <!-- Related Articles -->
            <div
              v-if="relatedArticles.length"
              class="mb-6"
            >
              <h6 class="text-h6 mb-3">
                {{ t('helpCenter.relatedArticles') }}
              </h6>
              <VList
                density="compact"
                class="pa-0 related-list"
              >
                <VListItem
                  v-for="rel in relatedArticles"
                  :key="rel.id"
                  :to="siblingRoute(rel)"
                  density="compact"
                  class="px-2"
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
                    {{ rel.title }}
                  </VListItemTitle>
                </VListItem>
              </VList>
            </div>

            <!-- Feedback widget -->
            <ArticleFeedback
              :helpful-count="article.feedback?.helpful_count || 0"
              :not-helpful-count="article.feedback?.not_helpful_count || 0"
              :user-feedback="article.feedback?.user_feedback"
              :is-authenticated="isAuthenticated"
              @submit="payload => submitFeedback(article.article?.id, payload)"
            />

            <!-- Escalation CTA -->
            <VCard
              flat
              border
              class="mt-4"
            >
              <VCardText class="d-flex align-center justify-space-between flex-wrap gap-3">
                <div>
                  <p class="text-body-1 font-weight-medium mb-0">
                    {{ $t('documentation.notResolved') }}
                  </p>
                  <p class="text-body-2 text-medium-emphasis mb-0">
                    {{ $t('documentation.notResolvedHint') }}
                  </p>
                </div>
                <VBtn
                  color="primary"
                  variant="tonal"
                  :to="supportRoute()"
                >
                  <VIcon
                    icon="tabler-message-circle"
                    class="me-2"
                  />
                  {{ $t('documentation.openTicket') }}
                </VBtn>
              </VCardText>
            </VCard>
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <!-- Table of Contents -->
            <div
              v-if="tableOfContents.length > 1"
              class="mb-6"
            >
              <h6
                class="text-h6 px-4 py-2 mb-2 rounded"
                style="background: rgba(var(--v-theme-primary), 0.08);"
              >
                {{ $t('documentation.tableOfContents') }}
              </h6>
              <VList
                density="compact"
                class="pa-0 toc-list"
              >
                <VListItem
                  v-for="item in tableOfContents"
                  :key="item.id"
                  class="px-4 cursor-pointer"
                  density="compact"
                  @click="scrollToHeading(item.id)"
                >
                  <VListItemTitle class="text-body-2 text-primary">
                    {{ item.text }}
                  </VListItemTitle>
                </VListItem>
              </VList>
            </div>

            <!-- Articles in this section -->
            <div>
              <h6
                class="text-h6 px-4 py-2 mb-2 rounded"
                style="background: rgba(var(--v-theme-on-surface), var(--v-hover-opacity));"
              >
                {{ $t('documentation.articlesInSection') }}
              </h6>
              <VList class="card-list pa-0">
                <VListItem
                  v-for="sibling in article.siblings"
                  :key="sibling.id"
                  :to="siblingRoute(sibling)"
                  link
                  class="text-disabled px-4"
                >
                  <template #prepend>
                    <VIcon
                      icon="tabler-arrow-right"
                      size="14"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <div class="text-body-2 text-high-emphasis">
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

    <HelpCenterFooter />
  </div>
</template>

<style lang="scss" scoped>
.article-section {
  margin-block: 6rem 3rem;
}

.card-list {
  --v-card-list-gap: 0.25rem;
}

.toc-list {
  :deep(.v-list-item) {
    min-block-size: 30px !important;
  }
}

.related-list {
  :deep(.v-list-item) {
    min-block-size: 32px !important;
    border-radius: 6px;

    &:hover {
      background: rgba(var(--v-theme-primary), 0.08);
    }
  }
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

  :deep(ol),
  :deep(ul) {
    padding-inline-start: 1.5rem;
    margin-block-end: 1rem;
  }

  :deep(li) {
    margin-block-end: 0.25rem;
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
