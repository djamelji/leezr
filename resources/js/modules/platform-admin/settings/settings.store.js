import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformSettingsStore = defineStore('platformSettings', {
  state: () => ({
    _modules: [],
    _themeSettings: null,
    _sessionSettings: null,
    _typographySettings: null,
    _fontFamilies: [],
    _maintenanceSettings: null,
  }),

  getters: {
    modules: state => state._modules,
    themeSettings: state => state._themeSettings,
    sessionSettings: state => state._sessionSettings,
    typographySettings: state => state._typographySettings,
    fontFamilies: state => state._fontFamilies,
    maintenanceSettings: state => state._maintenanceSettings,
  },

  actions: {
    // ─── Modules ────────────────────────────────────────
    async fetchModules() {
      const data = await $platformApi('/modules')

      this._modules = data.modules
    },

    async toggleModule(key) {
      const data = await $platformApi(`/modules/${key}/toggle`, { method: 'PUT' })

      const idx = this._modules.findIndex(m => m.key === key)
      if (idx !== -1)
        this._modules[idx] = data.module

      return data
    },

    // ─── Theme Settings ────────────────────────────────
    async fetchThemeSettings() {
      const data = await $platformApi('/theme')

      this._themeSettings = data.theme
    },

    async updateThemeSettings(payload) {
      const data = await $platformApi('/theme', {
        method: 'PUT',
        body: payload,
      })

      this._themeSettings = data.theme

      return data
    },

    // ─── Session Settings ─────────────────────────────
    async fetchSessionSettings() {
      const data = await $platformApi('/session-settings')

      this._sessionSettings = data.session
    },

    async updateSessionSettings(payload) {
      const data = await $platformApi('/session-settings', {
        method: 'PUT',
        body: payload,
      })

      this._sessionSettings = data.session

      return data
    },

    // ─── Typography Settings ────────────────────────────
    async fetchTypographySettings() {
      const data = await $platformApi('/typography')

      this._typographySettings = data.typography
      this._fontFamilies = data.families
    },

    async updateTypographySettings(payload) {
      const data = await $platformApi('/typography', {
        method: 'PUT',
        body: payload,
      })

      this._typographySettings = data.typography

      return data
    },

    async createFontFamily(payload) {
      const data = await $platformApi('/font-families', {
        method: 'POST',
        body: payload,
      })

      this._fontFamilies.push(data.family)

      return data
    },

    async uploadFont(familyId, formData) {
      const data = await $platformApi(`/font-families/${familyId}/fonts`, {
        method: 'POST',
        body: formData,
      })

      const idx = this._fontFamilies.findIndex(f => f.id === familyId)
      if (idx !== -1)
        this._fontFamilies[idx] = data.family

      return data
    },

    async deleteFont(familyId, fontId) {
      const data = await $platformApi(`/font-families/${familyId}/fonts/${fontId}`, {
        method: 'DELETE',
      })

      const idx = this._fontFamilies.findIndex(f => f.id === familyId)
      if (idx !== -1)
        this._fontFamilies[idx] = data.family

      return data
    },

    async deleteFontFamily(familyId) {
      const data = await $platformApi(`/font-families/${familyId}`, {
        method: 'DELETE',
      })

      this._fontFamilies = this._fontFamilies.filter(f => f.id !== familyId)

      return data
    },

    // ─── Maintenance Settings ────────────────────────────
    async fetchMaintenanceSettings() {
      const data = await $platformApi('/maintenance-settings')

      this._maintenanceSettings = data.maintenance
    },

    async updateMaintenanceSettings(payload) {
      const data = await $platformApi('/maintenance-settings', {
        method: 'PUT',
        body: payload,
      })

      this._maintenanceSettings = data.maintenance

      return data
    },

    async fetchMyIp() {
      return await $platformApi('/maintenance/my-ip')
    },
  },
})
