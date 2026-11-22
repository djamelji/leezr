<script setup>
// Leezr 5.0 cockpit (ADR-855) — « Essais » for platform.trials_ending (view_companies). Soonest end first ; an
// expired trial still marked « trialing » is a real situation to handle and is shown first, in red.
// Rows follow the real height (list family profile) ; each row opens the company.
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'
import { listRows } from '@/core/surface-engine/familyModes'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const items = computed(() => d.value.items || [])
const tone = n => (n < 0 ? 'error' : n <= 3 ? 'warning' : 'info')
const when = n => (n < 0 ? t('cockpit.trials.expired', { n: -n }) : n === 0 ? t('cockpit.trials.today') : t('cockpit.trials.inDays', { n }))
const initials = s => (s || '?').split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase()
</script>

<template>
  <AppWidgetShell
    bare
    :title="t('cockpit.trials.title')"
    :subtitle="t('cockpit.trials.subtitle', { total: d.total ?? 0, soon: d.ending_7d ?? 0 })"
    icon="tabler-hourglass-high"
    color="warning"
    :link="t('cockpit.trials.link')"
    :to="{ name: 'platform-companies' }"
    :state="loading && !data ? 'loading' : d.error ? 'error' : !items.length ? 'empty' : 'ready'"
    empty-icon="tabler-hourglass-empty"
    :empty-title="t('cockpit.trials.emptyTitle')"
  >
    <template #default="{ h }">
      <TransitionGroup
        name="lzr-list"
        tag="div"
        class="ct-list"
      >
        <RouterLink
          v-for="it in items.slice(0, listRows(h, 52, 76))"
          :key="it.company_id"
          :to="{ name: 'platform-companies-id', params: { id: it.company_id } }"
          class="ct-row"
        >
          <VAvatar
            color="primary"
            variant="tonal"
            size="34"
          >
            <span class="text-caption font-weight-bold">{{ initials(it.name) }}</span>
          </VAvatar>
          <div class="min-w-0 flex-grow-1">
            <div class="text-body-2 font-weight-medium text-truncate">
              {{ it.name }}
            </div>
            <div class="text-caption text-medium-emphasis text-capitalize">
              {{ it.plan_key }}
            </div>
          </div>
          <VChip
            size="small"
            :color="tone(it.days_left)"
            variant="tonal"
            label
            class="flex-shrink-0"
          >
            {{ when(it.days_left) }}
          </VChip>
        </RouterLink>
      </TransitionGroup>
      <div
        v-if="items.length > listRows(h, 52, 76)"
        class="text-caption text-medium-emphasis mt-1"
      >
        {{ t('cockpit.more', { n: items.length - listRows(h, 52, 76) }) }}
      </div>
    </template>
  </AppWidgetShell>
</template>

<style scoped>
.ct-list { display: flex; flex-direction: column; gap: .25rem; }
.ct-row { display: flex; align-items: center; gap: .75rem; padding: .375rem .5rem; margin-inline: -.5rem; border-radius: 10px; color: inherit; text-decoration: none; transition: background .15s; }
.ct-row:hover { background: rgba(var(--v-theme-on-surface), .04); }
</style>
