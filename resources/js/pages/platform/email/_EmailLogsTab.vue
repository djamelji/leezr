<script setup>
import { ref, computed, onMounted } from 'vue'
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'

const { toast } = useAppToast()
const { t } = useI18n()

const logs = ref([])
const stats = ref({ sent_24h: 0, failed_24h: 0, success_rate: 100 })
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
const isLoading = ref(true)
const isError = ref(false)
const errorMessage = ref('')

// Filters
const statusFilter = ref(null)
const templateFilter = ref(null)
const categoryFilter = ref(null)
const templateKeyFilter = ref(null)
const search = ref('')
const templateKeyOptions = ref([])

const statusOptions = [
  { title: t('common.all'), value: null },
  { title: t('email.sent'), value: 'sent' },
  { title: t('email.failed'), value: 'failed' },
  { title: t('email.queued'), value: 'queued' },
]

const categoryOptions = [
  { title: t('common.all'), value: null },
  { title: 'Billing', value: 'billing' },
  { title: 'Documents', value: 'documents' },
  { title: 'Support', value: 'support' },
  { title: 'Members', value: 'members' },
]

const fetchTemplateKeys = async () => {
  try {
    const data = await $platformApi('/email/templates/configurable')
    templateKeyOptions.value = [
      { title: t('common.all'), value: null },
      ...data.templates.map(tpl => ({ title: tpl.name, value: tpl.key })),
    ]
  } catch {
    templateKeyOptions.value = [{ title: t('common.all'), value: null }]
  }
}

const statusChipColor = status => {
  const map = { sent: 'success', failed: 'error', queued: 'warning' }

  return map[status] || 'default'
}

const fetchLogs = async (page = 1) => {
  isLoading.value = true
  isError.value = false
  try {
    const params = new URLSearchParams()

    params.set('page', page)
    if (statusFilter.value) params.set('status', statusFilter.value)
    if (templateFilter.value) params.set('template_key', templateFilter.value)
    if (categoryFilter.value) params.set('category', categoryFilter.value)
    if (templateKeyFilter.value) params.set('template_key', templateKeyFilter.value)
    if (search.value) params.set('search', search.value)

    const data = await $platformApi(`/email/logs?${params}`)

    logs.value = data.data
    stats.value = data.stats
    pagination.value = {
      current_page: data.current_page,
      last_page: data.last_page,
      total: data.total,
    }
  }
  catch (e) {
    isError.value = true
    errorMessage.value = e.message || 'Failed to load email logs'
  }
  finally {
    isLoading.value = false
  }
}

const retryEmail = async id => {
  try {
    await $platformApi(`/email/logs/${id}/retry`, { method: 'POST' })
    toast(t('email.retrySuccess'), 'success')
    await fetchLogs(pagination.value.current_page)
  }
  catch (e) {
    toast(e.message || 'Retry failed', 'error')
  }
}

const handlePageChange = page => {
  fetchLogs(page)
}

// Expanded row
const expandedRows = ref([])

onMounted(() => {
  fetchLogs()
  fetchTemplateKeys()
})

// Watch filters
watch([statusFilter, templateFilter, categoryFilter, templateKeyFilter], () => fetchLogs(1))

const debouncedSearch = useDebounceFn(() => fetchLogs(1), 400)

watch(search, debouncedSearch)
</script>

