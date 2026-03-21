<script setup>
/**
 * Platform Users Tab — Faithfully adapted from Vuexy UserList.vue preset
 * Pattern: resources/ui/presets/apps/roles/UserList.vue
 * ADR-380
 */
import { usePlatformUsersStore } from '@/modules/platform-admin/users/users.store'
import { usePlatformRolesStore } from '@/modules/platform-admin/roles/roles.store'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'

const { t } = useI18n()
const router = useRouter()
const usersStore = usePlatformUsersStore()
const rolesStore = usePlatformRolesStore()
const platformAuthStore = usePlatformAuthStore()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()
const isLoading = ref(true)
const actionLoading = ref(null)

// Preset pattern: filters + pagination state
const searchQuery = ref('')
const selectedRole = ref()
const selectedStatus = ref()
const itemsPerPage = ref(10)
const page = ref(1)
const sortBy = ref()
const orderBy = ref()
const selectedRows = ref([])

const updateOptions = options => {
  sortBy.value = options.sortBy[0]?.key
  orderBy.value = options.sortBy[0]?.order
}

// Drawer state (create only)
const isDrawerOpen = ref(false)
const drawerForm = ref({ first_name: '', last_name: '', email: '', roles: [], credentialMode: 'invite', password: '', password_confirmation: '' })
const drawerLoading = ref(false)

// Permission check
const canManageCredentials = computed(() =>
  platformAuthStore.hasPermission('manage_platform_user_credentials'),
)

// Role filter options (from store)
const roleFilterOptions = computed(() =>
  rolesStore.roles.map(r => ({ title: r.name, value: r.id })),
)

// Status filter options
const statusOptions = [
  { title: 'Active', value: 'active' },
  { title: 'Invited', value: 'invited' },
]

onMounted(async () => {
  try {
    await Promise.all([
      usersStore.fetchPlatformUsers(),
      rolesStore.fetchRoles(),
    ])
  }
  finally {
    isLoading.value = false
  }
})

// Preset pattern: headers
const headers = computed(() => [
  { title: t('platformUsers.user'), key: 'display_name' },
  { title: t('platformUsers.role'), key: 'roles', sortable: false },
  { title: t('common.status'), key: 'status', width: '140px' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '120px', sortable: false },
])

// Filtered users (client-side since backend loads all)
const filteredUsers = computed(() => {
  let items = usersStore.platformUsers

  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase()

    items = items.filter(u =>
      u.display_name?.toLowerCase().includes(q)
      || u.email?.toLowerCase().includes(q),
    )
  }
  if (selectedRole.value) {
    items = items.filter(u =>
      u.roles?.some(r => r.id === selectedRole.value),
    )
  }
  if (selectedStatus.value) {
    items = items.filter(u => u.status === selectedStatus.value)
  }

  return items
})

const totalUsers = computed(() => filteredUsers.value.length)

// Preset pattern: resolveUserRoleVariant
const resolveRoleVariant = role => {
  if (role?.key === 'super_admin') return { color: 'error', icon: 'tabler-shield-lock' }

  return { color: 'primary', icon: 'tabler-shield' }
}

// Preset pattern: resolveUserStatusVariant
const resolveStatusVariant = status => {
  if (status === 'active') return 'success'
  if (status === 'invited') return 'warning'
  if (status === 'inactive') return 'secondary'

  return 'primary'
}

const hasRole = (member, key) => member.roles?.some(r => r.key === key)

const openCreateDrawer = () => {
  drawerForm.value = { first_name: '', last_name: '', email: '', roles: [], credentialMode: 'invite', password: '', password_confirmation: '' }
  isDrawerOpen.value = true
}

const handleDrawerSubmit = async () => {
  drawerLoading.value = true

  try {
    const payload = {
      first_name: drawerForm.value.first_name,
      last_name: drawerForm.value.last_name,
      email: drawerForm.value.email,
      roles: drawerForm.value.roles,
    }

    if (canManageCredentials.value && drawerForm.value.credentialMode === 'password') {
      payload.invite = false
      payload.password = drawerForm.value.password
      payload.password_confirmation = drawerForm.value.password_confirmation
    }

    const data = await usersStore.createPlatformUser(payload)

    toast(data.message, 'success')
    isDrawerOpen.value = false
  }
  catch (error) {
    toast(error?.data?.message || t('common.operationFailed'), 'error')
  }
  finally {
    drawerLoading.value = false
  }
}

