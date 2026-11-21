<script setup>
import AppAccessState from '@/components/app/AppAccessState.vue'
import { useCatalogText } from '@/composables/useCatalogText'
import { useAuthStore } from '@/core/stores/auth'
import { useModuleStore } from '@/core/stores/module'
import { MODULE_STATE_ACTION, moduleAvailabilityState, moduleDependencies } from '@/core/modules/moduleAvailability'

/**
 * ADR-839 (Leezr 4.2 M3, audit UXC-02 / UXC-05 / FUN-03) — explicit page instead of the silent redirect to the
 * dashboard when an address belongs to a module the company cannot use. Three situations, read from the module
 * catalogue already loaded at boot (no new engine, no implicit activation, no billing shortcut) :
 *  B — module available but not activated → « not activated » + its dependencies + « Activate in Modules » (modules.manage only)
 *  C — module not in the plan / paid option → « available with … » + « See the plans » (billing.manage) or « See the options » (modules.manage)
 *  — module unknown for this company → « not available for your company », no action.
 */

definePage({})

const { t, te } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const modules = useModuleStore()
const { moduleName } = useCatalogText()

const key = computed(() => String(route.query.module || ''))
const mod = computed(() => modules.modules.find(m => m.key === key.value) || null)
const name = computed(() => moduleName(key.value, mod.value?.name || t('moduleUnavailable.thisFeature')))
const canManageModules = computed(() => auth.hasPermission(MODULE_STATE_ACTION.inactive))
const canManageBilling = computed(() => auth.hasPermission(MODULE_STATE_ACTION.plan))
const planLabel = k => (te(`moduleUnavailable.plans.${k}`) ? t(`moduleUnavailable.plans.${k}`) : (k ? k.charAt(0).toUpperCase() + k.slice(1) : ''))
const askAdmin = t('moduleUnavailable.askAdmin')

const state = computed(() => moduleAvailabilityState(mod.value))

const view = computed(() => {
  const m = mod.value
  switch (state.value) {
  case 'inactive':
    return {
      icon: 'tabler-plug-off', color: 'info',
      title: t('moduleUnavailable.inactive.title', { name: name.value }),
      message: t('moduleUnavailable.inactive.message'),
      details: moduleDependencies(m, modules.isActive).map(d => ({ label: moduleName(d.key, d.key), ok: d.ok })),
      detailsTitle: (m.requires || []).length ? t('moduleUnavailable.inactive.requires') : '',
      hint: canManageModules.value ? t('moduleUnavailable.inactive.adminHint') : askAdmin,
      actions: canManageModules.value ? [{ label: t('moduleUnavailable.inactive.action'), to: { path: '/company/modules', query: { module: m.key } }, testid: 'module-unavailable-activate' }] : [],
    }
  case 'plan':
    return {
      icon: 'tabler-sparkles', color: 'primary',
      title: t('moduleUnavailable.plan.title', { name: name.value }),
      message: m.upgrade_target_plan ? t('moduleUnavailable.plan.message', { plan: planLabel(m.upgrade_target_plan) }) : t('moduleUnavailable.plan.messageGeneric'),
      hint: canManageBilling.value ? t('moduleUnavailable.plan.adminHint') : askAdmin,
      actions: canManageBilling.value ? [{ label: t('moduleUnavailable.plan.action'), to: { name: 'company-billing-tab', params: { tab: 'plan' } }, testid: 'module-unavailable-plans' }] : [],
    }
  case 'option':
    return {
      icon: 'tabler-puzzle', color: 'primary',
      title: t('moduleUnavailable.option.title', { name: name.value }),
      message: t(m.display_state === 'contact_sales' ? 'moduleUnavailable.option.contactSales' : 'moduleUnavailable.option.message'),
      hint: canManageModules.value ? '' : askAdmin,
      actions: canManageModules.value ? [{ label: t('moduleUnavailable.option.action'), to: { path: '/company/modules', query: { module: m.key } }, testid: 'module-unavailable-options' }] : [],
    }
  case 'active':
    return {
      icon: 'tabler-circle-check', color: 'success',
      title: t('moduleUnavailable.active.title', { name: name.value }),
      message: t('moduleUnavailable.active.message'),
      actions: route.query.from ? [{ label: t('moduleUnavailable.active.action'), to: String(route.query.from), testid: 'module-unavailable-open' }] : [],
    }
  default:
    return {
      icon: 'tabler-ban', color: 'secondary',
      title: t('moduleUnavailable.unavailable.title'),
      message: t('moduleUnavailable.unavailable.message'),
    }
  }
})
</script>

<template>
  <AppAccessState
    :icon="view.icon"
    :color="view.color"
    :title="view.title"
    :message="view.message"
    :hint="view.hint"
    :details="view.details || []"
    :details-title="view.detailsTitle"
    :actions="[...(view.actions || []), { label: t('accessState.home'), to: auth.workspace === 'home' ? '/home' : '/dashboard', variant: 'text', testid: 'access-state-home' }]"
    :testid="`module-unavailable-${state}`"
  />
</template>