<template>
  <div>
    <!-- KPI Cards -->
    <VRow class="card-grid card-grid-xs mb-6">
      <VCol
        cols="12"
        sm="4"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              color="success"
              variant="tonal"
              rounded
              size="42"
            >
              <VIcon
                icon="tabler-send"
                size="24"
              />
            </VAvatar>
            <div>
              <div class="text-body-1 font-weight-medium">
                {{ stats.sent_24h }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ t('email.sent24h') }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="12"
        sm="4"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              color="error"
              variant="tonal"
              rounded
              size="42"
            >
              <VIcon
                icon="tabler-alert-triangle"
                size="24"
              />
            </VAvatar>
            <div>
              <div class="text-body-1 font-weight-medium">
                {{ stats.failed_24h }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ t('email.failed24h') }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="12"
        sm="4"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              color="info"
              variant="tonal"
              rounded
              size="42"
            >
              <VIcon
                icon="tabler-percentage"
                size="24"
              />
            </VAvatar>
            <div>
              <div class="text-body-1 font-weight-medium">
                {{ stats.success_rate }}%
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ t('email.successRate') }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters -->
    <VCard>
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            sm="4"
          >
            <AppTextField
              v-model="search"
              :placeholder="t('common.search')"
              prepend-inner-icon="tabler-search"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            sm="4"
          >
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :label="t('email.status')"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            sm="4"
          >
            <AppSelect
              v-model="categoryFilter"
              :items="categoryOptions"
              :label="t('email.category')"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            sm="4"
          >
            <AppSelect
              v-model="templateKeyFilter"
              :items="templateKeyOptions"
              :label="t('email.template')"
              clearable
            />
          </VCol>
        </VRow>
      </VCardText>

      <!-- Loading -->
      <VSkeletonLoader
        v-if="isLoading"
        type="table-heading, table-tbody"
      />

      <!-- Error -->
      <VCardText v-else-if="isError">
        <VAlert
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ errorMessage }}
        </VAlert>
        <VBtn
          color="primary"
          variant="outlined"
          @click="fetchLogs(1)"
        >
          {{ t('common.retry') }}
        </VBtn>
      </VCardText>

      <!-- Empty -->
      <VCardText v-else-if="logs.length === 0">
        <div class="text-center py-8">
          <VIcon
            icon="tabler-mail-off"
            size="48"
            class="text-medium-emphasis mb-4"
          />
          <h6 class="text-h6 mb-1">
            {{ t('email.noLogs') }}
          </h6>
          <p class="text-body-2 text-medium-emphasis">
            {{ t('email.noLogsDescription') }}
          </p>
        </div>
      </VCardText>

      <!-- Table -->
      <template v-else>
        <VTable>
          <thead>
            <tr>
              <th />
              <th>{{ t('email.recipient') }}</th>
              <th>{{ t('email.subject') }}</th>
              <th>{{ t('email.template') }}</th>
              <th>{{ t('email.status') }}</th>
              <th>{{ t('email.date') }}</th>
              <th>{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <template
              v-for="log in logs"
              :key="log.id"
            >
              <tr>
                <td>
                  <VBtn
                    icon
                    variant="text"
                    size="small"
                    @click="expandedRows.includes(log.id) ? expandedRows = expandedRows.filter(id => id !== log.id) : expandedRows.push(log.id)"
                  >
                    <VIcon :icon="expandedRows.includes(log.id) ? 'tabler-chevron-down' : 'tabler-chevron-right'" />
                  </VBtn>
                </td>
                <td>
                  <div class="text-body-2 font-weight-medium">
                    {{ log.recipient_name || log.recipient_email }}
                  </div>
                  <div
                    v-if="log.recipient_name"
                    class="text-caption text-medium-emphasis"
                  >
                    {{ log.recipient_email }}
                  </div>
                </td>
                <td class="text-body-2">
                  {{ log.subject }}
                </td>
                <td>
                  <VChip
                    size="small"
                    variant="tonal"
                    color="secondary"
                  >
                    {{ log.template_key }}
                  </VChip>
                </td>
                <td>
                  <VChip
                    size="small"
                    variant="tonal"
                    :color="statusChipColor(log.status)"
                  >
                    {{ t(`email.${log.status}`) }}
                  </VChip>
                </td>
                <td class="text-body-2 text-no-wrap">
                  {{ log.sent_at || log.created_at }}
                </td>
                <td>
                  <VBtn
                    v-if="log.status === 'failed'"
                    size="small"
                    variant="text"
                    color="primary"
                    @click="retryEmail(log.id)"
                  >
                    {{ t('email.retry') }}
                  </VBtn>
                </td>
              </tr>
              <!-- Expanded row -->
              <tr v-if="expandedRows.includes(log.id)">
                <td
                  colspan="7"
                  class="pa-4"
                  style="background: rgb(var(--v-theme-surface))"
                >
                  <VRow>
                    <VCol
                      cols="12"
                      sm="6"
                    >
                      <div class="text-caption text-medium-emphasis mb-1">
                        Message ID
                      </div>
                      <code class="text-body-2">{{ log.message_id }}</code>
                    </VCol>
                    <VCol
                      cols="12"
                      sm="6"
                    >
                      <div class="text-caption text-medium-emphasis mb-1">
                        From
                      </div>
                      <div class="text-body-2">
                        {{ log.from_email }}
                      </div>
                    </VCol>
                    <VCol
                      v-if="log.error_message"
                      cols="12"
                    >
                      <div class="text-caption text-medium-emphasis mb-1">
                        Erreur
                      </div>
                      <VAlert
                        type="error"
                        variant="tonal"
                        density="compact"
                      >
                        {{ log.error_message }}
                      </VAlert>
                    </VCol>
                    <VCol
                      v-if="log.metadata"
                      cols="12"
                    >
                      <div class="text-caption text-medium-emphasis mb-1">
                        Metadata
                      </div>
                      <code class="text-body-2">{{ JSON.stringify(log.metadata, null, 2) }}</code>
                    </VCol>
                  </VRow>
                </td>
              </tr>
            </template>
          </tbody>
        </VTable>

        <!-- Pagination -->
        <VCardText
          v-if="pagination.last_page > 1"
          class="d-flex justify-center"
        >
          <VPagination
            :model-value="pagination.current_page"
            :length="pagination.last_page"
            @update:model-value="handlePageChange"
          />
        </VCardText>
      </template>
    </VCard>
  </div>
</template>
