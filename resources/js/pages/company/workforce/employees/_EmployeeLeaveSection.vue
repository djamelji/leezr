<script setup>
// ADR-640 (W-C) — congés du collaborateur (lazy). Réutilise les endpoints existants (scopés W-D).
import { $api } from '@/utils/api'
import { formatDate } from '@/utils/datetime'
const props = defineProps({ employeeId: { type: [String, Number], required: true } })
const { t } = useI18n()
const router = useRouter()
const loading = ref(true)
const requests = ref([])
const balances = ref([])
onMounted(async () => {
  try {
    const [r, b] = await Promise.all([
      $api(`/workforce/leaves?employee_id=${props.employeeId}&per_page=10`),
      $api(`/workforce/employees/${props.employeeId}/leave-balances`),
    ])
    requests.value = r.data ?? r ?? []
    balances.value = b?.balances ?? b ?? []
  } catch { /* scope / module handled */ } finally { loading.value = false }
})
</script>
<template>
  <div>
    <VSkeletonLoader v-if="loading" type="table-row@3" />
    <template v-else>
      <div v-if="balances.length" class="d-flex gap-2 flex-wrap mb-4">
        <VChip v-for="(b, i) in balances" :key="i" color="primary" variant="tonal" size="small">
          {{ b.leave_type_name || b.type || b.code }} : {{ ((b.available_hundredths ?? b.available ?? 0) / 100).toFixed(1) }} {{ t('employeeFile.days') }}
        </VChip>
      </div>
      <VTable v-if="requests.length" density="compact">
        <thead><tr><th>{{ t('employeeFile.period') }}</th><th>{{ t('employeeFile.type') }}</th><th>{{ t('employeeFile.status') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in requests" :key="r.id">
            <td>{{ formatDate(r.date_from) }} – {{ formatDate(r.date_to) }}</td>
            <td>{{ r.leave_type?.name || r.leave_type_name || '—' }}</td>
            <td><VChip size="x-small">{{ r.status }}</VChip></td>
          </tr>
        </tbody>
      </VTable>
      <div v-else class="text-center text-disabled py-4">{{ t('employeeFile.noLeave') }}</div>
      <div class="d-flex gap-2 mt-3 flex-wrap">
        <VBtn variant="text" size="small" append-icon="tabler-arrow-right" @click="router.push('/company/workforce/leave')">{{ t('employeeFile.seeAll') }}</VBtn>
        <VBtn variant="text" size="small" prepend-icon="tabler-calendar" @click="router.push({ path: '/company/workforce/leave', query: { tab: 'calendar', employee: employeeId } })">{{ t('employeeFile.openCalendar') }}</VBtn>
      </div>
    </template>
  </div>
</template>
