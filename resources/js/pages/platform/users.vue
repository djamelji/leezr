<script setup>
import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'
import { usePlatformStore } from '@/core/stores/platform'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'manage_platform_users',
  },
})

const platformStore = usePlatformStore()
const platformAuthStore = usePlatformAuthStore()
const { toast } = useAppToast()
const isLoading = ref(true)
const actionLoading = ref(null)

// Drawer state
const isDrawerOpen = ref(false)
const isEditMode = ref(false)
const editingUser = ref(null)
const drawerForm = ref({ name: '', email: '', roles: [], credentialMode: 'invite', password: '', password_confirmation: '' })
const drawerLoading = ref(false)

// Credential management (edit mode)
const showSetPasswordFields = ref(false)
const setPasswordForm = ref({ password: '', password_confirmation: '' })
const setPasswordLoading = ref(false)
const resetPasswordLoading = ref(false)

// Dynamic fields (edit mode)
const dynamicFieldDefs = ref([])
const dynamicForm = ref({})
const profileLoading = ref(false)

// Confirm dialog
const isConfirmDialogVisible = ref(false)
const confirmAction = ref(null)

// Permission check
const canManageCredentials = computed(() =>
  platformAuthStore.hasPermission('manage_platform_user_credentials'),
)

// Edited user guards for credential section visibility
const showCredentialSection = computed(() => {
  if (!isEditMode.value || !editingUser.value) return false
  if (!canManageCredentials.value) return false
  if (editingUser.value.roles?.some(r => r.key === 'super_admin')) return false
  if (editingUser.value.id === platformAuthStore.user?.id) return false

  return true
})

// Roles options for VSelect
const roleOptions = computed(() =>
  platformStore.roles.map(r => ({ title: r.name, value: r.id })),
)

onMounted(async () => {
  try {
    await Promise.all([
      platformStore.fetchPlatformUsers(),
      platformStore.fetchRoles(),
    ])
  }
  finally {
    isLoading.value = false
  }
})

const headers = [
  { title: 'Name', key: 'name' },
  { title: 'Email', key: 'email' },
  { title: 'Status', key: 'status', width: '140px' },
  { title: 'Roles', key: 'roles', sortable: false },
  { title: 'Actions', key: 'actions', align: 'center', width: '120px', sortable: false },
]

const hasRole = (user, key) => {
  return user.roles?.some(r => r.key === key)
}

const openCreateDrawer = () => {
  isEditMode.value = false
  editingUser.value = null
  drawerForm.value = { name: '', email: '', roles: [], credentialMode: 'invite', password: '', password_confirmation: '' }
  showSetPasswordFields.value = false
  isDrawerOpen.value = true
}

const openEditDrawer = async user => {
  isEditMode.value = true
  editingUser.value = user
  drawerForm.value = {
    name: user.name,
    email: user.email,
    roles: user.roles?.map(r => r.id) || [],
    credentialMode: 'invite',
    password: '',
    password_confirmation: '',
  }
  showSetPasswordFields.value = false
  setPasswordForm.value = { password: '', password_confirmation: '' }
  dynamicFieldDefs.value = []
  dynamicForm.value = {}
  isDrawerOpen.value = true

  // Fetch profile with dynamic fields
  profileLoading.value = true
  try {
    const profile = await platformStore.fetchPlatformUserProfile(user.id)

    dynamicFieldDefs.value = profile.dynamic_fields || []

    const df = {}
    for (const field of profile.dynamic_fields || []) {
      df[field.code] = field.value
    }
    dynamicForm.value = df
  }
  finally {
    profileLoading.value = false
  }
}

const handleDrawerSubmit = async () => {
  drawerLoading.value = true

  try {
    if (isEditMode.value) {
      const payload = {
        name: drawerForm.value.name,
        email: drawerForm.value.email,
        roles: drawerForm.value.roles,
      }

      if (dynamicFieldDefs.value.length > 0) {
        payload.dynamic_fields = { ...dynamicForm.value }
      }

      const data = await platformStore.updatePlatformUser(editingUser.value.id, payload)

      toast(data.message, 'success')
    }
    else {
      const payload = {
        name: drawerForm.value.name,
        email: drawerForm.value.email,
        roles: drawerForm.value.roles,
      }

      if (canManageCredentials.value && drawerForm.value.credentialMode === 'password') {
        payload.invite = false
        payload.password = drawerForm.value.password
        payload.password_confirmation = drawerForm.value.password_confirmation
      }

      const data = await platformStore.createPlatformUser(payload)

      toast(data.message, 'success')
    }
    isDrawerOpen.value = false
  }
  catch (error) {
    toast(error?.data?.message || 'Operation failed.', 'error')
  }
  finally {
    drawerLoading.value = false
  }
}

const confirmForceReset = () => {
  confirmAction.value = 'forceReset'
  isConfirmDialogVisible.value = true
}

