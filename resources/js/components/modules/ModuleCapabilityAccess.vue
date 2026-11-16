<script setup>
// ADR-803 — « Qui peut faire quoi » pour UN module : une ligne par capacité (bundle du manifest), une puce
// par rôle. Produit uniquement des changements EXPLICITES (cellule touchée) : une capacité détenue
// partiellement n'est jamais élargie ni réduite sans clic. Les identifiants techniques ne s'affichent qu'en
// mode avancé. Réutilisable ailleurs (Équipe → Rôles). Aucune écriture ici : le parent prévisualise puis applique.
const props = defineProps({
  capabilities: { type: Array, required: true },
  roles: { type: Array, required: true },
  suggestion: { type: Object, default: () => ({ available: false, roles: {} }) },
  readonly: { type: Boolean, default: false },
})
const emit = defineEmits(['update:changes'])
const { t, te } = useI18n()

const advanced = ref(false)
const editableRoles = computed(() => props.roles.filter(r => r.editable))
const ownerRoles = computed(() => props.roles.filter(r => !r.editable))
const desired = ref({}) // `${roleId}:${cap}` → true | false (only touched cells)

const key = (r, c) => `${r.id}:${c.key}`
const initial = (r, c) => r.holds?.[c.key] || 'none'
const isOn = (r, c) => (key(r, c) in desired.value ? desired.value[key(r, c)] : initial(r, c) === 'full')
const suggested = (r, c) => (props.suggestion?.roles?.[r.id] || []).includes(c.key)
const blockedFor = (r, c) => c.administrative_only && !r.is_administrative

const toggle = (r, c) => {
  if (props.readonly || blockedFor(r, c)) return
  const next = !isOn(r, c)
  const k = key(r, c)
  if ((next && initial(r, c) === 'full') || (!next && initial(r, c) === 'none')) delete desired.value[k] // back to initial
  else desired.value[k] = next
  desired.value = { ...desired.value }
  emit('update:changes', Object.entries(desired.value).map(([k2, grant]) => {
    const [roleId, ...cap] = k2.split(':')
    return { role_id: Number(roleId), capability: cap.join(':'), grant }
  }))
}

const applySuggestion = () => {
  for (const r of editableRoles.value) for (const c of props.capabilities) {
    if (suggested(r, c) && !isOn(r, c) && !blockedFor(r, c)) toggle(r, c)
  }
}

const { capLabel: label, capHint: hint, moduleName } = useCatalogText() // ADR-806 : dotted catalog keys
// ADR-804 : une capacité sans fonction branchée (`unwired`) n'est montrée qu'en mode avancé, avec la mention.
const shown = computed(() => props.capabilities.filter(c => advanced.value || !c.unwired))
const groups = computed(() => [
  { key: 'collective', title: t('moduleSetup.groups.collective'), items: shown.value.filter(c => !c.sensitive && c.scope === 'collective') },
  { key: 'own', title: t('moduleSetup.groups.own'), items: shown.value.filter(c => !c.sensitive && c.scope !== 'collective') },
  { key: 'sensitive', title: t('moduleSetup.groups.sensitive'), items: shown.value.filter(c => c.sensitive) },
].filter(g => g.items.length))
const reset = () => { desired.value = {}; emit('update:changes', []) }

defineExpose({ reset })
</script>

<template>
  <div data-test="capability-access">
    <div class="d-flex flex-wrap align-center gap-2 mb-4">
      <VBtn
        v-if="suggestion?.available && !readonly"
        variant="tonal"
        size="small"
        prepend-icon="tabler-sparkles"
        data-test="capability-apply-suggestion"
        @click="applySuggestion"
      >
        {{ t('moduleSetup.applySuggestion', { name: suggestion.label || '' }) }}
      </VBtn>
      <span
        v-else-if="!readonly"
        class="text-body-2 text-medium-emphasis"
      >{{ t('moduleSetup.noSuggestion') }}</span>
      <VSpacer />
      <VSwitch
        v-model="advanced"
        density="compact"
        hide-details
        :label="t('moduleSetup.advanced')"
      />
    </div>
    <p
      v-if="ownerRoles.length"
      class="text-body-2 text-medium-emphasis mb-4"
    >
      {{ t('moduleSetup.ownerNote', { list: ownerRoles.map(r => r.name).join(', ') }) }}
    </p>

    <div
      v-for="g in groups"
      :key="g.key"
      class="mb-6"
      :data-test="`capability-group-${g.key}`"
    >
      <h6 class="text-h6 mb-1">
        {{ g.title }}
      </h6>
      <p
        v-if="g.key === 'sensitive'"
        class="text-body-2 text-warning mb-2"
      >
        {{ t('moduleSetup.sensitiveHint') }}
      </p>
      <VCard
        v-for="c in g.items"
        :key="c.key"
        variant="outlined"
        class="mb-2"
      >
        <VCardText>
          <div class="text-body-1 font-weight-medium d-flex align-center flex-wrap gap-2">
            {{ label(c) }}
            <VChip
              v-if="c.administrative_only"
              size="x-small"
              variant="tonal"
              :data-test="`capability-admin-only-${c.key}`"
            >
              {{ t('moduleSetup.adminOnly') }}
            </VChip>
          </div>
          <div
            v-if="hint(c)"
            class="text-body-2 text-medium-emphasis mb-2"
          >
            {{ hint(c) }}
          </div>
          <VAlert
            v-if="c.unwired"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-2"
            :data-test="`capability-unwired-${c.key}`"
          >
            {{ t('moduleSetup.unwired') }}
          </VAlert>
          <!-- ADR-807 : droits d'un autre module non activé, jamais attribués d'ici -->
          <div
            v-if="c.dormant_modules?.length"
            class="text-body-2 text-medium-emphasis mb-2"
            :data-test="`capability-dormant-${c.key}`"
          >
            {{ t('moduleSetup.dormant', { list: c.dormant_modules.map(k => moduleName(k)).join(', ') }) }}
          </div>
          <div
            v-if="advanced"
            class="text-caption text-medium-emphasis mb-2"
          >
            {{ c.key }} · {{ t('moduleSetup.permissionCount', { n: c.permission_count }) }}
            <span
              v-if="c.inert_permissions?.length"
              :data-test="`capability-inert-${c.key}`"
            > · {{ t('moduleSetup.inertPermissions', { n: c.inert_permissions.length, list: c.inert_permissions.join(', ') }) }}</span>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <VChip
              v-for="r in editableRoles"
              :key="r.id"
              :color="isOn(r, c) ? 'primary' : undefined"
              :variant="isOn(r, c) ? 'flat' : 'outlined'"
              :disabled="readonly || blockedFor(r, c)"
              :data-test="`cap-${c.key}-role-${r.id}`"
              size="small"
              @click="toggle(r, c)"
            >
              <VIcon
                v-if="isOn(r, c)"
                icon="tabler-check"
                start
                size="14"
              />
              {{ r.name }}
              <span
                v-if="initial(r, c) === 'partial' && !(key(r, c) in desired)"
                class="ms-1"
              >({{ t('moduleSetup.partial') }})</span>
              <VIcon
                v-if="suggested(r, c) && !isOn(r, c)"
                icon="tabler-sparkles"
                end
                size="14"
              />
            </VChip>
          </div>
        </VCardText>
      </VCard>
    </div>
    <VBtn
      v-if="Object.keys(desired).length && !readonly"
      variant="text"
      size="small"
      @click="reset"
    >
      {{ t('moduleSetup.undo') }}
    </VBtn>
  </div>
</template>
