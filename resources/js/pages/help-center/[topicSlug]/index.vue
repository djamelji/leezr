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

        <div class="d-flex align-center gap-x-4 mb-6">
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

        <VList class="card-list">
          <VListItem
            v-for="article in topic.articles"
            :key="article.id"
            :to="{
              name: 'help-center-topicSlug-articleSlug',
              params: { topicSlug: route.params.topicSlug, articleSlug: article.slug },
            }"
            class="text-high-emphasis"
          >
            <VListItemTitle class="text-body-1 font-weight-medium">
              {{ article.title }}
            </VListItemTitle>
            <VListItemSubtitle
              v-if="article.excerpt"
              class="text-body-2"
            >
              {{ article.excerpt }}
            </VListItemSubtitle>
            <template #append>
              <VIcon
                icon="tabler-chevron-right"
                class="flip-in-rtl"
                size="20"
              />
            </template>
          </VListItem>
        </VList>

        <div
          v-if="!topic.articles?.length"
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

.card-list {
  --v-card-list-gap: 0.5rem;
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
