<script setup>
import { usePlatformDocumentationStore } from '@/modules/platform-admin/documentation/documentation.store'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.documentation',
    permission: 'manage_documentation',
  },
})

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const docStore = usePlatformDocumentationStore()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()

const topicId = computed(() => route.params.slug)
const isLoading = ref(true)
const isDrawerOpen = ref(false)
const isEditMode = ref(false)
const editingArticle = ref(null)
const formLoading = ref(false)

const defaultForm = {
  topic_id: null,
  title: '',
  content: '',
  excerpt: '',
  audience: 'company',
  is_published: false,
  sort_order: 0,
}

const form = ref({ ...defaultForm })

const audienceOptions = computed(() => [
  { title: t('documentation.audienceCompany'), value: 'company' },
  { title: t('documentation.audiencePlatform'), value: 'platform' },
  { title: t('documentation.audienceBoth'), value: 'both' },
])

const headers = computed(() => [
  { title: t('common.title'), key: 'title' },
  { title: t('documentation.audience'), key: 'audience', width: '130px' },
  { title: t('documentation.helpful'), key: 'helpful', width: '120px', align: 'center', sortable: false },
  { title: t('common.status'), key: 'is_published', width: '120px' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '120px', sortable: false },
])

const loadData = async () => {
  isLoading.value = true
  try {
    await docStore.fetchTopic(topicId.value)
  }
  finally {
    isLoading.value = false
  }
}

onMounted(loadData)

const topic = computed(() => docStore.currentTopic)
const articles = computed(() => topic.value?.articles || [])

const audienceColor = audience => {
  if (audience === 'platform') return 'info'
  if (audience === 'company') return 'success'

  return 'warning'
}

const helpfulPercent = article => {
  const total = (article.helpful_count || 0) + (article.not_helpful_count || 0)

  if (!total) return '-'

  const pct = Math.round((article.helpful_count / total) * 100)

  return `${pct}%`
}

const openCreateDrawer = () => {
  isEditMode.value = false
  editingArticle.value = null
  form.value = { ...defaultForm, topic_id: topicId.value }
  isDrawerOpen.value = true
}

const openEditDrawer = article => {
  isEditMode.value = true
  editingArticle.value = article
  form.value = {
    topic_id: article.topic_id,
    title: article.title,
    content: article.content || '',
    excerpt: article.excerpt || '',
    audience: article.audience,
    is_published: article.is_published,
    sort_order: article.sort_order,
  }
  isDrawerOpen.value = true
}

const handleSubmit = async () => {
  formLoading.value = true
  try {
    if (isEditMode.value) {
      await docStore.updateArticle(editingArticle.value.id, form.value)
      toast(t('common.updateSuccess'), 'success')
    }
    else {
      await docStore.createArticle(form.value)
      toast(t('common.createSuccess'), 'success')
    }
    isDrawerOpen.value = false
    await loadData()
  }
  catch (error) {
    toast(error?.data?.message || t('common.operationFailed'), 'error')
  }
  finally {
    formLoading.value = false
  }
}

const deleteArticle = async article => {
  const ok = await confirm({
    question: t('documentation.confirmDeleteArticle', { title: article.title }),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('common.deleteSuccess'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })
  if (!ok)
    return

  try {
    await docStore.deleteArticle(article.id)
    toast(t('common.deleteSuccess'), 'success')
    await loadData()
  }
  catch (error) {
    toast(error?.data?.message || t('common.operationFailed'), 'error')
  }
}

const goBack = () => {
  router.push({ name: 'platform-documentation' })
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center gap-4 mb-6">
      <VBtn
        icon
        variant="text"
        @click="goBack"
      >
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <div v-if="topic">
        <h4 class="text-h4">
          {{ topic.title }}
        </h4>
        <p
          v-if="topic.description"
          class="text-body-2 text-disabled mb-0"
        >
          {{ topic.description }}
        </p>
      </div>
    </div>

    <VCard>
      <VCardText>
        <div class="d-flex align-center justify-space-between flex-wrap gap-4 mb-4">
          <h5 class="text-h5">
            {{ t('documentation.articles') }}
          </h5>
          <VBtn
            prepend-icon="tabler-plus"
            @click="openCreateDrawer"
          >
            {{ t('documentation.newArticle') }}
          </VBtn>
        </div>

        <VDataTable
          :items="articles"
          :headers="headers"
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

          <template #item.helpful="{ item }">
            <div class="d-flex align-center justify-center gap-2">
              <VIcon
                icon="tabler-thumb-up"
                size="16"
                color="success"
              />
              <span class="text-body-2">{{ item.helpful_count || 0 }}</span>
              <VIcon
                icon="tabler-thumb-down"
                size="16"
                color="error"
              />
              <span class="text-body-2">{{ item.not_helpful_count || 0 }}</span>
              <span class="text-caption text-disabled">({{ helpfulPercent(item) }})</span>
            </div>
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
                @click="openEditDrawer(item)"
              >
                <VIcon
                  icon="tabler-pencil"
                  size="20"
                />
              </IconBtn>
              <IconBtn
                size="small"
                color="error"
                @click="deleteArticle(item)"
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

    <!-- Drawer create/edit article -->
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="600"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5">
          {{ isEditMode ? t('documentation.editArticle') : t('documentation.newArticle') }}
        </h5>
        <VSpacer />
        <IconBtn @click="isDrawerOpen = false">
          <VIcon icon="tabler-x" />
        </IconBtn>
      </div>

      <VDivider />

      <div class="pa-4">
        <VForm @submit.prevent="handleSubmit">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="form.title"
                :label="t('common.title')"
                required
              />
            </VCol>
            <VCol cols="12">
              <AppTextarea
                v-model="form.excerpt"
                :label="t('documentation.excerpt')"
                rows="2"
              />
            </VCol>
            <VCol cols="12">
              <AppTextarea
                v-model="form.content"
                :label="t('documentation.content')"
                rows="12"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                v-model="form.audience"
                :items="audienceOptions"
                :label="t('documentation.audience')"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
            >
              <AppTextField
                v-model.number="form.sort_order"
                :label="t('common.sortOrder')"
                type="number"
              />
            </VCol>
            <VCol cols="12">
              <VSwitch
                v-model="form.is_published"
                :label="t('documentation.published')"
                color="success"
              />
            </VCol>
            <VCol cols="12">
              <VBtn
                type="submit"
                block
                :loading="formLoading"
              >
                {{ isEditMode ? t('common.update') : t('common.create') }}
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <ConfirmDialogComponent />
  </div>
</template>
