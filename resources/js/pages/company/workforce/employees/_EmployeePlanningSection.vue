<script setup>
// ADR-640 (W-C) — shifts à venir du collaborateur (lazy).
import { $api } from '@/utils/api'
import { formatDate, formatDateTime } from '@/utils/datetime'
const props = defineProps({ employeeId: { type: [String, Number], required: true } })
const { t } = useI18n()
const router = useRouter()
const loading = ref(true)
const shifts = ref([])
onMounted(async () => {
  try {
    const d = await $api(`/workforce/planning/shifts?employee_id=${props.employeeId}&per_page=10`)
    shifts.value = d.data ?? d ?? []
  } catch { /* */ } finally { loading.value = false }
})
</script>
<template>
  <div>
    <VSkeletonLoader v-if="loading" type="table-row@3" />
    <template v-else>
      <VTable v-if="shifts.length" density="compact">
        <thead><tr><th>{{ t('employeeFile.date') }}</th><th>{{ t('employeeFile.hours') }}</th><th>{{ t('employeeFile.status') }}</th></tr></thead>
        <tbody>
          <tr v-for="s in shifts" :key="s.id">
            <td>{{ formatDate(s.date) }}</td>
            <td>{{ s.start_at ? formatDateTime(s.start_at) : '—' }}</td>
            <td><VChip size="x-small">{{ s.status }}</VChip></td>
          </tr>
        </tbody>
      </VTable>
      <div v-else class="text-center text-disabled py-4">{{ t('employeeFile.noPlanning') }}</div>
      <VBtn class="mt-3" variant="text" size="small" append-icon="tabler-arrow-right" @click="router.push('/company/workforce/planning')">{{ t('employeeFile.seeAll') }}</VBtn>
    </template>
  </div>
</template>
