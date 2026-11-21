<script setup>
const props = defineProps({
  icon: { type: String, default: 'tabler-lock' },
  color: { type: String, default: 'warning' },
  title: { type: String, required: true },
  message: { type: String, required: true },
  hint: { type: String, default: '' },
  details: { type: Array, default: () => [] }, // [{ label, ok }]
  detailsTitle: { type: String, default: '' },
  actions: { type: Array, default: () => [] },
  showBack: { type: Boolean, default: true },
  compact: { type: Boolean, default: false },
  testid: { type: String, default: 'access-state' },
})

// ADR-839 (Leezr 4.2 M3) — one card for every « you cannot use this here » situation, Company and Platform :
// permission missing (A), module not activated (B), module not in the plan (C), record not accessible.
// Pure composition of Vuetify primitives (same as the historical /company/403 card). It shows no data :
// only a plain-language title, a reason, optional details (dependencies…) and the actions the caller allows.
//
// actions : [{ label, to?, onClick?, color?, variant?, testid? }] — the caller passes only actions the user may take.
const { t } = useI18n()
const router = useRouter()

const goBack = () => {
  if (window.history.length > 1) router.back()
  else router.replace('/')
}
</script>

<template>
  <VRow
    :class="compact ? 'my-4' : 'fill-height'"
    align="center"
    justify="center"
  >
    <VCol
      cols="12"
      md="8"
      lg="6"
    >
      <VCard
        class="text-center pa-6"
        :data-testid="props.testid"
      >
        <VAvatar
          :color="props.color"
          variant="tonal"
          size="72"
          class="mb-4"
        >
          <VIcon
            :icon="props.icon"
            size="40"
          />
        </VAvatar>

        <VCardTitle
          class="text-h5 mb-2 text-wrap"
          tag="h1"
        >
          {{ props.title }}
        </VCardTitle>

        <VCardText class="text-body-1 pb-2">
          {{ props.message }}
        </VCardText>

        <VCardText
          v-if="props.details.length"
          class="pt-0"
        >
          <div
            v-if="props.detailsTitle"
            class="text-body-2 text-medium-emphasis mb-2"
          >
            {{ props.detailsTitle }}
          </div>
          <div class="d-flex flex-wrap justify-center gap-2">
            <VChip
              v-for="d in props.details"
              :key="d.label"
              size="small"
              :color="d.ok ? 'success' : 'secondary'"
              :prepend-icon="d.ok ? 'tabler-check' : 'tabler-circle-dashed'"
            >
              {{ d.label }}
            </VChip>
          </div>
        </VCardText>

        <VCardText
          v-if="props.hint"
          class="text-body-2 text-medium-emphasis pt-0"
          :data-testid="`${props.testid}-hint`"
        >
          {{ props.hint }}
        </VCardText>

        <VCardActions class="justify-center flex-wrap gap-2">
          <VBtn
            v-if="props.showBack"
            variant="tonal"
            prepend-icon="tabler-arrow-left"
            :data-testid="`${props.testid}-back`"
            @click="goBack"
          >
            {{ t('accessState.back') }}
          </VBtn>
          <VBtn
            v-for="a in props.actions"
            :key="a.label"
            :color="a.color || 'primary'"
            :variant="a.variant || 'elevated'"
            :to="a.to"
            :data-testid="a.testid"
            @click="a.onClick?.()"
          >
            {{ a.label }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VCol>
  </VRow>
</template>
