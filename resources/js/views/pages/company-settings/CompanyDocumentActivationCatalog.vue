<script setup>
import { animations } from '@formkit/drag-and-drop'
import { dragAndDrop } from '@formkit/drag-and-drop/vue'
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
  hideCreateButton: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['refresh', 'create-custom', 'edit-custom', 'archive-custom', 'delete-custom'])

const { t } = useI18n()
const { toast } = useAppToast()

// ─── Local reactive copies for drag reordering ──────────────
const localMemberDocs = ref([])
const localCompanyDocs = ref([])

// ─── Edits state: switches per doc ──────────────
const edits = ref({})

const memberTbodyRef = ref(null)
const companyTbodyRef = ref(null)

const isSaving = ref(false)

// ─── Snapshot for dirty tracking ──────────────
const originalSnapshot = ref(null)

const buildSnapshot = () => {
  const snap = {}

  for (const doc of localMemberDocs.value) {
    const e = edits.value[doc.code]

    snap[doc.code] = {
      order: localMemberDocs.value.indexOf(doc),
      enabled: e?.enabled ?? doc.enabled,
      required_override: e?.required_override ?? doc.required_override,
    }
  }
  for (const doc of localCompanyDocs.value) {
    const e = edits.value[doc.code]

    snap[doc.code] = {
      order: localCompanyDocs.value.indexOf(doc),
      enabled: e?.enabled ?? doc.enabled,
      required_override: e?.required_override ?? doc.required_override,
    }
  }

  return snap
}

const isDirty = computed(() => {
  if (!originalSnapshot.value) return false
  const current = buildSnapshot()
  const keys = new Set([...Object.keys(originalSnapshot.value), ...Object.keys(current)])

  for (const k of keys) {
    const o = originalSnapshot.value[k]
    const c = current[k]

    if (!o || !c) return true
    if (o.order !== c.order || o.enabled !== c.enabled || o.required_override !== c.required_override) return true
  }

  return false
})

// ─── Init from props ──────────────
const initFromProps = () => {
  localMemberDocs.value = [...props.companyUserDocuments]
  localCompanyDocs.value = [...props.companyDocuments]

  edits.value = {}
  const all = [...props.companyUserDocuments, ...props.companyDocuments]

  for (const doc of all) {
    edits.value[doc.code] = {
      enabled: doc.enabled,
      required_override: doc.required_override,
    }
  }

  nextTick(() => {
    originalSnapshot.value = buildSnapshot()
  })
}

watch(() => [props.companyUserDocuments, props.companyDocuments], initFromProps, { deep: true })
onMounted(initFromProps)

// ─── Drag-and-drop init ──────────────
const initDragMember = () => {
  if (memberTbodyRef.value && localMemberDocs.value.length && props.canEdit) {
    dragAndDrop({
      parent: memberTbodyRef,
      values: localMemberDocs,
      dragHandle: '.drag-handle',
      plugins: [animations()],
    })
  }
}

const initDragCompany = () => {
  if (companyTbodyRef.value && localCompanyDocs.value.length && props.canEdit) {
    dragAndDrop({
      parent: companyTbodyRef,
      values: localCompanyDocs,
      dragHandle: '.drag-handle',
      plugins: [animations()],
    })
  }
}

onMounted(() => {
  nextTick(() => {
    initDragMember()
    initDragCompany()
  })
})

watch(() => [props.companyUserDocuments, props.companyDocuments], () => {
  nextTick(() => {
    initDragMember()
    initDragCompany()
  })
}, { deep: true })

// ─── Batch save ──────────────
const handleBatchSave = async () => {
  isSaving.value = true

  try {
    const updates = []

    localMemberDocs.value.forEach((doc, index) => {
      const e = edits.value[doc.code]

      updates.push({
        code: doc.code,
        enabled: e?.enabled ?? doc.enabled,
        required_override: e?.required_override ?? doc.required_override,
        order: index,
      })
    })

    localCompanyDocs.value.forEach((doc, index) => {
      const e = edits.value[doc.code]

      updates.push({
        code: doc.code,
        enabled: e?.enabled ?? doc.enabled,
        required_override: e?.required_override ?? doc.required_override,
        order: index,
      })
    })

    const results = await Promise.allSettled(
      updates.map(u =>
        $api(`/company/document-activations/${u.code}`, {
          method: 'PUT',
          body: {
            enabled: u.enabled,
            required_override: u.required_override,
            order: u.order,
          },
        }),
      ),
    )

    const failures = results.filter(r => r.status === 'rejected')

    if (failures.length === 0) {
      toast(t('companySettings.activationSaved'), 'success')
    }
    else {
      toast(t('companySettings.activationFailed'), 'error')
    }

    emit('refresh')
  }
  catch (error) {
    toast(error?.data?.message || t('companySettings.activationFailed'), 'error')
  }
  finally {
    isSaving.value = false
  }
}
</script>

