<script setup>
import { useAuthStore } from '@/core/stores/auth'
import { useCompanyStore } from '@/core/stores/company'
import AddMemberDrawer from '@/company/views/AddMemberDrawer.vue'

const auth = useAuthStore()
const companyStore = useCompanyStore()

const isDrawerOpen = ref(false)
const editingMember = ref(null)
const editRole = ref('')
const errorMessage = ref('')
const successMessage = ref('')

const canManage = computed(() => {
  const role = auth.currentCompany?.role
  return role === 'owner' || role === 'admin'
})

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

const roleColor = role => {
  const colors = {
    owner: 'primary',
    admin: 'warning',
    user: 'info',
  }

  return colors[role] || 'secondary'
}
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-4">
        <span>Team Members</span>
        <VBtn
          v-if="canManage"
          prepend-icon="tabler-plus"
          @click="isDrawerOpen = true"
        >
          Add Member
        </VBtn>
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
  </div>
</template>
