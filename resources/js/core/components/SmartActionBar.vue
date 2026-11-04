<script setup>
// SMART RESPONSIVE UX 2026 (ADR-694) — command bar à overflow intelligent, pilotée par la LARGEUR
// RÉELLE du conteneur (ResizeObserver), pas par un simple isMobile. Les actions prioritaires restent
// visibles ; les autres basculent automatiquement dans un menu « Actions » / « ··· ».
//
// Contrat d'une action (toutes clés optionnelles sauf id + label) :
//   { id, label, icon, priority, group, permission, anyPermission, visible, disabled,
//     variant, color, danger, to, handler, primary, dataTest }
//
// - permission (string|array) : requiert TOUTES ; anyPermission (array) : au moins UNE. Owner bypass.
// - primary:true désigne L'ACTION PRINCIPALE (cohérente avec l'activité, pas l'ordre d'insertion).
//   À défaut, la plus haute priorité. L'action principale reste toujours visible.
// - danger:true : style error + (le handler reste responsable de la confirmation sensible).
// - Générique : AUCUNE dépendance à Employee/Shift/JobDomain. Les modules fournissent leurs actions.
import { computed, nextTick, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useCan } from '@/composables/useCan'
import { useContainerWidth } from '@/composables/useContainerWidth'

const props = defineProps({
  actions: { type: Array, default: () => [] },
  // Taille des boutons (Vuetify).
  size: { type: String, default: 'default' },
  density: { type: String, default: 'default' },
  // Libellé du déclencheur d'overflow (mobile/étroit).
  overflowLabel: { type: String, default: '' },
  // Espace inter-boutons (doit matcher le gap CSS ci-dessous).
  gap: { type: Number, default: 8 },
})

const emit = defineEmits(['action'])

const { t } = useI18n()
const router = useRouter()
const { can, canAny, isOwner } = useCan()

// ── 1. Filtrage : visibilité métier + permissions ────────────────────────────
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

// ── 2. Action principale (explicite, sinon priorité la plus haute) ───────────
const primary = computed(() => {
  const list = authorized.value
  if (!list.length) return null

  return list.find(a => a.primary) || [...list].sort((x, y) => (y.priority || 0) - (x.priority || 0))[0]
})

// ── 3. Actions secondaires, triées par priorité décroissante (stable) ────────
const rest = computed(() => {
  const p = primary.value

  return authorized.value
    .filter(a => a !== p)
    .map((a, i) => ({ a, i }))
    .sort((u, v) => ((v.a.priority || 0) - (u.a.priority || 0)) || (u.i - v.i))
    .map(x => x.a)
})

// ── 4. Mesure (hidden row) + calcul du nombre visible selon la largeur réelle ─
const barRef = ref(null)
const measureRef = ref(null)
const { width: containerWidth } = useContainerWidth(barRef)

const measured = ref({ primary: 0, rest: [], overflow: 0 })

const measure = async () => {
  await nextTick()
  const el = measureRef.value
  if (!el) return
  const children = Array.from(el.children)
  if (!children.length) return
  // Ordre dans la hidden row : [primary?, ...rest, overflow]
  let idx = 0
  const primaryW = primary.value ? children[idx++].getBoundingClientRect().width : 0
  const restW = rest.value.map(() => children[idx++].getBoundingClientRect().width)
  const overflowW = children[idx] ? children[idx].getBoundingClientRect().width : 0
  measured.value = { primary: primaryW, rest: restW, overflow: overflowW }
}

watch([authorized, () => props.size, () => props.density], measure, { immediate: true, flush: 'post' })

// Nombre d'actions secondaires visibles qui tiennent (le reste → overflow).
const visibleCount = computed(() => {
  const cw = containerWidth.value
  const { primary: pW, rest: rWs, overflow: oW } = measured.value
  const total = rWs.length
  if (!cw || !total) return total // pas encore mesuré : on montre tout (puis recalcul)
  for (let show = total; show >= 0; show--) {
    let w = pW
    for (let i = 0; i < show; i++) w += props.gap + rWs[i]
    if (show < total) w += props.gap + oW
    if (w <= cw) return show
  }

  return 0
})

const visibleRest = computed(() => rest.value.slice(0, visibleCount.value))
const overflowRest = computed(() => rest.value.slice(visibleCount.value))

const overflowTriggerLabel = computed(() => props.overflowLabel || t('common.actions', 'Actions'))

// ── 5. Exécution ─────────────────────────────────────────────────────────────
const run = a => {
  if (a.disabled) return
  emit('action', a.id)
  if (typeof a.handler === 'function') a.handler()
  else if (a.to) router.push(a.to)
}

const btnColor = a => a.danger ? 'error' : (a.color || (a.primary ? 'primary' : undefined))
const btnVariant = a => a.variant || (a.primary ? 'elevated' : 'tonal')
</script>

<template>
  <div
    ref="barRef"
    class="smart-action-bar d-flex align-center justify-end"
    role="toolbar"
    :aria-label="overflowTriggerLabel"
  >
    <!-- Action principale : toujours visible -->
    <VBtn
      v-if="primary"
      :color="btnColor(primary)"
      :variant="btnVariant(primary)"
      :size="size"
      :density="density"
      :prepend-icon="primary.icon"
      :disabled="primary.disabled"
      :data-test="primary.dataTest || `sab-${primary.id}`"
      @click="run(primary)"
    >
      {{ primary.label }}
    </VBtn>

    <!-- Actions secondaires qui tiennent -->
    <VBtn
      v-for="a in visibleRest"
      :key="a.id"
      :color="btnColor(a)"
      :variant="btnVariant(a)"
      :size="size"
      :density="density"
      :prepend-icon="a.icon"
      :disabled="a.disabled"
      :data-test="a.dataTest || `sab-${a.id}`"
      @click="run(a)"
    >
      {{ a.label }}
    </VBtn>

    <!-- Overflow : le reste des actions (menu accessible clavier/lecteur d'écran) -->
    <VBtn
      v-if="overflowRest.length"
      icon
      variant="tonal"
      color="secondary"
      :size="size"
      :aria-label="overflowTriggerLabel"
      data-test="sab-overflow"
    >
      <VIcon icon="tabler-dots-vertical" />
      <VMenu
        activator="parent"
        location="bottom end"
        :aria-label="overflowTriggerLabel"
      >
        <VList
          density="comfortable"
          min-width="220"
          role="menu"
        >
          <VListItem
            v-for="a in overflowRest"
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

    <!-- Rangée de MESURE cachée (largeurs naturelles de tous les boutons) -->
    <div
      ref="measureRef"
      class="smart-action-bar__measure"
      aria-hidden="true"
    >
      <VBtn
        v-if="primary"
        :variant="btnVariant(primary)"
        :size="size"
        :density="density"
        :prepend-icon="primary.icon"
      >
        {{ primary.label }}
      </VBtn>
      <VBtn
        v-for="a in rest"
        :key="`m-${a.id}`"
        :variant="btnVariant(a)"
        :size="size"
        :density="density"
        :prepend-icon="a.icon"
      >
        {{ a.label }}
      </VBtn>
      <VBtn
        icon
        variant="tonal"
        :size="size"
      >
        <VIcon icon="tabler-dots-vertical" />
      </VBtn>
    </div>
  </div>
</template>

<style lang="scss">
.smart-action-bar {
  gap: 8px;
  flex-wrap: nowrap;
  min-inline-size: 0;

  // Rangée de mesure : hors flux, invisible, non interactive.
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
