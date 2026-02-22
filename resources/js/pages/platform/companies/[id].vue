<script setup>
import { usePlatformCompaniesStore } from '@/modules/platform-admin/companies/companies.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'manage_companies',
  },
})

const route = useRoute()
const router = useRouter()
const companiesStore = usePlatformCompaniesStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const activeTab = ref('overview')
const actionLoading = ref(false)

// ─── Profile data ───────────────────────────────────
const company = ref(null)
const plan = ref(null)
const modules = ref([])

// ─── Overview form ──────────────────────────────────
const planForm = ref({ plan_key: '' })

const applyProfile = data => {
  company.value = data.company
  plan.value = data.plan
  modules.value = data.modules
  planForm.value.plan_key = data.company.plan_key || 'starter'
}

onMounted(async () => {
  try {
    const [profileData] = await Promise.all([
      companiesStore.fetchCompanyProfile(route.params.id),
      companiesStore.fetchPlans(),
    ])

    applyProfile(profileData)
  }
  catch {
    toast('Failed to load company profile.', 'error')
    router.push({ name: 'platform-companies' })
  }
  finally {
    isLoading.value = false
  }
})

// ─── Plan change ────────────────────────────────────
const changePlan = async planKey => {
  if (planKey === company.value.plan_key)
    return

  actionLoading.value = true

  try {
    await companiesStore.updateCompanyPlan(company.value.id, planKey)
    company.value.plan_key = planKey
    toast('Plan updated.', 'success')

    // Refresh modules since entitlements may have changed
    const profileData = await companiesStore.fetchCompanyProfile(route.params.id)

    applyProfile(profileData)
  }
  catch (error) {
    planForm.value.plan_key = company.value.plan_key
    toast(error?.data?.message || 'Failed to update plan.', 'error')
  }
  finally {
    actionLoading.value = false
  }
}

