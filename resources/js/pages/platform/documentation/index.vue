<script setup>
import { usePlatformDocumentationStore } from '@/modules/platform-admin/documentation/documentation.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.documentation',
    permission: 'manage_documentation',
  },
})

const { t } = useI18n()
const router = useRouter()
const docStore = usePlatformDocumentationStore()
const { toast } = useAppToast()

const activeTab = ref('topics')
const isLoading = ref(true)

// ── Topics ──────────────────────────────────────
const isTopicDrawerOpen = ref(false)
const isTopicEditMode = ref(false)
const editingTopic = ref(null)
const topicFormLoading = ref(false)
const audienceFilter = ref('')
const publishedFilter = ref('')

const defaultTopicForm = { title: '', description: '', icon: 'tabler-book', group_id: null, audience: 'company', is_published: false, sort_order: 0 }
const topicForm = ref({ ...defaultTopicForm })

// ── Groups ──────────────────────────────────────
const isGroupDrawerOpen = ref(false)
const isGroupEditMode = ref(false)
const editingGroup = ref(null)
const groupFormLoading = ref(false)

const defaultGroupForm = { title: '', icon: 'tabler-folder', audience: 'company', is_published: false, sort_order: 0 }
const groupForm = ref({ ...defaultGroupForm })

// ── Shared ──────────────────────────────────────
const audienceOptions = computed(() => [
  { title: t('documentation.audienceCompany'), value: 'company' },
  { title: t('documentation.audiencePlatform'), value: 'platform' },
  { title: t('documentation.audiencePublic'), value: 'public' },
])

const audienceFilterOptions = computed(() => [
  { title: t('common.all'), value: '' },
  ...audienceOptions.value,
])

const publishedFilterOptions = computed(() => [
  { title: t('common.all'), value: '' },
  { title: t('documentation.published'), value: 'true' },
  { title: t('documentation.draft'), value: 'false' },
])

const audienceColor = audience => {
  if (audience === 'platform') return 'info'
  if (audience === 'company') return 'success'
  if (audience === 'public') return 'primary'

  return 'warning'
}

// ── Topics headers ──────────────────────────────
const topicHeaders = computed(() => [
  { title: '', key: 'icon', width: '48px', sortable: false },
  { title: t('common.title'), key: 'title' },
  { title: t('documentation.audience'), key: 'audience', width: '130px' },
  { title: t('documentation.articles'), key: 'articles_count', width: '100px', align: 'center' },
  { title: t('common.status'), key: 'is_published', width: '120px' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '100px', sortable: false },
])

// ── Groups headers ──────────────────────────────
const groupHeaders = computed(() => [
  { title: '', key: 'icon', width: '48px', sortable: false },
  { title: t('common.title'), key: 'title' },
  { title: t('documentation.audience'), key: 'audience', width: '130px' },
  { title: t('documentation.topicsCount'), key: 'topics_count', width: '100px', align: 'center' },
  { title: t('common.status'), key: 'is_published', width: '120px' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '100px', sortable: false },
])

// ── Search misses headers ───────────────────────
const missHeaders = computed(() => [
  { title: t('documentation.searchMissQuery'), key: 'query' },
  { title: t('documentation.searchMissCount'), key: 'search_count', width: '150px', align: 'center' },
  { title: t('documentation.lastSearched'), key: 'last_searched_at', width: '200px' },
])

// ── Groups select options for topic form ────────
const groupSelectOptions = computed(() => [
  { title: t('common.none'), value: null },
  ...docStore.groups.map(g => ({ title: g.title, value: g.id })),
])

// ── Load data ───────────────────────────────────
const loadTopics = async () => {
  isLoading.value = true
  try {
    const params = { per_page: 50 }

    if (audienceFilter.value) params.audience = audienceFilter.value
    if (publishedFilter.value) params.is_published = publishedFilter.value

    await docStore.fetchTopics(params)
  }
  finally {
    isLoading.value = false
  }
}

const loadGroups = async () => {
  isLoading.value = true
  try {
    await docStore.fetchGroups({ per_page: 50 })
  }
  finally {
    isLoading.value = false
  }
}

const loadFeedback = async () => {
  isLoading.value = true
  try {
    await docStore.fetchFeedbackStats()
  }
  finally {
    isLoading.value = false
  }
}

const loadSearchMisses = async () => {
  isLoading.value = true
  try {
    await docStore.fetchSearchMisses()
  }
  finally {
    isLoading.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadTopics(), loadGroups()])
})

watch(activeTab, tab => {
  if (tab === 'feedback') loadFeedback()
  else if (tab === 'searchMisses') loadSearchMisses()
})

watch([audienceFilter, publishedFilter], loadTopics)