<template>
  <!-- Add Custom Document Type button -->
  <div
    v-if="canEdit && !props.hideCreateButton"
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
  <template v-if="localMemberDocs.length">
    <div class="d-flex align-center gap-2 mb-3">
      <h6 class="text-h6">
        {{ t('companySettings.memberDocuments') }}
      </h6>
      <DocumentScopeChip scope="company_user" />
    </div>
    <VTable class="text-no-wrap mb-6">
      <thead>
        <tr>
          <th
            v-if="canEdit"
            style="width: 40px;"
          />
          <th>{{ t('documents.title') }}</th>
          <th style="width: 80px;">
            <VTooltip :text="t('documents.tooltipEnabled')">
              <template #activator="{ props: tp }">
                <span v-bind="tp">{{ t('companySettings.enabled') }}</span>
              </template>
            </VTooltip>
          </th>
          <th style="width: 80px;">
            <VTooltip :text="t('documents.tooltipRequired')">
              <template #activator="{ props: tp }">
                <span v-bind="tp">{{ t('companySettings.requiredOverride') }}</span>
              </template>
            </VTooltip>
          </th>
          <th style="width: 60px;" />
        </tr>
      </thead>
      <tbody ref="memberTbodyRef">
        <tr
          v-for="doc in localMemberDocs"
          :key="doc.code"
        >
          <td
            v-if="canEdit"
            class="drag-handle"
          >
            <VIcon
              icon="tabler-grip-vertical"
              size="20"
            />
          </td>
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
            <VTooltip
              v-if="doc.mandatory"
              :text="t('documents.tooltipMandatory')"
            >
              <template #activator="{ props: tp }">
                <span v-bind="tp">
                  <VSwitch
                    v-if="edits[doc.code]"
                    v-model="edits[doc.code].required_override"
                    disabled
                    density="compact"
                    hide-details
                  />
                </span>
              </template>
            </VTooltip>
            <VSwitch
              v-else-if="edits[doc.code]"
              v-model="edits[doc.code].required_override"
              :disabled="!canEdit"
              density="compact"
              hide-details
            />
          </td>
          <td class="d-flex align-center gap-1">
            <VBtn
              v-if="canEdit && doc.is_system === false"
              icon
              variant="text"
              size="small"
              color="info"
              @click="emit('edit-custom', doc)"
            >
              <VIcon icon="tabler-pencil" />
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
  <template v-if="localCompanyDocs.length">
    <div class="d-flex align-center gap-2 mb-3">
      <h6 class="text-h6">
        {{ t('companySettings.companyDocumentsScope') }}
      </h6>
      <DocumentScopeChip scope="company" />
      <VSpacer />
      <VBtn
        v-if="canEdit"
        icon
        variant="tonal"
        color="success"
        size="x-small"
        @click="emit('create-custom')"
      >
        <VIcon
          icon="tabler-plus"
          size="18"
        />
        <VTooltip
          activator="parent"
          location="top"
        >
          {{ t('companyProfile.createType') }}
        </VTooltip>
      </VBtn>
    </div>
    <VTable class="text-no-wrap">
      <thead>
        <tr>
          <th
            v-if="canEdit"
            style="width: 40px;"
          />
          <th>{{ t('documents.title') }}</th>
          <th style="width: 80px;">
            {{ t('companySettings.enabled') }}
          </th>
          <th style="width: 80px;">
            {{ t('companySettings.requiredOverride') }}
          </th>
          <th style="width: 60px;" />
        </tr>
      </thead>
      <tbody ref="companyTbodyRef">
        <tr
          v-for="doc in localCompanyDocs"
          :key="doc.code"
        >
          <td
            v-if="canEdit"
            class="drag-handle"
          >
            <VIcon
              icon="tabler-grip-vertical"
              size="20"
            />
          </td>
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
            <VTooltip
              v-if="doc.mandatory"
              :text="t('documents.tooltipMandatory')"
            >
              <template #activator="{ props: tp }">
                <span v-bind="tp">
                  <VSwitch
                    v-if="edits[doc.code]"
                    v-model="edits[doc.code].required_override"
                    disabled
                    density="compact"
                    hide-details
                  />
                </span>
              </template>
            </VTooltip>
            <VSwitch
              v-else-if="edits[doc.code]"
              v-model="edits[doc.code].required_override"
              :disabled="!canEdit"
              density="compact"
              hide-details
            />
          </td>
          <td class="d-flex align-center gap-1">
            <VBtn
              v-if="canEdit && doc.is_system === false"
              icon
              variant="text"
              size="small"
              color="info"
              @click="emit('edit-custom', doc)"
            >
              <VIcon icon="tabler-pencil" />
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

  <!-- Batch save button -->
  <div
    v-if="canEdit"
    class="d-flex justify-end mt-4"
  >
    <VBadge
      :model-value="isDirty"
      dot
      color="warning"
      offset-x="-4"
      offset-y="-4"
    >
      <VBtn
        color="primary"
        :loading="isSaving"
        :disabled="!isDirty"
        prepend-icon="tabler-device-floppy"
        @click="handleBatchSave"
      >
        {{ t('common.save') }}
      </VBtn>
    </VBadge>
  </div>
</template>

<style scoped>
.drag-handle {
  cursor: grab;
  opacity: 0.4;
  transition: opacity 0.2s;
}

tr:hover .drag-handle {
  opacity: 1;
}
</style>
