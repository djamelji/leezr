<script setup>
import { usePlatformSettingsStore } from '@/modules/platform-admin/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'
import { previewTypography, resetTypography } from '@/composables/useApplyTypography'

const settingsStore = usePlatformSettingsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)

const defaults = {
  active_source: 'local',
  active_family_id: null,
  google_fonts_enabled: false,
  google_active_family: null,
  google_weights: [300, 400, 500, 600, 700],
  headings_family_id: null,
  body_family_id: null,
}

const form = reactive({ ...defaults })

// ─── Font Family Dialog ──────────────────────
const isNewFamilyDialogVisible = ref(false)
const newFamilyName = ref('')
const isCreatingFamily = ref(false)

// ─── Font Upload ─────────────────────────────
const uploadFiles = ref(null)
const isUploading = ref(false)

// ─── Options ─────────────────────────────────
const weightOptions = [
  { title: '100 — Thin', value: 100 },
  { title: '200 — Extra Light', value: 200 },
  { title: '300 — Light', value: 300 },
  { title: '400 — Regular', value: 400 },
  { title: '500 — Medium', value: 500 },
  { title: '600 — Semi Bold', value: 600 },
  { title: '700 — Bold', value: 700 },
  { title: '800 — Extra Bold', value: 800 },
  { title: '900 — Black', value: 900 },
]

const weightLabels = {
  100: 'Thin', 200: 'Extra Light', 300: 'Light', 400: 'Regular',
  500: 'Medium', 600: 'Semi Bold', 700: 'Bold', 800: 'Extra Bold', 900: 'Black',
}

// ─── Computed ────────────────────────────────
const localFamilies = computed(() => settingsStore.fontFamilies)

const familySelectItems = computed(() =>
  localFamilies.value.map(f => ({ title: f.name, value: f.id })),
)

const selectedFamily = computed(() => {
  if (!form.active_family_id) return null

  return localFamilies.value.find(f => f.id === form.active_family_id)
})

const variantHeaders = [
  { title: 'Weight', key: 'weight' },
  { title: 'Style', key: 'style' },
  { title: 'File', key: 'original_name' },
  { title: '', key: 'actions', sortable: false, align: 'end' },
]

// ─── Preview ─────────────────────────────────
const previewPayload = computed(() => {
  if (form.active_source === 'local') {
    const family = selectedFamily.value

    return {
      active_source: 'local',
      active_family_name: family?.name || null,
      font_faces: family?.fonts?.map(f => ({
        weight: f.weight,
        style: f.style,
        url: `/storage/${f.file_path}`,
        format: f.format || 'woff2',
      })) || [],
    }
  }

  if (form.active_source === 'google' && form.google_fonts_enabled) {
    return {
      active_source: 'google',
      active_family_name: form.google_active_family,
      google_weights: form.google_weights,
    }
  }

  return null
})

watch(previewPayload, payload => {
  if (!isLoading.value) {
    if (payload?.active_family_name) {
      previewTypography(payload)
    }
    else {
      resetTypography()
    }
  }
}, { deep: true })

// Source select syncs google_fonts_enabled for backend guard
watch(() => form.active_source, source => {
  form.google_fonts_enabled = source === 'google'
})

// ─── Load ────────────────────────────────────
const loadSettings = data => {
  form.active_source = data.active_source ?? defaults.active_source
  form.active_family_id = data.active_family_id ?? defaults.active_family_id
  form.google_fonts_enabled = data.google_fonts_enabled ?? defaults.google_fonts_enabled
  form.google_active_family = data.google_active_family ?? defaults.google_active_family
  form.google_weights = data.google_weights ?? [...defaults.google_weights]
  form.headings_family_id = data.headings_family_id ?? defaults.headings_family_id
  form.body_family_id = data.body_family_id ?? defaults.body_family_id
}

onMounted(async () => {
  try {
    await settingsStore.fetchTypographySettings()
    if (settingsStore.typographySettings)
      loadSettings(settingsStore.typographySettings)
  }
  finally {
    isLoading.value = false
  }
})

// ─── Save / Reset ────────────────────────────
const save = async () => {
  isSaving.value = true
  try {
    const data = await settingsStore.updateTypographySettings({ ...form })

    toast(data.message, 'success')
    loadSettings(data.typography)
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to save typography settings.', 'error')
  }
  finally {
    isSaving.value = false
  }
}

const resetToDefaults = async () => {
  isSaving.value = true
  try {
    const data = await settingsStore.updateTypographySettings({ ...defaults })

    toast('Typography reset to defaults.', 'success')
    loadSettings(data.typography)
    resetTypography()
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to reset typography settings.', 'error')
  }
  finally {
    isSaving.value = false
  }
}

