<script setup>
import { usePlatformJobdomainsStore } from '@/modules/platform-admin/jobdomains/jobdomains.store'
import { useAppToast } from '@/composables/useAppToast'

// Sub-component — no definePage — hub: platform/catalog/[tab].vue

const { t } = useI18n()
const router = useRouter()
const jobdomainsStore = usePlatformJobdomainsStore()
const { toast } = useAppToast()

const isLoading = ref(true)

// ─── Create dialog ──────────────────────────────────
const isCreateDialogOpen = ref(false)
const createForm = ref({ key: '', label: '', description: '' })
const createLoading = ref(false)

const handleCreate = async () => {
  createLoading.value = true

  try {
    const data = await jobdomainsStore.createJobdomain({
      key: createForm.value.key,
      label: createForm.value.label,
      description: createForm.value.description || null,
    })

    toast(data.message, 'success')
    isCreateDialogOpen.value = false
    createForm.value = { key: '', label: '', description: '' }

    // Navigate to the newly created jobdomain profile
    router.push({ name: 'platform-jobdomains-id', params: { id: data.jobdomain.id } })
  }
  catch (error) {
    toast(error?.data?.message || t('platformJobdomains.failedToCreate'), 'error')
  }
  finally {
    createLoading.value = false
  }
}

// ─── Delete dialog ──────────────────────────────────
const isDeleteDialogOpen = ref(false)
const deletingJobdomain = ref(null)

const confirmDelete = jobdomain => {
  deletingJobdomain.value = jobdomain
  isDeleteDialogOpen.value = true
}

const handleDelete = async () => {
  if (!deletingJobdomain.value) return

  try {
    const data = await jobdomainsStore.deleteJobdomain(deletingJobdomain.value.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformJobdomains.failedToDelete'), 'error')
  }
  finally {
    isDeleteDialogOpen.value = false
    deletingJobdomain.value = null
  }
}

// ─── Table ──────────────────────────────────────────
const headers = computed(() => [
  { title: t('common.code'), key: 'key', width: '160px' },
  { title: t('common.name'), key: 'label' },
  { title: t('Companies'), key: 'companies_count', width: '120px', align: 'center' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '160px', sortable: false },
])

// ─── Load data ──────────────────────────────────────
onMounted(async () => {
  try {
    await jobdomainsStore.fetchJobdomains()
  }
  finally {
    isLoading.value = false
  }
})
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-briefcase"
          class="me-2"
        />
        {{ t('platformJobdomains.title') }}
        <VSpacer />
        <VBtn
          size="small"
          prepend-icon="tabler-plus"
          @click="isCreateDialogOpen = true"
        >
          {{ t('platformJobdomains.addJobDomain') }}
        </VBtn>
      </VCardTitle>

      <VDataTable
        :headers="headers"
        :items="jobdomainsStore.jobdomains"
        :loading="isLoading"
        :items-per-page="-1"
        hide-default-footer
      >
        <template #item.key="{ item }">
          <code class="text-body-2">{{ item.key }}</code>
        </template>

        <template #item.companies_count="{ item }">
          <VChip
            v-if="item.companies_count > 0"
            color="primary"
            size="small"
          >
            {{ item.companies_count }}
          </VChip>
          <span
            v-else
            class="text-disabled"
          >0</span>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex gap-1 justify-center">
            <VBtn
              size="small"
              variant="tonal"
              :to="{ name: 'platform-jobdomains-id', params: { id: item.id } }"
            >
              {{ t('platformJobdomains.manage') }}
            </VBtn>
            <VBtn
              icon
              variant="text"
              size="small"
              color="error"
              :disabled="item.companies_count > 0"
              @click="confirmDelete(item)"
            >
              <VIcon icon="tabler-trash" />
              <VTooltip
                v-if="item.companies_count > 0"
                activator="parent"
                location="top"
              >
                {{ t('platformJobdomains.cannotDelete', { count: item.companies_count }) }}
              </VTooltip>
            </VBtn>
          </div>
        </template>

        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            {{ t('platformJobdomains.noJobDomains') }}
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- ─── Create Dialog ──────────────────────────────── -->
    <VDialog
      v-model="isCreateDialogOpen"
      max-width="500"
    >
      <VCard :title="t('platformJobdomains.newJobDomain')">
        <VCardText>
          <VForm @submit.prevent="handleCreate">
            <VRow>
              <VCol cols="12">
                <AppTextField
                  v-model="createForm.key"
                  :label="t('platformJobdomains.codeLabel')"
                  :placeholder="t('platformJobdomains.codePlaceholder')"
                  :hint="t('platformJobdomains.codeHint')"
                  persistent-hint
                />
              </VCol>

              <VCol cols="12">
                <AppTextField
                  v-model="createForm.label"
                  :label="t('common.name')"
                  :placeholder="t('platformJobdomains.namePlaceholder')"
                />
              </VCol>

              <VCol cols="12">
                <AppTextarea
                  v-model="createForm.description"
                  :label="t('common.description')"
                  :placeholder="t('platformJobdomains.descriptionPlaceholder')"
                  rows="3"
                />
              </VCol>

              <VCol cols="12">
                <div class="d-flex gap-3 justify-end">
                  <VBtn
                    variant="tonal"
                    color="secondary"
                    @click="isCreateDialogOpen = false"
                  >
                    {{ t('common.cancel') }}
                  </VBtn>
                  <VBtn
                    type="submit"
                    :loading="createLoading"
                  >
                    {{ t('common.create') }}
                  </VBtn>
                </div>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- ─── Delete Confirmation Dialog ────────────────── -->
    <VDialog
      v-model="isDeleteDialogOpen"
      max-width="400"
    >
      <VCard>
        <VCardTitle>{{ t('platformJobdomains.confirmDeleteTitle') }}</VCardTitle>
        <VCardText>
          {{ t('platformJobdomains.confirmDeleteMessage', { name: deletingJobdomain?.label }) }}
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            @click="isDeleteDialogOpen = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            @click="handleDelete"
          >
            {{ t('common.delete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
