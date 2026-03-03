<script setup>
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import DocumentMandatoryChip from '@/views/shared/documents/DocumentMandatoryChip.vue'
import DocumentConstraintsInline from '@/views/shared/documents/DocumentConstraintsInline.vue'
import DocumentScopeChip from '@/views/shared/documents/DocumentScopeChip.vue'

const props = defineProps({
  companyUserDocuments: {
    type: Array,
    default: () => [],
  },
  companyDocuments: {
    type: Array,
    default: () => [],
  },
  canEdit: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['refresh', 'create-custom', 'archive-custom', 'delete-custom'])

const { t } = useI18n()
const { toast } = useAppToast()

const savingCode = ref(null)

// Local editable copies keyed by code
const edits = ref({})

const initEdits = () => {
  const all = [...props.companyUserDocuments, ...props.companyDocuments]

  for (const doc of all) {
    if (!edits.value[doc.code]) {
      edits.value[doc.code] = {
        enabled: doc.enabled,
        required_override: doc.required_override,
        order: doc.order,
      }
    }
  }
}

watch(() => [props.companyUserDocuments, props.companyDocuments], () => {
  // Reset edits when data refreshes
  edits.value = {}
  initEdits()
}, { deep: true })

onMounted(initEdits)

const handleSave = async doc => {
  savingCode.value = doc.code

  try {
    const edit = edits.value[doc.code]

    await $api(`/company/document-activations/${doc.code}`, {
      method: 'PUT',
      body: {
        enabled: edit.enabled,
        required_override: edit.required_override,
        order: edit.order,
      },
    })

    toast(t('companySettings.activationSaved'), 'success')
    emit('refresh')
  }
  catch (error) {
    toast(error?.data?.message || t('companySettings.activationFailed'), 'error')
  }
  finally {
    savingCode.value = null
  }
}
</script>

<template>
  <!-- Add Custom Document Type button -->
  <div
    v-if="canEdit"
    class="d-flex justify-end mb-4"
  >
    <VBtn
      color="primary"
      prepend-icon="tabler-plus"
      @click="emit('create-custom')"
    >
      {{ t('documents.addCustom') }}
    </VBtn>
  </div>

  <!-- Member Documents Section -->
  <template v-if="props.companyUserDocuments.length">
    <div class="d-flex align-center gap-2 mb-3">
      <h6 class="text-h6">
        {{ t('companySettings.memberDocuments') }}
      </h6>
      <DocumentScopeChip scope="company_user" />
    </div>
    <VTable class="text-no-wrap mb-6">
      <thead>
        <tr>
          <th>{{ t('documents.title') }}</th>
          <th style="width: 80px;">
            {{ t('companySettings.enabled') }}
          </th>
          <th style="width: 80px;">
            {{ t('companySettings.requiredOverride') }}
          </th>
          <th style="width: 80px;">
            {{ t('companySettings.order') }}
          </th>
          <th style="width: 60px;" />
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="doc in props.companyUserDocuments"
          :key="doc.code"
        >
          <td class="font-weight-medium">
            {{ t(`documents.type.${doc.code}`, doc.label) }}
            <VChip
              v-if="doc.is_system === false"
              size="x-small"
              color="info"
              variant="tonal"
              class="ms-2"
            >
              {{ t('documents.customDocument') }}
            </VChip>
            <DocumentMandatoryChip
              :mandatory="doc.mandatory"
              :required="doc.required_override"
              class="ms-2"
            />
            <DocumentConstraintsInline
              :max-file-size-mb="doc.max_file_size_mb"
              :accepted-types="doc.accepted_types"
            />
          </td>
          <td>
            <VSwitch
              v-if="edits[doc.code]"
              v-model="edits[doc.code].enabled"
              :disabled="!canEdit"
              density="compact"
              hide-details
            />
          </td>
          <td>
            <VSwitch
              v-if="edits[doc.code]"
              v-model="edits[doc.code].required_override"
              :disabled="!canEdit || doc.mandatory"
              density="compact"
              hide-details
            />
          </td>
          <td>
            <AppTextField
              v-if="edits[doc.code]"
              v-model.number="edits[doc.code].order"
              type="number"
              :min="0"
              :disabled="!canEdit"
              density="compact"
              hide-details
              style="max-width: 70px;"
            />
          </td>
          <td class="d-flex align-center gap-1">
            <VBtn
              v-if="canEdit"
              icon
              variant="text"
              size="small"
              color="primary"
              :loading="savingCode === doc.code"
              @click="handleSave(doc)"
            >
              <VIcon icon="tabler-device-floppy" />
            </VBtn>
            <VBtn
              v-if="canEdit && doc.is_system === false && doc.usage_count === 0"
              icon
              variant="text"
              size="small"
              color="error"
              @click="emit('delete-custom', doc.code)"
            >
              <VIcon icon="tabler-trash" />
            </VBtn>
            <VBtn
              v-else-if="canEdit && doc.is_system === false"
              icon
              variant="text"
              size="small"
              color="secondary"
              @click="emit('archive-custom', doc.code)"
            >
              <VIcon icon="tabler-archive" />
            </VBtn>
          </td>
        </tr>
      </tbody>
    </VTable>
  </template>

  <!-- Company Documents Section -->
  <template v-if="props.companyDocuments.length">
    <div class="d-flex align-center gap-2 mb-3">
      <h6 class="text-h6">
        {{ t('companySettings.companyDocumentsScope') }}
      </h6>
      <DocumentScopeChip scope="company" />
    </div>
    <VTable class="text-no-wrap">
      <thead>
        <tr>
          <th>{{ t('documents.title') }}</th>
          <th style="width: 80px;">
            {{ t('companySettings.enabled') }}
          </th>
          <th style="width: 80px;">
            {{ t('companySettings.requiredOverride') }}
          </th>
          <th style="width: 80px;">
            {{ t('companySettings.order') }}
          </th>
          <th style="width: 60px;" />
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="doc in props.companyDocuments"
          :key="doc.code"
        >
          <td class="font-weight-medium">
            {{ t(`documents.type.${doc.code}`, doc.label) }}
            <VChip
              v-if="doc.is_system === false"
              size="x-small"
              color="info"
              variant="tonal"
              class="ms-2"
            >
              {{ t('documents.customDocument') }}
            </VChip>
            <DocumentMandatoryChip
              :mandatory="doc.mandatory"
              :required="doc.required_override"
              class="ms-2"
            />
            <DocumentConstraintsInline
              :max-file-size-mb="doc.max_file_size_mb"
              :accepted-types="doc.accepted_types"
            />
          </td>
          <td>
            <VSwitch
              v-if="edits[doc.code]"
              v-model="edits[doc.code].enabled"
              :disabled="!canEdit"
              density="compact"
              hide-details
            />
          </td>
          <td>
            <VSwitch
              v-if="edits[doc.code]"
              v-model="edits[doc.code].required_override"
              :disabled="!canEdit || doc.mandatory"
              density="compact"
              hide-details
            />
          </td>
          <td>
            <AppTextField
              v-if="edits[doc.code]"
              v-model.number="edits[doc.code].order"
              type="number"
              :min="0"
              :disabled="!canEdit"
              density="compact"
              hide-details
              style="max-width: 70px;"
            />
          </td>
          <td class="d-flex align-center gap-1">
            <VBtn
              v-if="canEdit"
              icon
              variant="text"
              size="small"
              color="primary"
              :loading="savingCode === doc.code"
              @click="handleSave(doc)"
            >
              <VIcon icon="tabler-device-floppy" />
            </VBtn>
            <VBtn
              v-if="canEdit && doc.is_system === false && doc.usage_count === 0"
              icon
              variant="text"
              size="small"
              color="error"
              @click="emit('delete-custom', doc.code)"
            >
              <VIcon icon="tabler-trash" />
            </VBtn>
            <VBtn
              v-else-if="canEdit && doc.is_system === false"
              icon
              variant="text"
              size="small"
              color="secondary"
              @click="emit('archive-custom', doc.code)"
            >
              <VIcon icon="tabler-archive" />
            </VBtn>
          </td>
        </tr>
      </tbody>
    </VTable>
  </template>
</template>
