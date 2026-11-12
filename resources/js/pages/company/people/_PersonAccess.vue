<script setup>
// ADR-768 (M7 LOT 2) — onglet « Accès & autorisations » de la fiche unifiée. Toutes les actions
// appellent les endpoints sécurisés existants (MembershipSecurityPolicy, GlobalIdentityPolicy) :
// rôle, suspension / réactivation, retrait, relance d'invitation, identifiants. L'affichage suit
// `can.*` calculé par le serveur ; chaque endpoint revérifie.
import { usePersonStore } from '@/modules/company/people/person.store'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'

const props = defineProps({
  accountMeta: { type: Object, required: true },
})

const emit = defineEmits(['changed', 'removed', 'grant'])

const { t } = useI18n()
const store = usePersonStore()
const settings = useCompanySettingsStore()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()

const can = computed(() => store.can)
const account = computed(() => store.account)
const hasMembership = computed(() => !!store.membershipId)
const busy = ref(null)

// ── Rôle ──
const roleItems = computed(() => {
  const list = (settings.roles || []).map(r => ({ title: r.name, value: r.id }))
  const cur = account.value.role
  if (cur && !list.some(i => i.value === cur.id)) list.unshift({ title: cur.name, value: cur.id })

  return list
})
const selectedRole = ref(null)

watch(() => account.value.role, r => { selectedRole.value = r?.id ?? null }, { immediate: true })
onMounted(() => { if (can.value.change_role && !settings.roles?.length) settings.fetchCompanyRoles({ silent: true }).catch(() => {}) })

const run = async (key, fn, successKey, after = 'changed') => {
  if (busy.value) return
  busy.value = key
  try {
    const res = await fn()

    toast(res?.message && key === 'reset' ? res.message : t(successKey), 'success')
    emit(after)
  }
  catch (e) {
    const errors = e?.data?.errors
    const first = errors ? Object.values(errors)[0]?.[0] : null

    toast(first || e?.data?.message || t('common.error'), 'error')
  }
  finally { busy.value = null }
}

const saveRole = () => run('role', () => store.changeRole(selectedRole.value), 'people.access.roleUpdated')
const restore = () => run('restore', () => store.restoreAccess(), 'people.access.restored')
const resend = () => run('resend', () => store.resendInvitation(), 'people.access.invitationResent')

const suspendDialog = ref(false)
const suspendReason = ref('')
const suspend = async () => {
  suspendDialog.value = false
  await run('suspend', () => store.suspendAccess(suspendReason.value), 'people.access.suspended')
  suspendReason.value = ''
}

