<script setup>
definePage({ meta: { module: 'core.members', surface: 'structure' } })

import { useAuthStore } from '@/core/stores/auth'
import { useMembersStore } from '@/modules/company/members/members.store'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useJobdomainStore } from '@/modules/company/jobdomain/jobdomain.store'
import AddMemberDrawer from '@/company/views/AddMemberDrawer.vue'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const auth = useAuthStore()
const membersStore = useMembersStore()
const settingsStore = useCompanySettingsStore()
const jobdomainStore = useJobdomainStore()
const router = useRouter()
const { toast } = useAppToast()

const isDrawerOpen = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

// Quick View modal (read-only)
const isQuickViewOpen = ref(false)
const quickViewMember = ref(null)
const quickViewBaseFields = ref({})
const quickViewDynamicFields = ref([])
const quickViewLoading = ref(false)

// Member Fields Settings drawer
const isFieldsDrawerOpen = ref(false)
const fieldsLoading = ref(false)

// Create Custom Field dialog
const isCreateFieldDialogOpen = ref(false)
const createFieldForm = ref({
  code: '',
  label: '',
  scope: 'company_user',
  type: 'string',
  options: [],
})
const createFieldLoading = ref(false)

// Auto-generate code from label
const codeManuallyEdited = ref(false)

const slugifyLabel = label => {
  return label
    .toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_|_$/g, '')
    .replace(/^(\d)/, '_$1')
    .slice(0, 50)
}

watch(() => createFieldForm.value.label, newLabel => {
  if (!codeManuallyEdited.value)
    createFieldForm.value.code = slugifyLabel(newLabel)
})

// Reset options when type changes away from select
watch(() => createFieldForm.value.type, newType => {
  if (newType !== 'select')
    createFieldForm.value.options = []
})

const isSelectValid = computed(() => {
  if (createFieldForm.value.type !== 'select') return true

  const cleaned = createFieldForm.value.options.map(o => o.trim()).filter(o => o.length)
  const unique = new Set(cleaned)

  return cleaned.length > 0 && cleaned.length === unique.size
})

const canInvite = computed(() => auth.hasPermission('members.invite'))
const canManage = computed(() => auth.hasPermission('members.manage'))

const allowCustomFields = computed(() => jobdomainStore.allowCustomFields)

onMounted(async () => {
  await Promise.all([
    membersStore.fetchMembers(),
    settingsStore.fetchCompanyRoles({ silent: true }).catch(() => {}),
  ])
})

const handleMemberAdded = () => {
  isDrawerOpen.value = false
  successMessage.value = t('members.memberAdded')
}

const removeMember = async member => {
  if (!confirm(t('members.confirmRemove', { name: member.user.display_name })))
    return

  try {
    await membersStore.removeMember(member.id)
    successMessage.value = t('members.memberRemoved')
  }
  catch (error) {
    errorMessage.value = error?.data?.message || t('members.failedToRemove')
  }
}

const openQuickView = async member => {
  quickViewMember.value = member
  quickViewBaseFields.value = {}
  quickViewDynamicFields.value = []
  isQuickViewOpen.value = true
  quickViewLoading.value = true

  try {
    const data = await membersStore.fetchMemberProfile(member.id)

    quickViewMember.value = { ...member, ...data.member }
    quickViewBaseFields.value = data.base_fields || {}
    quickViewDynamicFields.value = data.dynamic_fields || []
  }
  catch {
    quickViewBaseFields.value = {}
    quickViewDynamicFields.value = []
  }
  finally {
    quickViewLoading.value = false
  }
}

const roleColor = role => {
  const colors = {
    owner: 'primary',
    admin: 'warning',
    user: 'info',
  }

  return colors[role] || 'secondary'
}

// ─── Member Fields Settings drawer ──────────────────
const openFieldsDrawer = async () => {
  isFieldsDrawerOpen.value = true
  fieldsLoading.value = true

  try {
    await Promise.all([
      membersStore.fetchFieldActivations(),
      membersStore.fetchCustomFieldDefinitions(),
    ])
  }
  finally {
    fieldsLoading.value = false
  }
}

// Edit custom field (drawer slide)
const editFieldDef = ref(null)
const editFieldForm = ref({ label: '', options: [] })
const editFieldLoading = ref(false)

