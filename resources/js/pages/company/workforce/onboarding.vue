<script setup>
// ADR-637 (W-F) — assistant d'onboarding RH. Parcours métier, reprise serveur, étapes conditionnées
// par les modules actifs, non destructif (réutilise W-E pour l'organisation). Aucun champ technique.
definePage({
  meta: { surface: 'structure', module: 'workforce', navActiveLink: 'company-workforce-organization-tab', permission: 'workforce.manage' },
})

import OrganizationPresetCard from './organization/_OrganizationPresetCard.vue'
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const router = useRouter()
const { toast } = useAppToast()

const data = ref(null)
const stepIndex = ref(0)
const busy = ref(false)
const selectedProfile = ref(null)

const STEP_META = {
  company: { icon: 'tabler-building', optional: false },
  organization: { icon: 'tabler-sitemap', optional: false },
  roles: { icon: 'tabler-users-group', optional: true },
  leave: { icon: 'tabler-beach', optional: true },
  worktime: { icon: 'tabler-clock', optional: true },
  first_employee: { icon: 'tabler-user-plus', optional: true },
  done: { icon: 'tabler-circle-check', optional: false },
}

const steps = computed(() => data.value?.context?.applicable_steps || [])
const ctx = computed(() => data.value?.context || {})
const state = computed(() => data.value?.state || {})
const currentStep = computed(() => steps.value[stepIndex.value])

const load = async () => {
  data.value = await $api('/workforce/onboarding')
  selectedProfile.value = ctx.value.company?.org_profile_key || null
  // Reprise : se positionner sur la 1re étape non terminée.
  const completed = state.value.completed_steps || []
  const idx = steps.value.findIndex(s => !completed.includes(s) && s !== 'done')
  stepIndex.value = idx >= 0 ? idx : Math.max(0, steps.value.length - 1)
  if (state.value.status === 'not_started') await $api('/workforce/onboarding/start', { method: 'POST', body: {} })
}

const saveProfile = async () => {
  try {
    await $api('/workforce/organization/profile', { method: 'PUT', body: { org_profile_key: selectedProfile.value || null } })
    toast(t('orgPreset.profileSaved'), 'success')
    await load()
  }
  catch { /* handled */ }
}

const complete = async step => {
  busy.value = true
  try {
    data.value = await $api('/workforce/onboarding/step', { method: 'POST', body: { step } })
    next()
  }
  catch { /* handled (e.g. 422 org requires org) */ }
  finally { busy.value = false }
}

const defer = async step => {
  busy.value = true
  try {
    data.value = await $api('/workforce/onboarding/defer', { method: 'POST', body: { step } })
    next()
  }
  finally { busy.value = false }
}

const finish = async () => {
  busy.value = true
  try {
    data.value = await $api('/workforce/onboarding/finish', { method: 'POST', body: {} })
    toast(t('workforceOnboarding.finished'), 'success')
  }
  finally { busy.value = false }
}

const next = () => { if (stepIndex.value < steps.value.length - 1) stepIndex.value++ }
const prev = () => { if (stepIndex.value > 0) stepIndex.value-- }

const profileItems = computed(() => (ctx.value.available_profiles || []).map(p => ({ title: p.label, value: p.key })))
const go = path => router.push(path)

onMounted(load)
</script>

