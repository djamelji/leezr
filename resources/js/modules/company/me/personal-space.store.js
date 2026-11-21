/**
 * ADR-769 (M7 LOT 3) — « Mon espace » : capacités PERSONNELLES réelles de la personne connectée
 * dans l'entreprise courante (`GET /profile/space`). Le serveur décide ; ce store est la SEULE
 * source consultée par l'accueil, la navigation personnelle et le pointage pour savoir si une API
 * self-service existe — aucun écran ne sonde un module absent (pas de 403 décoratif).
 */
import { useAuthStore } from '@/core/stores/auth'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { $api } from '@/utils/api'
import { registerTenantScoped } from '@/core/runtime/tenantScoped'

export const usePersonalSpaceStore = defineStore('personal-space', () => {
  const data = ref(null)
  const loading = ref(false)
  const failed = ref(false)
  let pending = null

  const capabilities = computed(() => data.value?.capabilities || {})
  const counts = computed(() => data.value?.counts || {})
  const hasEmployee = computed(() => !!data.value?.has_employee)
  const ready = computed(() => data.value !== null)
  const can = key => !!capabilities.value[key]

  /** Idempotent : une seule requête par contexte d'entreprise, partagée par tous les consommateurs. */
  function load(force = false) {
    if (data.value && !force) return Promise.resolve(data.value)
    if (pending) return pending
    if (useAuthStore().isCompanySuspended) return Promise.resolve(null) // ADR-847 : refused while suspended — not requested
    loading.value = true
    failed.value = false
    pending = $api('/profile/space')
      .then(res => { data.value = res; return res })
      .catch(() => { failed.value = true; data.value = null; return null })
      .finally(() => { loading.value = false; pending = null })

    return pending
  }

  function reset() {
    data.value = null
    failed.value = false
    pending = null
  }

  registerTenantScoped('personal-space', { reset, reload: () => load(true) })

  return { data, loading, failed, capabilities, counts, hasEmployee, ready, can, load, reset }
})
