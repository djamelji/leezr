/**
 * Theme preference store — ADR-159.
 *
 * DB is the authority for user theme preference.
 * Cookie is a performance cache (survives refresh without API call).
 * @core/stores/config.theme remains the Vuetify integration layer.
 *
 * Flow:
 * 1. Login/fetchMe → init(preference, scope) → sets configStore.theme
 * 2. User clicks ThemeSwitcher → @core sets configStore.theme → watcher persists to DB
 * 3. Refresh → cookie has correct value; fetchMe confirms from DB
 */

import { defineStore } from 'pinia'
import { watch } from 'vue'
import { useConfigStore } from '@core/stores/config'
import { $api } from '@/utils/api'
import { $platformApi } from '@/utils/platformApi'

export const useThemeStore = defineStore('theme', {
  state: () => ({
    _preference: 'system',
    _scope: null, // 'company' | 'platform'
    _hydrated: false,
    _syncing: false, // prevent feedback loop with configStore watcher
    _unwatchConfig: null,
  }),

  getters: {
    preference: state => state._preference,
    hydrated: state => state._hydrated,
  },

  actions: {
    /**
     * Initialize from auth payload.
     * Called once per login/fetchMe. Sets configStore.theme and starts
     * watching for user-initiated changes via ThemeSwitcher.
     */
    init(preference, scope) {
      this._preference = preference || 'system'
      this._scope = scope
      this._hydrated = true

      // Apply to configStore — this drives Vuetify theme via existing watcher
      const configStore = useConfigStore()

      this._syncing = true
      configStore.theme = this._preference
      this._syncing = false

      // Watch configStore.theme for user-initiated changes (ThemeSwitcher clicks)
      this._startWatching()
    },

    /**
     * Start watching configStore.theme for changes initiated by the @core ThemeSwitcher.
     * When user clicks the toggle, @core sets configStore.theme directly.
     * We detect this and persist to DB.
     */
    _startWatching() {
      // Cleanup previous watcher if any
      if (this._unwatchConfig) {
        this._unwatchConfig()
      }

      const configStore = useConfigStore()

      this._unwatchConfig = watch(
        () => configStore.theme,
        newTheme => {
          if (this._syncing || !this._hydrated) return
          if (newTheme === this._preference) return

          this._preference = newTheme
          this._persistToDb(newTheme)
        },
      )
    },

    async _persistToDb(theme) {
      try {
        const apiFn = this._scope === 'platform' ? $platformApi : $api

        await apiFn('/theme-preference', {
          method: 'PUT',
          body: { theme },
        })
      }
      catch (e) {
        console.warn('[theme] Failed to persist preference:', e)
      }
    },

    reset() {
      if (this._unwatchConfig) {
        this._unwatchConfig()
        this._unwatchConfig = null
      }
      this._preference = 'system'
      this._scope = null
      this._hydrated = false
      this._syncing = false
    },
  },
})
