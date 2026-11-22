<script setup>
// ADR-777 (Company Experience 2.0 / UX2) — « Ajouter une personne » : UN seul parcours.
//
// L'utilisateur répond à une question métier (« aura-t-elle besoin de se connecter à Leezr ? ») ; la
// distinction compte / fiche reste interne. Aucun moteur nouveau : mêmes endpoints, mêmes autorités
// serveur (invitation `POST /company/members`, fiche `POST /workforce/employees`, accès d'une fiche
// `POST /workforce/employees/{id}/access`). Ce qui n'est pas permis n'est pas proposé :
//   - sans Workforce (ou sans `workforce.manage`) : aucune fiche salarié n'est créée ni demandée ;
//   - sans `members.invite` : aucune invitation n'est proposée.
import { useMembersStore } from '@/modules/company/members/members.store'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useCan } from '@/composables/useCan'
import { useAppToast } from '@/composables/useAppToast'
import { $api } from '@/utils/api'

const props = defineProps({
  modelValue: { type: Boolean, default: false },

  // Réponse préremplie quand le lien d'origine la porte déjà (`?action=invite|create`).
  presetAccess: { type: Boolean, default: null },
})

const emit = defineEmits(['update:modelValue', 'created'])

const { t } = useI18n()
const { can, canModule } = useCan()
const { toast } = useAppToast()
const members = useMembersStore()
const employees = useEmployeesStore()
const settings = useCompanySettingsStore()

const canInvite = computed(() => can('members.invite'))
const canStaff = computed(() => canModule('workforce') && can('workforce.manage'))
const asksAccess = computed(() => canInvite.value && canStaff.value)

const today = () => new Date().toISOString().slice(0, 10)
const blank = () => ({ first_name: '', last_name: '', email: '', access: null, company_role_id: null, staff: true, hire_date: today(), phone: '', employee_number: '', job_role_id: null })
const form = ref(blank())
const errors = ref({})
const message = ref('')
const saving = ref(false)
const more = ref(false)

// La réponse effective : imposée quand un seul des deux parcours est permis.
const withAccess = computed(() => (asksAccess.value ? form.value.access : canInvite.value))
const withRecord = computed(() => canStaff.value && (withAccess.value === false || form.value.staff))
const roleOptions = computed(() => (settings.roles || []).map(r => ({ title: r.name, value: r.id })))

const ready = computed(() => form.value.first_name.trim() && form.value.last_name.trim()
  && withAccess.value !== null
  && (!withAccess.value || (form.value.email.trim() && form.value.company_role_id))
  && (!withRecord.value || form.value.hire_date))

// ── ADR-858 (L5) — the position is chosen here (optional) ; what it requires is shown when the user may read it.
// Nothing new is asked : the file collects these items later (onboarding / person file), as today.
const positions = ref([])
const positionNeeds = ref(null) // null = unknown (not readable or not loaded) ; [] = nothing specific

const loadPositions = async () => {
  if (!withRecord.value || positions.value.length) return
  const list = await $api('/workforce/job-roles').catch(() => [])

  positions.value = (Array.isArray(list) ? list : []).map(p => ({ title: p.department?.name ? `${p.title} · ${p.department.name}` : p.title, value: p.id }))
}

watch(() => form.value.job_role_id, async id => {
  positionNeeds.value = null
  if (!id) return
  try {
    const r = await $api(`/workforce/requirements?scope=job_role:${id}`)
    const st = r?.state || {}

    positionNeeds.value = [...(st.fields || []), ...(st.documents || [])].filter(i => (i.state ?? 'required') === 'required').map(i => i.label || i.code)
  }
  catch { positionNeeds.value = null }
})
watch(withRecord, v => { if (v) loadPositions() }, { immediate: true })
watch(() => props.modelValue, async open => {
  if (!open) return
  form.value = { ...blank(), access: asksAccess.value ? props.presetAccess : null }
  errors.value = {}
  message.value = ''
  more.value = false
  if (canInvite.value && !settings.roles?.length) await settings.fetchCompanyRoles({ silent: true }).catch(() => {})
})

const close = () => emit('update:modelValue', false)

const fail = e => {
  errors.value = e?.data?.errors || {}
  message.value = Object.keys(errors.value).length ? '' : (e?.data?.message || t('common.error'))
}

