<script setup>
// ADR-857 (Leezr 5.0 L3) — Company workspace hero (V22) : the L0 AppPageHero « workspace » band, fed with REAL
// context only. The host builds the situation (workspaceSituation.js) and the actions (server-filtered home actions
// and priorities) ; this component only presents them. No action is invented here : an empty list shows none.
import AppPageHero from '@/components/app/AppPageHero.vue'
import { displayLocale } from '@/utils/datetime'

const props = defineProps({
  name: { type: String, default: '' },
  greeting: { type: String, default: '' }, // overrides the time-of-day greeting (e.g. « Bonne tournée, »)
  company: { type: String, default: '' },
  sentences: { type: Array, default: () => [] }, // translated sentences, in order
  loading: { type: Boolean, default: false },
  live: { type: Number, default: null }, // people currently at work (clock), null = unknown
  progress: { type: Object, default: null }, // { value, label }
  actions: { type: Array, default: () => [] }, // [{ key, label, icon, to, test? }] — first one is primary, max 2
})

const { t } = useI18n()
const hour = new Date().getHours()
const greetingText = computed(() => props.greeting || t(hour >= 18 ? 'l3.hero.evening' : 'l3.hero.morning'))
const today = new Date().toLocaleDateString(displayLocale(), { weekday: 'long', day: 'numeric', month: 'long' })

const chips = computed(() => [
  ...(props.live !== null && props.live > 0 ? [{ live: true, text: t('l3.hero.live', { n: props.live }, props.live) }] : []),
  { icon: 'tabler-calendar', text: today.charAt(0).toUpperCase() + today.slice(1) },
  ...(props.company ? [{ icon: 'tabler-building', text: props.company }] : []),
])

const subtitle = computed(() => (props.loading && !props.sentences.length ? t('l3.hero.loading') : props.sentences.join(' ')))
</script>

<template>
  <AppPageHero
    variant="workspace"
    class="lz-ws-hero"
    data-test="workspace-hero"
    :greeting="greetingText"
    :name="name || company || '…'"
    :subtitle="subtitle"
    :chips="chips"
    :progress="progress ? progress.value : null"
    :progress-label="progress ? progress.label : ''"
  >
    <template
      v-if="actions.length"
      #actions
    >
      <VBtn
        v-for="(a, i) in actions.slice(0, 2)"
        :key="a.key"
        :to="a.to"
        :class="i === 0 ? 'lzr-on-brand' : 'lzr-on-brand-outline'"
        :variant="i === 0 ? 'flat' : 'outlined'"
        :prepend-icon="a.icon"
        :data-test="a.test || `workspace-hero-action-${a.key}`"
      >
        {{ a.label }}
      </VBtn>
    </template>
  </AppPageHero>
</template>
