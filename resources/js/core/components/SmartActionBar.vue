<script setup>
// SMART RESPONSIVE UX 2026 (ADR-695) — command bar SOBRE & adaptative, décisions de DESIGN (pas « max
// de boutons »). Trois modes par action, choisis selon la largeur RÉELLE du conteneur (ResizeObserver),
// la priorité, l'ambiguïté de l'icône et le caractère sensible :
//   • COMPLET  : icône + libellé (quand le libellé aide la compréhension) ;
//   • COMPACT  : icône seule + tooltip desktop + nom accessible (aria-label) — uniquement si l'icône
//                est non ambiguë ET l'action non sensible ;
//   • OVERFLOW : dans le menu « Plus » (toujours icône + libellé explicite).
//
// Contrat d'action : { id, label, icon, priority, group, permission|anyPermission, visible, disabled,
//   variant, color, danger, sensitive, compactable, tooltip, to|handler, primary }
// - primary:true = action principale (cohérente avec l'activité, pas l'ordre d'insertion) ; TOUJOURS visible.
// - compactable (déf. icône présente && !danger && !sensitive) : autorise le mode icône seule.
// - danger/sensitive : JAMAIS en icône seule — libellé conservé (plein ou overflow) ; confirmation = handler.
// Générique : aucune dépendance Employee/Shift/JobDomain. Clavier + lecteurs d'écran.
import { computed, nextTick, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useCan } from '@/composables/useCan'
import { useContainerWidth } from '@/composables/useContainerWidth'

const props = defineProps({
  actions: { type: Array, default: () => [] },
  size: { type: String, default: 'default' },
  // En dessous de cette largeur de conteneur : action principale compacte + tout le reste en overflow.
  compactBelow: { type: Number, default: 400 },
  gap: { type: Number, default: 8 },
})
const emit = defineEmits(['action'])

const { t } = useI18n()
const router = useRouter()
const { can, canAny, isOwner } = useCan()

const compactableOf = a => a.compactable ?? (!!a.icon && !a.danger && !a.sensitive)

const allowed = a => {
  if (a.visible === false) return false
  if (isOwner?.value) return true
  if (a.permission) {
    const keys = Array.isArray(a.permission) ? a.permission : [a.permission]
    if (!keys.every(k => can(k))) return false
  }
  if (a.anyPermission?.length && !canAny(a.anyPermission)) return false

  return true
}
const authorized = computed(() => props.actions.filter(allowed))

const primary = computed(() => {
  const list = authorized.value
  if (!list.length) return null

  return list.find(a => a.primary) || [...list].sort((x, y) => (y.priority || 0) - (x.priority || 0))[0]
})
const rest = computed(() => {
  const p = primary.value

  return authorized.value
    .filter(a => a !== p)
    .map((a, i) => ({ a, i }))
    .sort((u, v) => ((v.a.priority || 0) - (u.a.priority || 0)) || (u.i - v.i))
    .map(x => x.a)
})

// ── Mesure (rangée cachée : plein + compact de chaque action) ────────────────
const barRef = ref(null)
const measureRef = ref(null)
const { width: containerWidth } = useContainerWidth(barRef)
const measured = ref({ primaryFull: 0, primaryIcon: 0, restFull: [], icon: 0, overflow: 0 })

const measure = async () => {
  await nextTick()
  const el = measureRef.value
  if (!el) return
  const by = sel => el.querySelector(sel)?.getBoundingClientRect().width || 0
  measured.value = {
    primaryFull: by('[data-m="pf"]'),
    primaryIcon: by('[data-m="pi"]'),
    restFull: rest.value.map((_, i) => by(`[data-m="rf-${i}"]`)),
    icon: by('[data-m="ic"]'),
    overflow: by('[data-m="of"]'),
  }
}
watch([authorized, () => props.size], measure, { immediate: true, flush: 'post' })

// ── Décision de présentation ─────────────────────────────────────────────────
const isNarrow = computed(() => (containerWidth.value || 9999) < props.compactBelow)

const primaryMode = computed(() => {
  if (!primary.value) return null
  if (isNarrow.value && compactableOf(primary.value)) return 'icon'

  return 'full'
})

// Résout le mode de chaque action secondaire : full | compact | overflow.
const layout = computed(() => {
  const cw = containerWidth.value
  const m = measured.value
  const total = rest.value.length
  if (!total) return { shown: [], overflow: [] }

  // Mode mobile/étroit : tout le secondaire dans l'overflow.
  if (isNarrow.value) return { shown: [], overflow: rest.value }

  // Pas encore mesuré : on montre tout (recalcul au prochain tick).
  if (!cw || !m.overflow) return { shown: rest.value.map(a => ({ a, mode: compactableOf(a) ? 'compact' : 'full' })), overflow: [] }

  const primW = primaryMode.value === 'icon' ? m.primaryIcon : m.primaryFull
  let used = primW
  const shown = []
  for (let i = 0; i < total; i++) {
    const a = rest.value[i]
    const mode = compactableOf(a) ? 'compact' : 'full'
    const w = mode === 'compact' ? m.icon : m.restFull[i]
    const reserve = i < total - 1 ? props.gap + m.overflow : 0
    if (used + props.gap + w + reserve <= cw) {
      used += props.gap + w
      shown.push({ a, mode })
    } else break
  }

  return { shown, overflow: rest.value.slice(shown.length) }
})

