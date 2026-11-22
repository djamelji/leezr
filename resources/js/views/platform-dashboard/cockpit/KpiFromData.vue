<script setup>
// ADR-856 (Leezr 5.0 L4) — KPI presentation driven by a small spec (label, icon, value extractor, context, breakdown,
// link). Used by the Platform KPI widgets (billing / AI) so each keeps ITS data and wording, with the shared
// AppKpiTile behaviour (size profile, states, link). Not an engine : a presentational adapter.
import AppKpiTile from '@/components/app/AppKpiTile.vue'

const props = defineProps({
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  spec: { type: Object, required: true }, // { label, icon, color(d), value(d), format?, currency(d)?, context(d)?, emptyHint?, breakdown(d)?, to, linkLabel }
})

const d = computed(() => props.data || {})
const call = (f, fallback = null) => (typeof f === 'function' ? f(d.value) : (f ?? fallback))
const empty = computed(() => !props.data || Object.keys(d.value).length === 0)
</script>

<template>
  <AppKpiTile
    bare
    :label="spec.label"
    :icon="spec.icon"
    :color="call(spec.color, 'primary')"
    :value="empty ? null : call(spec.value)"
    :format="spec.format || 'number'"
    :currency="call(spec.currency, 'EUR')"
    :decimals="spec.decimals || 0"
    :context="empty ? '' : call(spec.context, '')"
    :empty-hint="call(spec.emptyHint, '')"
    :breakdown="empty ? [] : call(spec.breakdown, [])"
    :loading="loading && !data"
    :error="!!d.error"
    :to="spec.to"
    :link-label="spec.linkLabel || ''"
  />
</template>