const deleteUser = async member => {
  const ok = await confirm({
    question: t('platformUsers.confirmDeleteUser', { name: member.display_name }),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('common.deleteSuccess'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })
  if (!ok)
    return

  actionLoading.value = member.id

  try {
    const data = await usersStore.deletePlatformUser(member.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformUsers.failedToDeleteUser'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}
</script>

<template>
  <section>
    <VCard>
      <!-- Preset pattern: filter bar -->
      <VCardText class="d-flex flex-wrap gap-4">
        <div class="d-flex gap-2 align-center">
          <p class="text-body-1 mb-0">
            {{ t('common.show') }}
          </p>
          <AppSelect
            :model-value="itemsPerPage"
            :items="[
              { value: 10, title: '10' },
              { value: 25, title: '25' },
              { value: 50, title: '50' },
              { value: 100, title: '100' },
              { value: -1, title: t('common.all') },
            ]"
            style="inline-size: 5.5rem;"
            @update:model-value="itemsPerPage = parseInt($event, 10)"
          />
        </div>

        <VSpacer />

        <div class="d-flex align-center flex-wrap gap-4">
          <!-- Search -->
          <AppTextField
            v-model="searchQuery"
            :placeholder="t('platformUsers.searchPlaceholder')"
            style="inline-size: 15.625rem;"
          />

          <!-- Role filter -->
          <AppSelect
            v-model="selectedRole"
            :placeholder="t('platformUsers.filterByRole')"
            :items="roleFilterOptions"
            clearable
            clear-icon="tabler-x"
            style="inline-size: 10rem;"
          />

          <!-- Status filter -->
          <AppSelect
            v-model="selectedStatus"
            :placeholder="t('platformUsers.filterByStatus')"
            :items="statusOptions"
            clearable
            clear-icon="tabler-x"
            style="inline-size: 10rem;"
          />
        </div>
      </VCardText>

      <VDivider />

      <!-- Preset pattern: VDataTableServer -->
      <VDataTableServer
        v-model:items-per-page="itemsPerPage"
        v-model:model-value="selectedRows"
        v-model:page="page"
        :items-per-page-options="[
          { value: 10, title: '10' },
          { value: 20, title: '20' },
          { value: 50, title: '50' },
          { value: -1, title: '$vuetify.dataFooter.itemsPerPageAll' },
        ]"
        :items="filteredUsers"
        :items-length="totalUsers"
        :headers="headers"
        :loading="isLoading"
        class="text-no-wrap"
        show-select
        @update:options="updateOptions"
      >
        <!-- Preset pattern: User column (avatar + name + email) -->
        <template #item.display_name="{ item }">
          <div class="d-flex align-center gap-x-4">
            <VAvatar
              size="34"
              variant="tonal"
              :color="hasRole(item, 'super_admin') ? 'error' : 'primary'"
            >
              <span>{{ avatarText(item.display_name) }}</span>
            </VAvatar>
            <div class="d-flex flex-column">
              <h6 class="text-base">
                <RouterLink
                  :to="`/platform/users/${item.id}`"
                  class="font-weight-medium text-link"
                >
                  {{ item.display_name }}
                </RouterLink>
              </h6>
              <div class="text-sm">
                {{ item.email }}
              </div>
            </div>
          </div>
        </template>

        <!-- Preset pattern: Role column (icon + text) -->
        <template #item.roles="{ item }">
          <div
            v-for="role in item.roles"
            :key="role.key"
            class="d-flex align-center gap-x-2"
          >
            <VIcon
              :size="22"
              :icon="resolveRoleVariant(role).icon"
              :color="resolveRoleVariant(role).color"
            />
            <div class="text-capitalize text-high-emphasis text-body-1">
              {{ role.name }}
            </div>
          </div>
          <span
            v-if="!item.roles?.length"
            class="text-disabled"
          >
            —
          </span>
        </template>

        <!-- Preset pattern: Status column (chip label) -->
        <template #item.status="{ item }">
          <VChip
            :color="resolveStatusVariant(item.status)"
            size="small"
            label
            class="text-capitalize"
          >
            {{ item.status }}
          </VChip>
        </template>

        <!-- Preset pattern: Actions (trash + eye + dots menu) -->
        <template #item.actions="{ item }">
          <IconBtn
            :disabled="hasRole(item, 'super_admin')"
            :loading="actionLoading === item.id"
            @click="deleteUser(item)"
          >
            <VIcon icon="tabler-trash" />
          </IconBtn>

          <IconBtn @click="router.push(`/platform/users/${item.id}`)">
            <VIcon icon="tabler-eye" />
          </IconBtn>

          <VBtn
            icon
            variant="text"
            color="medium-emphasis"
          >
            <VIcon icon="tabler-dots-vertical" />
            <VMenu activator="parent">
              <VList>
                <VListItem :to="`/platform/users/${item.id}`">
                  <template #prepend>
                    <VIcon icon="tabler-eye" />
                  </template>
                  <VListItemTitle>{{ t('common.view') }}</VListItemTitle>
                </VListItem>

                <VListItem
                  :disabled="hasRole(item, 'super_admin')"
                  @click="deleteUser(item)"
                >
                  <template #prepend>
                    <VIcon icon="tabler-trash" />
                  </template>
                  <VListItemTitle>{{ t('common.delete') }}</VListItemTitle>
                </VListItem>
              </VList>
            </VMenu>
          </VBtn>
        </template>

        <!-- Preset pattern: TablePagination in #bottom -->
        <template #bottom>
          <TablePagination
            v-model:page="page"
            :items-per-page="itemsPerPage"
            :total-items="totalUsers"
          />
        </template>
      </VDataTableServer>
    </VCard>

    <!-- Create Drawer -->
    <Teleport to="body">
      <VNavigationDrawer
        v-model="isDrawerOpen"
        temporary
        location="end"
        width="400"
      >
        <AppDrawerHeaderSection
          :title="t('platformUsers.addPlatformUser')"
          @cancel="isDrawerOpen = false"
        />

        <VDivider />

        <VCardText>
          <VForm @submit.prevent="handleDrawerSubmit">
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="drawerForm.first_name"
                  :label="t('platformUsers.firstName')"
                  :placeholder="t('platformUsers.firstNamePlaceholder')"
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="drawerForm.last_name"
                  :label="t('platformUsers.lastName')"
                  :placeholder="t('platformUsers.lastNamePlaceholder')"
                />
              </VCol>
              <VCol cols="12">
                <AppTextField
                  v-model="drawerForm.email"
                  :label="t('common.email')"
                  type="email"
                  :placeholder="t('platformUsers.emailPlaceholder')"
                />
              </VCol>
              <VCol cols="12">
                <AppSelect
                  v-model="drawerForm.roles"
                  :items="roleFilterOptions"
                  :label="t('platformUsers.role')"
                  :placeholder="t('platformUsers.selectRoles')"
                  multiple
                  chips
                  closable-chips
                />
              </VCol>

              <!-- Credential mode (if permission) -->
              <template v-if="canManageCredentials">
                <VCol cols="12">
                  <VDivider class="mb-2" />
                  <div class="text-body-2 font-weight-medium mb-2">
                    {{ t('platformUsers.credentials') }}
                  </div>
                  <VRadioGroup
                    v-model="drawerForm.credentialMode"
                    inline
                  >
                    <VRadio
                      :label="t('platformUsers.sendInvitationLink')"
                      value="invite"
                    />
                    <VRadio
                      :label="t('platformUsers.setPasswordNow')"
                      value="password"
                    />
                  </VRadioGroup>
                </VCol>

                <template v-if="drawerForm.credentialMode === 'password'">
                  <VCol cols="12">
                    <AppTextField
                      v-model="drawerForm.password"
                      :label="t('platformUsers.password')"
                      type="password"
                      :placeholder="t('credentials.minChars')"
                    />
                  </VCol>
                  <VCol cols="12">
                    <AppTextField
                      v-model="drawerForm.password_confirmation"
                      :label="t('platformUsers.confirmPasswordLabel')"
                      type="password"
                      :placeholder="t('credentials.repeatPassword')"
                    />
                  </VCol>
                </template>
              </template>

              <!-- Invitation info (no permission) -->
              <VCol
                v-if="!canManageCredentials"
                cols="12"
              >
                <VAlert
                  type="info"
                  variant="tonal"
                  density="compact"
                >
                  {{ t('platformUsers.invitationInfo') }}
                </VAlert>
              </VCol>

              <VCol cols="12">
                <VBtn
                  type="submit"
                  class="me-3"
                  :loading="drawerLoading"
                >
                  {{ t('common.create') }}
                </VBtn>
                <VBtn
                  variant="tonal"
                  color="secondary"
                  @click="isDrawerOpen = false"
                >
                  {{ t('common.cancel') }}
                </VBtn>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VNavigationDrawer>
    </Teleport>

    <ConfirmDialogComponent />
  </section>
</template>
