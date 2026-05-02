<script setup>
import { useHelpCenter } from '@/composables/useHelpCenter'
import HelpCenterFooter from './_HelpCenterFooter.vue'
import HelpCenterHeader from './_HelpCenterHeader.vue'
import HelpCenterKnowledgeBase from './_HelpCenterKnowledgeBase.vue'
import HelpCenterSearchResults from './_HelpCenterSearchResults.vue'

definePage({
  meta: {
    layout: 'blank',
    public: true,
  },
})

const { t } = useI18n()
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

// ── Quick Actions by audience ──
const quickActions = computed(() => {
  const audience = data.value?.audience
  if (audience === 'platform') {
    return [
      { icon: 'tabler-file-invoice', label: t('helpCenter.actions.fixInvoice'), topicSlug: 'facturation-revenus', articleSlug: 'emettre-avoir-appliquer-coupon', color: 'error' },
      { icon: 'tabler-building', label: t('helpCenter.actions.manageClient'), topicSlug: 'gestion-clients', articleSlug: 'consulter-detail-entreprise', color: 'primary' },
      { icon: 'tabler-alert-triangle', label: t('helpCenter.actions.resolveAlert'), topicSlug: 'operations-monitoring', articleSlug: 'centre-alertes', color: 'warning' },
      { icon: 'tabler-puzzle', label: t('helpCenter.actions.activateModule'), topicSlug: 'modules-catalogue', articleSlug: 'gerer-modules-entreprise', color: 'success' },
    ]
  }
  if (audience === 'company') {
    return [
      { icon: 'tabler-user-plus', label: t('helpCenter.actions.addMember'), topicSlug: 'membres', articleSlug: 'inviter-nouveau-membre', color: 'primary' },
      { icon: 'tabler-file-upload', label: t('helpCenter.actions.sendDocument'), topicSlug: 'documents', articleSlug: 'telecharger-gerer-document', color: 'success' },
      { icon: 'tabler-receipt', label: t('helpCenter.actions.understandInvoice'), topicSlug: 'facturation', articleSlug: 'comprendre-votre-facture', color: 'info' },
      { icon: 'tabler-puzzle', label: t('helpCenter.actions.activateModule'), topicSlug: 'modules', articleSlug: 'activer-un-module', color: 'warning' },
    ]
  }
  // public
  return [
    { icon: 'tabler-rocket', label: t('helpCenter.actions.discoverLeezr'), topicSlug: 'presentation', articleSlug: 'quest-ce-que-leezr', color: 'primary' },
    { icon: 'tabler-target', label: t('helpCenter.actions.isItForMe'), topicSlug: 'presentation', articleSlug: 'a-qui-sadresse-leezr', color: 'info' },
    { icon: 'tabler-layout-dashboard', label: t('helpCenter.actions.howItWorks'), topicSlug: 'presentation', articleSlug: 'comment-fonctionne-la-plateforme', color: 'success' },
    { icon: 'tabler-gift', label: t('helpCenter.actions.freeTrial'), topicSlug: 'tarification', articleSlug: 'la-periode-dessai-gratuite', color: 'warning' },
  ]
})

function actionRoute(action) {
  return {
    name: 'help-center-topic-slug-article-slug',
    params: { topicSlug: action.topicSlug, articleSlug: action.articleSlug },
  }
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

      <!-- Quick Actions -->
      <div class="help-center-section bg-surface">
        <VContainer>
          <h5 class="text-h5 text-center mb-6">
            {{ $t('helpCenter.whatDoYouWant') }}
          </h5>
          <VRow class="justify-center">
            <VCol
              v-for="action in quickActions"
              :key="action.articleSlug"
              cols="6"
              sm="3"
            >
              <VCard
                :to="actionRoute(action)"
                class="text-center pa-4 h-100 quick-action-card"
                flat
                border
              >
                <VAvatar
                  :color="action.color"
                  variant="tonal"
                  size="48"
                  class="mb-3"
                >
                  <VIcon
                    :icon="action.icon"
                    size="24"
                  />
                </VAvatar>
                <div class="text-body-1 font-weight-medium">
                  {{ action.label }}
                </div>
              </VCard>
            </VCol>
          </VRow>
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

  .search-results-overlay {
    position: absolute;
    z-index: 10;
    inline-size: 100%;
  }

  .quick-action-card {
    cursor: pointer;
    transition: all 0.2s ease;

    &:hover {
      border-color: rgba(var(--v-theme-primary), 0.5) !important;
      transform: translateY(-2px);
    }
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
  }
}
</style>
