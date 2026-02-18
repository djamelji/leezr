<script setup>
import SettingsTypography from './_SettingsTypography.vue'
import { usePlatformStore } from '@/core/stores/platform'
import { useAppToast } from '@/composables/useAppToast'
import { applyTheme } from '@/composables/useApplyTheme'
import borderSkin from '@images/customizer-icons/border-light.svg'
import compact from '@images/customizer-icons/compact-light.svg'
import defaultSkin from '@images/customizer-icons/default-light.svg'
import horizontalLight from '@images/customizer-icons/horizontal-light.svg'
import wideSvg from '@images/customizer-icons/wide-light.svg'

const platformStore = usePlatformStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)

const defaults = {
  theme: 'system',
  skin: 'default',
  primary_color: '#7367F0',
  primary_darken_color: '#675DD8',
  layout: 'vertical',
  nav_collapsed: false,
  semi_dark: false,
  navbar_blur: true,
  content_width: 'boxed',
}

const form = reactive({ ...defaults })

const presetColors = [
  { main: '#7367F0', darken: '#675DD8' },
  { main: '#0D9394', darken: '#0C8485' },
  { main: '#FFB400', darken: '#E6A200' },
  { main: '#FF4C51', darken: '#E64449' },
  { main: '#16B1FF', darken: '#149FE6' },
]

const isCustomColor = computed(() => {
  return !presetColors.some(c => c.main === form.primary_color)
})

const themeMode = [
  { bgImage: 'tabler-sun', value: 'light', label: 'Light' },
  { bgImage: 'tabler-moon-stars', value: 'dark', label: 'Dark' },
  { bgImage: 'tabler-device-desktop-analytics', value: 'system', label: 'System' },
]

const skinOptions = [
  { bgImage: defaultSkin, value: 'default', label: 'Default' },
  { bgImage: borderSkin, value: 'bordered', label: 'Bordered' },
]

const layoutOptions = [
  { bgImage: defaultSkin, value: 'vertical', label: 'Vertical' },
  { bgImage: horizontalLight, value: 'horizontal', label: 'Horizontal' },
]

const contentWidthOptions = [
  { bgImage: compact, value: 'boxed', label: 'Compact' },
  { bgImage: wideSvg, value: 'fluid', label: 'Wide' },
]

const isHorizontal = computed(() => form.layout === 'horizontal')

const selectPresetColor = color => {
  form.primary_color = color.main
  form.primary_darken_color = color.darken
}

const applyCustomColor = val => {
  if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
    form.primary_color = val.toUpperCase()
    form.primary_darken_color = val.toUpperCase()
  }
}

const loadSettings = data => {
  Object.assign(form, data)
}

// Live preview — watch only previewable fields (layout excluded: structural change, applied on save)
const previewPayload = computed(() => ({
  theme: form.theme,
  skin: form.skin,
  primary_color: form.primary_color,
  primary_darken_color: form.primary_darken_color,
  semi_dark: form.semi_dark,
  navbar_blur: form.navbar_blur,
  nav_collapsed: form.nav_collapsed,
  content_width: form.content_width,
}))

watch(previewPayload, val => {
  if (!isLoading.value)
    applyTheme(val)
})

onMounted(async () => {
  try {
    await platformStore.fetchThemeSettings()
    if (platformStore.themeSettings)
      loadSettings(platformStore.themeSettings)
  }
  finally {
    isLoading.value = false
  }
})

const save = async () => {
  isSaving.value = true
  try {
    const data = await platformStore.updateThemeSettings({ ...form })

    toast(data.message, 'success')
    loadSettings(data.theme)
    applyTheme(data.theme)
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to save theme settings.', 'error')
  }
  finally {
    isSaving.value = false
  }
}

