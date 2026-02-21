<script setup>
import { useMembersStore } from '@/modules/company/members/members.store'

const props = defineProps({
  isDrawerOpen: {
    type: Boolean,
    required: true,
  },
  companyRoles: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['update:isDrawerOpen', 'memberAdded'])

const membersStore = useCompanyStore()

const form = ref({
  first_name: '',
  last_name: '',
  email: '',
  company_role_id: null,
})

const isLoading = ref(false)
const errorMessage = ref('')

const roleOptions = computed(() =>
  props.companyRoles.map(r => ({ title: r.name, value: r.id })),
)

const handleSubmit = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    await membersStore.addMember({
      first_name: form.value.first_name,
      last_name: form.value.last_name,
      email: form.value.email,
      company_role_id: form.value.company_role_id,
    })

    form.value = { first_name: '', last_name: '', email: '', company_role_id: null }
    emit('memberAdded')
  }
  catch (error) {
    errorMessage.value = error?.data?.message || 'Failed to add member.'
  }
  finally {
    isLoading.value = false
  }
}

const handleClose = () => {
  emit('update:isDrawerOpen', false)
  form.value = { first_name: '', last_name: '', email: '', company_role_id: null }
  errorMessage.value = ''
}
</script>

<template>
  <VNavigationDrawer
    temporary
    :width="400"
    location="end"
    class="scrollable-content"
    :model-value="props.isDrawerOpen"
    @update:model-value="handleClose"
  >
    <AppDrawerHeaderSection
      title="Add Member"
      @cancel="handleClose"
    />

    <VDivider />

    <div style="block-size: calc(100vh - 56px); overflow-y: auto;">
      <VCard flat>
        <VCardText>
          <VAlert
            v-if="errorMessage"
            type="error"
            class="mb-4"
            closable
            @click:close="errorMessage = ''"
          >
            {{ errorMessage }}
          </VAlert>

          <VAlert
            type="info"
            variant="tonal"
            class="mb-4"
          >
            If the email is not already registered, an invitation will be sent automatically.
          </VAlert>

          <VForm @submit.prevent="handleSubmit">
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.first_name"
                  label="First Name"
                  placeholder="John"
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.last_name"
                  label="Last Name"
                  placeholder="Doe"
                />
              </VCol>
              <VCol cols="12">
                <AppTextField
                  v-model="form.email"
                  label="Email"
                  type="email"
                  placeholder="user@email.com"
                />
              </VCol>

              <VCol cols="12">
                <AppSelect
                  v-model="form.company_role_id"
                  label="Role"
                  :items="roleOptions"
                  clearable
                  placeholder="No role"
                />
              </VCol>

              <VCol cols="12">
                <VBtn
                  type="submit"
                  class="me-4"
                  :loading="isLoading"
                >
                  Add
                </VBtn>
                <VBtn
                  type="reset"
                  variant="tonal"
                  color="secondary"
                  @click="handleClose"
                >
                  Cancel
                </VBtn>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </div>
  </VNavigationDrawer>
</template>
