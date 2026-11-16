<script setup>
// ADR-803.1 — aperçu EXACT d'un changement d'accès par capacité, partagé par l'assistant de module et par
// Équipe → Rôles (même moteur serveur). Montre +/− droits par rôle, la raison d'un blocage, les capacités
// qu'un retrait AFFECTERAIT (décision explicite exigée) et celles qui se trouvent complétées.
const props = defineProps({
  preview: { type: Object, required: true },
  busy: { type: Boolean, default: false },
  advanced: { type: Boolean, default: false },
})
const emit = defineEmits(['apply'])
const { t, te } = useI18n()
const accepted = ref(false)
const capLabel = c => (te(`permissionCatalog.bundles.${c.key}.label`) ? t(`permissionCatalog.bundles.${c.key}.label`) : c.label)
const canApply = computed(() => !props.preview.blocked && (!props.preview.requires_confirmation || accepted.value))
watch(() => props.preview, () => { accepted.value = false })
</script>

<template>
  <VCard
    variant="tonal"
    class="mt-4"
    data-test="capability-preview"
  >
    <VCardText>
      <div
        v-if="!preview.plan.length"
        class="text-body-2"
      >
        {{ t('moduleSetup.noChange') }}
      </div>
      <div
        v-for="p in preview.plan"
        :key="p.role_id"
        class="mb-3"
      >
        <div>
          <strong>{{ p.role }}</strong> — {{ t('moduleSetup.diff', { adds: p.adds, removes: p.removes }) }}
          <span
            v-if="p.blocked"
            class="text-error"
            :data-test="`capability-blocked-${p.role_id}`"
          > · {{ t(`moduleSetup.blocked.${p.blocked}`) }}</span>
        </div>
        <div
          v-if="p.affected.length"
          class="text-body-2 text-warning"
          data-test="capability-affected"
        >
          {{ t('moduleSetup.affected') }} : {{ p.affected.map(capLabel).join(', ') }}
        </div>
        <div
          v-if="p.also_completes.length"
          class="text-body-2 text-medium-emphasis"
        >
          {{ t('moduleSetup.alsoCompletes') }} : {{ p.also_completes.map(capLabel).join(', ') }}
        </div>
        <div
          v-if="advanced && (p.add_keys.length || p.remove_keys.length)"
          class="text-caption text-medium-emphasis"
        >
          <span v-if="p.add_keys.length">+ {{ p.add_keys.join(', ') }}</span>
          <span v-if="p.remove_keys.length"> − {{ p.remove_keys.join(', ') }}</span>
        </div>
      </div>
      <VCheckbox
        v-if="preview.requires_confirmation && !preview.blocked"
        v-model="accepted"
        density="compact"
        :label="t('moduleSetup.acceptAffected')"
        data-test="capability-accept-affected"
      />
      <VBtn
        class="mt-2"
        color="primary"
        :disabled="!canApply"
        :loading="busy"
        data-test="module-setup-apply"
        @click="emit('apply', preview.requires_confirmation && accepted)"
      >
        {{ t('moduleSetup.apply') }}
      </VBtn>
    </VCardText>
  </VCard>
</template>
