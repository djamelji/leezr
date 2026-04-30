<script setup>
import Shepherd from 'shepherd.js'
import { useConfigStore } from '@core/stores/config'
import { $platformApi } from '@/utils/platformApi'

defineOptions({
  inheritAttrs: false,
})

const { t } = useI18n()
const configStore = useConfigStore()
const isAppSearchBarVisible = ref(false)
const isLoading = ref(false)

// 👉 Default suggestions — real platform routes
const suggestionGroups = computed(() => [
  {
    title: t('globalSearch.management'),
    content: [
      {
        icon: 'tabler-dashboard',
        title: t('globalSearch.dashboard'),
        url: { name: 'platform' },
      },
      {
        icon: 'tabler-user-shield',
        title: t('globalSearch.users'),
        url: { name: 'platform-access-tab', params: { tab: 'users' } },
      },
      {
        icon: 'tabler-building',
        title: t('globalSearch.companies'),
        url: { name: 'platform-companies' },
      },
      {
        icon: 'tabler-shield-lock',
        title: t('globalSearch.roles'),
        url: { name: 'platform-access-tab', params: { tab: 'roles' } },
      },
    ],
  },
  {
    title: t('globalSearch.configuration'),
    content: [
      {
        icon: 'tabler-briefcase',
        title: t('globalSearch.jobDomains'),
        url: { name: 'platform-jobdomains' },
      },
      {
        icon: 'tabler-forms',
        title: t('globalSearch.customFields'),
        url: { name: 'platform-fields' },
      },
      {
        icon: 'tabler-puzzle',
        title: t('globalSearch.modules'),
        url: { name: 'platform-modules' },
      },
      {
        icon: 'tabler-settings',
        title: t('globalSearch.settings'),
        url: { name: 'platform-settings-tab', params: { tab: 'general' } },
      },
    ],
  },
])

// 👉 No Data suggestion
const noDataSuggestions = computed(() => [
  {
    title: t('globalSearch.dashboard'),
    icon: 'tabler-dashboard',
    url: { name: 'platform' },
  },
  {
    title: t('globalSearch.users'),
    icon: 'tabler-user-shield',
    url: { name: 'platform-access-tab', params: { tab: 'users' } },
  },
  {
    title: t('globalSearch.companies'),
    icon: 'tabler-building',
    url: { name: 'platform-companies' },
  },
])

// i18n mapping for backend group titles
const groupTitleMap = {
  Companies: 'globalSearch.companies',
  Users: 'globalSearch.users',
  Invoices: 'globalSearch.invoices',
  'Support Tickets': 'globalSearch.tickets',
}

const translateGroupTitle = title => {
  const key = groupTitleMap[title]

  return key ? t(key) : title
}

const searchQuery = ref('')
const router = useRouter()
const searchResult = ref([])

const fetchResults = async () => {
  const q = searchQuery.value?.trim()
  if (!q || q.length < 2) {
    searchResult.value = []

    return
  }

  isLoading.value = true

  try {
    const data = await $platformApi('/search', { query: { q } })

    searchResult.value = data ?? []
  }
  catch {
    searchResult.value = []
  }
  finally {
    isLoading.value = false
  }
}

watch(searchQuery, fetchResults)

const closeSearchBar = () => {
  isAppSearchBarVisible.value = false
  searchQuery.value = ''
}

const redirectToSuggestedPage = selected => {
  router.push(selected.url)
  closeSearchBar()
}

const LazyAppBarSearch = defineAsyncComponent(() => import('@core/components/AppBarSearch.vue'))
</script>

<template>
  <div
    class="d-flex align-center cursor-pointer"
    v-bind="$attrs"
    style="user-select: none;"
    @click="isAppSearchBarVisible = !isAppSearchBarVisible"
  >
    <!-- 👉 Search Trigger button -->
    <!-- close active tour while opening search bar using icon -->
    <IconBtn @click="Shepherd.activeTour?.cancel()">
      <VIcon icon="tabler-search" />
    </IconBtn>

    <span
      v-if="configStore.appContentLayoutNav === 'vertical'"
      class="d-none d-md-flex align-center text-disabled ms-2"
      @click="Shepherd.activeTour?.cancel()"
    >
      <span class="me-2">{{ t('globalSearch.placeholder') }}</span>
      <span class="meta-key">&#8984;K</span>
    </span>
  </div>

  <!-- 👉 App Bar Search -->
  <LazyAppBarSearch
    v-model:is-dialog-visible="isAppSearchBarVisible"
    :search-results="searchResult"
    :is-loading="isLoading"
    @search="searchQuery = $event"
  >
    <!-- suggestion -->
    <template #suggestions>
      <VCardText class="app-bar-search-suggestions pa-12">
        <VRow v-if="suggestionGroups">
          <VCol
            v-for="suggestion in suggestionGroups"
            :key="suggestion.title"
            cols="12"
            sm="6"
          >
            <p
              class="custom-letter-spacing text-disabled text-uppercase py-2 px-4 mb-0"
              style="font-size: 0.75rem; line-height: 0.875rem;"
            >
              {{ suggestion.title }}
            </p>
            <VList class="card-list">
              <VListItem
                v-for="item in suggestion.content"
                :key="item.title"
                class="app-bar-search-suggestion mx-4 mt-2"
                @click="redirectToSuggestedPage(item)"
              >
                <VListItemTitle>{{ item.title }}</VListItemTitle>
                <template #prepend>
                  <VIcon
                    :icon="item.icon"
                    size="20"
                    class="me-n1"
                  />
                </template>
              </VListItem>
            </VList>
          </VCol>
        </VRow>
      </VCardText>
    </template>

    <!-- no data suggestion -->
    <template #noDataSuggestion>
      <div class="mt-9">
        <span class="d-flex justify-center text-disabled mb-2">{{ t('globalSearch.trySuggestions') }}</span>
        <h6
          v-for="suggestion in noDataSuggestions"
          :key="suggestion.title"
          class="app-bar-search-suggestion text-h6 font-weight-regular cursor-pointer py-2 px-4"
          @click="redirectToSuggestedPage(suggestion)"
        >
          <VIcon
            size="20"
            :icon="suggestion.icon"
            class="me-2"
          />
          <span>{{ suggestion.title }}</span>
        </h6>
      </div>
    </template>

    <!-- search result -->
    <template #searchResult="{ item }">
      <VListSubheader class="text-disabled custom-letter-spacing font-weight-regular ps-4">
        {{ translateGroupTitle(item.title) }}
      </VListSubheader>
      <VListItem
        v-for="list in item.children"
        :key="list.title"
        :to="list.url"
        @click="closeSearchBar"
      >
        <template #prepend>
          <VIcon
            size="20"
            :icon="list.icon"
            class="me-n1"
          />
        </template>
        <template #append>
          <VIcon
            size="20"
            icon="tabler-corner-down-left"
            class="enter-icon flip-in-rtl"
          />
        </template>
        <VListItemTitle>
          {{ list.title }}
        </VListItemTitle>
      </VListItem>
    </template>
  </LazyAppBarSearch>
</template>

<style lang="scss">
@use "@styles/variables/vuetify.scss";

.meta-key {
  border: thin solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  block-size: 1.5625rem;
  font-size: 0.8125rem;
  line-height: 1.3125rem;
  padding-block: 0.125rem;
  padding-inline: 0.25rem;
}

.app-bar-search-dialog {
  .custom-letter-spacing {
    letter-spacing: 0.8px;
  }

  .card-list {
    --v-card-list-gap: 8px;
  }
}
</style>