const submit = async () => {
  if (!ready.value || saving.value) return
  saving.value = true
  errors.value = {}
  message.value = ''

  const f = form.value

  try {
    // 1. Fiche salarié (seulement si l'entreprise utilise Workforce et que la personne est du personnel).
    let employee = null
    if (withRecord.value) {
      const created = await employees.createEmployee({
        first_name: f.first_name, last_name: f.last_name, email: f.email || null, hire_date: f.hire_date,
        phone: f.phone || null, employee_number: f.employee_number || null, job_role_id: f.job_role_id || null, // ADR-858
      })

      employee = created?.data ?? created
    }

    // 2. Accès : invitation contrôlée par le serveur (compte existant → acceptation explicite, quotas).
    if (withAccess.value && employee) {
      try {
        await employees.grantEmployeeAccess(employee.id, { email: f.email, company_role_id: f.company_role_id })
        toast(t('people.add.createdInvited'), 'success')
      }
      catch (e) {
        // La fiche existe ; l'accès reste à donner depuis la fiche — on le dit, sans rien masquer.
        toast(t('people.add.createdAccessFailed', { reason: Object.values(e?.data?.errors || {})[0]?.[0] || e?.data?.message || '' }), 'warning')
        emit('created', { personRef: `employee-${employee.id}`, tab: 'access' })
        close()

        return
      }
    }
    else if (withAccess.value) {
      const member = await members.addMember({ first_name: f.first_name, last_name: f.last_name, email: f.email, company_role_id: f.company_role_id })

      if (member?._invitationDelivery === 'failed') toast(t('peopleList.invitedNotSent'), 'warning')
      else if (member?._invitationDelivery === 'queued') toast(t(member?._pendingAcceptance ? 'peopleList.invitedPendingQueued' : 'peopleList.invitedQueued'), 'info')
      else toast(t(member?._pendingAcceptance ? 'peopleList.invitedPending' : 'peopleList.invited'), member?._pendingAcceptance ? 'info' : 'success')
      emit('created', { personRef: member?.user?.id ? `user-${member.user.id}` : null, member })
      close()

      return
    }
    else {
      toast(t('people.add.created'), 'success')
    }

    emit('created', { personRef: `employee-${employee.id}` })
    close()
  }
  catch (e) { fail(e) }
  finally { saving.value = false }
}
</script>

