<script setup>
import { useCompanyStore } from '@/core/stores/company'

const props = defineProps({
  isDrawerOpen: {
    type: Boolean,
    required: true,
  },
})

const emit = defineEmits(['update:isDrawerOpen', 'memberAdded'])

const companyStore = useCompanyStore()

const form = ref({
  email: '',
  role: 'user',
})

const isLoading = ref(false)
const errorMessage = ref('')

const handleSubmit = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    await companyStore.addMember({
      email: form.value.email,
      role: form.value.role,
    })

    form.value = { email: '', role: 'user' }
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
  form.value = { email: '', role: 'user' }
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

    <PerfectScrollbar :options="{ wheelPropagation: false }">
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
                  v-model="form.role"
                  label="Role"
                  :items="[
                    { title: 'Admin', value: 'admin' },
                    { title: 'User', value: 'user' },
                  ]"
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
    </PerfectScrollbar>
  </VNavigationDrawer>
</template>