const handleConfirmDialog = async confirmed => {
  if (!confirmed || confirmAction.value !== 'forceReset') return

  resetPasswordLoading.value = true

  try {
    const data = await platformStore.resetPlatformUserPassword(editingUser.value.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to send reset email.', 'error')
  }
  finally {
    resetPasswordLoading.value = false
  }
}

const handleSetPassword = async () => {
  setPasswordLoading.value = true

  try {
    const data = await platformStore.setPlatformUserPassword(editingUser.value.id, {
      password: setPasswordForm.value.password,
      password_confirmation: setPasswordForm.value.password_confirmation,
    })

    toast(data.message, 'success')
    showSetPasswordFields.value = false
    setPasswordForm.value = { password: '', password_confirmation: '' }

    // Update editingUser status
    editingUser.value = data.user
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to set password.', 'error')
  }
  finally {
    setPasswordLoading.value = false
  }
}

const deleteUser = async user => {
  if (!confirm(`Delete platform user "${user.name}"?`))
    return

  actionLoading.value = user.id

  try {
    const data = await platformStore.deletePlatformUser(user.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to delete user.', 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const onPageChange = async page => {
  isLoading.value = true

  try {
    await platformStore.fetchPlatformUsers(page)
  }
  finally {
    isLoading.value = false
  }
}
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-user-shield"
          class="me-2"
        />
        Platform Users
        <VSpacer />
        <VBtn
          size="small"
          prepend-icon="tabler-plus"
          @click="openCreateDrawer"
        >
          Add User
        </VBtn>
      </VCardTitle>
      <VCardSubtitle>
        Manage platform employees and their roles.
      </VCardSubtitle>

      <VDataTable
        :headers="headers"
        :items="platformStore.platformUsers"
        :loading="isLoading"
        :items-per-page="-1"
        hide-default-footer
      >
        <!-- Name -->
        <template #item.name="{ item }">
          <div class="d-flex align-center gap-x-3 py-2">
            <VAvatar
              :color="hasRole(item, 'super_admin') ? 'error' : 'secondary'"
              variant="tonal"
              size="34"
            >
              <span class="text-sm">{{ item.name?.charAt(0)?.toUpperCase() }}</span>
            </VAvatar>
            <span class="text-body-1 font-weight-medium text-high-emphasis">
              {{ item.name }}
            </span>
          </div>
        </template>

        <!-- Status -->
        <template #item.status="{ item }">
          <VChip
            :color="item.status === 'invited' ? 'warning' : 'success'"
            size="small"
          >
            {{ item.status === 'invited' ? 'Invitation pending' : 'Active' }}
          </VChip>
        </template>

        <!-- Roles -->
        <template #item.roles="{ item }">
          <VChip
            v-for="role in item.roles"
            :key="role.key"
            color="error"
            size="small"
            class="me-1"
          >
            {{ role.name }}
          </VChip>
          <span
            v-if="!item.roles?.length"
            class="text-disabled"
          >
            —
          </span>
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <div class="d-flex gap-1 justify-center">
            <VBtn
              icon
              variant="text"
              size="small"
              color="default"
              @click="openEditDrawer(item)"
            >
              <VIcon icon="tabler-pencil" />
            </VBtn>
            <VBtn
              v-if="!hasRole(item, 'super_admin')"
              icon
              variant="text"
              size="small"
              color="error"
              :loading="actionLoading === item.id"
              @click="deleteUser(item)"
            >
              <VIcon icon="tabler-trash" />
            </VBtn>
          </div>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            No platform users found.
          </div>
        </template>
      </VDataTable>

      <!-- Pagination -->
      <VCardText
        v-if="platformStore.platformUsersPagination.last_page > 1"
        class="d-flex justify-center"
      >
        <VPagination
          :model-value="platformStore.platformUsersPagination.current_page"
          :length="platformStore.platformUsersPagination.last_page"
          @update:model-value="onPageChange"
        />
      </VCardText>
    </VCard>

    <!-- Create/Edit Drawer -->
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <AppDrawerHeaderSection
        :title="isEditMode ? 'Edit Platform User' : 'Add Platform User'"
        @cancel="isDrawerOpen = false"
      />

      <VDivider />

      <VCardText>
        <VForm @submit.prevent="handleDrawerSubmit">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="drawerForm.name"
                label="Name"
                placeholder="John Doe"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="drawerForm.email"
                label="Email"
                type="email"
                placeholder="john@leezr.com"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="drawerForm.roles"
                :items="roleOptions"
                label="Roles"
                placeholder="Select roles"
                multiple
                chips
                closable-chips
              />
            </VCol>

            <!-- Dynamic fields (edit mode) -->
            <template v-if="isEditMode && dynamicFieldDefs.length">
              <VCol cols="12">
                <VDivider class="mb-2" />
                <div class="text-body-2 font-weight-medium mb-2">
                  Custom Fields
                </div>
              </VCol>
              <DynamicFormRenderer
                v-model="dynamicForm"
                :fields="dynamicFieldDefs"
                :disabled="profileLoading"
                :cols="12"
              />
            </template>

            <template v-if="isEditMode && profileLoading">
              <VCol cols="12">
                <VProgressLinear indeterminate />
              </VCol>
            </template>

            <!-- Credential mode (create only, if permission) -->
            <template v-if="!isEditMode && canManageCredentials">
              <VCol cols="12">
                <VDivider class="mb-2" />
                <div class="text-body-2 font-weight-medium mb-2">
                  Credentials
                </div>
                <VRadioGroup
                  v-model="drawerForm.credentialMode"
                  inline
                >
                  <VRadio
                    label="Send invitation link"
                    value="invite"
                  />
                  <VRadio
                    label="Set password now"
                    value="password"
                  />
                </VRadioGroup>
              </VCol>

              <template v-if="drawerForm.credentialMode === 'password'">
                <VCol cols="12">
                  <AppTextField
                    v-model="drawerForm.password"
                    label="Password"
                    type="password"
                    placeholder="Min 8 characters"
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextField
                    v-model="drawerForm.password_confirmation"
                    label="Confirm Password"
                    type="password"
                    placeholder="Repeat password"
                  />
                </VCol>
              </template>
            </template>

            <!-- Invitation info (create, no permission) -->
            <VCol
              v-if="!isEditMode && !canManageCredentials"
              cols="12"
            >
              <VAlert
                type="info"
                variant="tonal"
                density="compact"
              >
                An invitation email will be sent to set their password.
              </VAlert>
            </VCol>

            <VCol cols="12">
              <VBtn
                type="submit"
                class="me-3"
                :loading="drawerLoading"
              >
                {{ isEditMode ? 'Update' : 'Create' }}
              </VBtn>
              <VBtn
                variant="tonal"
                color="secondary"
                @click="isDrawerOpen = false"
              >
                Cancel
              </VBtn>
            </VCol>
          </VRow>
        </VForm>

        <!-- Credential management section (edit mode only) -->
        <template v-if="showCredentialSection">
          <VDivider class="my-4" />
          <div class="text-body-2 font-weight-medium mb-3">
            Credential Management
          </div>

          <div class="d-flex flex-column gap-3">
            <VBtn
              prepend-icon="tabler-mail-forward"
              variant="outlined"
              color="warning"
              :loading="resetPasswordLoading"
              @click="confirmForceReset"
            >
              Force Password Reset
            </VBtn>

            <VBtn
              v-if="!showSetPasswordFields"
              prepend-icon="tabler-key"
              variant="outlined"
              color="info"
              @click="showSetPasswordFields = true"
            >
              Set Password Manually
            </VBtn>

            <template v-if="showSetPasswordFields">
              <AppTextField
                v-model="setPasswordForm.password"
                label="New Password"
                type="password"
                placeholder="Min 8 characters"
              />
              <AppTextField
                v-model="setPasswordForm.password_confirmation"
                label="Confirm Password"
                type="password"
                placeholder="Repeat password"
              />
              <div class="d-flex gap-2">
                <VBtn
                  color="info"
                  :loading="setPasswordLoading"
                  @click="handleSetPassword"
                >
                  Save Password
                </VBtn>
                <VBtn
                  variant="tonal"
                  color="secondary"
                  @click="showSetPasswordFields = false; setPasswordForm = { password: '', password_confirmation: '' }"
                >
                  Cancel
                </VBtn>
              </div>
            </template>
          </div>
        </template>
      </VCardText>
    </VNavigationDrawer>

    <!-- Confirm Dialog for Force Reset -->
    <VDialog
      v-model="isConfirmDialogVisible"
      max-width="500"
    >
      <VCard class="text-center px-10 py-6">
        <VCardText>
          <VBtn
            icon
            variant="outlined"
            color="warning"
            class="my-4"
            style="block-size: 88px; inline-size: 88px; pointer-events: none;"
          >
            <span class="text-5xl">!</span>
          </VBtn>

          <h6 class="text-lg font-weight-medium">
            Send a password reset email to {{ editingUser?.name }}?
          </h6>
          <p class="text-body-2 text-disabled mt-2">
            This will invalidate any previous reset tokens.
          </p>
        </VCardText>

        <VCardText class="d-flex align-center justify-center gap-2">
          <VBtn
            variant="elevated"
            color="warning"
            @click="isConfirmDialogVisible = false; handleConfirmDialog(true)"
          >
            Confirm
          </VBtn>

          <VBtn
            color="secondary"
            variant="tonal"
            @click="isConfirmDialogVisible = false; handleConfirmDialog(false)"
          >
            Cancel
          </VBtn>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>
