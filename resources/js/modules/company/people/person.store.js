/**
 * ADR-753 (Phase 1) — store de la fiche Collaborateur unifiée. Lit le read-model Core
 * `/company/people/{personRef}` (agrège User/Membership/Employee/CompanyRole + sections
 * Workforce autorisées) et l'historique d'audit réel. Aucune donnée dérivée côté client :
 * le serveur décide de ce qui est visible (masquage read-model).
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { $api } from '@/utils/api'

export const usePersonStore = defineStore('company-person', () => {
  const data = ref(null)
  const loading = ref(false)
  const error = ref(null)

  const identity = computed(() => data.value?.identity || {})
  const account = computed(() => data.value?.account || {})
  const employment = computed(() => data.value?.employment || null)
  const sections = computed(() => data.value?.available_sections || [])
  const can = computed(() => data.value?.can || {})
  const employeeId = computed(() => data.value?.employee_id || null)
  const userId = computed(() => data.value?.user_id || null)
  const personRef = computed(() => data.value?.person_ref || null)

  async function load(ref) {
    loading.value = true
    error.value = null
    try {
      data.value = await $api(`/company/people/${encodeURIComponent(ref)}`)
    }
    catch (e) {
      error.value = e?.data?.message || e?.message || 'load_error'
      data.value = null
    }
    finally {
      loading.value = false
    }
  }

  const history = ref([])
  const historyLoading = ref(false)
  async function loadHistory(ref) {
    historyLoading.value = true
    try {
      const res = await $api(`/company/people/${encodeURIComponent(ref)}/history`)
      history.value = res.entries || []
    }
    catch { history.value = [] }
    finally { historyLoading.value = false }
  }

  return {
    data, loading, error,
    identity, account, employment, sections, can, employeeId, userId, personRef,
    history, historyLoading,
    load, loadHistory,
  }
})
