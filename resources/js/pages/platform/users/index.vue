<script setup>
import { usePlatformUsersStore } from '@/modules/platform-admin/users/users.store'
import { usePlatformRolesStore } from '@/modules/platform-admin/roles/roles.store'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'manage_platform_users',
  },
})

const router = useRouter()
const usersStore = usePlatformUsersStore()
const rolesStore = usePlatformRolesStore()
const platformAuthStore = usePlatformAuthStore()
const { toast } = useAppToast()
const isLoading = ref(true)
const actionLoading = ref(null)

// Drawer state (create only)
const isDrawerOpen = ref(false)
const drawerForm = ref({ first_name: '', last_name: '', email: '', roles: [], credentialMode: 'invite', password: '', password_confirmation: '' })
const drawerLoading = ref(false)

// Permission check
const canManageCredentials = computed(() =>
  platformAuthStore.hasPermission('manage_platform_user_credentials'),
)

// Roles options for VSelect
const roleOptions = computed(() =>
  rolesStore.roles.map(r => ({ title: r.name, value: r.id })),
)

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

const headers = computed(() => [
  { title: t('common.name'), key: 'display_name' },
  { title: t('common.email'), key: 'email' },
  { title: t('common.status'), key: 'status', width: '140px' },
  { title: t('Roles'), key: 'roles', sortable: false },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '120px', sortable: false },
])

const hasRole = (user, key) => {
  return user.roles?.some(r => r.key === key)
}

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

const deleteUser = async user => {
  if (!confirm(t('platformUsers.confirmDeleteUser', { name: user.display_name })))
    return

  actionLoading.value = user.id

  try {
    const data = await usersStore.deletePlatformUser(user.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformUsers.failedToDeleteUser'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const onPageChange = async page => {
  isLoading.value = true

  try {
    await usersStore.fetchPlatformUsers(page)
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
        {{ t('platformUsers.title') }}
        <VSpacer />
        <VBtn
          size="small"
          prepend-icon="tabler-plus"
          @click="openCreateDrawer"
        >
          {{ t('platformUsers.addUser') }}
        </VBtn>
      </VCardTitle>
      <VCardSubtitle>
        {{ t('platformUsers.subtitle') }}
      </VCardSubtitle>

      <VDataTable
        :headers="headers"
        :items="usersStore.platformUsers"
        :loading="isLoading"
        :items-per-page="-1"
        hide-default-footer
      >
        <!-- Name -->
        <template #item.display_name="{ item }">
          <div class="d-flex align-center gap-x-3 py-2">
            <VAvatar
              :color="hasRole(item, 'super_admin') ? 'error' : 'secondary'"
              variant="tonal"
              size="34"
            >
              <span class="text-sm">{{ item.first_name?.charAt(0)?.toUpperCase() }}</span>
            </VAvatar>
            <RouterLink
              :to="`/platform/users/${item.id}`"
              class="text-body-1 font-weight-medium text-high-emphasis text-link"
            >
              {{ item.display_name }}
            </RouterLink>
          </div>
        </template>

        <!-- Status -->
        <template #item.status="{ item }">
          <VChip
            :color="item.status === 'invited' ? 'warning' : 'success'"
            size="small"
          >
            {{ item.status === 'invited' ? t('common.invitationPending') : t('common.active') }}
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
              @click="router.push(`/platform/users/${item.id}`)"
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
            {{ t('platformUsers.noPlatformUsers') }}
          </div>
        </template>
      </VDataTable>

      <!-- Pagination -->
      <VCardText
        v-if="usersStore.platformUsersPagination.last_page > 1"
        class="d-flex justify-center"
      >
        <VPagination
          :model-value="usersStore.platformUsersPagination.current_page"
          :length="usersStore.platformUsersPagination.last_page"
          @update:model-value="onPageChange"
        />
      </VCardText>
    </VCard>

    <!-- Create Drawer -->
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
                :items="roleOptions"
                :label="t('Roles')"
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
  </div>
</template>
