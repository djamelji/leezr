<script setup>
import { usePlatformSettingsStore } from '@/modules/platform-admin/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'
import MaintenancePreview from '@/components/MaintenancePreview.vue'

const settingsStore = usePlatformSettingsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)

const defaults = {
  enabled: false,
  allowlist_ips: [],
  headline: "We'll be back soon!",
  subheadline: "We're working hard to improve the experience.",
  description: 'Our website is currently undergoing scheduled maintenance.',
  cta_text: 'Notify Me',
  list_slug: 'maintenance',
}

const form = reactive({
  enabled: false,
  allowlist_ips: [],
  headline: '',
  subheadline: '',
  description: '',
  cta_text: '',
  list_slug: 'maintenance',
})

const newIp = ref('')

const loadSettings = data => {
  form.enabled = data.enabled ?? false
  form.allowlist_ips = [...(data.allowlist_ips || [])]
  form.headline = data.headline ?? ''
  form.subheadline = data.subheadline ?? ''
  form.description = data.description ?? ''
  form.cta_text = data.cta_text ?? ''
  form.list_slug = data.list_slug || 'maintenance'
}

onMounted(async () => {
  try {
    await settingsStore.fetchMaintenanceSettings()
    if (settingsStore.maintenanceSettings)
      loadSettings(settingsStore.maintenanceSettings)
  }
  finally {
    isLoading.value = false
  }
})

const addIp = () => {
  const ip = newIp.value.trim()
  if (ip && !form.allowlist_ips.includes(ip)) {
    form.allowlist_ips.push(ip)
    newIp.value = ''
  }
}

const removeIp = index => {
  form.allowlist_ips.splice(index, 1)
}

const detectMyIp = async () => {
  try {
    const data = await settingsStore.fetchMyIp()
    if (data.ip && !form.allowlist_ips.includes(data.ip)) {
      form.allowlist_ips.push(data.ip)
      toast(`Added your IP: ${data.ip}`, 'success')
    }
    else if (data.ip) {
      toast('Your IP is already in the allowlist.', 'info')
    }
  }
  catch {
    toast('Failed to detect your IP.', 'error')
  }
}

const save = async () => {
  isSaving.value = true
  try {
    const data = await settingsStore.updateMaintenanceSettings({
      enabled: form.enabled,
      allowlist_ips: form.allowlist_ips,
      headline: form.headline,
      subheadline: form.subheadline,
      description: form.description,
      cta_text: form.cta_text,
      list_slug: form.list_slug,
    })

    toast(data.message, 'success')
    loadSettings(data.maintenance)
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to save maintenance settings.', 'error')
  }
  finally {
    isSaving.value = false
  }
}

const resetToDefaults = async () => {
  isSaving.value = true
  try {
    const data = await settingsStore.updateMaintenanceSettings({ ...defaults })

    toast('Maintenance settings reset to defaults.', 'success')
    loadSettings(data.maintenance)
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to reset maintenance settings.', 'error')
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
          icon="tabler-barrier-block"
          class="me-2"
        />
        Maintenance Mode
      </VCardTitle>
      <VCardSubtitle>
        Control maintenance mode, IP allowlist, and public page content.
      </VCardSubtitle>

      <VCardText v-if="!isLoading">
        <!-- Section 1 — Toggle -->
        <div class="d-flex align-center justify-space-between mb-4">
          <VLabel for="maintenance-toggle">
            Enable Maintenance Mode
          </VLabel>
          <VSwitch
            id="maintenance-toggle"
            v-model="form.enabled"
            hide-details
          />
        </div>

        <VAlert
          v-if="form.enabled"
          type="warning"
          variant="tonal"
          class="mb-6"
        >
          Enabling maintenance will block all non-platform users from accessing the application.
        </VAlert>

        <VDivider class="mb-6" />

        <!-- Section 2 — IP Allowlist -->
        <h6 class="text-h6 mb-4">
          IP Allowlist
        </h6>
        <p class="text-body-2 mb-4">
          IPs in this list can bypass maintenance mode.
        </p>

        <VTable
          v-if="form.allowlist_ips.length"
          class="mb-4"
        >
          <thead>
            <tr>
              <th>IP Address</th>
              <th class="text-end">
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(ip, index) in form.allowlist_ips"
              :key="index"
            >
              <td>{{ ip }}</td>
              <td class="text-end">
                <IconBtn @click="removeIp(index)">
                  <VIcon icon="tabler-trash" />
                </IconBtn>
              </td>
            </tr>
          </tbody>
        </VTable>

        <div class="d-flex gap-4 mb-6">
          <AppTextField
            v-model="newIp"
            placeholder="Enter IP address"
            class="flex-grow-1"
            @keyup.enter="addIp"
          />
          <VBtn
            variant="outlined"
            @click="addIp"
          >
            Add
          </VBtn>
          <VBtn
            variant="tonal"
            @click="detectMyIp"
          >
            Detect my IP
          </VBtn>
        </div>

        <VDivider class="mb-6" />

        <!-- Section 3 — Mailing List -->
        <h6 class="text-h6 mb-4">
          Notification List
        </h6>
        <AppTextField
          v-model="form.list_slug"
          label="Mailing List Slug"
          hint="Slug of the mailing list for 'Notify me' subscriptions."
          persistent-hint
          class="mb-6"
        />

        <VDivider class="mb-6" />

        <!-- Section 4 — Editable Preview -->
        <div class="d-flex align-center justify-space-between mb-4">
          <h6 class="text-h6">
            Page Content
          </h6>
          <span class="text-caption text-disabled">Click on text to edit</span>
        </div>

        <MaintenancePreview
          v-model:headline="form.headline"
          v-model:subheadline="form.subheadline"
          v-model:description="form.description"
          v-model:cta-text="form.cta_text"
        />
      </VCardText>

      <VDivider />

      <VCardActions class="pa-4">
        <VBtn
          color="primary"
          :loading="isSaving"
          :disabled="isLoading"
          @click="save"
        >
          Save
        </VBtn>
        <VBtn
          variant="outlined"
          :loading="isSaving"
          :disabled="isLoading"
          @click="resetToDefaults"
        >
          Reset to Defaults
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
