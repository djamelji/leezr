<script setup>
// ADR-640 (W-C) — pointages récents du collaborateur (lazy, période bornée).
import { $api } from '@/utils/api'
import { displayLocale, formatDate } from '@/utils/datetime'
import StatusChip from '@/core/components/StatusChip.vue'
const props = defineProps({ employeeId: { type: [String, Number], required: true } })
const { t } = useI18n()
const router = useRouter()
const loading = ref(true)
const entries = ref([])
const hm = iso => (iso ? new Date(iso).toLocaleTimeString(displayLocale(), { hour: '2-digit', minute: '2-digit' }) : '')
onMounted(async () => {
  try {
    // ADR-823 : 30 derniers jours (le serveur ne renvoyait par défaut que la semaine en cours)
    const from = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10)
    const d = await $api(`/workforce/time-entries?employee_id=${props.employeeId}&from=${from}`)
    entries.value = d.data ?? d ?? []
  } catch { /* */ } finally { loading.value = false }
})
</script>
<template>
  <div>
    <VSkeletonLoader v-if="loading" type="table-row@3" />
    <template v-else>
      <VList v-if="entries.length" density="compact" class="py-0" data-test="person-clock-history">
        <VListItem v-for="e in entries.slice(0, 10)" :key="e.id" class="px-0">
          <template #prepend>
            <VAvatar color="info" variant="tonal" rounded size="36" class="me-3">
              <VIcon icon="tabler-clock" size="20" />
            </VAvatar>
          </template>
          <VListItemTitle class="text-capitalize">{{ formatDate(e.date) }}</VListItemTitle>
          <VListItemSubtitle>{{ e.clock_in ? hm(e.clock_in) : '—' }} – {{ e.clock_out ? hm(e.clock_out) : t('employeeFile.inProgress') }}</VListItemSubtitle>
          <template #append>
            <StatusChip domain="timeEntry" :status="e.status" size="x-small" />
          </template>
        </VListItem>
      </VList>
      <div v-else class="text-center text-disabled py-4">{{ t('employeeFile.noTime30') }}</div>
      <VBtn class="mt-3" variant="text" size="small" append-icon="tabler-arrow-right" @click="router.push('/company/workforce/time')">{{ t('employeeFile.seeAll') }}</VBtn>
    </template>
  </div>
</template>
