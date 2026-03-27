<script setup>
definePage({ meta: { module: 'core.members', surface: 'structure', permission: 'members.view' } })

import { useAuthStore } from '@/core/stores/auth'
import { useMembersStore } from '@/modules/company/members/members.store'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useJobdomainStore } from '@/modules/company/jobdomain/jobdomain.store'
import AddMemberDrawer from '@/company/views/AddMemberDrawer.vue'
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'

const { t } = useI18n()
const auth = useAuthStore()
const membersStore = useMembersStore()
const settingsStore = useCompanySettingsStore()
const jobdomainStore = useJobdomainStore()
const router = useRouter()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()

const isDrawerOpen = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const showIncompleteOnly = ref(false)

// ADR-168b: incomplete profiles
const incompleteCount = computed(() =>
  membersStore.members.filter(m => !m.profile_completeness?.complete).length,
)

const filteredMembers = computed(() => {
  if (!showIncompleteOnly.value) return membersStore.members

  return membersStore.members.filter(m => !m.profile_completeness?.complete)
})

// Quick View modal (read-only)
const isQuickViewOpen = ref(false)
const quickViewMember = ref(null)
const quickViewBaseFields = ref({})
const quickViewDynamicFields = ref([])
const quickViewLoading = ref(false)

// Member Fields Settings drawer
const isFieldsDrawerOpen = ref(false)
const fieldsLoading = ref(false)
const fieldsSearch = ref('')
const fieldsDrawerTab = ref('active')

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
  const ok = await confirm({
    question: t('members.confirmRemove', { name: member.user.display_name }),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('members.memberRemoved'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })
  if (!ok)
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
  fieldsSearch.value = ''
  fieldsDrawerTab.value = 'active'

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

// Filtered activations by search
const filteredActivations = computed(() => {
  if (!fieldsSearch.value) return memberActivations.value
  const q = fieldsSearch.value.toLowerCase()

  return memberActivations.value.filter(
    a => a.definition?.label?.toLowerCase().includes(q) || a.definition?.code?.toLowerCase().includes(q),
  )
})

const filteredAvailableDefs = computed(() => {
  if (!fieldsSearch.value) return availableMemberDefs.value
  const q = fieldsSearch.value.toLowerCase()

  return availableMemberDefs.value.filter(
    d => d.label?.toLowerCase().includes(q) || d.code?.toLowerCase().includes(q),
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
        <div class="d-flex align-center gap-2">
          <span>{{ t('members.title') }}</span>
          <VChip
            v-if="membersStore.memberLimit !== null"
            :color="membersStore.memberCount >= membersStore.memberLimit ? 'error' : 'info'"
            size="small"
          >
            {{ t('members.memberQuota', { current: membersStore.memberCount, limit: membersStore.memberLimit }) }}
          </VChip>
          <VChip
            v-else
            color="success"
            size="small"
          >
            {{ t('members.unlimitedMembers') }}
          </VChip>
        </div>
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
            :disabled="membersStore.memberLimit !== null && membersStore.memberCount >= membersStore.memberLimit"
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

        <!-- ADR-172: member limit reached -->
        <VAlert
          v-if="membersStore.memberLimit !== null && membersStore.memberCount >= membersStore.memberLimit"
          type="warning"
          variant="tonal"
          class="mb-4"
        >
          {{ t('members.memberLimitReached') }}
        </VAlert>

        <!-- ADR-168b: incomplete profiles summary -->
        <VAlert
          v-if="incompleteCount > 0"
          type="warning"
          variant="tonal"
          class="mb-4"
        >
          {{ t('members.incompleteProfiles', { count: incompleteCount }) }}
        </VAlert>

        <!-- ADR-168b: filter -->
        <div
          v-if="incompleteCount > 0"
          class="d-flex align-center mb-4"
        >
          <VCheckbox
            v-model="showIncompleteOnly"
            :label="t('members.showIncompleteOnly')"
            hide-details
            density="compact"
          />
        </div>

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
              v-for="member in filteredMembers"
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
                  <VIcon
                    v-if="!member.profile_completeness?.complete"
                    icon="tabler-alert-circle"
                    color="error"
                    size="16"
                    :title="t('fields.profileIncompleteShort')"
                  />
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
      width="560"
      class="scrollable-content"
    >
      <!-- ── Edit Custom Field View ──────────────────────── -->
      <template v-if="editFieldDef">
        <AppDrawerHeaderSection
          :title="t('members.editCustomField')"
          @cancel="closeEditField"
        />

        <VDivider />

        <PerfectScrollbar :options="{ wheelPropagation: false }">
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
        </PerfectScrollbar>
      </template>

      <!-- ── Field List View ─────────────────────────────── -->
      <template v-else>
        <AppDrawerHeaderSection
          :title="t('members.memberFields')"
          @cancel="isFieldsDrawerOpen = false"
        />

        <VDivider />

        <!-- Search -->
        <VCardText class="pb-0">
          <AppTextField
            v-model="fieldsSearch"
            :placeholder="t('members.searchFields')"
            prepend-inner-icon="tabler-search"
            density="compact"
            hide-details
            clearable
          />
        </VCardText>

        <!-- Tabs: Active / Available / Custom -->
        <VCardText class="pb-0 pt-3">
          <VTabs
            v-model="fieldsDrawerTab"
            density="compact"
          >
            <VTab value="active">
              {{ t('members.activeFields') }}
              <VChip
                size="x-small"
                class="ms-1"
                :color="activeCount >= 50 ? 'error' : 'success'"
              >
                {{ activeCount }}
              </VChip>
            </VTab>
            <VTab value="available">
              {{ t('members.availableFields') }}
              <VChip
                v-if="filteredAvailableDefs.length"
                size="x-small"
                class="ms-1"
                color="primary"
              >
                {{ filteredAvailableDefs.length }}
              </VChip>
            </VTab>
            <VTab
              v-if="allowCustomFields"
              value="custom"
            >
              {{ t('common.custom') }}
              <VChip
                size="x-small"
                class="ms-1"
                :color="customFieldCount >= 20 ? 'error' : 'info'"
              >
                {{ customFieldCount }}
              </VChip>
            </VTab>
          </VTabs>
        </VCardText>

        <VDivider />

        <PerfectScrollbar :options="{ wheelPropagation: false }">
        <VCardText v-if="fieldsLoading">
          <VProgressLinear indeterminate />
        </VCardText>

        <template v-else>
          <VWindow
            v-model="fieldsDrawerTab"
            :touch="false"
          >
            <!-- Tab: Active Fields -->
            <VWindowItem value="active">
              <VTable
                v-if="filteredActivations.length"
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
                    v-for="activation in filteredActivations"
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
                          {{ activation.used_count }} {{ t('common.used') }}
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
                {{ fieldsSearch ? t('members.noFieldsMatch') : t('members.noFieldsActivated') }}
              </VCardText>
            </VWindowItem>

            <!-- Tab: Available Fields -->
            <VWindowItem value="available">
              <VCardText v-if="filteredAvailableDefs.length">
                <div class="d-flex flex-wrap gap-2">
                  <VChip
                    v-for="def in filteredAvailableDefs"
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
              <VCardText
                v-else
                class="text-disabled"
              >
                {{ fieldsSearch ? t('members.noFieldsMatch') : t('members.allFieldsActivated') }}
              </VCardText>
            </VWindowItem>

            <!-- Tab: Custom Fields -->
            <VWindowItem
              v-if="allowCustomFields"
              value="custom"
            >
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
            </VWindowItem>
          </VWindow>
        </template>
        </PerfectScrollbar>
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

    <ConfirmDialogComponent />
  </div>
</template>

<style lang="scss">
.scrollable-content {
  .v-navigation-drawer__content {
    overflow: hidden !important;
  }
}
</style>
