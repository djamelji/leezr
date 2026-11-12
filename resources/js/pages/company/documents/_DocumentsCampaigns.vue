<script setup>
// ADR-761 (DMS M4) — campagnes documentaires collectives : liste, création (type + ciblage +
// prévisualisation réelle + échéance + message), suivi (partition + progression).
import { ref, computed, onMounted } from 'vue'
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const { toast } = useAppToast()

const loading = ref(false)
const campaigns = ref([])
const departments = ref([])
const docTypes = ref([])

const drawer = ref(false)
const form = ref({ document_type_code: null, mode: 'all', department_ids: [], message: '', due_date: null, requires_validation: true })
const preview = ref(null)
const previewing = ref(false)
const launching = ref(false)

const tracking = ref(null)
const trackingOpen = ref(false)

const targeting = computed(() => {
  const base = { mode: form.value.mode }
  if (form.value.mode === 'department') base.department_ids = form.value.department_ids

  return base
})

const fetchAll = async () => {
  loading.value = true
  try {
    const data = await $api('/company/document-campaigns')

    campaigns.value = data.campaigns || []
    departments.value = data.departments || []
    docTypes.value = data.document_types || []
  }
  catch { /* silent */ }
  finally { loading.value = false }
}

const runPreview = async () => {
  previewing.value = true
  try {
    preview.value = await $api('/company/document-campaigns/preview', { method: 'POST', body: { targeting: targeting.value } })
  }
  catch (e) { toast(e?.data?.message || t('common.error'), 'error') }
  finally { previewing.value = false }
}

const launch = async () => {
  if (!form.value.document_type_code) return
  launching.value = true
  try {
    await $api('/company/document-campaigns', {
      method: 'POST',
      body: {
        document_type_code: form.value.document_type_code,
        targeting: targeting.value,
        message: form.value.message || null,
        due_date: form.value.due_date || null,
        requires_validation: form.value.requires_validation,
      },
    })
    toast(t('documents.campaign.launched'), 'success')
    drawer.value = false
    preview.value = null
    await fetchAll()
  }
  catch (e) { toast(e?.data?.message || t('common.error'), 'error') }
  finally { launching.value = false }
}

const openTracking = async id => {
  tracking.value = null
  trackingOpen.value = true
  try { tracking.value = await $api(`/company/document-campaigns/${id}`) }
  catch { tracking.value = null }
}

const statusColor = s => ({ draft: 'secondary', launching: 'info', active: 'success', completed: 'success', cancelled: 'error' }[s] || 'secondary')

onMounted(fetchAll)
</script>

