<script setup>
const props = defineProps({
  results: { type: Array, default: () => [] },
  query: { type: String, default: '' },
  hasSupportModule: { type: Boolean, default: false },
})

const emit = defineEmits(['select'])

import { useReturnNavigation } from '@/composables/useReturnNavigation'

const { isAuthenticated } = useReturnNavigation()

function articleRoute(article) {
  return {
    name: 'help-center-topic-slug-article-slug',
    params: { topicSlug: article.topic?.slug, articleSlug: article.slug },
  }
}

function supportRoute() {
  return isAuthenticated.value ? { name: 'company-support' } : { name: 'login' }
}
</script>

<template>
  <VCard
    v-if="query.length >= 2"
    class="search-results-card"
    elevation="8"
  >
    <VCardText v-if="results.length">
      <VList class="card-list">
        <VListItem
          v-for="article in results"
          :key="article.id"
          :to="articleRoute(article)"
          class="text-high-emphasis"
          @click="emit('select', article)"
        >
          <template #prepend>
            <VAvatar
              rounded
              color="primary"
              variant="tonal"
              size="28"
            >
              <VIcon
                :icon="article.topic?.icon || 'tabler-book'"
                size="16"
              />
            </VAvatar>
          </template>
          <VListItemTitle class="text-body-1 font-weight-medium">
            {{ article.title }}
          </VListItemTitle>
          <VListItemSubtitle class="d-flex align-center gap-x-2 mt-1">
            <VChip
              v-if="article.topic?.title"
              size="x-small"
              color="primary"
              variant="tonal"
              label
            >
              {{ article.topic.title }}
            </VChip>
            <span
              v-if="article.excerpt"
              class="text-body-2 text-truncate"
            >
              {{ article.excerpt }}
            </span>
          </VListItemSubtitle>
          <template #append>
            <VIcon
              icon="tabler-chevron-right"
              class="flip-in-rtl"
              size="18"
            />
          </template>
        </VListItem>
      </VList>
    </VCardText>

    <VCardText
      v-else
      class="text-center py-6"
    >
      <VIcon
        icon="tabler-search-off"
        size="48"
        color="disabled"
        class="mb-4"
      />
      <h6 class="text-h6 mb-2">
        {{ $t('documentation.noResultsSearch', { q: query }) }}
      </h6>
      <p class="text-body-1 text-medium-emphasis mb-4">
        {{ $t('documentation.noResultsHint') }}
      </p>
      <VBtn
        v-if="hasSupportModule"
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
</template>

<style lang="scss" scoped>
.search-results-card {
  position: absolute;
  z-index: 10;
  inline-size: 100%;
  max-block-size: 400px;
  overflow-y: auto;
}

.card-list {
  --v-card-list-gap: 0.25rem;
}
</style>