const overflowLabel = computed(() => t('common.more', 'Plus'))
const run = a => {
  if (a.disabled) return
  emit('action', a.id)
  if (typeof a.handler === 'function') a.handler()
  else if (a.to) router.push(a.to)
}
const colorOf = a => a.danger ? 'error' : (a.color || (a.primary ? 'primary' : undefined))
const variantOf = a => a.variant || (a.primary ? 'elevated' : 'tonal')
</script>

<template>
  <div
    ref="barRef"
    class="smart-action-bar d-flex align-center justify-end"
    role="toolbar"
    :aria-label="overflowLabel"
  >
    <!-- Action principale -->
    <template v-if="primary">
      <VBtn
        v-if="primaryMode === 'full'"
        :color="colorOf(primary)"
        :variant="variantOf(primary)"
        :size="size"
        :prepend-icon="primary.icon"
        :disabled="primary.disabled"
        :data-test="primary.dataTest || `sab-${primary.id}`"
        @click="run(primary)"
      >
        {{ primary.label }}
      </VBtn>
      <VBtn
        v-else
        icon
        :color="colorOf(primary)"
        :variant="variantOf(primary)"
        :size="size"
        :aria-label="primary.label"
        :disabled="primary.disabled"
        :data-test="primary.dataTest || `sab-${primary.id}`"
        @click="run(primary)"
      >
        <VIcon :icon="primary.icon" />
        <VTooltip activator="parent" location="bottom" open-delay="400">{{ primary.tooltip || primary.label }}</VTooltip>
      </VBtn>
    </template>

    <!-- Actions secondaires visibles (compact = icône seule + tooltip ; full = icône + libellé) -->
    <template v-for="s in layout.shown" :key="s.a.id">
      <VBtn
        v-if="s.mode === 'compact'"
        icon
        variant="tonal"
        :color="colorOf(s.a)"
        :size="size"
        :aria-label="s.a.label"
        :disabled="s.a.disabled"
        :data-test="s.a.dataTest || `sab-${s.a.id}`"
        @click="run(s.a)"
      >
        <VIcon :icon="s.a.icon" />
        <VTooltip activator="parent" location="bottom" open-delay="400">{{ s.a.tooltip || s.a.label }}</VTooltip>
      </VBtn>
      <VBtn
        v-else
        :variant="variantOf(s.a)"
        :color="colorOf(s.a)"
        :size="size"
        :prepend-icon="s.a.icon"
        :disabled="s.a.disabled"
        :data-test="s.a.dataTest || `sab-${s.a.id}`"
        @click="run(s.a)"
      >
        {{ s.a.label }}
      </VBtn>
    </template>

    <!-- Overflow « Plus » : icône + libellé explicite (actions sensibles gardent leur libellé) -->
    <VBtn
      v-if="layout.overflow.length"
      icon
      variant="text"
      color="medium-emphasis"
      :size="size"
      :aria-label="overflowLabel"
      data-test="sab-overflow"
    >
      <VIcon icon="tabler-dots-vertical" />
      <VMenu activator="parent" location="bottom end" :aria-label="overflowLabel">
        <VList density="comfortable" min-width="220" role="menu">
          <VListItem
            v-for="a in layout.overflow"
            :key="a.id"
            :prepend-icon="a.icon"
            :disabled="a.disabled"
            :base-color="a.danger ? 'error' : undefined"
            :data-test="a.dataTest || `sab-${a.id}`"
            role="menuitem"
            @click="run(a)"
          >
            <VListItemTitle>{{ a.label }}</VListItemTitle>
          </VListItem>
        </VList>
      </VMenu>
    </VBtn>

    <!-- Rangée de mesure cachée (plein + compact + overflow) -->
    <div ref="measureRef" class="smart-action-bar__measure" aria-hidden="true">
      <VBtn v-if="primary" data-m="pf" :variant="variantOf(primary)" :size="size" :prepend-icon="primary.icon">{{ primary.label }}</VBtn>
      <VBtn v-if="primary" data-m="pi" icon :variant="variantOf(primary)" :size="size"><VIcon :icon="primary.icon" /></VBtn>
      <VBtn v-for="(a, i) in rest" :key="`mf-${a.id}`" :data-m="`rf-${i}`" variant="tonal" :size="size" :prepend-icon="a.icon">{{ a.label }}</VBtn>
      <VBtn data-m="ic" icon variant="tonal" :size="size"><VIcon icon="tabler-circle" /></VBtn>
      <VBtn data-m="of" icon variant="text" :size="size"><VIcon icon="tabler-dots-vertical" /></VBtn>
    </div>
  </div>
</template>

<style lang="scss">
.smart-action-bar {
  gap: 8px;
  flex-wrap: nowrap;
  min-inline-size: 0;
  // Remplit l'espace DISPONIBLE (flex-grow) pour mesurer la largeur réelle du conteneur, pas du
  // contenu — sans largeur explicite (éviterait un débordement en contexte flex).
  flex: 1 1 auto;

  &__measure {
    position: absolute;
    visibility: hidden;
    pointer-events: none;
    inset-block-start: -9999px;
    inset-inline-start: -9999px;
    display: flex;
    gap: 8px;
    white-space: nowrap;
  }
}
</style>
