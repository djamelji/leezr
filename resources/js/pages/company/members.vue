<script setup>
import { useAuthStore } from '@/core/stores/auth'
import { useCompanyStore } from '@/core/stores/company'
import { useJobdomainStore } from '@/core/stores/jobdomain'
import AddMemberDrawer from '@/company/views/AddMemberDrawer.vue'
import { useAppToast } from '@/composables/useAppToast'

const auth = useAuthStore()
const companyStore = useCompanyStore()
const jobdomainStore = useJobdomainStore()
const { toast } = useAppToast()

const isDrawerOpen = ref(false)
const editingMember = ref(null)
const editRole = ref('')
const errorMessage = ref('')
const successMessage = ref('')

// Member detail drawer
const isDetailDrawerOpen = ref(false)
const detailMember = ref(null)
const detailDynamicFields = ref([])
const detailLoading = ref(false)

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
})
const createFieldLoading = ref(false)

const canManage = computed(() => {
  const role = auth.currentCompany?.role
  return role === 'owner' || role === 'admin'
})

const allowCustomFields = computed(() => jobdomainStore.allowCustomFields)

onMounted(async () => {
  await companyStore.fetchMembers()
})

const handleMemberAdded = () => {
  isDrawerOpen.value = false
  successMessage.value = 'Member added successfully.'
}

const startEditRole = member => {
  editingMember.value = member.id
  editRole.value = member.role
}

const cancelEditRole = () => {
  editingMember.value = null
  editRole.value = ''
}

const saveRole = async member => {
  try {
    await companyStore.updateMember(member.id, { role: editRole.value })
    editingMember.value = null
    successMessage.value = 'Role updated.'
  }
  catch (error) {
    errorMessage.value = error?.data?.message || 'Failed to update role.'
  }
}

const removeMember = async member => {
  if (!confirm(`Remove ${member.user.name} from this company?`))
    return

  try {
    await companyStore.removeMember(member.id)
    successMessage.value = 'Member removed.'
  }
  catch (error) {
    errorMessage.value = error?.data?.message || 'Failed to remove member.'
  }
}

const openDetailDrawer = async member => {
  detailMember.value = member
  detailDynamicFields.value = []
  isDetailDrawerOpen.value = true
  detailLoading.value = true

  try {
    const data = await companyStore.fetchMemberProfile(member.id)

    detailDynamicFields.value = data.dynamic_fields || []
  }
  catch {
    detailDynamicFields.value = []
  }
  finally {
    detailLoading.value = false
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
      companyStore.fetchFieldActivations(),
      companyStore.fetchCustomFieldDefinitions(),
    ])
  }
  finally {
    fieldsLoading.value = false
  }
}

// Inline edit for custom field label
const editingFieldId = ref(null)
const editFieldLabel = ref('')

const startEditFieldLabel = activation => {
  editingFieldId.value = activation.definition?.id
  editFieldLabel.value = activation.definition?.label || ''
}

const cancelEditFieldLabel = () => {
  editingFieldId.value = null
  editFieldLabel.value = ''
}