<template>
  <VCard>
    <VCardItem>
      <VCardTitle>{{ t('documents.campaign.title') }}</VCardTitle>
      <VCardSubtitle>{{ t('documents.campaign.subtitle') }}</VCardSubtitle>
      <template #append>
        <VBtn
          color="primary"
          prepend-icon="tabler-send"
          @click="drawer = true"
        >
          {{ t('documents.campaign.new') }}
        </VBtn>
      </template>
    </VCardItem>

    <VCardText>
      <VTable v-if="campaigns.length">
        <thead>
          <tr>
            <th>{{ t('documents.campaign.name') }}</th>
            <th>{{ t('documents.title') }}</th>
            <th>{{ t('documents.campaign.recipients') }}</th>
            <th>{{ t('documents.campaign.status') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="c in campaigns"
            :key="c.id"
          >
            <td class="font-weight-medium">
              {{ c.name }}
            </td>
            <td>{{ c.document_label }}</td>
            <td>{{ c.recipients_total }}</td>
            <td>
              <VChip
                size="small"
                :color="statusColor(c.status)"
              >
                {{ t(`documents.campaign.state.${c.status}`) }}
              </VChip>
            </td>
            <td class="text-end">
              <VBtn
                size="small"
                variant="text"
                @click="openTracking(c.id)"
              >
                {{ t('documents.campaign.track') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
      <div
        v-else-if="!loading"
        class="text-body-2 text-medium-emphasis py-6 text-center"
      >
        {{ t('documents.campaign.empty') }}
      </div>
    </VCardText>

    <!-- Drawer création -->
    <VNavigationDrawer
      v-model="drawer"
      temporary
      location="end"
      width="440"
    >
      <div class="pa-5">
        <h5 class="text-h5 mb-4">
          {{ t('documents.campaign.new') }}
        </h5>

        <AppSelect
          v-model="form.document_type_code"
          :items="docTypes.map(d => ({ title: d.label, value: d.code }))"
          :label="t('documents.title')"
          class="mb-4"
        />

        <AppSelect
          v-model="form.mode"
          :items="[
            { title: t('documents.campaign.target.all'), value: 'all' },
            { title: t('documents.campaign.target.department'), value: 'department' },
          ]"
          :label="t('documents.campaign.targeting')"
          class="mb-4"
          @update:model-value="preview = null"
        />

        <AppSelect
          v-if="form.mode === 'department'"
          v-model="form.department_ids"
          :items="departments.map(d => ({ title: d.name, value: d.id }))"
          :label="t('documents.campaign.target.department')"
          multiple
          chips
          class="mb-4"
          @update:model-value="preview = null"
        />

        <VBtn
          variant="tonal"
          size="small"
          :loading="previewing"
          class="mb-4"
          prepend-icon="tabler-users"
          @click="runPreview"
        >
          {{ t('documents.campaign.preview') }}
        </VBtn>

        <VAlert
          v-if="preview"
          type="info"
          variant="tonal"
          class="mb-4"
        >
          {{ t('documents.campaign.previewResult', { total: preview.total }) }}
          <div
            v-if="preview.account_less"
            class="text-caption mt-1"
          >
            {{ t('documents.campaign.accountLessCount', { n: preview.account_less }) }}
          </div>
        </VAlert>

        <AppTextarea
          v-model="form.message"
          :label="t('documents.requestMessage')"
          rows="2"
          class="mb-4"
        />
        <AppDateTimePicker
          v-model="form.due_date"
          :label="t('documents.dueDate')"
          class="mb-4"
        />
        <VSwitch
          v-model="form.requires_validation"
          :label="t('documents.campaign.requiresValidation')"
          class="mb-4"
        />

        <div class="d-flex gap-3">
          <VBtn
            color="primary"
            :loading="launching"
            :disabled="!form.document_type_code || !preview || preview.total === 0"
            @click="launch"
          >
            {{ t('documents.campaign.launch') }}
          </VBtn>
          <VBtn
            variant="tonal"
            color="secondary"
            @click="drawer = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
        </div>
      </div>
    </VNavigationDrawer>

    <!-- Dialog suivi -->
    <VDialog
      v-model="trackingOpen"
      max-width="560"
    >
      <VCard v-if="tracking">
        <VCardItem>
          <VCardTitle>{{ tracking.campaign.name }}</VCardTitle>
          <VCardSubtitle>{{ tracking.campaign.document_label }} · {{ tracking.campaign.recipients_total }} {{ t('documents.campaign.recipients') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VProgressLinear
            :model-value="tracking.progress"
            color="success"
            height="8"
            rounded
            class="mb-4"
          />
          <div class="text-body-2 mb-3">
            {{ t('documents.campaign.progress') }}: {{ tracking.progress }}%
          </div>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <VChip
              size="small"
              color="warning"
            >
              {{ t('documents.campaign.partition.pending') }}: {{ tracking.partition.pending }}
            </VChip>
            <VChip
              size="small"
              color="info"
            >
              {{ t('documents.campaign.partition.received') }}: {{ tracking.partition.received }}
            </VChip>
            <VChip
              size="small"
              color="success"
            >
              {{ t('documents.campaign.partition.validated') }}: {{ tracking.partition.validated }}
            </VChip>
            <VChip
              size="small"
              color="error"
            >
              {{ t('documents.rejected') }}: {{ tracking.partition.rejected }}
            </VChip>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <VChip
              size="x-small"
              variant="outlined"
            >
              {{ t('documents.overdue') }}: {{ tracking.dimensions.overdue }}
            </VChip>
            <VChip
              size="x-small"
              variant="outlined"
            >
              {{ t('companyDocuments.compliance.expired') }}: {{ tracking.dimensions.expired }}
            </VChip>
            <VChip
              size="x-small"
              variant="outlined"
            >
              {{ t('documents.campaign.accountLess') }}: {{ tracking.dimensions.account_less }}
            </VChip>
          </div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn @click="trackingOpen = false">
            {{ t('common.close') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>
