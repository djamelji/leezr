<script setup>
// ADR-834 — step E : the company administrator (Owner). Never a password here : activation link for a new
// account, invitation to accept for an existing one (never attached silently, identity never overwritten).
const { t } = useI18n()

const props = defineProps({
  lookup: { type: Object, default: null },
  checking: { type: Boolean, default: false },
  errors: { type: Object, default: () => ({}) },
})

const firstName = defineModel('firstName', { type: String })
const lastName = defineModel('lastName', { type: String })
const email = defineModel('email', { type: String })

const existingName = computed(() => [props.lookup?.first_name, props.lookup?.last_name].filter(Boolean).join(' '))
</script>

<template>
  <h4 class="text-h4 mb-1">
    {{ t('platformCompanyCreation.administrator.heading') }}
  </h4>
  <p class="text-body-1 mb-6">
    {{ t('platformCompanyCreation.administrator.description') }}
  </p>

  <VRow>
    <VCol
      cols="12"
      sm="6"
    >
      <AppTextField
        v-model="firstName"
        :label="t('platformCompanyCreation.administrator.firstName')"
        autocomplete="off"
        :error-messages="errors['administrator.first_name'] || []"
      />
    </VCol>
    <VCol
      cols="12"
      sm="6"
    >
      <AppTextField
        v-model="lastName"
        :label="t('platformCompanyCreation.administrator.lastName')"
        autocomplete="off"
        :error-messages="errors['administrator.last_name'] || []"
      />
    </VCol>
    <VCol cols="12">
      <AppTextField
        v-model="email"
        type="email"
        :label="t('platformCompanyCreation.administrator.email')"
        autocomplete="off"
        :loading="checking"
        :error-messages="errors['administrator.email'] || []"
      />
    </VCol>
  </VRow>

  <div
    aria-live="polite"
    class="mt-4"
  >
    <p
      v-if="checking"
      class="text-body-2 text-medium-emphasis"
    >
      {{ t('platformCompanyCreation.administrator.checking') }}
    </p>
    <VAlert
      v-else-if="lookup && !lookup.exists"
      type="info"
      variant="tonal"
    >
      {{ t('platformCompanyCreation.administrator.newAccount') }}
    </VAlert>
    <VAlert
      v-else-if="lookup?.exists"
      type="warning"
      variant="tonal"
    >
      {{ lookup.active ? t('platformCompanyCreation.administrator.existingActive', { name: existingName }) : t('platformCompanyCreation.administrator.existingInactive') }}
      <div
        v-if="lookup.trial_used"
        class="mt-1"
      >
        {{ t('platformCompanyCreation.administrator.trialUsed') }}
      </div>
    </VAlert>
  </div>

  <VChip
    class="mt-4"
    color="primary"
    variant="tonal"
    prepend-icon="tabler-crown"
  >
    {{ t('platformCompanyCreation.administrator.role') }}
  </VChip>
</template>