<template>
  <VNavigationDrawer
    temporary
    :width="420"
    location="end"
    class="scrollable-content"
    :model-value="props.modelValue"
    data-test="person-add-drawer"
    @update:model-value="v => emit('update:modelValue', v)"
  >
    <AppDrawerHeaderSection
      :title="t('people.add.title')"
      @cancel="close"
    />
    <VDivider />
    <div style="block-size: calc(100vh - 56px); overflow-y: auto;">
      <VCard flat>
        <VCardText>
          <VAlert
            v-if="message"
            type="error"
            variant="tonal"
            class="mb-4"
            data-test="person-add-error"
          >
            {{ message }}
          </VAlert>

          <VForm @submit.prevent="submit">
            <VRow>
              <VCol
                cols="12"
                sm="6"
              >
                <AppTextField
                  v-model="form.first_name"
                  :label="t('people.add.firstName') + ' *'"
                  :error-messages="errors.first_name"
                  autofocus
                  data-test="person-add-first-name"
                />
              </VCol>
              <VCol
                cols="12"
                sm="6"
              >
                <AppTextField
                  v-model="form.last_name"
                  :label="t('people.add.lastName') + ' *'"
                  :error-messages="errors.last_name"
                  data-test="person-add-last-name"
                />
              </VCol>

              <!-- La question métier — posée seulement quand les deux réponses sont possibles. -->
              <VCol
                v-if="asksAccess"
                cols="12"
              >
                <div class="text-body-1 font-weight-medium mb-1">
                  {{ t('people.add.accessQuestion') }}
                </div>
                <VRadioGroup
                  v-model="form.access"
                  hide-details
                  data-test="person-add-access"
                >
                  <VRadio
                    :value="true"
                    :label="t('people.add.accessYes')"
                    data-test="person-add-access-yes"
                  />
                  <VRadio
                    :value="false"
                    :label="t('people.add.accessNo')"
                    data-test="person-add-access-no"
                  />
                </VRadioGroup>
              </VCol>
              <VCol
                v-else
                cols="12"
              >
                <div class="text-body-2 text-medium-emphasis">
                  {{ canInvite ? t('people.add.onlyAccess') : t('people.add.onlyRecord') }}
                </div>
              </VCol>

              <template v-if="withAccess">
                <VCol cols="12">
                  <AppTextField
                    v-model="form.email"
                    type="email"
                    :label="t('common.email') + ' *'"
                    :error-messages="errors.email"
                    :hint="t('people.add.emailHint')"
                    persistent-hint
                    data-test="person-add-email"
                  />
                </VCol>
                <VCol cols="12">
                  <AppSelect
                    v-model="form.company_role_id"
                    :label="t('people.add.role') + ' *'"
                    :items="roleOptions"
                    :error-messages="errors.company_role_id"
                    data-test="person-add-role"
                  />
                </VCol>
                <VCol
                  v-if="canStaff"
                  cols="12"
                >
                  <VSwitch
                    v-model="form.staff"
                    :label="t('people.add.staff')"
                    hide-details
                    data-test="person-add-staff"
                  />
                </VCol>
              </template>

              <VCol
                v-if="withRecord && withAccess !== null"
                cols="12"
              >
                <AppTextField
                  v-model="form.hire_date"
                  type="date"
                  :label="t('people.add.hireDate') + ' *'"
                  :error-messages="errors.hire_date"
                  data-test="person-add-hire-date"
                />
              </VCol>
              <VCol
                v-if="withRecord && withAccess !== null && positions.length"
                cols="12"
              >
                <AppSelect
                  v-model="form.job_role_id"
                  :items="positions"
                  :label="t('l5.add.position')"
                  :placeholder="t('l5.add.positionPlaceholder')"
                  clearable
                  :error-messages="errors.job_role_id"
                  data-test="person-add-position"
                />
                <div
                  v-if="positionNeeds && positionNeeds.length"
                  class="lz-needs mt-2"
                  data-test="person-add-position-needs"
                >
                  <div class="text-caption font-weight-medium mb-1">
                    {{ t('l5.add.positionNeeds') }}
                  </div>
                  <div class="d-flex flex-wrap gap-1">
                    <VChip
                      v-for="n in positionNeeds"
                      :key="n"
                      size="x-small"
                      label
                    >
                      {{ n }}
                    </VChip>
                  </div>
                  <div class="text-caption text-medium-emphasis mt-1">
                    {{ t('l5.add.positionNeedsHint') }}
                  </div>
                </div>
                <div
                  v-else-if="positionNeeds && !positionNeeds.length"
                  class="text-caption text-medium-emphasis mt-1"
                >
                  {{ t('l5.add.positionNoNeeds') }}
                </div>
              </VCol>

              <!-- Informations utiles plus tard : repliées, jamais obligatoires. -->
              <VCol
                v-if="withAccess !== null && (withRecord || !withAccess)"
                cols="12"
              >
                <VBtn
                  variant="text"
                  size="small"
                  class="px-0"
                  :append-icon="more ? 'tabler-chevron-up' : 'tabler-chevron-down'"
                  data-test="person-add-more"
                  @click="more = !more"
                >
                  {{ t('people.add.more') }}
                </VBtn>
                <VExpandTransition>
                  <VRow
                    v-show="more"
                    class="mt-1"
                  >
                    <VCol
                      v-if="!withAccess"
                      cols="12"
                    >
                      <AppTextField
                        v-model="form.email"
                        type="email"
                        :label="t('common.email')"
                        :error-messages="errors.email"
                      />
                    </VCol>
                    <VCol
                      cols="12"
                      sm="6"
                    >
                      <AppTextField
                        v-model="form.phone"
                        :label="t('people.add.phone')"
                        :error-messages="errors.phone"
                      />
                    </VCol>
                    <VCol
                      cols="12"
                      sm="6"
                    >
                      <AppTextField
                        v-model="form.employee_number"
                        :label="t('people.add.employeeNumber')"
                        :placeholder="t('employees.fields.employeeNumberAuto')"
                        persistent-placeholder
                        :error-messages="errors.employee_number"
                      />
                    </VCol>
                  </VRow>
                </VExpandTransition>
              </VCol>

              <VCol
                cols="12"
                class="d-flex gap-3 justify-end"
              >
                <VBtn
                  variant="tonal"
                  color="secondary"
                  @click="close"
                >
                  {{ t('common.cancel') }}
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :disabled="!ready"
                  :loading="saving"
                  data-test="person-add-submit"
                >
                  {{ withAccess ? t('people.add.submitInvite') : t('people.add.submit') }}
                </VBtn>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </div>
  </VNavigationDrawer>
</template>

<style scoped>
.lz-needs { padding: .625rem .75rem; border-radius: 10px; background: rgba(var(--v-theme-primary), .05); }
</style>
