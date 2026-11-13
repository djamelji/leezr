/**
 * EmployeeHomeStore (ADR-751, P3) — données opérationnelles de la page d'accueil employé
 * `/home`. N'utilise QUE des endpoints self-service RÉELS, tenant-scoped et module-gated
 * côté serveur (aucune fiction, aucun élargissement RBAC) :
 *   - GET /workforce/me/shifts          → mon planning (créneaux publiés, 14 j)
 *   - GET /workforce/me/leaves?pending  → mes demandes de congés en attente (compteur)
 *   - GET /profile/documents            → mes documents à régulariser (compteur)
 *   - GET /my-deliveries                → mes livraisons assignées (compteur)
 * Chaque chargeur est indépendant et tolérant : une absence de droit/module (403/404) ou
 * un non-salarié → valeurs vides, jamais d'erreur bloquante. L'appelant (home.vue) ne
 * déclenche un chargeur que si le MODULE correspondant est actif.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { $api } from '@/utils/api'
import { registerTenantScoped } from '@/core/runtime/tenantScoped'

export const useEmployeeHomeStore = defineStore('employee-home', () => {
  const shifts = ref([])
  const pendingLeaves = ref(0)
  const docsToProvide = ref(0)
  const deliveriesCount = ref(0)
  const loadingWorkforce = ref(false)
  const loadingDocs = ref(false)
  const loadingDeliveries = ref(false)

  const upcomingShifts = computed(() => {
    const now = Date.now()

    return [...shifts.value]
      .filter(s => s.start_at)
      .sort((a, b) => new Date(a.start_at) - new Date(b.start_at))
      .filter(s => (s.end_at ? new Date(s.end_at).getTime() : new Date(s.start_at).getTime()) >= now)
  })
  const nextShift = computed(() => upcomingShifts.value[0] || null)

  // ADR-769 : chaque sous-appel suit une capacité annoncée par le serveur (planning / congés).
  async function loadWorkforce({ planning = true, leave = true } = {}) {
    loadingWorkforce.value = true
    if (planning) {
      try {
        const d = await $api('/workforce/me/shifts?days=14')

        shifts.value = Array.isArray(d.shifts) ? d.shifts : []
      }
      catch { shifts.value = [] }
    }
    if (leave) {
      try {
        const l = await $api('/workforce/me/leaves?status=pending&perPage=1')

        pendingLeaves.value = l.total ?? (Array.isArray(l.data) ? l.data.length : 0)
      }
      catch { pendingLeaves.value = 0 }
    }
    loadingWorkforce.value = false
  }

  async function loadDocuments() {
    loadingDocs.value = true
    try {
      const d = await $api('/profile/documents')
      const docs = Array.isArray(d.documents) ? d.documents : []
      docsToProvide.value = docs.filter(x => x.request_status !== 'approved').length
    }
    catch { docsToProvide.value = 0 }
    loadingDocs.value = false
  }

  async function loadDeliveries() {
    loadingDeliveries.value = true
    try {
      const d = await $api('/my-deliveries')
      deliveriesCount.value = d.total ?? (Array.isArray(d.data) ? d.data.length : 0)
    }
    catch { deliveriesCount.value = 0 }
    loadingDeliveries.value = false
  }

  function reset() {
    shifts.value = []
    pendingLeaves.value = 0
    docsToProvide.value = 0
    deliveriesCount.value = 0
  }

  registerTenantScoped('employee-home', { reset })

  return {
    shifts, pendingLeaves, docsToProvide, deliveriesCount,
    upcomingShifts, nextShift,
    loadingWorkforce, loadingDocs, loadingDeliveries,
    loadWorkforce, loadDocuments, loadDeliveries, reset,
  }
})