const openEditField = activation => {
  const def = activation.definition

  editFieldDef.value = def
  editFieldForm.value = {
    label: def.label || '',
    options: def.type === 'select' ? [...(def.options || [])] : [],
  }
}

const closeEditField = () => {
  editFieldDef.value = null
  editFieldForm.value = { label: '', options: [] }
}

const isEditSelectValid = computed(() => {
  if (!editFieldDef.value || editFieldDef.value.type !== 'select') return true

  const cleaned = editFieldForm.value.options.map(o => o.trim()).filter(o => o.length)
  const unique = new Set(cleaned)

  return cleaned.length > 0 && cleaned.length === unique.size
})

const saveEditField = async () => {
  if (!editFieldDef.value || !editFieldForm.value.label.trim()) return

  editFieldLoading.value = true

  const payload = { label: editFieldForm.value.label.trim() }

  if (editFieldDef.value.type === 'select')
    payload.options = editFieldForm.value.options.map(o => o.trim()).filter(o => o.length)

  try {
    await membersStore.updateCustomFieldDefinition(editFieldDef.value.id, payload)
    await membersStore.fetchFieldActivations()
    toast(t('members.customFieldUpdated'), 'success')
    closeEditField()
  }
  catch (error) {
    toast(error?.data?.message || t('members.failedToUpdateField'), 'error')
  }
  finally {
    editFieldLoading.value = false
  }
}

const customFieldCount = computed(() => membersStore.customFieldDefinitions.length)

// company_user activations only (member fields)
const memberActivations = computed(() => {
  return membersStore.fieldActivations.filter(
    a => a.definition?.scope === 'company_user',
  )
})

const availableMemberDefs = computed(() => {
  return membersStore.availableFieldDefinitions.filter(
    d => d.scope === 'company_user',
  )
})

const activeCount = computed(() => memberActivations.value.filter(a => a.enabled).length)

const activateField = async def => {
  try {
    const data = await membersStore.upsertFieldActivation({
      field_definition_id: def.id,
      enabled: true,
      required_override: false,
      order: def.default_order || 0,
    })

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('members.failedToActivate'), 'error')
  }
}

const toggleVisible = async (activation, enabled) => {
  try {
    await membersStore.upsertFieldActivation({
      field_definition_id: activation.field_definition_id,
      enabled,
      required_override: activation.required_override,
      order: activation.order,
    })
  }
  catch (error) {
    toast(error?.data?.message || t('members.failedToUpdate'), 'error')
  }
}

const toggleRequired = async (activation, required) => {
  try {
    await membersStore.upsertFieldActivation({
      field_definition_id: activation.field_definition_id,
      enabled: activation.enabled,
      required_override: required,
      order: activation.order,
    })
  }
  catch (error) {
    toast(error?.data?.message || t('members.failedToUpdate'), 'error')
  }
}

// ─── Custom Field Creation ──────────────────────────
const openCreateFieldDialog = () => {
  createFieldForm.value = {
    code: '',
    label: '',
    scope: 'company_user',
    type: 'string',
    options: [],
  }
  codeManuallyEdited.value = false
  isCreateFieldDialogOpen.value = true
}

const createCustomField = async () => {
  createFieldLoading.value = true

  const payload = {
    code: createFieldForm.value.code,
    label: createFieldForm.value.label,
    scope: createFieldForm.value.scope,
    type: createFieldForm.value.type,
  }

  if (payload.type === 'select')
    payload.options = createFieldForm.value.options.map(o => o.trim()).filter(o => o.length)

  try {
    const data = await membersStore.createCustomFieldDefinition(payload)

    toast(data.message, 'success')
    isCreateFieldDialogOpen.value = false
  }
  catch (error) {
    toast(error?.data?.message || t('members.failedToCreateField'), 'error')
  }
  finally {
    createFieldLoading.value = false
  }
}

// Delete custom field confirmation
const isDeleteFieldDialogOpen = ref(false)
const deleteFieldTarget = ref(null)
const deleteFieldLoading = ref(false)

const confirmDeleteField = activation => {
  deleteFieldTarget.value = activation
  isDeleteFieldDialogOpen.value = true
}

