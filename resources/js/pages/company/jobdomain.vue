<script setup>
definePage({ meta: { surface: 'structure' } })

import { useAuthStore } from '@/core/stores/auth'
import { useJobdomainStore } from '@/core/stores/jobdomain'

const auth = useAuthStore()
const jobdomainStore = useJobdomainStore()

const isLoading = ref(true)
const isSaving = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

const canManage = computed(() => auth.isOwner)

onMounted(async () => {
  try {
    await jobdomainStore.fetchJobdomain()
  }
  finally {
    isLoading.value = false
  }
})

const selectJobdomain = async key => {
  isSaving.value = true
  successMessage.value = ''
  errorMessage.value = ''

  try {
    await jobdomainStore.setJobdomain(key)
    successMessage.value = 'Jobdomain assigned successfully. Default modules have been activated.'
  }
  catch (error) {
    errorMessage.value = error?.data?.message || 'Failed to assign jobdomain.'
  }
  finally {
    isSaving.value = false
  }
}
</script>

<template>
  <div>
    <VCard :loading="isLoading">
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-briefcase"
          class="me-2"
        />
        Industry Profile
      </VCardTitle>
      <VCardSubtitle>
        Select the industry profile for your company. This configures your default modules and navigation.
      </VCardSubtitle>

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

        <!-- Current jobdomain -->
        <VAlert
          v-if="jobdomainStore.assigned"
          type="info"
          variant="tonal"
          class="mb-6"
        >
          Current profile: <strong>{{ jobdomainStore.jobdomain?.label }}</strong>
        </VAlert>

        <!-- Available jobdomains -->
        <VRow v-if="!isLoading">
          <VCol
            v-for="jd in jobdomainStore.available"
            :key="jd.key"
            cols="12"
            md="6"
            lg="4"
          >
            <VCard
              :variant="jobdomainStore.jobdomain?.key === jd.key ? 'outlined' : 'elevated'"
              :color="jobdomainStore.jobdomain?.key === jd.key ? 'primary' : undefined"
              class="h-100"
            >
              <VCardTitle>
                <VIcon
                  icon="tabler-truck"
                  class="me-2"
                />
                {{ jd.label }}
              </VCardTitle>
              <VCardText>
                {{ jd.description || 'No description available.' }}
              </VCardText>
              <VCardActions>
                <VBtn
                  v-if="jobdomainStore.jobdomain?.key === jd.key"
                  color="primary"
                  variant="tonal"
                  disabled
                >
                  <VIcon
                    icon="tabler-check"
                    start
                  />
                  Active
                </VBtn>
                <VBtn
                  v-else-if="canManage"
                  color="primary"
                  :loading="isSaving"
                  @click="selectJobdomain(jd.key)"
                >
                  Select
                </VBtn>
              </VCardActions>
            </VCard>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>
  </div>
</template>
