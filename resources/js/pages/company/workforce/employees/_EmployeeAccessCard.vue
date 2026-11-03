<script setup>
// PRODUCT-UX-3 / P3-A (ADR-686) — unified "person" access card on the employee fiche.
// Surfaces the Employee ↔ User account ↔ Company membership link as ONE readable state
// (no technical entities exposed), server-authorized (reuses /employees/{id}/access).
// A suspend/revoke workflow does NOT exist backend-side → not offered (no 2nd identity
// system). Invite/resend reuse the existing EmployeeAccessService.
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useCan } from '@/composables/useCan'

const props = defineProps({ employeeId: { type: [Number, String], required: true }, reloadSignal: { type: Number, default: 0 } })
const emit = defineEmits(['manage'])

const { t } = useI18n()
const store = useEmployeesStore()
const { can } = useCan()

const access = ref(null)
const loading = ref(true)
const unwrap = r => (r && typeof r === 'object' && 'state' in r ? r : (r?.data ?? r))

const load = async () => {
  loading.value = true
  try { access.value = unwrap(await store.fetchEmployeeAccess(props.employeeId)) }
  catch { access.value = null }
  finally { loading.value = false }
}
onMounted(load)
watch(() => props.reloadSignal, load)

const state = computed(() => access.value?.state || 'none')
const canManage = computed(() => can('members.invite'))

// Honest, non-technical state presentation.
const STATE = {
  none: { color: 'secondary', icon: 'tabler-user-off' },
  invited: { color: 'warning', icon: 'tabler-mail-forward' },
  active: { color: 'info', icon: 'tabler-user-check' },
  connected: { color: 'success', icon: 'tabler-user-check' },
  suspended: { color: 'warning', icon: 'tabler-lock' },
  account_without_membership: { color: 'error', icon: 'tabler-alert-triangle' },
}
const meta = computed(() => STATE[state.value] ?? STATE.none)
const actionLabel = computed(() => (state.value === 'none' ? t('employeeAccess.card.invite') : state.value === 'invited' ? t('employeeAccess.resend') : t('employeeAccess.card.manage')))

defineExpose({ load })
</script>

<template>
  <VCard variant="outlined" class="h-100">
    <VCardText>
      <div class="d-flex align-center justify-space-between mb-2">
        <div class="text-overline">{{ $t('employeeAccess.card.title') }}</div>
        <VIcon :icon="meta.icon" :color="meta.color" size="20" />
      </div>

      <div v-if="loading" class="py-2">
        <VProgressCircular indeterminate size="22" color="primary" />
      </div>

      <template v-else>
        <VChip :color="meta.color" variant="tonal" size="small" label class="mb-2">
          {{ $t('employeeAccess.states.' + state) }}
        </VChip>

        <div v-if="access?.email" class="text-body-2 text-medium-emphasis mb-1">
          <VIcon icon="tabler-mail" size="14" class="me-1" />{{ access.email }}
        </div>
        <div v-else class="text-body-2 text-warning mb-1">
          <VIcon icon="tabler-mail-off" size="14" class="me-1" />{{ $t('employeeAccess.card.noEmail') }}
        </div>

        <!-- Mismatch Employee/User/Membership → explicit, actionable -->
        <VAlert
          v-if="state === 'account_without_membership'"
          type="error"
          variant="tonal"
          density="compact"
          class="mt-2 text-caption"
        >
          {{ $t('employeeAccess.card.mismatch') }}
        </VAlert>

        <VBtn
          v-if="canManage"
          variant="tonal"
          size="small"
          block
          class="mt-3"
          append-icon="tabler-arrow-right"
          data-test="emp-access-manage"
          @click="emit('manage')"
        >
          {{ actionLabel }}
        </VBtn>
      </template>
    </VCardText>
  </VCard>
</template>
