<script setup>
// Leezr 5.0 cockpit (ADR-855) — « Activité récente » for platform.recent_activity (view_audit_logs).
// The Platform audit journal without login noise ; a company event opens the company, others open the journal.
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'
import { listRows } from '@/core/surface-engine/familyModes'
import { relativeTime } from './cockpitFormat'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t, te } = useI18n()
const d = computed(() => props.data || {})
const items = computed(() => d.value.items || [])
const DOMAIN = { company: ['tabler-building', 'primary'], company_export: ['tabler-file-zip', 'warning'], subscription: ['tabler-receipt', 'success'], billing: ['tabler-receipt', 'success'], invoice: ['tabler-file-invoice', 'success'], platform_user: ['tabler-user-shield', 'info'], role: ['tabler-shield-lock', 'info'], module: ['tabler-puzzle', 'info'], security: ['tabler-lock', 'error'], two_factor: ['tabler-lock', 'warning'], automation: ['tabler-robot', 'info'], jobdomain: ['tabler-briefcase', 'primary'], organization_preset: ['tabler-template', 'primary'], preset: ['tabler-template', 'primary'] }
const domain = a => DOMAIN[(a || '').split('.')[0]] || ['tabler-point', 'secondary']
const label = a => (te(`cockpit.activity.actions.${a.replaceAll('.', '_')}`) ? t(`cockpit.activity.actions.${a.replaceAll('.', '_')}`) : a.split('.').slice(1).join(' ').replaceAll('_', ' ') || a)
const link = it => (it.target_type === 'company' && it.target_id ? { name: 'platform-companies-id', params: { id: it.target_id } } : { name: 'platform-dashboard-tab', params: { tab: 'activity' } })
</script>

<template>
  <AppWidgetShell
    bare
    :title="t('cockpit.activity.title')"
    :subtitle="t('cockpit.activity.subtitle')"
    icon="tabler-history"
    color="secondary"
    :link="t('cockpit.activity.link')"
    :to="{ name: 'platform-dashboard-tab', params: { tab: 'activity' } }"
    :state="loading && !data ? 'loading' : d.error ? 'error' : !items.length ? 'empty' : 'ready'"
    empty-icon="tabler-history"
    :empty-title="t('cockpit.activity.emptyTitle')"
  >
    <template #default="{ w, h }">
      <div
        class="ca-list"
        :class="{ 'ca-list--cols': w >= 760 }"
      >
        <RouterLink
          v-for="it in items.slice(0, listRows(h, 52, 76) * (w >= 760 ? 2 : 1))"
          :key="it.id"
          :to="link(it)"
          class="ca-row"
        >
          <VAvatar
            :color="it.severity === 'critical' ? 'error' : domain(it.action)[1]"
            variant="tonal"
            size="34"
            rounded="lg"
          >
            <VIcon
              :icon="domain(it.action)[0]"
              size="18"
            />
          </VAvatar>
          <div class="min-w-0 flex-grow-1">
            <div class="text-body-2 text-truncate">
              <strong v-if="it.company_name">{{ it.company_name }}</strong><span v-if="it.company_name"> · </span><span class="ca-action">{{ label(it.action) }}</span>
            </div>
            <div class="text-caption text-medium-emphasis text-truncate">
              {{ it.actor || t('cockpit.activity.system') }} · {{ relativeTime(it.at) }}
            </div>
          </div>
        </RouterLink>
      </div>
    </template>
  </AppWidgetShell>
</template>

<style scoped>
.ca-list { display: grid; gap: .25rem; }
.ca-list--cols { grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 1.25rem; }
.ca-row { display: flex; align-items: center; gap: .75rem; padding: .375rem .5rem; margin-inline: -.5rem; border-radius: 10px; color: inherit; text-decoration: none; transition: background .15s; }
.ca-row:hover { background: rgba(var(--v-theme-on-surface), .04); }
.ca-action { display: inline-block; }
.ca-action::first-letter { text-transform: uppercase; }
</style>
