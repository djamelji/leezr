<script setup>
// ADR-861 (Leezr 5.0 L7.7) — a page family with many tabs, the way Billing (ADR-842) and Settings (ADR-860) already
// work : the header says where the user is (« Family › Group ») and what the page does ; groups first (pills), then
// the pages of the active group (compact tabs, only when the group holds several). Same addresses, same permissions :
// the caller passes the tabs it already filtered by permission. Also gives each page its own browser-tab title.
import AppPageHeader from '@/components/app/AppPageHeader.vue'

const props = defineProps({
  family: { type: String, required: true },
  /** [{ key, title, icon, items: [{ tab, title, icon }] }] — empty groups are dropped */
  groups: { type: Array, required: true },
  routeName: { type: String, required: true },
  active: { type: String, default: '' },
  /** i18n prefix of the one-sentence hint per page (`${hintPrefix}.${tab}`) */
  hintPrefix: { type: String, default: '' },
  /** or explicit hints { [tab]: sentence } (wins over hintPrefix) */
  hints: { type: Object, default: () => ({}) },
  test: { type: String, default: 'family' },
  /** extra route params kept when switching page (e.g. { id }) */
  params: { type: Object, default: () => ({}) },
  /** the title shown in the header when it is not the page title (e.g. an entity name) */
  title: { type: String, default: '' },
})

const { t, te } = useI18n()
const visibleGroups = computed(() => props.groups.filter(g => g.items?.length))
const activeGroup = computed(() => visibleGroups.value.find(g => g.items.some(x => x.tab === props.active)) || null)
const activePage = computed(() => activeGroup.value?.items.find(x => x.tab === props.active) || null)
const multi = computed(() => visibleGroups.value.length > 1)
const eyebrow = computed(() => [props.family, activeGroup.value && activeGroup.value.items.length > 1 && multi.value ? activeGroup.value.title : null].filter(Boolean).join(' › '))
const hint = computed(() => props.hints[activePage.value?.tab] || (props.hintPrefix && activePage.value && te(`${props.hintPrefix}.${activePage.value.tab}`) ? t(`${props.hintPrefix}.${activePage.value.tab}`) : ''))
const to = tab => ({ name: props.routeName, params: { ...props.params, tab } })

watchEffect(() => {
  const page = activePage.value?.title
  document.title = [props.title, page, props.family, 'Leezr'].filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).join(' · ')
})
</script>

<template>
  <div class="app-tab-family">
    <AppPageHeader
      :eyebrow="title ? family : eyebrow"
      :title="title || activePage?.title || family"
      :description="title ? '' : hint"
      :data-test="`${test}-header`"
    >
      <template
        v-if="$slots.actions"
        #actions
      >
        <slot name="actions" />
      </template>
      <template
        v-if="$slots.tools"
        #tools
      >
        <slot name="tools" />
      </template>
    </AppPageHeader>

    <VTabs
      v-if="multi"
      :model-value="activeGroup?.key"
      class="v-tabs-pill"
      :mandatory="false"
      show-arrows
      :data-test="`${test}-groups`"
    >
      <VTab
        v-for="g in visibleGroups"
        :key="g.key"
        :value="g.key"
        :to="to(g.items[0].tab)"
        :data-test="`${test}-group-${g.key}`"
      >
        <VIcon
          size="20"
          start
          :icon="g.icon"
        />
        {{ g.title }}
      </VTab>
    </VTabs>
    <VTabs
      v-if="activeGroup && activeGroup.items.length > 1"
      :model-value="active"
      :density="multi ? 'compact' : 'default'"
      :class="multi ? 'mt-3' : 'v-tabs-pill'"
      :mandatory="false"
      show-arrows
      :data-test="`${test}-pages`"
    >
      <VTab
        v-for="item in activeGroup.items"
        :key="item.tab"
        :value="item.tab"
        :to="to(item.tab)"
        :data-test="`${test}-page-${item.tab}`"
      >
        <VIcon
          size="18"
          start
          :icon="item.icon"
        />
        {{ item.title }}
      </VTab>
    </VTabs>
    <p
      v-if="title && hint"
      class="text-body-2 text-medium-emphasis mt-4 mb-0"
      :data-test="`${test}-hint`"
    >
      {{ hint }}
    </p>
  </div>
</template>