const saveFieldLabel = async () => {
  if (!editingFieldId.value || !editFieldLabel.value.trim()) return

  try {
    await companyStore.updateCustomFieldDefinition(editingFieldId.value, {
      label: editFieldLabel.value.trim(),
    })

    // Refresh activations to pick up updated label
    await companyStore.fetchFieldActivations()
    toast('Label updated.', 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to update label.', 'error')
  }
  finally {
    editingFieldId.value = null
    editFieldLabel.value = ''
  }
}

const customFieldCount = computed(() => companyStore.customFieldDefinitions.length)

// company_user activations only (member fields)
const memberActivations = computed(() => {
  return companyStore.fieldActivations.filter(
    a => a.definition?.scope === 'company_user',
  )
})

const availableMemberDefs = computed(() => {
  return companyStore.availableFieldDefinitions.filter(
    d => d.scope === 'company_user',
  )
})

const activeCount = computed(() => memberActivations.value.filter(a => a.enabled).length)

const activateField = async def => {
  try {
    const data = await companyStore.upsertFieldActivation({
      field_definition_id: def.id,
      enabled: true,
      required_override: false,
      order: def.default_order || 0,
    })

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to activate field.', 'error')
  }
}

const toggleVisible = async (activation, enabled) => {
  try {
    await companyStore.upsertFieldActivation({
      field_definition_id: activation.field_definition_id,
      enabled,
      required_override: activation.required_override,
      order: activation.order,
    })
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to update.', 'error')
  }
}

const toggleRequired = async (activation, required) => {
  try {
    await companyStore.upsertFieldActivation({
      field_definition_id: activation.field_definition_id,
      enabled: activation.enabled,
      required_override: required,
      order: activation.order,
    })
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to update.', 'error')
  }
}

// ─── Custom Field Creation ──────────────────────────
const openCreateFieldDialog = () => {
  createFieldForm.value = {
    code: '',
    label: '',
    scope: 'company_user',
    type: 'string',
  }
  isCreateFieldDialogOpen.value = true
}

const createCustomField = async () => {
  createFieldLoading.value = true

  try {
    const data = await companyStore.createCustomFieldDefinition(createFieldForm.value)

    toast(data.message, 'success')
    isCreateFieldDialogOpen.value = false
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to create field.', 'error')
  }
  finally {
    createFieldLoading.value = false
  }
}

const deleteCustomField = async activation => {
  const defId = activation.definition?.id

  if (!defId || !confirm('Delete this custom field?'))
    return

  try {
    const data = await companyStore.deleteCustomFieldDefinition(defId)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to delete field.', 'error')
  }
}

const scopeOptions = [
  { title: 'Member', value: 'company_user' },
  { title: 'Company', value: 'company' },
]

const typeOptions = [
  { title: 'Text', value: 'string' },
  { title: 'Number', value: 'number' },
  { title: 'Yes/No', value: 'boolean' },
  { title: 'Date', value: 'date' },
  { title: 'Select', value: 'select' },
  { title: 'JSON', value: 'json' },
]
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-4">
        <span>Team Members</span>
        <div
          v-if="canManage"
          class="d-flex gap-2"
        >
          <VBtn
            variant="tonal"
            prepend-icon="tabler-forms"
            @click="openFieldsDrawer"
          >
            Member Fields
          </VBtn>
          <VBtn
            prepend-icon="tabler-plus"
            @click="isDrawerOpen = true"
          >
            Add Member
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
              <th>User</th>
              <th>Email</th>
              <th>Status</th>
              <th>Role</th>
              <th v-if="canManage">
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="member in companyStore.members"
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
                    <VIcon
                      v-else
                      icon="tabler-user"
                    />
                  </VAvatar>
                  <span class="text-body-1 font-weight-medium">{{ member.user.name }}</span>
                </div>
              </td>
              <td>{{ member.user.email }}</td>
              <td>
                <VChip
                  :color="member.user.status === 'active' ? 'success' : 'warning'"
                  size="small"
                >
                  {{ member.user.status === 'active' ? 'Active' : 'Invitation pending' }}
                </VChip>
              </td>
              <td>
                <template v-if="editingMember === member.id && member.role !== 'owner'">
                  <div class="d-flex align-center gap-2">
                    <AppSelect
                      v-model="editRole"
                      :items="['admin', 'user']"
                      density="compact"
                      style="min-inline-size: 120px;"
                    />
                    <VBtn
                      icon
                      size="small"
                      variant="text"
                      color="success"
                      @click="saveRole(member)"
                    >
                      <VIcon icon="tabler-check" />
                    </VBtn>
                    <VBtn
                      icon
                      size="small"
                      variant="text"
                      color="secondary"
                      @click="cancelEditRole"
                    >
                      <VIcon icon="tabler-x" />
                    </VBtn>
                  </div>
                </template>
                <template v-else>
                  <VChip
                    :color="roleColor(member.role)"
                    size="small"
                    class="text-capitalize"
                  >
                    {{ member.role }}
                  </VChip>
                </template>
              </td>
              <td v-if="canManage">
                <template v-if="member.role !== 'owner'">
                  <VBtn
                    icon
                    size="small"
                    variant="text"
                    color="default"
                    @click="openDetailDrawer(member)"
                  >
                    <VIcon icon="tabler-eye" />
                  </VBtn>
                  <VBtn
                    icon
                    size="small"
                    variant="text"
                    color="default"
                    @click="startEditRole(member)"
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
                <template v-else>
                  <VBtn
                    icon
                    size="small"
                    variant="text"
                    color="default"
                    @click="openDetailDrawer(member)"
                  >
                    <VIcon icon="tabler-eye" />
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
      @update:is-drawer-open="isDrawerOpen = $event"
      @member-added="handleMemberAdded"
    />

    <!-- Member Detail Drawer -->
    <VNavigationDrawer
      v-model="isDetailDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <AppDrawerHeaderSection
        title="Member Details"
        @cancel="isDetailDrawerOpen = false"
      />

      <VDivider />

      <VCardText v-if="detailMember">
        <div class="d-flex align-center gap-x-3 mb-4">
          <VAvatar
            size="48"
            :color="!detailMember.user.avatar ? 'primary' : undefined"
            :variant="!detailMember.user.avatar ? 'tonal' : undefined"
          >
            <VImg
              v-if="detailMember.user.avatar"
              :src="detailMember.user.avatar"
            />
            <VIcon
              v-else
              icon="tabler-user"
              size="24"
            />
          </VAvatar>
          <div>
            <h6 class="text-h6">
              {{ detailMember.user.name }}
            </h6>
            <span class="text-body-2 text-disabled">{{ detailMember.user.email }}</span>
          </div>
        </div>

        <VDivider class="mb-4" />

        <div
          v-if="detailDynamicFields.length"
          class="mb-4"
        >
          <div class="text-body-2 font-weight-medium mb-3">
            Custom Fields
          </div>
          <VList density="compact">
            <VListItem
              v-for="field in detailDynamicFields"
              :key="field.code"
            >
              <VListItemTitle class="text-body-2 text-disabled">
                {{ field.label }}
              </VListItemTitle>
              <VListItemSubtitle class="text-body-1">
                {{ field.value || '—' }}
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </div>

        <VProgressLinear
          v-if="detailLoading"
          indeterminate
        />

        <div
          v-if="!detailLoading && !detailDynamicFields.length"
          class="text-body-2 text-disabled"
        >
          No custom fields configured.
        </div>
      </VCardText>
    </VNavigationDrawer>

    <!-- ─── Member Fields Settings Drawer ───────────────── -->
    <VNavigationDrawer
      v-model="isFieldsDrawerOpen"
      temporary
      location="end"
      width="480"
    >
      <AppDrawerHeaderSection
        title="Member Fields"
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
            {{ activeCount }} / 50 active fields
          </VChip>
        </VCardText>

        <!-- Section A: Active Fields -->
        <VCardTitle class="text-body-1">
          Active Fields
        </VCardTitle>

        <VTable
          v-if="memberActivations.length"
          class="text-no-wrap"
          density="compact"
        >
          <thead>
            <tr>
              <th>Label</th>
              <th style="width: 70px;">
                Visible
              </th>
              <th style="width: 70px;">
                Required
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
                <template v-if="editingFieldId === activation.definition?.id">
                  <div class="d-flex align-center gap-1">
                    <AppTextField
                      v-model="editFieldLabel"
                      density="compact"
                      hide-details
                      style="max-inline-size: 180px;"
                      @keyup.enter="saveFieldLabel"
                    />
                    <VBtn
                      icon
                      size="x-small"
                      variant="text"
                      color="success"
                      @click="saveFieldLabel"
                    >
                      <VIcon
                        icon="tabler-check"
                        size="16"
                      />
                    </VBtn>
                    <VBtn
                      icon
                      size="x-small"
                      variant="text"
                      @click="cancelEditFieldLabel"
                    >
                      <VIcon
                        icon="tabler-x"
                        size="16"
                      />
                    </VBtn>
                  </div>
                </template>
                <div
                  v-else
                  class="d-flex align-center gap-1"
                >
                  <span>{{ activation.definition?.label }}</span>
                  <VBtn
                    v-if="activation.definition?.company_id"
                    icon
                    size="x-small"
                    variant="text"
                    @click="startEditFieldLabel(activation)"
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
                    system
                  </VChip>
                  <VChip
                    v-if="activation.definition?.company_id"
                    color="primary"
                    variant="tonal"
                    size="x-small"
                  >
                    custom
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
                <VTooltip
                  v-if="activation.used_count > 0"
                  location="top"
                >
                  <template #activator="{ props }">
                    <VBtn
                      v-bind="props"
                      icon
                      variant="text"
                      size="x-small"
                      disabled
                    >
                      <VIcon
                        icon="tabler-lock"
                        size="16"
                      />
                    </VBtn>
                  </template>
                  Used by {{ activation.used_count }} member(s). Cannot remove.
                </VTooltip>
                <VBtn
                  v-else-if="activation.definition?.company_id"
                  icon
                  variant="text"
                  size="x-small"
                  color="error"
                  @click="deleteCustomField(activation)"
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
          No member fields activated yet.
        </VCardText>

        <!-- Section B: Available Fields -->
        <template v-if="availableMemberDefs.length">
          <VDivider class="my-2" />

          <VCardTitle class="text-body-1">
            Available Fields
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
              {{ customFieldCount }} / 20 custom fields
            </VChip>
            <VBtn
              variant="tonal"
              color="primary"
              prepend-icon="tabler-plus"
              :disabled="customFieldCount >= 20"
              @click="openCreateFieldDialog"
            >
              Create Custom Field
            </VBtn>
          </VCardText>
        </template>
      </template>
    </VNavigationDrawer>

    <!-- ─── Create Custom Field Dialog ──────────────────── -->
    <VDialog
      v-model="isCreateFieldDialogOpen"
      max-width="500"
    >
      <VCard title="Create Custom Field">
        <VCardText>
          <VRow>
            <VCol cols="6">
              <AppTextField
                v-model="createFieldForm.code"
                label="Code"
                placeholder="e.g. employee_id"
                hint="Lowercase, underscores only"
                persistent-hint
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model="createFieldForm.label"
                label="Label"
                placeholder="e.g. Employee ID"
              />
            </VCol>
            <VCol cols="6">
              <AppSelect
                v-model="createFieldForm.scope"
                label="Scope"
                :items="scopeOptions"
              />
            </VCol>
            <VCol cols="6">
              <AppSelect
                v-model="createFieldForm.type"
                label="Type"
                :items="typeOptions"
              />
            </VCol>
          </VRow>
        </VCardText>

        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            @click="isCreateFieldDialogOpen = false"
          >
            Cancel
          </VBtn>
          <VBtn
            color="primary"
            :loading="createFieldLoading"
            :disabled="!createFieldForm.code || !createFieldForm.label"
            @click="createCustomField"
          >
            Create
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