// ─── Suspend / Reactivate ───────────────────────────
const suspend = async () => {
  if (!confirm(`Suspend "${company.value.name}"? All company members will lose access.`))
    return

  actionLoading.value = true

  try {
    const data = await companiesStore.suspendCompany(company.value.id)

    company.value = data.company
    toast('Company suspended.', 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to suspend company.', 'error')
  }
  finally {
    actionLoading.value = false
  }
}

const reactivate = async () => {
  actionLoading.value = true

  try {
    const data = await companiesStore.reactivateCompany(company.value.id)

    company.value = data.company
    toast('Company reactivated.', 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to reactivate company.', 'error')
  }
  finally {
    actionLoading.value = false
  }
}

// ─── Module groups ──────────────────────────────────
const coreModules = computed(() =>
  modules.value.filter(m => m.type === 'core'),
)

const includedModules = computed(() =>
  modules.value.filter(m => m.type !== 'core' && m.entitlement_source === 'jobdomain' && m.is_entitled),
)

const planGatedModules = computed(() =>
  modules.value.filter(m => m.type !== 'core' && m.entitlement_reason === 'plan_required'),
)

const availableModules = computed(() =>
  modules.value.filter(m => {
    if (m.type === 'core') return false
    if (m.entitlement_source === 'jobdomain' && m.is_entitled) return false
    if (m.entitlement_reason === 'plan_required') return false

    return true
  }),
)

const planLabel = planKey => {
  const labels = { pro: 'Pro', business: 'Business' }

  return labels[planKey] || planKey
}

// ─── Module toggle ──────────────────────────────────
const moduleToggleLoading = ref(null)

const toggleModule = async mod => {
  moduleToggleLoading.value = mod.key

  try {
    let data
    if (mod.is_enabled_for_company) {
      data = await companiesStore.disableModule(company.value.id, mod.key)
    }
    else {
      data = await companiesStore.enableModule(company.value.id, mod.key)
    }

    modules.value = data.modules
    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to toggle module.', 'error')
  }
  finally {
    moduleToggleLoading.value = null
  }
}

const isModuleToggleDisabled = mod => {
  return mod.type === 'core' || !mod.is_entitled || !mod.is_enabled_globally
}

// ─── Helpers ────────────────────────────────────────
const formatDate = dateStr => {
  if (!dateStr)
    return '—'

  return new Date(dateStr).toLocaleDateString('fr-FR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}
</script>

<template>
  <div>
    <!-- Loading -->
    <VCard
      v-if="isLoading"
      class="pa-8 text-center"
    >
      <VProgressCircular indeterminate />
    </VCard>

    <template v-else-if="company">
      <!-- Header -->
      <VCard class="mb-4">
        <VCardText class="d-flex align-center gap-4">
          <VBtn
            icon
            variant="text"
            size="small"
            :to="{ name: 'platform-companies' }"
          >
            <VIcon icon="tabler-arrow-left" />
          </VBtn>

          <VAvatar
            size="48"
            color="primary"
            variant="tonal"
          >
            <span class="text-lg">{{ company.name?.charAt(0)?.toUpperCase() }}</span>
          </VAvatar>

          <div>
            <h5 class="text-h5">
              {{ company.name }}
            </h5>
            <div class="d-flex align-center gap-2 mt-1">
              <code class="text-body-2">{{ company.slug }}</code>
              <VChip
                :color="company.status === 'active' ? 'success' : 'error'"
                size="x-small"
              >
                {{ company.status }}
              </VChip>
            </div>
          </div>
        </VCardText>
      </VCard>

      <!-- Tabs -->
      <VTabs v-model="activeTab">
        <VTab value="overview">
          <VIcon
            icon="tabler-info-circle"
            class="me-1"
          />
          Overview
        </VTab>
        <VTab value="modules">
          <VIcon
            icon="tabler-puzzle"
            class="me-1"
          />
          Modules
        </VTab>
      </VTabs>

      <VWindow
        v-model="activeTab"
        class="mt-4"
      >
        <!-- ─── Tab 1: Overview ─────────────────────── -->
        <VWindowItem value="overview">
          <VCard>
            <VCardText>
              <VRow>
                <!-- Company Info -->
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    :model-value="company.name"
                    label="Name"
                    disabled
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    :model-value="company.slug"
                    label="Slug"
                    disabled
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    :model-value="formatDate(company.created_at)"
                    label="Created"
                    disabled
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    :model-value="String(company.memberships_count ?? 0)"
                    label="Members"
                    disabled
                  />
                </VCol>

                <!-- Job Domain -->
                <VCol cols="12">
                  <div class="text-body-1 font-weight-medium mb-2">
                    Job Domain
                  </div>
                  <div
                    v-if="company.jobdomains?.length"
                    class="d-flex flex-wrap gap-2"
                  >
                    <VChip
                      v-for="jd in company.jobdomains"
                      :key="jd.id"
                      color="info"
                      variant="tonal"
                    >
                      {{ jd.label }}
                    </VChip>
                  </div>
                  <span
                    v-else
                    class="text-disabled"
                  >No job domain assigned</span>
                </VCol>

                <!-- Plan -->
                <VCol
                  cols="12"
                  md="6"
                >
                  <VSelect
                    v-model="planForm.plan_key"
                    :items="companiesStore.plans"
                    item-title="name"
                    item-value="key"
                    label="Plan"
                    :loading="actionLoading"
                    @update:model-value="changePlan"
                  />
                </VCol>
              </VRow>
            </VCardText>

            <VDivider />

            <!-- Status Actions -->
            <VCardText>
              <div class="d-flex align-center justify-space-between">
                <div>
                  <div class="text-body-1 font-weight-medium">
                    Company Status
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ company.status === 'active' ? 'This company is active. Suspending will block all member access.' : 'This company is suspended. Members cannot access the platform.' }}
                  </div>
                </div>
                <VBtn
                  v-if="company.status === 'active'"
                  color="warning"
                  variant="tonal"
                  :loading="actionLoading"
                  @click="suspend"
                >
                  Suspend
                </VBtn>
                <VBtn
                  v-else
                  color="success"
                  variant="tonal"
                  :loading="actionLoading"
                  @click="reactivate"
                >
                  Reactivate
                </VBtn>
              </div>
            </VCardText>
          </VCard>
        </VWindowItem>

        <!-- ─── Tab 2: Modules ──────────────────────── -->
        <VWindowItem value="modules">
          <VCard>
            <VCardTitle class="d-flex align-center">
              <VIcon
                icon="tabler-puzzle"
                class="me-2"
              />
              Company Modules
            </VCardTitle>

            <!-- Section: Core -->
            <template v-if="coreModules.length">
              <VCardTitle class="text-body-1 mt-2">
                <VIcon
                  icon="tabler-shield-check"
                  size="20"
                  class="me-2"
                  color="primary"
                />
                Core Modules
              </VCardTitle>
              <VCardSubtitle class="text-body-2 mb-2">
                Always active. Cannot be disabled.
              </VCardSubtitle>
              <VTable class="text-no-wrap">
                <tbody>
                  <tr
                    v-for="mod in coreModules"
                    :key="mod.key"
                  >
                    <td class="font-weight-medium">
                      <RouterLink
                        :to="{ name: 'platform-modules-key', params: { key: mod.key } }"
                        class="text-high-emphasis text-decoration-none"
                      >
                        {{ mod.name }}
                      </RouterLink>
                      <VChip
                        color="primary"
                        size="x-small"
                        variant="tonal"
                        class="ms-2"
                      >
                        Core
                      </VChip>
                    </td>
                    <td class="text-medium-emphasis">
                      {{ mod.description }}
                    </td>
                    <td style="width: 100px;">
                      <VSwitch
                        :model-value="true"
                        density="compact"
                        hide-details
                        disabled
                      />
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </template>

            <!-- Section: Included by Jobdomain -->
            <template v-if="includedModules.length">
              <VDivider class="my-2" />
              <VCardTitle class="text-body-1">
                <VIcon
                  icon="tabler-check"
                  size="20"
                  class="me-2"
                  color="success"
                />
                Included by Job Domain
              </VCardTitle>
              <VCardSubtitle class="text-body-2 mb-2">
                Available through the company's job domain.
              </VCardSubtitle>
              <VTable class="text-no-wrap">
                <tbody>
                  <tr
                    v-for="mod in includedModules"
                    :key="mod.key"
                  >
                    <td class="font-weight-medium">
                      <RouterLink
                        :to="{ name: 'platform-modules-key', params: { key: mod.key } }"
                        class="text-high-emphasis text-decoration-none"
                      >
                        {{ mod.name }}
                      </RouterLink>
                      <VChip
                        color="success"
                        size="x-small"
                        variant="tonal"
                        class="ms-2"
                      >
                        Included
                      </VChip>
                    </td>
                    <td class="text-medium-emphasis">
                      {{ mod.description }}
                    </td>
                    <td style="width: 100px;">
                      <VSwitch
                        :model-value="mod.is_enabled_for_company"
                        density="compact"
                        hide-details
                        :loading="moduleToggleLoading === mod.key"
                        @update:model-value="toggleModule(mod)"
                      />
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </template>

            <!-- Section: Plan-gated -->
            <template v-if="planGatedModules.length">
              <VDivider class="my-2" />
              <VCardTitle class="text-body-1">
                <VIcon
                  icon="tabler-lock"
                  size="20"
                  class="me-2"
                  color="warning"
                />
                Plan-gated Modules
              </VCardTitle>
              <VCardSubtitle class="text-body-2 mb-2">
                Requires a plan upgrade to unlock.
              </VCardSubtitle>
              <VTable class="text-no-wrap">
                <tbody>
                  <tr
                    v-for="mod in planGatedModules"
                    :key="mod.key"
                    class="text-disabled"
                  >
                    <td class="font-weight-medium">
                      <RouterLink
                        :to="{ name: 'platform-modules-key', params: { key: mod.key } }"
                        class="text-decoration-none"
                      >
                        {{ mod.name }}
                      </RouterLink>
                      <VChip
                        color="warning"
                        size="x-small"
                        variant="tonal"
                        class="ms-2"
                      >
                        Requires {{ planLabel(mod.min_plan) }}
                      </VChip>
                    </td>
                    <td>
                      {{ mod.description }}
                    </td>
                    <td style="width: 100px;">
                      <VSwitch
                        :model-value="false"
                        density="compact"
                        hide-details
                        disabled
                      />
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </template>

            <!-- Section: Other / Not available -->
            <template v-if="availableModules.length">
              <VDivider class="my-2" />
              <VCardTitle class="text-body-1">
                <VIcon
                  icon="tabler-puzzle"
                  size="20"
                  class="me-2"
                  color="secondary"
                />
                Other Modules
              </VCardTitle>
              <VCardSubtitle class="text-body-2 mb-2">
                Not currently available for this company.
              </VCardSubtitle>
              <VTable class="text-no-wrap">
                <tbody>
                  <tr
                    v-for="mod in availableModules"
                    :key="mod.key"
                    class="text-disabled"
                  >
                    <td class="font-weight-medium">
                      <RouterLink
                        :to="{ name: 'platform-modules-key', params: { key: mod.key } }"
                        class="text-decoration-none"
                      >
                        {{ mod.name }}
                      </RouterLink>
                      <VChip
                        color="secondary"
                        size="x-small"
                        variant="tonal"
                        class="ms-2"
                      >
                        {{ mod.entitlement_reason === 'incompatible_jobdomain' ? 'Incompatible' : 'Not available' }}
                      </VChip>
                    </td>
                    <td>
                      {{ mod.description }}
                    </td>
                    <td style="width: 100px;">
                      <VSwitch
                        :model-value="false"
                        density="compact"
                        hide-details
                        disabled
                      />
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </template>

            <VCardText
              v-if="!modules.length"
              class="text-center text-disabled"
            >
              No modules available.
            </VCardText>
          </VCard>
        </VWindowItem>
      </VWindow>
    </template>
  </div>
</template>
