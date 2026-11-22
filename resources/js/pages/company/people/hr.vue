<script setup>
// ADR-864 — `/company/people/hr` (« Gestion RH », ADR-820) is folded into Personnes : one interface. The URL stays
// valid (links, bookmarks) and lands on /company/people with its filters kept (q, status, dept, role, manager,
// contract) ; Personnes translates the former ids into its own filters. Same guard as before.
definePage({ meta: { module: 'core.members', anyPermission: ['members.view', 'workforce.view', 'roles.view'] } })

const route = useRoute()
const router = useRouter()
const KEPT = ['q', 'status', 'dept', 'role', 'manager', 'contract']

const query = {}
for (const k of KEPT) if (route.query[k] != null && route.query[k] !== '') query[k] = route.query[k]
router.replace({ name: 'company-people', query })
</script>

<template>
  <div />
</template>