<template>
  <div v-if="data">
    <div class="mb-6">
      <h4 class="text-h4 font-weight-bold">
        {{ t('workforceOnboarding.title') }}
      </h4>
      <p class="text-body-1 text-medium-emphasis mb-0">
        {{ t('workforceOnboarding.subtitle') }}
      </p>
    </div>

    <VCard>
      <VCardText>
        <!-- Progress -->
        <div class="d-flex flex-wrap gap-2 mb-6">
          <VChip
            v-for="(s, i) in steps"
            :key="s"
            :color="(state.completed_steps || []).includes(s) ? 'success' : (i === stepIndex ? 'primary' : 'secondary')"
            :variant="i === stepIndex ? 'elevated' : 'tonal'"
            size="small"
            @click="stepIndex = i"
          >
            <VIcon
              :icon="STEP_META[s]?.icon"
              start
              size="16"
            />
            {{ t('workforceOnboarding.steps.' + s) }}
          </VChip>
        </div>

        <!-- ── Étape : Votre entreprise ── -->
        <div v-if="currentStep === 'company'">
          <h6 class="text-h6 mb-4">
            {{ t('workforceOnboarding.steps.company') }}
          </h6>
          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <VList
                lines="two"
                density="compact"
              >
                <VListItem
                  :title="ctx.company.name"
                  :subtitle="t('workforceOnboarding.companyName')"
                />
                <VListItem
                  :title="ctx.company.market_key"
                  :subtitle="t('workforceOnboarding.market')"
                />
                <VListItem
                  :title="ctx.company.jobdomain_key"
                  :subtitle="t('workforceOnboarding.jobdomain')"
                />
              </VList>
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppSelect
                v-model="selectedProfile"
                :items="profileItems"
                :label="t('orgPreset.profile')"
                :hint="t('workforceOnboarding.profileHint')"
                persistent-hint
                clearable
              />
              <VBtn
                class="mt-3"
                variant="tonal"
                :disabled="selectedProfile === ctx.company.org_profile_key"
                @click="saveProfile"
              >
                {{ t('orgPreset.saveProfile') }}
              </VBtn>
            </VCol>
          </VRow>
        </div>

        <!-- ── Étape : Votre organisation (réutilise W-E) ── -->
        <div v-else-if="currentStep === 'organization'">
          <h6 class="text-h6 mb-2">
            {{ t('workforceOnboarding.steps.organization') }}
          </h6>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ ctx.existing_org.has_org ? t('workforceOnboarding.orgExisting') : t('workforceOnboarding.orgNew') }}
          </p>
          <OrganizationPresetCard @applied="load" />
        </div>

        <!-- ── Étape : Responsabilités et accès ── -->
        <div v-else-if="currentStep === 'roles'">
          <h6 class="text-h6 mb-2">
            {{ t('workforceOnboarding.steps.roles') }}
          </h6>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('workforceOnboarding.rolesIntro') }}
          </p>
          <VList density="compact">
            <VListItem
              v-for="r in ctx.available_roles"
              :key="r.key"
              :title="r.name"
            >
              <template #append>
                <VChip
                  v-if="r.is_administrative"
                  size="x-small"
                  color="info"
                >
                  {{ t('workforceOnboarding.admin') }}
                </VChip>
              </template>
            </VListItem>
          </VList>
          <VAlert
            type="info"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            {{ t('workforceOnboarding.rolesDeferHint') }}
          </VAlert>
        </div>

        <!-- ── Étape : Congés ── -->
        <div v-else-if="currentStep === 'leave'">
          <h6 class="text-h6 mb-2">
            {{ t('workforceOnboarding.steps.leave') }}
          </h6>
          <p class="text-body-2 text-medium-emphasis">
            {{ t('workforceOnboarding.leaveIntro') }}
          </p>
        </div>

        <!-- ── Étape : Temps de travail ── -->
        <div v-else-if="currentStep === 'worktime'">
          <h6 class="text-h6 mb-2">
            {{ t('workforceOnboarding.steps.worktime') }}
          </h6>
          <p class="text-body-2 text-medium-emphasis">
            {{ t('workforceOnboarding.worktimeIntro') }}
          </p>
          <VBtn
            variant="tonal"
            class="mt-2"
            @click="go('/company/workforce/configuration')"
          >
            {{ t('workforceOnboarding.openSettings') }}
          </VBtn>
        </div>

        <!-- ── Étape : Premier collaborateur ── -->
        <div v-else-if="currentStep === 'first_employee'">
          <h6 class="text-h6 mb-2">
            {{ t('workforceOnboarding.steps.first_employee') }}
          </h6>
          <template v-if="ctx.existing_employees > 0">
            <VAlert
              type="success"
              variant="tonal"
              density="compact"
            >
              {{ t('workforceOnboarding.employeesExist', { count: ctx.existing_employees }) }}
            </VAlert>
          </template>
          <template v-else>
            <p class="text-body-2 text-medium-emphasis mb-3">
              {{ t('workforceOnboarding.firstEmployeeIntro') }}
            </p>
            <VBtn
              color="primary"
              prepend-icon="tabler-user-plus"
              @click="go('/company/workforce/employees')"
            >
              {{ t('workforceOnboarding.addEmployee') }}
            </VBtn>
          </template>
        </div>

        <!-- ── Étape : Terminé ── -->
        <div v-else-if="currentStep === 'done'">
          <div class="text-center py-4">
            <VIcon
              icon="tabler-circle-check"
              color="success"
              size="56"
              class="mb-2"
            />
            <h6 class="text-h5 mb-2">
              {{ t('workforceOnboarding.doneTitle') }}
            </h6>
            <p class="text-body-2 text-medium-emphasis mb-4">
              {{ t('workforceOnboarding.doneSubtitle') }}
            </p>
            <div class="d-flex gap-2 justify-center flex-wrap">
              <VBtn
                color="primary"
                @click="go('/company/workforce')"
              >
                {{ t('workforceOnboarding.goHr') }}
              </VBtn>
              <VBtn
                variant="tonal"
                @click="go('/company/workforce/organization/departments')"
              >
                {{ t('workforceOnboarding.openOrg') }}
              </VBtn>
            </div>
          </div>
        </div>

        <VDivider class="my-6" />

        <!-- Navigation -->
        <div class="d-flex justify-space-between flex-wrap gap-2">
          <VBtn
            variant="tonal"
            :disabled="stepIndex === 0"
            prepend-icon="tabler-arrow-left"
            @click="prev"
          >
            {{ t('common.previous') }}
          </VBtn>
          <div class="d-flex gap-2">
            <VBtn
              v-if="STEP_META[currentStep]?.optional && currentStep !== 'done'"
              variant="text"
              :loading="busy"
              @click="defer(currentStep)"
            >
              {{ t('workforceOnboarding.later') }}
            </VBtn>
            <VBtn
              v-if="currentStep === 'done'"
              color="success"
              :loading="busy"
              prepend-icon="tabler-check"
              @click="finish"
            >
              {{ t('workforceOnboarding.finishBtn') }}
            </VBtn>
            <VBtn
              v-else
              color="primary"
              :loading="busy"
              append-icon="tabler-arrow-right"
              @click="complete(currentStep)"
            >
              {{ t('workforceOnboarding.continueBtn') }}
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>
  </div>
</template>