const resetToDefaults = async () => {
  isSaving.value = true
  try {
    const data = await platformStore.updateThemeSettings({ ...defaults })

    toast('Theme reset to defaults.', 'success')
    loadSettings(data.theme)
    applyTheme(data.theme)
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to reset theme settings.', 'error')
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
          icon="tabler-palette"
          class="me-2"
        />
        Platform Theme Settings
      </VCardTitle>
      <VCardSubtitle>
        Configure the global UI appearance for all users. Changes are previewed live.
      </VCardSubtitle>

      <VCardText v-if="!isLoading">
        <!-- Primary Color + Theme Mode -->
        <VRow class="mb-6">
          <VCol
            cols="12"
            md="6"
          >
            <h6 class="text-h6 mb-2">
              Primary Color
            </h6>
            <div
              class="d-flex align-center"
              style="column-gap: 0.75rem;"
            >
              <div
                v-for="color in presetColors"
                :key="color.main"
                class="cursor-pointer"
                style="border-radius: 0.375rem; outline: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); padding-block: 0.5rem; padding-inline: 0.625rem;"
                :style="form.primary_color === color.main ? `outline-color: ${color.main}; outline-width: 2px;` : ''"
                @click="selectPresetColor(color)"
              >
                <div
                  style="border-radius: 0.375rem; block-size: 2.125rem; inline-size: 1.8938rem;"
                  :style="{ backgroundColor: color.main }"
                />
              </div>

              <!-- Custom color picker -->
              <label
                class="cursor-pointer"
                style="border-radius: 0.375rem; padding-block: 0.5rem; padding-inline: 0.625rem; position: relative;"
                :style="isCustomColor ? `outline: 2px solid ${form.primary_color};` : 'outline: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));'"
              >
                <div
                  class="d-flex align-center justify-center"
                  style="border-radius: 0.375rem; block-size: 2.125rem; inline-size: 1.8938rem; background: conic-gradient(from 0deg, #ff0000, #ffff00, #00ff00, #00ffff, #0000ff, #ff00ff, #ff0000);"
                >
                  <VIcon
                    icon="tabler-plus"
                    size="14"
                    color="white"
                  />
                </div>
                <input
                  type="color"
                  :value="form.primary_color"
                  style="position: absolute; opacity: 0; inline-size: 0; block-size: 0;"
                  @input="applyCustomColor($event.target.value)"
                >
              </label>
            </div>
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <h6 class="text-h6 mb-2">
              Theme Mode
            </h6>
            <CustomRadiosWithImage
              :key="form.theme"
              v-model:selected-radio="form.theme"
              :radio-content="themeMode"
              :grid-column="{ cols: '4' }"
              class="customizer-skins"
            >
              <template #label="item">
                <span class="text-sm text-medium-emphasis mt-1">{{ item?.label }}</span>
              </template>
              <template #content="{ item }">
                <div class="d-flex align-center justify-center py-3 w-100">
                  <VIcon
                    size="30"
                    :icon="item.bgImage"
                    color="high-emphasis"
                  />
                </div>
              </template>
            </CustomRadiosWithImage>
          </VCol>
        </VRow>

        <VDivider class="mb-6" />

        <!-- Skin / Layout / Content Width -->
        <div class="d-flex flex-column flex-md-row align-md-start gap-4 mb-6">
          <div class="flex-fill">
            <h6 class="text-h6 mb-2">
              Skin
            </h6>
            <CustomRadiosWithImage
              :key="form.skin"
              v-model:selected-radio="form.skin"
              :radio-content="skinOptions"
              :grid-column="{ cols: '6' }"
              class="compact-radios"
            >
              <template #label="item">
                <span class="text-sm text-medium-emphasis">{{ item?.label }}</span>
              </template>
            </CustomRadiosWithImage>
          </div>

          <VDivider :vertical="$vuetify.display.mdAndUp" />

          <div class="flex-fill">
            <h6 class="text-h6 mb-2">
              Layout
            </h6>
            <CustomRadiosWithImage
              :key="form.layout"
              v-model:selected-radio="form.layout"
              :radio-content="layoutOptions"
              :grid-column="{ cols: '6' }"
              class="compact-radios"
            >
              <template #label="item">
                <span class="text-sm text-medium-emphasis">{{ item?.label }}</span>
              </template>
            </CustomRadiosWithImage>
          </div>

          <VDivider :vertical="$vuetify.display.mdAndUp" />

          <div class="flex-fill">
            <h6 class="text-h6 mb-2">
              Content Width
            </h6>
            <CustomRadiosWithImage
              :key="form.content_width"
              v-model:selected-radio="form.content_width"
              :radio-content="contentWidthOptions"
              :grid-column="{ cols: '6' }"
              class="compact-radios"
            >
              <template #label="item">
                <span class="text-sm text-medium-emphasis">{{ item?.label }}</span>
              </template>
            </CustomRadiosWithImage>
          </div>
        </div>

        <VDivider class="mb-6" />

        <!-- Options -->
        <div class="mb-6">
          <h6 class="text-h6 mb-4">
            Options
          </h6>

          <div class="d-flex flex-column gap-4">
            <div class="d-flex align-center justify-space-between">
              <VLabel for="semi-dark">
                Semi-dark menu
              </VLabel>
              <VSwitch
                id="semi-dark"
                v-model="form.semi_dark"
                :disabled="isHorizontal"
                hide-details
              />
            </div>

            <div class="d-flex align-center justify-space-between">
              <VLabel for="navbar-blur">
                Navbar blur
              </VLabel>
              <VSwitch
                id="navbar-blur"
                v-model="form.navbar_blur"
                hide-details
              />
            </div>

            <div class="d-flex align-center justify-space-between">
              <VLabel for="nav-collapsed">
                Nav collapsed
              </VLabel>
              <VSwitch
                id="nav-collapsed"
                v-model="form.nav_collapsed"
                :disabled="isHorizontal"
                hide-details
              />
            </div>
          </div>
        </div>
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

    <VAlert
      type="info"
      variant="tonal"
      class="mt-4"
    >
      Changes are previewed live. Click Save to persist for all users.
    </VAlert>

    <SettingsTypography class="mt-6" />
  </div>
</template>

<style lang="scss" scoped>
:deep(.customizer-skins .v-radio) {
  display: none;
}
</style>