// ─── Family CRUD ─────────────────────────────
const createFamily = async () => {
  if (!newFamilyName.value.trim()) return
  isCreatingFamily.value = true
  try {
    const data = await settingsStore.createFontFamily({ name: newFamilyName.value.trim() })

    toast(data.message, 'success')
    form.active_family_id = data.family.id
    isNewFamilyDialogVisible.value = false
    newFamilyName.value = ''
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to create font family.', 'error')
  }
  finally {
    isCreatingFamily.value = false
  }
}

const deleteFamily = async familyId => {
  try {
    const data = await settingsStore.deleteFontFamily(familyId)

    toast(data.message, 'success')
    if (form.active_family_id === familyId)
      form.active_family_id = null
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to delete font family.', 'error')
  }
}

// ─── Font Upload / Delete ────────────────────
const weightPatterns = [
  { pattern: /thin/i, weight: 100 },
  { pattern: /extra[_-]?light|ultralight/i, weight: 200 },
  { pattern: /light/i, weight: 300 },
  { pattern: /regular|normal/i, weight: 400 },
  { pattern: /medium/i, weight: 500 },
  { pattern: /semi[_-]?bold|demi[_-]?bold/i, weight: 600 },
  { pattern: /extra[_-]?bold|ultra[_-]?bold/i, weight: 800 },
  { pattern: /bold/i, weight: 700 },
  { pattern: /black|heavy/i, weight: 900 },
]

const detectWeightAndStyle = filename => {
  const name = filename.replace(/\.[^.]+$/, '')
  const style = /italic/i.test(name) ? 'italic' : 'normal'

  for (const { pattern, weight } of weightPatterns) {
    if (pattern.test(name))
      return { weight, style }
  }

  return { weight: 400, style }
}

const uploadFonts = async () => {
  const files = uploadFiles.value
  if (!form.active_family_id || !files?.length) return

  isUploading.value = true
  let uploaded = 0

  try {
    for (const file of files) {
      const { weight, style } = detectWeightAndStyle(file.name)

      const fd = new FormData()

      fd.append('font', file)
      fd.append('weight', weight)
      fd.append('style', style)

      await settingsStore.uploadFont(form.active_family_id, fd)
      uploaded++
    }

    toast(`${uploaded} font${uploaded > 1 ? 's' : ''} uploaded.`, 'success')
    uploadFiles.value = null
  }
  catch (error) {
    const msg = uploaded > 0
      ? `${uploaded} uploaded, then failed: ${error?.data?.message || 'Upload error.'}`
      : error?.data?.message || 'Upload failed.'

    toast(msg, 'error')
  }
  finally {
    isUploading.value = false
  }
}

const deleteFont = async (familyId, fontId) => {
  try {
    const data = await settingsStore.deleteFont(familyId, fontId)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to delete font.', 'error')
  }
}
</script>

