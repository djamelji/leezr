<script setup>
import { useConfigStore } from '@core/stores/config'
import { getI18n } from '@/plugins/i18n'
import { cookieRef } from '@layouts/stores/config'

const { t, locale } = useI18n()
const configStore = useConfigStore()

// Theme preference — synced to configStore.theme (which triggers ThemeStore persistence)
const themeOptions = computed(() => [
  { title: t('accountSettings.themeLight'), value: 'light' },
  { title: t('accountSettings.themeDark'), value: 'dark' },
  { title: t('accountSettings.themeSystem'), value: 'system' },
])

const selectedTheme = computed({
  get: () => configStore.theme,
  set: val => { configStore.theme = val },
})

// Language preference — persisted to cookie + i18n locale
const languageOptions = [
  { title: 'Français', value: 'fr' },
  { title: 'English', value: 'en' },
]

const languageCookie = cookieRef('language', 'fr')

const selectedLanguage = computed({
  get: () => locale.value,
  set: val => {
    locale.value = val
    languageCookie.value = val
  },
})
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard :title="t('accountSettings.preferences')">
        <VCardText>
          <VRow>
            <!-- Theme -->
            <VCol
              cols="12"
              md="6"
            >
              <AppSelect
                v-model="selectedTheme"
                :items="themeOptions"
                :label="t('accountSettings.theme')"
                item-title="title"
                item-value="value"
              />
            </VCol>

            <!-- Language -->
            <VCol
              cols="12"
              md="6"
            >
              <AppSelect
                v-model="selectedLanguage"
                :items="languageOptions"
                :label="t('accountSettings.language')"
                item-title="title"
                item-value="value"
              />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>
