<script setup>
import { useMembersStore } from '@/modules/company/members/members.store'

const { t } = useI18n()

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

const membersStore = useMembersStore()

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
    errorMessage.value = error?.data?.message || t('members.failedToAdd')
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
      :title="t('members.addMember')"
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
            {{ t('members.invitationAutoSent') }}
          </VAlert>

          <VForm @submit.prevent="handleSubmit">
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.first_name"
                  :label="t('members.firstName')"
                  placeholder="John"
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.last_name"
                  :label="t('members.lastName')"
                  placeholder="Doe"
                />
              </VCol>
              <VCol cols="12">
                <AppTextField
                  v-model="form.email"
                  :label="t('common.email')"
                  type="email"
                  placeholder="user@email.com"
                />
              </VCol>

              <VCol cols="12">
                <AppSelect
                  v-model="form.company_role_id"
                  :label="t('members.role')"
                  :items="roleOptions"
                  clearable
                  :placeholder="t('members.noRole')"
                />
              </VCol>

              <VCol cols="12">
                <VBtn
                  type="submit"
                  class="me-4"
                  :loading="isLoading"
                >
                  {{ t('common.add') }}
                </VBtn>
                <VBtn
                  type="reset"
                  variant="tonal"
                  color="secondary"
                  @click="handleClose"
                >
                  {{ t('common.cancel') }}
                </VBtn>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </div>
  </VNavigationDrawer>
</template>