<template>
  <div>
    <VCard :loading="isLoading">
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-typography"
          class="me-2"
        />
        Typography
      </VCardTitle>
      <VCardSubtitle>
        Manage platform fonts. Changes are previewed live.
      </VCardSubtitle>

      <VCardText v-if="!isLoading">
        <!-- Font Source -->
        <div class="d-flex align-center justify-space-between mb-6">
          <h6 class="text-h6">
            Font Source
          </h6>
          <VRadioGroup
            v-model="form.active_source"
            inline
            hide-details
          >
            <VRadio
              label="Local"
              value="local"
            />
            <VRadio
              label="Google"
              value="google"
            />
          </VRadioGroup>
        </div>

        <VDivider class="mb-6" />

        <!-- Local Font Family -->
        <template v-if="form.active_source === 'local'">
          <h6 class="text-h6 mb-4">
            Local Font Family
          </h6>

          <VRow class="mb-6">
            <VCol
              cols="12"
              md="6"
            >
              <AppSelect
                v-model="form.active_family_id"
                :items="familySelectItems"
                label="Active Family"
                placeholder="Select a font family"
                clearable
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
              class="d-flex align-end gap-2"
            >
              <VBtn
                color="primary"
                variant="tonal"
                @click="isNewFamilyDialogVisible = true"
              >
                New Family
              </VBtn>
              <VBtn
                v-if="selectedFamily"
                color="error"
                variant="tonal"
                @click="deleteFamily(selectedFamily.id)"
              >
                Delete
              </VBtn>
            </VCol>
          </VRow>

          <!-- Selected family: variants table + upload -->
          <template v-if="selectedFamily">
            <VExpansionPanels class="mb-6">
              <VExpansionPanel>
                <VExpansionPanelTitle>
                  {{ selectedFamily.name }} — {{ (selectedFamily.fonts || []).length }} variant{{ (selectedFamily.fonts || []).length !== 1 ? 's' : '' }}
                </VExpansionPanelTitle>
                <VExpansionPanelText>
                  <VDataTable
                    :headers="variantHeaders"
                    :items="selectedFamily.fonts || []"
                    density="compact"
                    hide-default-footer
                  >
                    <template #item.weight="{ item }">
                      {{ item.weight }} — {{ weightLabels[item.weight] || '' }}
                    </template>
                    <template #item.actions="{ item }">
                      <IconBtn
                        size="small"
                        @click="deleteFont(selectedFamily.id, item.id)"
                      >
                        <VIcon
                          icon="tabler-trash"
                          size="22"
                        />
                      </IconBtn>
                    </template>
                    <template #no-data>
                      <span class="text-medium-emphasis">No font variants uploaded yet.</span>
                    </template>
                  </VDataTable>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>

            <!-- Upload Fonts -->
            <h6 class="text-h6 mb-4">
              Upload Fonts
            </h6>

            <VRow class="mb-6">
              <VCol
                cols="12"
                md="8"
              >
                <div class="app-text-field flex-grow-1">
                  <VLabel
                    class="mb-1 text-body-2 text-wrap"
                    style="line-height: 15px;"
                    text="Font files (.woff2)"
                  />
                  <VFileInput
                    v-model="uploadFiles"
                    accept=".woff2"
                    placeholder="Select one or more .woff2 files"
                    prepend-icon=""
                    prepend-inner-icon="tabler-upload"
                    variant="outlined"
                    multiple
                  />
                </div>
              </VCol>
              <VCol
                cols="12"
                md="4"
                class="d-flex align-end"
              >
                <VBtn
                  color="primary"
                  :loading="isUploading"
                  :disabled="!uploadFiles?.length"
                  @click="uploadFonts"
                >
                  Upload
                </VBtn>
              </VCol>
            </VRow>
            <VAlert
              v-if="uploadFiles?.length"
              type="info"
              variant="tonal"
              density="compact"
              class="mb-6"
            >
              Weight and style are auto-detected from filenames (e.g. Bold, Italic, Light, SemiBold).
            </VAlert>
          </template>

          <VDivider class="mb-6" />
        </template>

        <!-- Google Font -->
        <template v-if="form.active_source === 'google'">
          <h6 class="text-h6 mb-4">
            Google Font
          </h6>

          <VRow class="mb-6">
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.google_active_family"
                label="Font Family Name"
                placeholder="e.g. Roboto, Inter, Poppins"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppSelect
                v-model="form.google_weights"
                :items="weightOptions"
                :menu-props="{ maxHeight: '400' }"
                label="Weights"
                multiple
                chips
                closable-chips
              />
            </VCol>
          </VRow>

          <VDivider class="mb-6" />
        </template>

        <!-- Preview -->
        <h6 class="text-h6 mb-4">
          Preview
        </h6>

        <VCard variant="outlined">
          <VCardText>
            <h1 class="text-h1 mb-2">
              Heading 1
            </h1>
            <h2 class="text-h2 mb-4">
              Heading 2
            </h2>
            <p class="text-body-1 mb-2">
              The quick brown fox jumps over the lazy dog. 0123456789
            </p>
            <p class="text-body-2 text-medium-emphasis">
              ABCDEFGHIJKLMNOPQRSTUVWXYZ abcdefghijklmnopqrstuvwxyz
            </p>
          </VCardText>
        </VCard>
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

    <!-- New Family Dialog -->
    <VDialog
      v-model="isNewFamilyDialogVisible"
      max-width="500"
    >
      <DialogCloseBtn @click="isNewFamilyDialogVisible = false" />
      <VCard title="Create Font Family">
        <VCardText>
          <AppTextField
            v-model="newFamilyName"
            label="Family Name"
            placeholder="e.g. My Custom Font"
            autofocus
            @keyup.enter="createFamily"
          />
        </VCardText>
        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            @click="isNewFamilyDialogVisible = false"
          >
            Cancel
          </VBtn>
          <VBtn
            color="primary"
            :loading="isCreatingFamily"
            :disabled="!newFamilyName.trim()"
            @click="createFamily"
          >
            Create
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
