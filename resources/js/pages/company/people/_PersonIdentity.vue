<script setup>
// ADR-768 (M7 LOT 2) — identité d'une personne dans la fiche unifiée. RÉUTILISE le formulaire
// Members (`MemberProfileForm` : nom, rôle, champs dynamiques propres à l'entreprise) et son
// endpoint sécurisé. Sans facette ACCÈS (ou sans compte), l'identité reste en lecture seule ;
// les coordonnées RH s'éditent dans l'onglet Emploi (autorité Workforce).
import MemberProfileForm from '@/company/components/MemberProfileForm.vue'
import { useMembersStore } from '@/modules/company/members/members.store'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  membershipId: { type: Number, default: null },
  identity: { type: Object, default: () => ({}) },
  employeeNumber: { type: String, default: null },
  canViewAccess: { type: Boolean, default: false },
  canEdit: { type: Boolean, default: false },
  lockName: { type: Boolean, default: false },
  shared: { type: Boolean, default: false },
})

const emit = defineEmits(['updated'])

const { t } = useI18n()
const membersStore = useMembersStore()
const settingsStore = useCompanySettingsStore()
const { toast } = useAppToast()

const hasForm = computed(() => !!props.membershipId && props.canViewAccess)
const loading = ref(false)
const loadFailed = ref(false)
const fieldLoading = ref(false)
const member = ref(null)
const baseFields = ref({})
const dynamicFields = ref([])
const completeness = ref({ filled: 0, total: 0, complete: true })

const apply = data => {
  member.value = { ...data.member, _isProtected: data.member?.role === 'owner' }
  baseFields.value = data.base_fields || {}
  dynamicFields.value = data.dynamic_fields || []
  completeness.value = data.profile_completeness || { filled: 0, total: 0, complete: true }
}

const load = async () => {
  if (!hasForm.value) return
  loading.value = true
  loadFailed.value = false
  try {
    const [data] = await Promise.all([
      membersStore.fetchMemberProfile(props.membershipId),
      props.canEdit ? settingsStore.fetchCompanyRoles({ silent: true }).catch(() => {}) : Promise.resolve(),
    ])

    apply(data)
  }
  catch { loadFailed.value = true }
  finally { loading.value = false }
}

watch(() => props.membershipId, load, { immediate: true })

// ADR-795.1 : une donnée protégée par un contrat établi exige une confirmation explicite ; le serveur
// reste seul juge (droit RH + état du contrat), cette boîte ne fait que transmettre l'intention.
const pendingPayload = ref(null)
const onSave = async (payload, confirmContractual = false) => {
  try {
    apply(await membersStore.updateMemberProfile(props.membershipId, confirmContractual ? { ...payload, confirm_contractual: true } : payload))
    pendingPayload.value = null
    toast(t('members.profileUpdated'), 'success')
    emit('updated')
  }
  catch (error) {
    if (!confirmContractual && error?.data?.errors?.confirm_contractual) {
      pendingPayload.value = payload

      return
    }
    pendingPayload.value = null
    toast(error?.data?.message || t('members.failedToUpdateProfile'), 'error')
  }
}

// ADR-164 : les champs dynamiques dépendent du rôle sélectionné.
const onRoleChange = async roleId => {
  const roleKey = settingsStore.roles.find(r => r.id === roleId)?.key || null

  fieldLoading.value = true
  try { dynamicFields.value = await membersStore.fetchMemberFields(props.membershipId, roleKey) }
  catch { toast(t('members.failedToLoadFields'), 'error') }
  finally { fieldLoading.value = false }
}
</script>

<template>
  <div>
    <VAlert
      v-if="shared && canEdit"
      type="info"
      variant="tonal"
      density="compact"
      class="mb-4"
      data-test="person-identity-shared"
    >
      {{ t('people.identity.sharedNotice') }}
    </VAlert>

    <template v-if="hasForm">
      <VSkeletonLoader
        v-if="loading"
        type="article"
      />
      <VAlert
        v-else-if="loadFailed"
        type="error"
        variant="tonal"
      >
        {{ t('members.failedToLoadProfile') }}
        <template #append>
          <VBtn
            size="small"
            variant="text"
            @click="load"
          >
            {{ t('common.retry') }}
          </VBtn>
        </template>
      </VAlert>
      <MemberProfileForm
        v-else
        :member="member"
        :base-fields="baseFields"
        :dynamic-fields="dynamicFields"
        :profile-completeness="completeness"
        :editable="canEdit"
        :lock-name="lockName"
        :loading="fieldLoading"
        :company-roles="settingsStore.roles"
        @save="onSave"
        @role-change="onRoleChange"
      />
    </template>

    <!-- Lecture seule : pas de compte, ou pas la facette ACCÈS -->
    <VList
      v-else
      lines="two"
      class="py-0"
    >
      <VListItem
        :title="identity.display_name"
        :subtitle="t('people.fields.name')"
        prepend-icon="tabler-user"
      />
      <VListItem
        v-if="identity.login_email"
        :title="identity.login_email"
        :subtitle="t('people.fields.loginEmail')"
        prepend-icon="tabler-mail"
      />
      <VListItem
        v-if="identity.contact_email && identity.contact_email !== identity.login_email"
        :title="identity.contact_email"
        :subtitle="t('people.fields.hrEmail')"
        prepend-icon="tabler-at"
      />
      <VListItem
        v-if="identity.phone"
        :title="identity.phone"
        :subtitle="t('people.fields.phone')"
        prepend-icon="tabler-phone"
      />
      <VListItem
        v-if="employeeNumber"
        :title="employeeNumber"
        :subtitle="t('people.fields.employeeNumber')"
        prepend-icon="tabler-id"
      />
    </VList>
    <AppDialog
      :model-value="!!pendingPayload"
      :title="t('employeeFields.confirmTitle')"
      :max-width="520"
      @update:model-value="v => { if (!v) pendingPayload = null }"
    >
      <p class="mb-0">
        {{ t('employeeFields.confirmText') }}
      </p>
      <template #actions>
        <VBtn
          variant="text"
          @click="pendingPayload = null"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          data-test="person-identity-confirm-contractual"
          @click="onSave(pendingPayload, true)"
        >
          {{ t('employeeFields.confirmBtn') }}
        </VBtn>
      </template>
    </AppDialog>
  </div>
</template>