// ── Topic CRUD ──────────────────────────────────
const openCreateTopicDrawer = () => {
  isTopicEditMode.value = false
  editingTopic.value = null
  topicForm.value = { ...defaultTopicForm }
  isTopicDrawerOpen.value = true
}

const openEditTopicDrawer = topic => {
  isTopicEditMode.value = true
  editingTopic.value = topic
  topicForm.value = {
    title: topic.title,
    description: topic.description || '',
    icon: topic.icon || 'tabler-book',
    group_id: topic.group_id || null,
    audience: topic.audience,
    is_published: topic.is_published,
    sort_order: topic.sort_order,
  }
  isTopicDrawerOpen.value = true
}

const handleTopicSubmit = async () => {
  topicFormLoading.value = true
  try {
    if (isTopicEditMode.value) {
      await docStore.updateTopic(editingTopic.value.id, topicForm.value)
      toast(t('common.updateSuccess'), 'success')
    }
    else {
      await docStore.createTopic(topicForm.value)
      toast(t('common.createSuccess'), 'success')
    }
    isTopicDrawerOpen.value = false
    await loadTopics()
  }
  catch (error) {
    toast(error?.data?.message || t('common.operationFailed'), 'error')
  }
  finally {
    topicFormLoading.value = false
  }
}

const deleteTopic = async topic => {
  if (!confirm(t('documentation.confirmDeleteTopic', { title: topic.title }))) return
  try {
    await docStore.deleteTopic(topic.id)
    toast(t('common.deleteSuccess'), 'success')
    await loadTopics()
  }
  catch (error) {
    toast(error?.data?.message || t('common.operationFailed'), 'error')
  }
}

const navigateToTopic = topic => {
  router.push({ name: 'platform-documentation-slug', params: { slug: topic.id } })
}

// ── Group CRUD ──────────────────────────────────
const openCreateGroupDrawer = () => {
  isGroupEditMode.value = false
  editingGroup.value = null
  groupForm.value = { ...defaultGroupForm }
  isGroupDrawerOpen.value = true
}

const openEditGroupDrawer = group => {
  isGroupEditMode.value = true
  editingGroup.value = group
  groupForm.value = {
    title: group.title,
    icon: group.icon || 'tabler-folder',
    audience: group.audience,
    is_published: group.is_published,
    sort_order: group.sort_order,
  }
  isGroupDrawerOpen.value = true
}

const handleGroupSubmit = async () => {
  groupFormLoading.value = true
  try {
    if (isGroupEditMode.value) {
      await docStore.updateGroup(editingGroup.value.id, groupForm.value)
      toast(t('common.updateSuccess'), 'success')
    }
    else {
      await docStore.createGroup(groupForm.value)
      toast(t('common.createSuccess'), 'success')
    }
    isGroupDrawerOpen.value = false
    await loadGroups()
  }
  catch (error) {
    toast(error?.data?.message || t('common.operationFailed'), 'error')
  }
  finally {
    groupFormLoading.value = false
  }
}

const deleteGroup = async group => {
  if (!confirm(t('documentation.confirmDeleteGroup', { title: group.title }))) return
  try {
    await docStore.deleteGroup(group.id)
    toast(t('common.deleteSuccess'), 'success')
    await loadGroups()
  }
  catch (error) {
    toast(error?.data?.message || t('common.operationFailed'), 'error')
  }
}

// ── Feedback headers ────────────────────────────
const feedbackHeaders = computed(() => [
  { title: t('common.title'), key: 'title' },
  { title: t('documentation.audience'), key: 'audience', width: '120px' },
  { title: t('documentation.helpful'), key: 'helpful_count', width: '100px', align: 'center' },
  { title: t('documentation.notHelpful'), key: 'not_helpful_count', width: '120px', align: 'center' },
  { title: t('documentation.ratio'), key: 'ratio', width: '100px', align: 'center' },
])

const feedbackRatio = item => {
  const total = item.helpful_count + item.not_helpful_count

  return total > 0 ? Math.round((item.not_helpful_count / total) * 100) : 0
}
</script>

