<script setup>
defineProps({
  groups: { type: Array, default: () => [] },
  ungroupedTopics: { type: Array, default: () => [] },
})

function topicRoute(topic) {
  return { name: 'help-center-topicSlug', params: { topicSlug: topic.slug } }
}

function allTopics(group) {
  return group.published_topics || group.topics || []
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
        <VCard :title="topic.title">
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
          <VCardText v-if="topic.description">
            <p class="text-body-2 text-medium-emphasis mb-3">
              {{ topic.description }}
            </p>
            <RouterLink
              :to="topicRoute(topic)"
              class="text-base d-flex align-center font-weight-medium d-inline-block"
            >
              <span class="d-inline-block">{{ $t('documentation.seeAllArticles') }}</span>
              <VIcon
                icon="tabler-arrow-right"
                size="18"
                class="ms-3 flip-in-rtl"
              />
            </RouterLink>
          </VCardText>
          <VCardText v-else>
            <RouterLink
              :to="topicRoute(topic)"
              class="text-base d-flex align-center font-weight-medium d-inline-block"
            >
              <span class="d-inline-block">{{ $t('documentation.seeAllArticles') }}</span>
              <VIcon
                icon="tabler-arrow-right"
                size="18"
                class="ms-3 flip-in-rtl"
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
      <VCard :title="topic.title">
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
        <VCardText v-if="topic.description">
          <p class="text-body-2 text-medium-emphasis mb-3">
            {{ topic.description }}
          </p>
          <RouterLink
            :to="topicRoute(topic)"
            class="text-base d-flex align-center font-weight-medium d-inline-block"
          >
            <span class="d-inline-block">{{ $t('documentation.seeAllArticles') }}</span>
            <VIcon
              icon="tabler-arrow-right"
              size="18"
              class="ms-3 flip-in-rtl"
            />
          </RouterLink>
        </VCardText>
        <VCardText v-else>
          <RouterLink
            :to="topicRoute(topic)"
            class="text-base d-flex align-center font-weight-medium d-inline-block"
          >
            <span class="d-inline-block">{{ $t('documentation.seeAllArticles') }}</span>
            <VIcon
              icon="tabler-arrow-right"
              size="18"
              class="ms-3 flip-in-rtl"
            />
          </RouterLink>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>
