<script setup>
// ADR-834 — step G : every decision, in business terms, with a way back to each step ; warnings and what will
// remain to complete after creation (created ≠ in service).
const { t } = useI18n()

const props = defineProps({
  form: { type: Object, required: true },
  jobdomain: { type: Object, default: null },
  plan: { type: Object, default: null },
  market: { type: Object, default: null },
  legalStatuses: { type: Array, default: () => [] },
  modules: { type: Array, default: () => [] },
  initialization: { type: Object, default: null },
  outcome: { type: Object, default: null },
  lookup: { type: Object, default: null },
  missingRequiredFields: { type: Array, default: () => [] },
  paidBlocked: { type: Boolean, default: false },
  serverErrors: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['goTo'])

const baseCount = computed(() => props.modules.filter(m => m.class === 'base' && !m.key.startsWith('core.')).length)
const selectedModules = computed(() => props.modules.filter(m => props.form.module_keys.includes(m.key)))
const legalStatus = computed(() => props.legalStatuses.find(l => l.key === props.form.company.legal_status_key))
const confirmable = computed(() => (props.initialization?.presets || []).filter(p => p.confirmable))
const applied = computed(() => confirmable.value.filter(p => props.form.initialization[p.kind]).map(p => t(`platformCompanyCreation.initialization.kinds.${p.kind}`)))
const left = computed(() => confirmable.value.filter(p => !props.form.initialization[p.kind]).map(p => t(`platformCompanyCreation.initialization.kinds.${p.kind}`)))

const warnings = computed(() => {
  const w = []
  if (props.missingRequiredFields.length)
    w.push({ text: t('platformCompanyCreation.company.requiredMissing', { fields: props.missingRequiredFields.join(', ') }), step: 0 })
  if (props.paidBlocked)
    w.push({ text: t('platformCompanyCreation.modules.paidNeedsTrial'), step: 2 })
  if (props.outcome?.status === 'pending_payment')
    w.push({ text: props.outcome.kind === 'trial_used' ? t('platformCompanyCreation.subscription.trialUsed') : t('platformCompanyCreation.subscription.pendingPayment'), step: 3 })
  if (props.lookup?.exists)
    w.push({ text: props.lookup.active ? t('platformCompanyCreation.administrator.existingActive', { name: [props.lookup.first_name, props.lookup.last_name].filter(Boolean).join(' ') }) : t('platformCompanyCreation.administrator.existingInactive'), step: 4 })
  Object.values(props.serverErrors).flat().forEach(text => w.push({ text, step: null }))

  return w
})

const toComplete = computed(() => {
  const items = [t('platformCompanyCreation.onboarding.items.administrator')]
  if (left.value.length)
    items.push(t('platformCompanyCreation.onboarding.pendingDecisions', { items: left.value.join(', ') }))
  if (props.outcome?.status === 'pending_payment')
    items.push(t('platformCompanyCreation.statuses.pending_payment'))

  return items
})

const sections = computed(() => [
  { step: 0, title: t('platformCompanyCreation.summary.company'), lines: [props.form.company.name, [props.market?.name, legalStatus.value?.name].filter(Boolean).join(' · ')] },
  { step: 1, title: t('platformCompanyCreation.summary.activity'), lines: [props.jobdomain?.label] },
  { step: 2, title: t('platformCompanyCreation.summary.modules'), lines: [t('platformCompanyCreation.summary.baseModules', { count: baseCount.value }, baseCount.value), selectedModules.value.length ? selectedModules.value.map(m => m.name).join(', ') : t('platformCompanyCreation.summary.noSelectedModules')] },
  { step: 3, title: t('platformCompanyCreation.summary.subscription'), lines: [[props.plan?.name, props.form.subscription.annual ? t('common.annually') : t('common.monthly')].filter(Boolean).join(' · '), props.outcome ? t(`platformCompanyCreation.statuses.${props.outcome.status}`) : '', props.form.subscription.overrideEnabled ? t('platformCompanyCreation.summary.override', { reason: props.form.subscription.override.reason }) : ''] },
  { step: 4, title: t('platformCompanyCreation.summary.administrator'), lines: [[props.form.administrator.first_name, props.form.administrator.last_name].join(' '), props.form.administrator.email] },
  { step: 5, title: t('platformCompanyCreation.summary.initialization'), lines: [props.initialization ? t(`platformCompanyCreation.initialization.${props.initialization.path}`) : '', applied.value.length ? t('platformCompanyCreation.summary.applied', { items: applied.value.join(', ') }) : '', left.value.length ? t('platformCompanyCreation.summary.leftToCompany', { items: left.value.join(', ') }) : ''] },
].map(s => ({ ...s, lines: s.lines.filter(Boolean) })))
</script>

<template>
  <h4 class="text-h4 mb-1">
    {{ t('platformCompanyCreation.summary.heading') }}
  </h4>
  <p class="text-body-1 mb-6">
    {{ t('platformCompanyCreation.summary.description') }}
  </p>

  <VRow class="card-grid card-grid-sm">
    <VCol
      v-for="s in sections"
      :key="s.step"
      cols="12"
      md="6"
    >
      <VCard
        border
        flat
      >
        <VCardItem>
          <VCardTitle>{{ s.title }}</VCardTitle>
          <template #append>
            <VBtn
              size="small"
              variant="text"
              prepend-icon="tabler-pencil"
              @click="emit('goTo', s.step)"
            >
              {{ t('platformCompanyCreation.summary.edit') }}
            </VBtn>
          </template>
        </VCardItem>
        <VCardText>
          <p
            v-for="(line, i) in s.lines"
            :key="i"
            :class="i === 0 ? 'text-body-1 font-weight-medium mb-1' : 'text-body-2 mb-1'"
          >
            {{ line }}
          </p>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>

  <VAlert
    v-if="warnings.length"
    type="warning"
    variant="tonal"
    class="mt-6"
    :title="t('platformCompanyCreation.summary.warnings')"
  >
    <ul class="ps-4 mb-0">
      <li
        v-for="(w, i) in warnings"
        :key="i"
      >
        {{ w.text }}
        <a
          v-if="w.step !== null"
          href="#"
          class="ms-1"
          @click.prevent="emit('goTo', w.step)"
        >{{ t('platformCompanyCreation.summary.edit') }}</a>
      </li>
    </ul>
  </VAlert>

  <VAlert
    type="info"
    variant="tonal"
    class="mt-4"
    :title="t('platformCompanyCreation.summary.toComplete')"
  >
    <ul class="ps-4 mb-0">
      <li
        v-for="(item, i) in toComplete"
        :key="i"
      >
        {{ item }}
      </li>
    </ul>
  </VAlert>
</template>
