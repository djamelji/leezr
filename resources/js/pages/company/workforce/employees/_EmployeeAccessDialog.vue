<script setup>
// W-HR-UX-3 (ADR-668) — donner/gérer l'accès à l'espace collaborateur DEPUIS la fiche. Réutilise le
// mécanisme d'invitation Members existant. États réels : aucun / invité / activé (jamais confondus).
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  employeeId: { type: [Number, String], required: true },
  employeeEmail: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'granted'])

const { t } = useI18n()
const { toast } = useAppToast()
const store = useEmployeesStore()

const isOpen = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })
const loading = ref(false)
const acting = ref(false)
const access = ref(null)
const email = ref('')
const roleId = ref(null)

// Certaines réponses API sont enveloppées dans { data: ... } — on déballe systématiquement.
const unwrap = r => (r && typeof r === 'object' && 'state' in r ? r : (r?.data ?? r))

const load = async () => {
  loading.value = true
  try {
    access.value = unwrap(await store.fetchEmployeeAccess(props.employeeId))
    email.value = access.value.email || props.employeeEmail || ''
    roleId.value = access.value.assignable_roles?.[0]?.id ?? null
  } catch (e) { toast(e.response?._data?.message || t('common.error'), 'error') }
  finally { loading.value = false }
}

// immediate: couvre le cas où le composant est re-monté alors qu'il est déjà ouvert (après refresh de la fiche).
watch(isOpen, v => { if (v) load() }, { immediate: true })

const roleItems = computed(() => (access.value?.assignable_roles || []).map(r => ({ title: r.name, value: r.id })))
const state = computed(() => access.value?.state || 'none')

const grant = async () => {
  acting.value = true
  try {
    await store.grantEmployeeAccess(props.employeeId, { email: email.value, company_role_id: roleId.value })
    await load() // relit l'état autoritatif (none → invited/active)
    toast(t('employeeAccess.granted'), 'success')
    emit('granted')
  } catch (e) { toast(e.response?._data?.message || t('common.error'), 'error') }
  finally { acting.value = false }
}

const resend = async () => {
  acting.value = true
  try {
    await store.resendEmployeeAccess(props.employeeId)
    await load()
    toast(t('employeeAccess.resent'), 'success')
  } catch (e) { toast(e.response?._data?.message || t('common.error'), 'error') }
  finally { acting.value = false }
}
</script>

<template>
  <VDialog v-model="isOpen" max-width="520">
    <VCard>
      <VCardItem>
        <VCardTitle>{{ t('employeeAccess.title') }}</VCardTitle>
        <VCardSubtitle>{{ t('employeeAccess.hint') }}</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VCardText>
        <div v-if="loading" class="text-center py-6">
          <VProgressCircular indeterminate color="primary" />
        </div>

        <template v-else-if="access">
          <!-- Compte CONNECTÉ (1re connexion effectuée) -->
          <VAlert v-if="state === 'connected'" type="success" variant="tonal" density="compact">
            <VIcon icon="tabler-circle-check" start size="16" />
            {{ t('employeeAccess.connected') }}
          </VAlert>

          <!-- Compte ACTIVÉ (mot de passe défini, pas encore connecté) -->
          <VAlert v-else-if="state === 'active'" type="success" variant="tonal" density="compact">
            <VIcon icon="tabler-circle-check" start size="16" />
            {{ t('employeeAccess.active') }}
          </VAlert>

          <!-- INVITÉ (mot de passe non défini) -->
          <template v-else-if="state === 'invited'">
            <VAlert type="info" variant="tonal" density="compact" class="mb-3">
              <VIcon icon="tabler-mail-forward" start size="16" />
              {{ t('employeeAccess.invited') }}
            </VAlert>
            <VBtn
              variant="tonal"
              prepend-icon="tabler-send"
              :loading="acting"
              @click="resend"
            >
              {{ t('employeeAccess.resend') }}
            </VBtn>
          </template>

          <!-- AUCUN accès → formulaire d'invitation -->
          <template v-else>
            <p class="text-body-2 text-medium-emphasis mb-3">
              {{ t('employeeAccess.noneHint') }}
            </p>
            <AppTextField
              v-model="email"
              type="email"
              :label="t('employeeAccess.email') + ' *'"
              class="mb-3"
            />
            <AppSelect
              v-model="roleId"
              :items="roleItems"
              :label="t('employeeAccess.role')"
              :hint="t('employeeAccess.roleHint')"
              persistent-hint
              class="mb-3"
            />
            <VBtn
              color="primary"
              prepend-icon="tabler-user-plus"
              :loading="acting"
              :disabled="!email"
              block
              @click="grant"
            >
              {{ t('employeeAccess.grant') }}
            </VBtn>
          </template>

          <p class="text-caption text-medium-emphasis mt-4">
            {{ t('employeeAccess.statesNote') }}
          </p>
        </template>
      </VCardText>
      <VDivider />
      <VCardText class="d-flex justify-end">
        <VBtn variant="outlined" color="secondary" @click="isOpen = false">
          {{ t('common.close') }}
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>
</template>
