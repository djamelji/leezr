<script setup>
// W-CONTRACT-3B (ADR-650) — Paramètres RH → Contrats de travail.
// AGRÉGATEUR : identité documentaire employeur (données Company, aucune ressaisie — PP2),
// modèles par défaut par type (gérés dans la bibliothèque), rappel en-tête/pied + non-rétroactivité.
// Visible UNIQUEMENT quand le module workforce_contract est actif (gated côté backend).
import { useContractStore } from '@/modules/company/workforce/contract.store'
import { useCan } from '@/composables/useCan'

const props = defineProps({
  employerIdentity: { type: Object, default: () => ({}) },
})

const { t } = useI18n()
const router = useRouter()
const { can } = useCan()
const store = useContractStore()

const loading = ref(true)

onMounted(async () => {
  try { await store.fetchTemplates() }
  finally { loading.value = false }
})

// Modèles par défaut publiés, un par type de contrat (is_default + published).
const defaults = computed(() =>
  (store.templates || [])
    .filter(tpl => tpl.is_default && tpl.status === 'published')
    .map(tpl => ({ type: (tpl.contract_type || '—').toUpperCase(), name: tpl.name })),
)

const identityRows = computed(() => {
  const e = props.employerIdentity || {}

  return [
    { label: t('contractSettings.identity.name'), value: e.name },
    { label: t('contractSettings.identity.legalStatus'), value: e.legal_status },
    { label: t('contractSettings.identity.siret'), value: e.siret },
    { label: t('contractSettings.identity.naf'), value: e.naf_code },
    { label: t('contractSettings.identity.address'), value: e.address },
    { label: t('contractSettings.identity.city'), value: [e.city, e.country_code].filter(Boolean).join(' · ') },
  ].filter(r => r.value)
})

const go = p => router.push(p)
</script>

<template>
  <div>
    <p class="text-body-2 text-medium-emphasis mb-4">
      {{ t('contractSettings.intro') }}
    </p>

    <!-- Identité documentaire employeur (données Company, lecture seule ici) -->
    <VCard
      variant="outlined"
      class="mb-6"
    >
      <VCardItem>
        <template #prepend>
          <VIcon
            icon="tabler-building"
            color="primary"
          />
        </template>
        <VCardTitle class="text-subtitle-1">
          {{ t('contractSettings.identity.title') }}
        </VCardTitle>
        <VCardSubtitle>{{ t('contractSettings.identity.hint') }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VAlert
          v-if="!identityRows.length"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-0"
        >
          {{ t('contractSettings.identity.empty') }}
        </VAlert>
        <VRow v-else>
          <VCol
            v-for="row in identityRows"
            :key="row.label"
            cols="12"
            sm="6"
          >
            <div class="text-caption text-medium-emphasis">
              {{ row.label }}
            </div>
            <div class="text-body-1 font-weight-medium">
              {{ row.value }}
            </div>
          </VCol>
        </VRow>
        <VBtn
          variant="tonal"
          size="small"
          prepend-icon="tabler-pencil"
          class="mt-4"
          :disabled="!can('settings.company_management')"
          @click="go('/company/profile/overview')"
        >
          {{ t('contractSettings.identity.edit') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Modèles par défaut par type de contrat -->
    <VCard
      variant="outlined"
      class="mb-6"
    >
      <VCardItem>
        <template #prepend>
          <VIcon
            icon="tabler-star"
            color="primary"
          />
        </template>
        <VCardTitle class="text-subtitle-1">
          {{ t('contractSettings.defaults.title') }}
        </VCardTitle>
        <VCardSubtitle>{{ t('contractSettings.defaults.hint') }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VProgressLinear
          v-if="loading"
          indeterminate
          color="primary"
        />
        <template v-else>
          <VAlert
            v-if="!defaults.length"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-0"
          >
            {{ t('contractSettings.defaults.empty') }}
          </VAlert>
          <VList
            v-else
            lines="one"
            class="py-0"
          >
            <VListItem
              v-for="d in defaults"
              :key="d.type"
            >
              <template #prepend>
                <VChip
                  size="small"
                  color="primary"
                  label
                  class="me-3"
                >
                  {{ d.type }}
                </VChip>
              </template>
              <VListItemTitle>{{ d.name }}</VListItemTitle>
              <template #append>
                <VIcon
                  icon="tabler-star-filled"
                  color="warning"
                  size="18"
                />
              </template>
            </VListItem>
          </VList>
        </template>
        <VBtn
          variant="tonal"
          size="small"
          prepend-icon="tabler-files"
          class="mt-4"
          @click="go('/company/workforce/contract/templates')"
        >
          {{ t('contractSettings.defaults.manage') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Rappels : en-tête/pied visibles à l'aperçu PDF + non-rétroactivité -->
    <VAlert
      type="info"
      variant="tonal"
      density="compact"
    >
      {{ t('contractSettings.notice') }}
    </VAlert>
  </div>
</template>