const executeDeleteField = async () => {
  const defId = deleteFieldTarget.value?.definition?.id

  if (!defId) return

  deleteFieldLoading.value = true

  try {
    const data = await membersStore.deleteCustomFieldDefinition(defId)

    toast(data.message, 'success')
    isDeleteFieldDialogOpen.value = false
    deleteFieldTarget.value = null
  }
  catch (error) {
    toast(error?.data?.message || t('members.failedToDeleteField'), 'error')
  }
  finally {
    deleteFieldLoading.value = false
  }
}

const avatarInitials = name => {
  if (!name) return '?'
  const parts = name.trim().split(/\s+/)

  return parts.length >= 2
    ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
    : parts[0][0].toUpperCase()
}

const scopeOptions = computed(() => [
  { title: t('members.scopeMember'), value: 'company_user' },
  { title: t('members.scopeCompany'), value: 'company' },
])

const typeOptions = computed(() => [
  { title: t('members.typeText'), value: 'string' },
  { title: t('members.typeNumber'), value: 'number' },
  { title: t('members.typeBoolean'), value: 'boolean' },
  { title: t('members.typeDate'), value: 'date' },
  { title: t('members.typeSelect'), value: 'select' },
])
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-4">
        <span>{{ t('members.title') }}</span>
        <div
          v-if="canInvite || canManage"
          class="d-flex gap-2"
        >
          <VBtn
            v-if="canManage"
            variant="tonal"
            prepend-icon="tabler-forms"
            @click="openFieldsDrawer"
          >
            {{ t('members.memberFields') }}
          </VBtn>
          <VBtn
            v-if="canInvite"
            prepend-icon="tabler-plus"
            @click="isDrawerOpen = true"
          >
            {{ t('members.addMember') }}
          </VBtn>
        </div>
      </VCardTitle>

      <VCardText>
        <VAlert
          v-if="successMessage"
          type="success"
          class="mb-4"
          closable
          @click:close="successMessage = ''"
        >
          {{ successMessage }}
        </VAlert>

        <VAlert
          v-if="errorMessage"
          type="error"
          class="mb-4"
          closable
          @click:close="errorMessage = ''"
        >
          {{ errorMessage }}
        </VAlert>

        <VTable class="text-no-wrap">
          <thead>
            <tr>
              <th>{{ t('members.user') }}</th>
              <th>{{ t('members.email') }}</th>
              <th>{{ t('members.status') }}</th>
              <th>{{ t('members.role') }}</th>
              <th v-if="canInvite || canManage">
                {{ t('common.actions') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="member in membersStore.members"
              :key="member.id"
            >
              <td>
                <div class="d-flex align-center gap-x-3">
                  <VAvatar
                    size="34"
                    :color="!member.user.avatar ? 'primary' : undefined"
                    :variant="!member.user.avatar ? 'tonal' : undefined"
                  >
                    <VImg
                      v-if="member.user.avatar"
                      :src="member.user.avatar"
                    />
                    <span v-else class="text-xs font-weight-medium">
                      {{ avatarInitials(member.user.display_name) }}
                    </span>
                  </VAvatar>
                  <RouterLink
                    :to="`/company/members/${member.id}`"
                    class="text-body-1 font-weight-medium text-high-emphasis text-link"
                  >
                    {{ member.user.display_name }}
                  </RouterLink>
                </div>
              </td>
              <td>{{ member.user.email }}</td>
              <td>
                <VChip
                  :color="member.user.status === 'active' ? 'success' : 'warning'"
                  size="small"
                >
                  {{ member.user.status === 'active' ? t('members.activeStatus') : t('common.invitationPending') }}
                </VChip>
              </td>
              <td>
                <VChip
                  :color="roleColor(member.company_role?.key || member.role)"
                  size="small"
                  class="text-capitalize"
                >
                  {{ member.company_role?.name || member.role }}
                </VChip>
              </td>
              <td v-if="canInvite || canManage">
                <VBtn
                  icon
                  size="small"
                  variant="text"
                  color="default"
                  @click="openQuickView(member)"
                >
                  <VIcon icon="tabler-eye" />
                </VBtn>
                <template v-if="canManage && !member._isProtected">
                  <VBtn
                    icon
                    size="small"
                    variant="text"
                    color="default"
                    @click="router.push(`/company/members/${member.id}`)"
                  >
                    <VIcon icon="tabler-edit" />
                  </VBtn>
                  <VBtn
                    icon
                    size="small"
                    variant="text"
                    color="error"
                    @click="removeMember(member)"
                  >
                    <VIcon icon="tabler-trash" />
                  </VBtn>
                </template>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
    </VCard>

    <AddMemberDrawer
      :is-drawer-open="isDrawerOpen"
      :company-roles="settingsStore.roles"
      @update:is-drawer-open="isDrawerOpen = $event"
      @member-added="handleMemberAdded"
    />

    <!-- Quick View Modal (read-only) -->
    <VDialog
      v-model="isQuickViewOpen"
      max-width="600"
    >
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between">
          <span>{{ t('members.memberProfile') }}</span>
          <VBtn
            icon
            variant="text"
            size="small"
            @click="isQuickViewOpen = false"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardTitle>

        <VDivider />

        <VCardText v-if="quickViewLoading">
          <VProgressLinear indeterminate />
        </VCardText>

        <VCardText v-else-if="quickViewMember">
          <div class="d-flex align-center gap-x-4 mb-4">
            <VAvatar
              size="48"
              :color="!quickViewMember.user?.avatar ? 'primary' : undefined"
              :variant="!quickViewMember.user?.avatar ? 'tonal' : undefined"
            >
              <VImg
                v-if="quickViewMember.user?.avatar"
                :src="quickViewMember.user.avatar"
              />
              <span v-else class="text-sm font-weight-medium">
                {{ avatarInitials(quickViewMember.user?.display_name) }}
              </span>
            </VAvatar>
            <div>
              <div class="text-body-1 font-weight-medium text-high-emphasis">
                {{ quickViewMember.user?.display_name }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ quickViewMember.user?.email }}
              </div>
            </div>
          </div>

          <div class="d-flex gap-2 mb-4">
            <VChip
              :color="roleColor(quickViewMember.company_role?.key || quickViewMember.role)"
              size="small"
              class="text-capitalize"
            >
              {{ quickViewMember.company_role?.name || quickViewMember.role }}
            </VChip>
            <VChip
              :color="quickViewMember.user?.status === 'active' ? 'success' : 'warning'"
              size="small"
            >
              {{ quickViewMember.user?.status === 'active' ? t('members.activeStatus') : t('common.invitationPending') }}
            </VChip>
          </div>

          <template v-if="quickViewDynamicFields.length">
            <VDivider class="mb-4" />

            <div
              v-for="field in quickViewDynamicFields"
              :key="field.code"
              class="d-flex justify-space-between py-1"
            >
              <span class="text-body-2 text-medium-emphasis">{{ field.label }}</span>
              <span class="text-body-2 font-weight-medium">{{ field.value ?? '—' }}</span>
            </div>
          </template>

          <VDivider class="my-4" />

          <VBtn
            variant="tonal"
            block
            prepend-icon="tabler-external-link"
            @click="router.push(`/company/members/${quickViewMember.id}`); isQuickViewOpen = false"
          >
            {{ t('members.viewFullProfile') }}
          </VBtn>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- ─── Member Fields Settings Drawer ───────────────── -->
    <VNavigationDrawer
      v-model="isFieldsDrawerOpen"
      temporary
      location="end"
      width="480"
    >
      <!-- ── Edit Custom Field View ──────────────────────── -->
      <template v-if="editFieldDef">
        <AppDrawerHeaderSection
          :title="t('members.editCustomField')"
          @cancel="closeEditField"
        />

        <VDivider />

        <VCardText>
          <!-- Read-only info -->
          <div class="d-flex flex-wrap gap-2 mb-4">
            <VChip
              size="small"
              variant="tonal"
            >
              {{ editFieldDef.code }}
            </VChip>
            <VChip
              size="small"
              color="info"
              variant="tonal"
              class="text-capitalize"
            >
              {{ editFieldDef.type }}
            </VChip>
            <VChip
              size="small"
              variant="tonal"
            >
              {{ editFieldDef.scope === 'company_user' ? t('members.scopeMember') : t('members.scopeCompany') }}
            </VChip>
          </div>

          <!-- Editable fields -->
          <AppTextField
            v-model="editFieldForm.label"
            :label="t('members.labelField')"
            class="mb-4"
          />

          <!-- Options editor for select type -->
          <template v-if="editFieldDef.type === 'select'">
            <div class="text-body-2 font-weight-medium mb-2">
              {{ t('common.options') }}
            </div>
            <div
              v-for="(option, idx) in editFieldForm.options"
              :key="idx"
              class="d-flex align-center gap-2 mb-2"
            >
              <AppTextField
                :model-value="option"
                :placeholder="`Option ${idx + 1}`"
                density="compact"
                hide-details
                @update:model-value="editFieldForm.options[idx] = $event"
              />
              <VBtn
                icon
                size="x-small"
                variant="text"
                color="error"
                @click="editFieldForm.options.splice(idx, 1)"
              >
                <VIcon
                  icon="tabler-x"
                  size="16"
                />
              </VBtn>
            </div>
            <VBtn
              variant="tonal"
              size="small"
              prepend-icon="tabler-plus"
              class="mb-4"
              @click="editFieldForm.options.push('')"
            >
              {{ t('members.addOption') }}
            </VBtn>
          </template>

          <VBtn
            color="primary"
            block
            :loading="editFieldLoading"
            :disabled="!editFieldForm.label.trim() || !isEditSelectValid"
            @click="saveEditField"
          >
            {{ t('common.save') }}
          </VBtn>
        </VCardText>
      </template>

      <!-- ── Field List View ─────────────────────────────── -->
      <template v-else>
        <AppDrawerHeaderSection
          :title="t('members.memberFields')"
          @cancel="isFieldsDrawerOpen = false"
        />

        <VDivider />

        <VCardText v-if="fieldsLoading">
          <VProgressLinear indeterminate />
        </VCardText>

        <template v-else>
          <!-- Counter -->
          <VCardText class="pb-2">
            <VChip
              :color="activeCount >= 50 ? 'error' : 'success'"
              size="small"
            >
              {{ t('members.activeFieldsCount', { count: activeCount }) }}
            </VChip>
          </VCardText>

          <!-- Section A: Active Fields -->
          <VCardTitle class="text-body-1">
            {{ t('members.activeFields') }}
          </VCardTitle>

          <VTable
            v-if="memberActivations.length"
            class="text-no-wrap"
            density="compact"
          >
            <thead>
              <tr>
                <th>{{ t('members.labelField') }}</th>
                <th style="width: 70px;">
                  {{ t('members.visible') }}
                </th>
                <th style="width: 70px;">
                  {{ t('members.required') }}
                </th>
                <th style="width: 60px;" />
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="activation in memberActivations"
                :key="activation.id"
              >
                <td>
                  <div class="d-flex align-center gap-1">
                    <span>{{ activation.definition?.label }}</span>
                    <VBtn
                      v-if="activation.definition?.company_id"
                      icon
                      size="x-small"
                      variant="text"
                      @click="openEditField(activation)"
                    >
                      <VIcon
                        icon="tabler-pencil"
                        size="14"
                      />
                    </VBtn>
                    <VChip
                      v-if="activation.definition?.is_system"
                      color="warning"
                      variant="tonal"
                      size="x-small"
                    >
                      {{ t('common.system') }}
                    </VChip>
                    <VChip
                      v-if="activation.definition?.company_id"
                      color="primary"
                      variant="tonal"
                      size="x-small"
                    >
                      {{ t('common.custom') }}
                    </VChip>
                    <VChip
                      v-if="activation.used_count > 0"
                      color="info"
                      variant="tonal"
                      size="x-small"
                    >
                      {{ activation.used_count }} used
                    </VChip>
                  </div>
                </td>
                <td>
                  <VSwitch
                    :model-value="activation.enabled"
                    density="compact"
                    hide-details
                    @update:model-value="toggleVisible(activation, $event)"
                  />
                </td>
                <td>
                  <VCheckbox
                    :model-value="activation.required_override"
                    density="compact"
                    hide-details
                    @update:model-value="toggleRequired(activation, $event)"
                  />
                </td>
                <td>
                  <VBtn
                    v-if="activation.definition?.company_id"
                    icon
                    variant="text"
                    size="x-small"
                    color="error"
                    @click="confirmDeleteField(activation)"
                  >
                    <VIcon
                      icon="tabler-trash"
                      size="16"
                    />
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>

          <VCardText
            v-else
            class="text-disabled"
          >
            {{ t('members.noFieldsActivated') }}
          </VCardText>

          <!-- Section B: Available Fields -->
          <template v-if="availableMemberDefs.length">
            <VDivider class="my-2" />

            <VCardTitle class="text-body-1">
              {{ t('members.availableFields') }}
            </VCardTitle>

            <VCardText>
              <div class="d-flex flex-wrap gap-2">
                <VChip
                  v-for="def in availableMemberDefs"
                  :key="def.id"
                  variant="outlined"
                  color="primary"
                  :disabled="activeCount >= 50"
                  @click="activateField(def)"
                >
                  <VIcon
                    icon="tabler-plus"
                    size="16"
                    start
                  />
                  {{ def.label }}
                </VChip>
              </div>
            </VCardText>
          </template>

          <!-- Section C: Create Custom Field -->
          <template v-if="allowCustomFields">
            <VDivider class="my-2" />

            <VCardText class="d-flex align-center justify-space-between">
              <VChip
                :color="customFieldCount >= 20 ? 'error' : 'info'"
                size="small"
              >
                {{ t('members.customFieldCount', { count: customFieldCount }) }}
              </VChip>
              <VBtn
                variant="tonal"
                color="primary"
                prepend-icon="tabler-plus"
                :disabled="customFieldCount >= 20"
                @click="openCreateFieldDialog"
              >
                {{ t('members.createCustomField') }}
              </VBtn>
            </VCardText>
          </template>
        </template>
      </template>
    </VNavigationDrawer>

    <!-- ─── Create Custom Field Dialog ──────────────────── -->
    <VDialog
      v-model="isCreateFieldDialogOpen"
      max-width="500"
    >
      <VCard :title="t('members.createCustomField')">
        <VCardText>
          <VRow>
            <VCol cols="6">
              <AppTextField
                v-model="createFieldForm.label"
                :label="t('members.labelField')"
                :placeholder="t('members.labelPlaceholder')"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model="createFieldForm.code"
                :label="t('common.code')"
                :placeholder="t('members.codePlaceholder')"
                :hint="t('members.codeHint')"
                persistent-hint
                @input="codeManuallyEdited = true"
              />
            </VCol>
            <VCol cols="6">
              <AppSelect
                v-model="createFieldForm.scope"
                :label="t('common.scope')"
                :items="scopeOptions"
              />
            </VCol>
            <VCol cols="6">
              <AppSelect
                v-model="createFieldForm.type"
                :label="t('common.type')"
                :items="typeOptions"
              />
            </VCol>

            <!-- Select Options Editor -->
            <VCol
              v-if="createFieldForm.type === 'select'"
              cols="12"
            >
              <div class="text-body-2 font-weight-medium mb-2">
                {{ t('common.options') }}
              </div>
              <div
                v-for="(option, idx) in createFieldForm.options"
                :key="idx"
                class="d-flex align-center gap-2 mb-2"
              >
                <AppTextField
                  :model-value="option"
                  :placeholder="`Option ${idx + 1}`"
                  density="compact"
                  hide-details
                  @update:model-value="createFieldForm.options[idx] = $event"
                />
                <VBtn
                  icon
                  size="x-small"
                  variant="text"
                  color="error"
                  @click="createFieldForm.options.splice(idx, 1)"
                >
                  <VIcon
                    icon="tabler-x"
                    size="16"
                  />
                </VBtn>
              </div>
              <VBtn
                variant="tonal"
                size="small"
                prepend-icon="tabler-plus"
                @click="createFieldForm.options.push('')"
              >
                {{ t('members.addOption') }}
              </VBtn>
            </VCol>
          </VRow>
        </VCardText>

        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            @click="isCreateFieldDialogOpen = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="createFieldLoading"
            :disabled="!createFieldForm.code || !createFieldForm.label || !isSelectValid"
            @click="createCustomField"
          >
            {{ t('common.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ─── Delete Custom Field Confirmation Dialog ────── -->
    <VDialog
      v-model="isDeleteFieldDialogOpen"
      max-width="450"
    >
      <VCard :title="t('members.deleteCustomField')">
        <VCardText>
          <template v-if="deleteFieldTarget?.used_count > 0">
            {{ t('members.deleteConfirmUsed', { count: deleteFieldTarget.used_count }) }}
            <br><br>
            <strong>{{ t('members.deleteIrreversible') }}</strong>
          </template>
          <template v-else>
            {{ t('members.deleteConfirm') }}
          </template>
        </VCardText>

        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            @click="isDeleteFieldDialogOpen = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            :loading="deleteFieldLoading"
            @click="executeDeleteField"
          >
            {{ t('members.deletePermanently') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
