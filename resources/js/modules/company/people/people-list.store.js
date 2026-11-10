/**
 * ADR-754 (P2) — store de la liste Collaborateurs unifiée. Lit `/company/people` (union
 * dédupliquée memberships ∪ employees, colonnes selon les facettes autorisées). Recherche,
 * filtres, tri et pagination côté serveur. Aucun champ hors-facette n'est reçu (gating serveur).
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { $api } from '@/utils/api'

export const usePeopleListStore = defineStore('people-list', () => {
  const rows = ref([])
  const total = ref(0)
  const facets = ref({ access: false, hr: false })
  const loading = ref(false)

  async function fetch({ page = 1, perPage = 20, search = '', sortBy = 'name', sortDir = 'asc', filters = {} } = {}) {
    loading.value = true
    try {
      const q = new URLSearchParams({ page, per_page: perPage, sort_by: sortBy, sort_dir: sortDir })
      if (search) q.set('search', search)
      for (const [k, v] of Object.entries(filters)) { if (v) q.set(k, v) }
      const res = await $api(`/company/people?${q.toString()}`)
      rows.value = res.data || []
      total.value = res.total || 0
      facets.value = res.facets || { access: false, hr: false }
    }
    catch {
      rows.value = []
      total.value = 0
    }
    finally { loading.value = false }
  }

  return { rows, total, facets, loading, fetch }
})