const remove = async () => {
  const ok = await confirm({
    question: t('people.access.removeConfirm', { name: store.identity.display_name }),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('people.access.removed'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })
  if (ok) run('remove', () => store.removeAccess(), 'people.access.removed', 'removed')
}

// ── Identifiants ──
const sendReset = async () => {
  const ok = await confirm({
    question: t('credentials.confirmResetTitle', { name: store.identity.display_name }),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('credentials.confirmResetMessage'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })
  if (ok) run('reset', () => store.sendPasswordReset(), 'people.access.resetSent')
}

const showPassword = ref(false)
const passwordForm = ref({ password: '', password_confirmation: '' })
const savePassword = async () => {
  await run('password', () => store.setPassword(passwordForm.value), 'people.access.passwordSet')
  showPassword.value = false
  passwordForm.value = { password: '', password_confirmation: '' }
}
</script>

<template>
  <div data-test="person-access">
    <!-- ═══ État du compte ═══ -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar
          :color="accountMeta.color"
          variant="tonal"
          rounded
        >
          <VIcon icon="tabler-key" />
        </VAvatar>
        <div>
          <div class="text-body-1 font-weight-medium">
            {{ accountMeta.label }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ t('people.access.stateHint.' + (account.state || 'none')) }}
          </div>
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <VBtn
          v-if="!hasMembership && store.employeeId && can.invite"
          color="primary"
          prepend-icon="tabler-user-plus"
          data-test="person-grant-access"
          @click="emit('grant')"
        >
          {{ t('people.access.grant') }}
        </VBtn>
        <VBtn
          v-if="hasMembership && can.invite && ['invited', 'pending_acceptance'].includes(account.state)"
          variant="tonal"
          prepend-icon="tabler-mail-forward"
          :loading="busy === 'resend'"
          data-test="person-resend"
          @click="resend"
        >
          {{ t('people.access.resend') }}
        </VBtn>
        <VBtn
          v-if="can.suspend_access && account.state === 'suspended'"
          color="success"
          variant="tonal"
          prepend-icon="tabler-lock-open"
          :loading="busy === 'restore'"
          data-test="person-restore"
          @click="restore"
        >
          {{ t('people.access.restore') }}
        </VBtn>
        <VBtn
          v-if="can.suspend_access && account.has_access"
          color="warning"
          variant="tonal"
          prepend-icon="tabler-lock"
          :loading="busy === 'suspend'"
          data-test="person-suspend"
          @click="suspendDialog = true"
        >
          {{ t('people.access.suspend') }}
        </VBtn>
        <VBtn
          v-if="can.remove_access"
          color="error"
          variant="tonal"
          prepend-icon="tabler-user-minus"
          :loading="busy === 'remove'"
          data-test="person-remove"
          @click="remove"
        >
          {{ account.state === 'pending_acceptance' ? t('people.access.cancelInvitation') : t('people.access.remove') }}
        </VBtn>
      </div>
    </div>

    <VAlert
      v-if="account.state === 'pending_acceptance'"
      type="warning"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      {{ t('people.access.pendingNotice') }}
    </VAlert>

    <!-- ═══ Rôle (MembershipSecurityPolicy côté serveur) ═══ -->
    <template v-if="hasMembership">
      <VDivider class="mb-4" />
      <div class="text-overline mb-2">
        {{ t('people.access.assignRole') }}
      </div>
      <div
        v-if="can.change_role && !account.is_owner"
        class="d-flex align-end flex-wrap gap-2 mb-4"
      >
        <AppSelect
          v-model="selectedRole"
          :items="roleItems"
          density="compact"
          data-test="person-role-select"
          style="max-inline-size: 280px"
        />
        <VBtn
          color="primary"
          variant="tonal"
          :loading="busy === 'role'"
          :disabled="selectedRole === account.role?.id"
          prepend-icon="tabler-check"
          data-test="person-role-save"
          @click="saveRole"
        >
          {{ t('common.save') }}
        </VBtn>
      </div>
      <div
        v-else
        class="mb-4"
      >
        <VChip
          v-if="account.is_owner"
          color="primary"
          variant="tonal"
          label
        >
          {{ t('people.owner') }}
        </VChip>
        <VChip
          v-else-if="account.role"
          :color="account.role.is_administrative ? 'warning' : 'secondary'"
          variant="tonal"
          label
        >
          {{ account.role.name }}
        </VChip>
        <span
          v-else
          class="text-medium-emphasis"
        >—</span>
      </div>
    </template>

    <!-- ═══ Identifiants (members.credentials) ═══ -->
    <template v-if="can.manage_credentials">
      <VDivider class="mb-4" />
      <div class="text-overline mb-2">
        {{ t('credentials.title') }}
      </div>
      <div class="d-flex flex-wrap gap-2 mb-2">
        <VBtn
          variant="outlined"
          color="warning"
          prepend-icon="tabler-mail-forward"
          :loading="busy === 'reset'"
          data-test="person-reset-password"
          @click="sendReset"
        >
          {{ t('credentials.sendResetEmail') }}
        </VBtn>
        <VBtn
          v-if="can.set_password && !showPassword"
          variant="outlined"
          color="info"
          prepend-icon="tabler-key"
          data-test="person-set-password"
          @click="showPassword = true"
        >
          {{ t('credentials.setPassword') }}
        </VBtn>
      </div>
      <div
        v-if="!can.set_password"
        class="text-body-2 text-medium-emphasis mb-4"
        data-test="person-password-shared"
      >
        {{ t('people.access.passwordShared') }}
      </div>
      <VRow
        v-if="showPassword"
        class="mb-2"
      >
        <VCol
          cols="12"
          md="6"
        >
          <AppTextField
            v-model="passwordForm.password"
            :label="t('credentials.newPassword')"
            :placeholder="t('credentials.minChars')"
            type="password"
            autocomplete="new-password"
          />
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <AppTextField
            v-model="passwordForm.password_confirmation"
            :label="t('credentials.confirmPassword')"
            :placeholder="t('credentials.repeatPassword')"
            type="password"
            autocomplete="new-password"
          />
        </VCol>
        <VCol
          cols="12"
          class="d-flex gap-2"
        >
          <VBtn
            color="info"
            :loading="busy === 'password'"
            @click="savePassword"
          >
            {{ t('credentials.savePassword') }}
          </VBtn>
          <VBtn
            variant="tonal"
            color="secondary"
            @click="showPassword = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
        </VCol>
      </VRow>
    </template>

    <!-- ═══ Permissions effectives ═══ -->
    <template v-if="hasMembership && can.view_access">
      <VDivider class="mb-4" />
      <div class="text-overline mb-2">
        {{ t('people.access.effectivePermissions') }}
      </div>
      <div
        v-if="(account.effective_permissions || []).length"
        class="d-flex flex-wrap gap-1"
      >
        <VChip
          v-for="p in account.effective_permissions"
          :key="p"
          size="x-small"
          label
          variant="outlined"
        >
          {{ p }}
        </VChip>
      </div>
      <div
        v-else
        class="text-body-2 text-medium-emphasis"
      >
        {{ t('people.access.noPermissions') }}
      </div>
    </template>

    <!-- Motif de suspension -->
    <VDialog
      v-model="suspendDialog"
      max-width="480"
    >
      <VCard :title="t('people.access.suspendTitle')">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">
            {{ t('people.access.suspendHint') }}
          </p>
          <AppTextField
            v-model="suspendReason"
            :label="t('people.access.suspendReason')"
            data-test="person-suspend-reason"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            color="secondary"
            @click="suspendDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="warning"
            variant="elevated"
            data-test="person-suspend-confirm"
            @click="suspend"
          >
            {{ t('people.access.suspend') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialogComponent />
  </div>
</template>
