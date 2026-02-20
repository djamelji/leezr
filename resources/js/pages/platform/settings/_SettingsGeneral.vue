<script setup>
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { setAppName } from '@/composables/useAppName'
import { $platformApi } from '@/utils/platformApi'
import BrandLogo from '@/components/BrandLogo.vue'

const platformAuth = usePlatformAuthStore()
const meta = computed(() => platformAuth.appMeta || {})

const form = ref({ app_name: meta.value.app_name || 'Leezr' })
const saving = ref(false)
const saved = ref(false)

watch(meta, val => {
  if (val.app_name && !saving.value) {
    form.value.app_name = val.app_name
  }
}, { immediate: true })

async function save() {
  saving.value = true
  saved.value = false

  try {
    await $platformApi('/general-settings', {
      method: 'PUT',
      body: { app_name: form.value.app_name },
    })

    setAppName(form.value.app_name)
    saved.value = true
    setTimeout(() => { saved.value = false }, 3000)
  }
  finally {
    saving.value = false
  }
}

const versionLabel = computed(() => {
  const v = meta.value.version
  return v && v !== 'dev' ? `v${v}` : 'dev'
})

const envLabel = computed(() => {
  const host = window.location.hostname
  if (host === 'leezr.com') return 'Production'
  if (host === 'dev.leezr.com') return 'Developpement'

  return 'Local'
})

const buildDateLabel = computed(() => {
  const raw = meta.value.build_date
  if (!raw) return null
  try {
    return new Date(raw).toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' })
  }
  catch { return raw }
})

const infoItems = computed(() => [
  { icon: 'tabler-versions', label: 'Version', value: versionLabel.value },
  { icon: 'tabler-server', label: 'Environment', value: envLabel.value },
  { icon: 'tabler-hash', label: 'Build', value: meta.value.build_number ? `#${meta.value.build_number}` : null },
  { icon: 'tabler-git-commit', label: 'Commit', value: meta.value.commit_hash || null },
  { icon: 'tabler-calendar-event', label: 'Build Date', value: buildDateLabel.value },
  { icon: 'tabler-clock-up', label: 'Uptime', value: meta.value.uptime || null },
])
</script>

<template>
  <div>
    <!-- Brand card -->
    <VCard class="mb-6">
      <VCardText class="d-flex flex-column align-center py-8">
        <BrandLogo size="lg" />
        <span class="text-body-1 text-medium-emphasis mt-2">
          {{ versionLabel }}
        </span>
      </VCardText>
    </VCard>

    <!-- Branding settings -->
    <VCard class="mb-6">
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-brush"
          class="me-2"
        />
        Branding
      </VCardTitle>
      <VCardSubtitle>
        Application name displayed across the platform.
      </VCardSubtitle>

      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <AppTextField
              v-model="form.app_name"
              label="Application Name"
              :rules="[v => !!v || 'Required', v => v.length <= 50 || 'Max 50 characters']"
            />
          </VCol>
        </VRow>

        <div class="d-flex align-center gap-3 mt-4">
          <VBtn
            :loading="saving"
            @click="save"
          >
            Save
          </VBtn>
          <span
            v-if="saved"
            class="text-success text-body-2"
          >
            Saved!
          </span>
        </div>
      </VCardText>
    </VCard>

    <!-- System info -->
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-info-circle"
          class="me-2"
        />
        System Information
      </VCardTitle>

      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <VList>
              <template
                v-for="item in infoItems"
                :key="item.label"
              >
                <VListItem v-if="item.value">
                  <template #prepend>
                    <VIcon
                      :icon="item.icon"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle>{{ item.label }}</VListItemTitle>
                  <VListItemSubtitle>{{ item.value }}</VListItemSubtitle>
                </VListItem>
              </template>
            </VList>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>
  </div>
</template>