<template>
  <div>
    <VTabs v-model="activeTab">
      <VTab value="topics">
        {{ t('documentation.topics') }}
      </VTab>
      <VTab value="groups">
        {{ t('documentation.groups') }}
      </VTab>
      <VTab value="feedback">
        {{ t('documentation.feedback') }}
      </VTab>
      <VTab value="searchMisses">
        {{ t('documentation.searchMisses') }}
      </VTab>
    </VTabs>

    <VDivider />

    <VTabsWindow v-model="activeTab">
      <!-- ══════ Topics Tab ══════ -->
      <VTabsWindowItem value="topics">
        <VCard flat>
          <VCardText>
            <div class="d-flex align-center justify-space-between flex-wrap gap-4 mb-4">
              <h5 class="text-h5">
                {{ t('documentation.topics') }}
              </h5>
              <VBtn
                prepend-icon="tabler-plus"
                @click="openCreateTopicDrawer"
              >
                {{ t('documentation.newTopic') }}
              </VBtn>
            </div>

            <VRow class="mb-4">
              <VCol
                cols="12"
                sm="4"
              >
                <AppSelect
                  v-model="audienceFilter"
                  :items="audienceFilterOptions"
                  :label="t('documentation.audience')"
                  clearable
                />
              </VCol>
              <VCol
                cols="12"
                sm="4"
              >
                <AppSelect
                  v-model="publishedFilter"
                  :items="publishedFilterOptions"
                  :label="t('common.status')"
                  clearable
                />
              </VCol>
            </VRow>

            <VDataTable
              :items="docStore.topics"
              :headers="topicHeaders"
              :loading="isLoading"
              class="text-no-wrap"
              @click:row="(_, { item }) => navigateToTopic(item)"
            >
              <template #item.icon="{ item }">
                <VAvatar
                  rounded
                  color="primary"
                  variant="tonal"
                  size="32"
                >
                  <VIcon
                    :icon="item.icon || 'tabler-book'"
                    size="20"
                  />
                </VAvatar>
              </template>

              <template #item.audience="{ item }">
                <VChip
                  :color="audienceColor(item.audience)"
                  size="small"
                  label
                >
                  {{ t(`documentation.audience${item.audience.charAt(0).toUpperCase() + item.audience.slice(1)}`) }}
                </VChip>
              </template>

              <template #item.is_published="{ item }">
                <VChip
                  :color="item.is_published ? 'success' : 'secondary'"
                  size="small"
                  label
                >
                  {{ item.is_published ? t('documentation.published') : t('documentation.draft') }}
                </VChip>
              </template>

              <template #item.actions="{ item }">
                <div class="d-flex gap-1 justify-center">
                  <IconBtn
                    size="small"
                    @click.stop="openEditTopicDrawer(item)"
                  >
                    <VIcon
                      icon="tabler-pencil"
                      size="20"
                    />
                  </IconBtn>
                  <IconBtn
                    size="small"
                    color="error"
                    @click.stop="deleteTopic(item)"
                  >
                    <VIcon
                      icon="tabler-trash"
                      size="20"
                    />
                  </IconBtn>
                </div>
              </template>
            </VDataTable>
          </VCardText>
        </VCard>
      </VTabsWindowItem>

      <!-- ══════ Groups Tab ══════ -->
      <VTabsWindowItem value="groups">
        <VCard flat>
          <VCardText>
            <div class="d-flex align-center justify-space-between flex-wrap gap-4 mb-4">
              <h5 class="text-h5">
                {{ t('documentation.groups') }}
              </h5>
              <VBtn
                prepend-icon="tabler-plus"
                @click="openCreateGroupDrawer"
              >
                {{ t('documentation.newGroup') }}
              </VBtn>
            </div>

            <VDataTable
              :items="docStore.groups"
              :headers="groupHeaders"
              :loading="isLoading"
              class="text-no-wrap"
            >
              <template #item.icon="{ item }">
                <VAvatar
                  rounded
                  color="primary"
                  variant="tonal"
                  size="32"
                >
                  <VIcon
                    :icon="item.icon || 'tabler-folder'"
                    size="20"
                  />
                </VAvatar>
              </template>

              <template #item.audience="{ item }">
                <VChip
                  :color="audienceColor(item.audience)"
                  size="small"
                  label
                >
                  {{ t(`documentation.audience${item.audience.charAt(0).toUpperCase() + item.audience.slice(1)}`) }}
                </VChip>
              </template>

              <template #item.is_published="{ item }">
                <VChip
                  :color="item.is_published ? 'success' : 'secondary'"
                  size="small"
                  label
                >
                  {{ item.is_published ? t('documentation.published') : t('documentation.draft') }}
                </VChip>
              </template>

              <template #item.actions="{ item }">
                <div class="d-flex gap-1 justify-center">
                  <IconBtn
                    size="small"
                    @click.stop="openEditGroupDrawer(item)"
                  >
                    <VIcon
                      icon="tabler-pencil"
                      size="20"
                    />
                  </IconBtn>
                  <IconBtn
                    size="small"
                    color="error"
                    @click.stop="deleteGroup(item)"
                  >
                    <VIcon
                      icon="tabler-trash"
                      size="20"
                    />
                  </IconBtn>
                </div>
              </template>
            </VDataTable>
          </VCardText>
        </VCard>
      </VTabsWindowItem>

      <!-- ══════ Feedback Tab ══════ -->
      <VTabsWindowItem value="feedback">
        <VCard flat>
          <VCardText>
            <h5 class="text-h5 mb-4">
              {{ t('documentation.feedback') }}
            </h5>

            <VDataTable
              :items="docStore.feedbackStats"
              :headers="feedbackHeaders"
              :loading="isLoading"
              class="text-no-wrap"
            >
              <template #item.audience="{ item }">
                <VChip
                  :color="audienceColor(item.audience)"
                  size="small"
                  label
                >
                  {{ t(`documentation.audience${item.audience.charAt(0).toUpperCase() + item.audience.slice(1)}`) }}
                </VChip>
              </template>

              <template #item.ratio="{ item }">
                <VChip
                  :color="feedbackRatio(item) > 50 ? 'error' : 'success'"
                  size="small"
                  label
                >
                  {{ feedbackRatio(item) }}% {{ t('documentation.negative') }}
                </VChip>
              </template>
            </VDataTable>
          </VCardText>
        </VCard>
      </VTabsWindowItem>

      <!-- ══════ Search Misses Tab ══════ -->
      <VTabsWindowItem value="searchMisses">
        <VCard flat>
          <VCardText>
            <h5 class="text-h5 mb-2">
              {{ t('documentation.searchMisses') }}
            </h5>
            <p class="text-body-2 text-medium-emphasis mb-4">
              {{ t('documentation.searchMissesHint') }}
            </p>

            <VDataTable
              :items="docStore.searchMisses"
              :headers="missHeaders"
              :loading="isLoading"
              class="text-no-wrap"
            >
              <template #item.last_searched_at="{ item }">
                {{ item.last_searched_at ? new Date(item.last_searched_at).toLocaleDateString() : '-' }}
              </template>
            </VDataTable>
          </VCardText>
        </VCard>
      </VTabsWindowItem>
    </VTabsWindow>

    <!-- ══════ Topic Drawer ══════ -->
    <VNavigationDrawer
      v-model="isTopicDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5">
          {{ isTopicEditMode ? t('documentation.editTopic') : t('documentation.newTopic') }}
        </h5>
        <VSpacer />
        <IconBtn @click="isTopicDrawerOpen = false">
          <VIcon icon="tabler-x" />
        </IconBtn>
      </div>
      <VDivider />
      <div class="pa-4">
        <VForm @submit.prevent="handleTopicSubmit">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="topicForm.title"
                :label="t('common.title')"
                required
              />
            </VCol>
            <VCol cols="12">
              <AppTextarea
                v-model="topicForm.description"
                :label="t('common.description')"
                rows="3"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="topicForm.icon"
                :label="t('documentation.icon')"
                placeholder="tabler-book"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="topicForm.group_id"
                :items="groupSelectOptions"
                :label="t('documentation.group')"
                clearable
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="topicForm.audience"
                :items="audienceOptions"
                :label="t('documentation.audience')"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model.number="topicForm.sort_order"
                :label="t('common.sortOrder')"
                type="number"
              />
            </VCol>
            <VCol cols="12">
              <VSwitch
                v-model="topicForm.is_published"
                :label="t('documentation.published')"
                color="success"
              />
            </VCol>
            <VCol cols="12">
              <VBtn
                type="submit"
                block
                :loading="topicFormLoading"
              >
                {{ isTopicEditMode ? t('common.update') : t('common.create') }}
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- ══════ Group Drawer ══════ -->
    <VNavigationDrawer
      v-model="isGroupDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5">
          {{ isGroupEditMode ? t('documentation.editGroup') : t('documentation.newGroup') }}
        </h5>
        <VSpacer />
        <IconBtn @click="isGroupDrawerOpen = false">
          <VIcon icon="tabler-x" />
        </IconBtn>
      </div>
      <VDivider />
      <div class="pa-4">
        <VForm @submit.prevent="handleGroupSubmit">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="groupForm.title"
                :label="t('common.title')"
                required
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="groupForm.icon"
                :label="t('documentation.icon')"
                placeholder="tabler-folder"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="groupForm.audience"
                :items="audienceOptions"
                :label="t('documentation.audience')"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model.number="groupForm.sort_order"
                :label="t('common.sortOrder')"
                type="number"
              />
            </VCol>
            <VCol cols="12">
              <VSwitch
                v-model="groupForm.is_published"
                :label="t('documentation.published')"
                color="success"
              />
            </VCol>
            <VCol cols="12">
              <VBtn
                type="submit"
                block
                :loading="groupFormLoading"
              >
                {{ isGroupEditMode ? t('common.update') : t('common.create') }}
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>
  </div>
</template>
