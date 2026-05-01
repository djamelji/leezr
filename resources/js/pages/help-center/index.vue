<script setup>
import { useHelpCenter } from '@/composables/useHelpCenter'
import HelpCenterFooter from './_HelpCenterFooter.vue'
import HelpCenterHeader from './_HelpCenterHeader.vue'
import HelpCenterKnowledgeBase from './_HelpCenterKnowledgeBase.vue'
import HelpCenterQuickActions from './_HelpCenterQuickActions.vue'
import HelpCenterSearchResults from './_HelpCenterSearchResults.vue'

definePage({
  meta: {
    layout: 'blank',
    public: true,
  },
})

const { data, searchResults, hasSupportModule, loading, fetchLanding, search } = useHelpCenter()
const searchQuery = ref('')
const showResults = ref(false)

let debounceTimer = null

function onSearch(val) {
  searchQuery.value = val
  clearTimeout(debounceTimer)
  if (!val || val.length < 2) {
    searchResults.value = []
    showResults.value = false

    return
  }
  debounceTimer = setTimeout(async () => {
    await search(val)
    showResults.value = true
  }, 300)
}

onMounted(fetchLanding)
</script>

<template>
  <div class="help-center-page">
    <HelpCenterHeader />

    <div v-if="data">
      <!-- Search hero -->
      <div style="position: relative;">
        <AppSearchHeader
          :subtitle="$t('documentation.heroSubtitle')"
          custom-class="rounded-0"
          :placeholder="$t('documentation.searchPlaceholder')"
          @update:model-value="onSearch"
        >
          <template #title>
            <h4
              class="text-h4 font-weight-medium"
              style="color: rgba(var(--v-theme-primary), 1);"
            >
              {{ $t('documentation.publicTitle') }}
            </h4>
          </template>
        </AppSearchHeader>

        <!-- Search results overlay -->
        <div
          v-if="showResults"
          class="search-results-overlay"
        >
          <VContainer>
            <HelpCenterSearchResults
              :results="searchResults"
              :query="searchQuery"
              :has-support-module="hasSupportModule"
              @select="showResults = false"
            />
          </VContainer>
        </div>
      </div>

      <!-- Quick Actions — "Que voulez-vous faire ?" -->
      <div class="help-center-section-compact">
        <VContainer>
          <HelpCenterQuickActions
            :groups="data.groups"
            :ungrouped-topics="data.ungrouped_topics"
          />
        </VContainer>
      </div>

      <!-- Knowledge Base -->
      <div class="help-center-section">
        <VContainer>
          <h4 class="text-h4 text-center mb-6">
            {{ $t('documentation.knowledgeBase') }}
          </h4>
          <HelpCenterKnowledgeBase
            :groups="data.groups"
            :ungrouped-topics="data.ungrouped_topics"
          />
        </VContainer>
      </div>

      <!-- Still need help? -->
      <div class="help-center-section bg-surface">
        <HelpCenterFooter />
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
  </div>
</template>

<style lang="scss">
.help-center-page {
  .search-header {
    background-size: cover !important;
    padding-block: 9.25rem 4.75rem !important;
  }

  .help-center-section {
    padding-block: 5.25rem;
  }

  .help-center-section-compact {
    padding-block: 3rem 1rem;
  }

  .search-results-overlay {
    position: absolute;
    z-index: 10;
    inline-size: 100%;
  }
}

@media (max-width: 960px) and (min-width: 600px) {
  .help-center-page {
    .v-container {
      padding-inline: 2rem !important;
    }
  }
}

@media (max-width: 599px) {
  .help-center-page {
    .search-header {
      padding-block: 7rem 2rem !important;
      padding-inline: 2rem !important;
    }

    .help-center-section {
      padding-block: 3.5rem;
    }

    .help-center-section-compact {
      padding-block: 2rem 0.5rem;
    }
  }
}
</style>
