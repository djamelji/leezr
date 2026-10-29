<script setup>
// ADR-640 (W-C) — pointages récents du collaborateur (lazy, période bornée).
import { $api } from '@/utils/api'
import { formatDate, formatDateTime } from '@/utils/datetime'
const props = defineProps({ employeeId: { type: [String, Number], required: true } })
const { t } = useI18n()
const router = useRouter()
const loading = ref(true)
const entries = ref([])
onMounted(async () => {
  try {
    const d = await $api(`/workforce/time-entries?employee_id=${props.employeeId}`)
    entries.value = d.data ?? d ?? []
  } catch { /* */ } finally { loading.value = false }
})
</script>
<template>
  <div>
    <VSkeletonLoader v-if="loading" type="table-row@3" />
    <template v-else>
      <VTable v-if="entries.length" density="compact">
        <thead><tr><th>{{ t('employeeFile.date') }}</th><th>{{ t('employeeFile.clockIn') }}</th><th>{{ t('employeeFile.status') }}</th></tr></thead>
        <tbody>
          <tr v-for="e in entries" :key="e.id">
            <td>{{ formatDate(e.date) }}</td>
            <td>{{ e.clock_in ? formatDateTime(e.clock_in) : '—' }}</td>
            <td><VChip size="x-small">{{ e.status }}</VChip></td>
          </tr>
        </tbody>
      </VTable>
      <div v-else class="text-center text-disabled py-4">{{ t('employeeFile.noTime') }}</div>
      <VBtn class="mt-3" variant="text" size="small" append-icon="tabler-arrow-right" @click="router.push('/company/workforce/time')">{{ t('employeeFile.seeAll') }}</VBtn>
    </template>
  </div>
</template>
