import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'
import { applyTheme } from '@/composables/useApplyTheme'
import { useThemeStore } from '@/modules/core/theme/theme.store'
import { setAppName } from '@/composables/useAppName'
import { refreshCsrf } from '@/utils/csrf'
import { postBroadcast } from '@/core/runtime/broadcast'

export const usePlatformAuthStore = defineStore('platformAuth', {
  state: () => ({
    _user: useCookie('platformUserData').value || null,
    _roles: useCookie('platformRoles').value || [],
    _permissions: useCookie('platformPermissions').value || [],
    _disabledModuleKeys: useCookie('platformDisabledModules').value || [],
    _hydrated: false,
    _sessionConfig: null,
    _appMeta: null,
    _twoFactorEnabled: false,
  }),

  getters: {
    user: state => state._user,
    isLoggedIn: state => !!state._user,
    roles: state => state._roles,
    permissions: state => state._permissions,
    disabledModuleKeys: state => state._disabledModuleKeys,
    isModuleInactive: state => key => state._disabledModuleKeys.includes(key),
    isSuperAdmin: state => Array.isArray(state._roles) && state._roles.includes('super_admin'),
    sessionConfig: state => state._sessionConfig,
    appMeta: state => state._appMeta,
    twoFactorEnabled: state => state._twoFactorEnabled,
  },

  actions: {
    _persistUser(user) {
      this._user = user

      // Strip loaded relations (roles.permissions) — they have their own cookies.
      // Without this, the cookie exceeds 4KB and the browser silently drops it.
      if (user) {
        const { roles, permissions, ...userData } = user
        useCookie('platformUserData').value = userData
      }
      else {
        useCookie('platformUserData').value = null
      }
    },

    _persistRoles(roles) {
      this._roles = roles
      useCookie('platformRoles').value = roles
    },

    _persistPermissions(permissions) {
      this._permissions = permissions
      useCookie('platformPermissions').value = permissions
    },

    _persistDisabledModules(keys) {
      this._disabledModuleKeys = keys
      useCookie('platformDisabledModules').value = keys
    },

    hasPermission(key) {
      if (this.isSuperAdmin) return true

      return Array.isArray(this._permissions) && this._permissions.includes(key)
    },

    async login({ email, password }) {
      await refreshCsrf()

      const data = await $platformApi('/login', {
        method: 'POST',
        body: { email, password },
      })

      // ADR-351: 2FA required — don't persist yet
      if (data.requires_2fa) {
        return data
      }

      this._hydrateFromLoginResponse(data)

      return data
    },

    async verify2fa(code) {
      const data = await $platformApi('/2fa/verify', {
        method: 'POST',
        body: { code },
      })

      this._hydrateFromLoginResponse(data)

      return data
    },

    _hydrateFromLoginResponse(data) {
      this._persistUser(data.user)
      this._persistRoles(data.roles || [])
      this._persistPermissions(data.permissions || [])
      this._persistDisabledModules(data.disabled_modules || [])
      applyTheme(data.ui_theme, data.theme_preference)
      useThemeStore().init(data.theme_preference, 'platform')
      this._sessionConfig = data.ui_session ?? null
      this._appMeta = data.app_meta ?? null
      if (data.app_meta?.app_name) setAppName(data.app_meta.app_name)
      this._twoFactorEnabled = data.two_factor_enabled ?? false
    },

    async logout() {
      try {
        await $platformApi('/logout', { method: 'POST' })
      }
      catch {
        // Ignore errors on logout
      }

      this._persistUser(null)
      this._persistRoles([])
      this._persistPermissions([])
      this._persistDisabledModules([])
      this._hydrated = false
      postBroadcast('logout')
    },

    async updateProfile({ first_name, last_name, email }) {
      const data = await $platformApi('/me/profile', {
        method: 'PUT',
        body: { first_name, last_name, email },
      })

      this._persistUser(data.user)

      return data.user
    },

    async updatePassword({ current_password, password, password_confirmation }) {
      await $platformApi('/me/password', {
        method: 'PUT',
        body: { current_password, password, password_confirmation },
      })
    },

    async fetchMe({ signal } = {}) {
      try {
        const data = await $platformApi('/me', { _authCheck: true, signal })

        this._persistUser(data.user)
        this._persistRoles(data.roles || [])
        this._persistPermissions(data.permissions || [])
        this._persistDisabledModules(data.disabled_modules || [])
        applyTheme(data.ui_theme, data.theme_preference)
        useThemeStore().init(data.theme_preference, 'platform')
        this._sessionConfig = data.ui_session ?? null
        this._appMeta = data.app_meta ?? null
        if (data.app_meta?.app_name) setAppName(data.app_meta.app_name)
        this._twoFactorEnabled = data.two_factor_enabled ?? false
        this._hydrated = true

        return data.user
      }
      catch {
        this._persistUser(null)
        this._persistRoles([])
        this._persistPermissions([])
        this._persistDisabledModules([])
        this._hydrated = true

        return null
      }
    },
  },
})
