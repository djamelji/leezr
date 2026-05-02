<script setup>
const props = defineProps({
  results: { type: Array, default: () => [] },
  query: { type: String, default: '' },
  hasSupportModule: { type: Boolean, default: false },
  commonProblems: { type: Array, default: () => [] },
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

function problemRoute(problem) {
  return {
    name: 'help-center-topic-slug-article-slug',
    params: { topicSlug: problem.topicSlug, articleSlug: problem.articleSlug },
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

    <!-- No results — show common problems fallback -->
    <VCardText
      v-else
      class="py-4"
    >
      <div class="text-center mb-4">
        <VIcon
          icon="tabler-search-off"
          size="40"
          color="disabled"
          class="mb-2"
        />
        <h6 class="text-h6 mb-1">
          {{ $t('documentation.noResultsSearch', { q: query }) }}
        </h6>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ $t('documentation.noResultsHint') }}
        </p>
      </div>

      <!-- Common problems fallback -->
      <div v-if="commonProblems.length">
        <VDivider class="mb-3" />
        <div class="d-flex align-center gap-x-2 mb-2 px-2">
          <VIcon
            icon="tabler-alert-triangle"
            color="warning"
            size="18"
          />
          <span class="text-subtitle-2 font-weight-medium">
            {{ $t('helpCenter.commonProblems') }}
          </span>
        </div>
        <VList
          density="compact"
          class="pa-0"
        >
          <VListItem
            v-for="problem in commonProblems"
            :key="problem.articleSlug"
            :to="problemRoute(problem)"
            density="compact"
            class="px-2"
            @click="emit('select', problem)"
          >
            <template #prepend>
              <VAvatar
                color="warning"
                variant="tonal"
                size="24"
                class="me-2"
              >
                <VIcon
                  :icon="problem.icon"
                  size="14"
                />
              </VAvatar>
            </template>
            <VListItemTitle class="text-body-2">
              {{ problem.label }}
            </VListItemTitle>
            <template #append>
              <VIcon
                icon="tabler-chevron-right"
                size="16"
                color="warning"
              />
            </template>
          </VListItem>
        </VList>
      </div>

      <!-- Support ticket button -->
      <div
        v-if="hasSupportModule"
        class="text-center mt-3"
      >
        <VBtn
          color="primary"
          variant="tonal"
          size="small"
          :to="supportRoute()"
        >
          <VIcon
            icon="tabler-message-circle"
            class="me-2"
          />
          {{ $t('documentation.openTicket') }}
        </VBtn>
      </div>
    </VCardText>
  </VCard>
</template>

<style lang="scss" scoped>
.search-results-card {
  position: absolute;
  z-index: 10;
  inline-size: 100%;
  max-block-size: 450px;
  overflow-y: auto;
}

.card-list {
  --v-card-list-gap: 0.25rem;
}
</style>
