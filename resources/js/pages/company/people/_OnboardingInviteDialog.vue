<script setup>
// ADR-796 (L6) — « Inviter une recrue à compléter son dossier » : personne, poste, éléments proposés
// (sources expliquées par le serveur), déclaration de collecte, puis lien affiché UNE seule fois.
// Aucun compte, aucun droit, aucun contrat n'est créé.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({ isDialogVisible: { type: Boolean, default: false } })
const emit = defineEmits(['update:isDialogVisible', 'created'])
const { t, te } = useI18n()
const itemLabel = i => (i.kind === 'document' && te(`documents.type.${i.code}`) ? t(`documents.type.${i.code}`) : i.label)
const { toast } = useAppToast()

const step = ref(1)
const jobs = ref([])
const proposal = ref({ items: [], purposes: [], legal_bases: [] })
const selected = ref({}) // « kind:code » → { required }
const form = ref({ first_name: '', last_name: '', email: '', hire_date: '', candidate_email: '', job_role_id: null, purpose: 'hiring_onboarding', legal_basis: null, notice_text: '', days: 7 })
const link = ref(null)
const busy = ref(false)

const reset = () => {
  step.value = 1
  link.value = null
  selected.value = {}
  form.value = { first_name: '', last_name: '', email: '', hire_date: '', candidate_email: '', job_role_id: null, purpose: 'hiring_onboarding', legal_basis: null, notice_text: t('onboardingFile.defaultNotice'), days: 7 }
}

const loadProposal = async () => {
  const q = form.value.job_role_id ? `?job_role_id=${form.value.job_role_id}` : ''

  proposal.value = await $api(`/workforce/onboarding-files/proposal${q}`)
  selected.value = Object.fromEntries(proposal.value.items.filter(i => i.suggested).map(i => [`${i.kind}:${i.code}`, { required: i.suggested_required }]))
}

watch(() => props.isDialogVisible, async v => {
  if (!v) return
  reset()
  try {
    const res = await $api('/workforce/job-roles')

    jobs.value = Array.isArray(res) ? res : (res.data || res.job_roles || [])
  }
  catch { jobs.value = [] }
})

const toggle = item => {
  const key = `${item.kind}:${item.code}`
  const next = { ...selected.value }

  next[key] ? delete next[key] : (next[key] = { required: item.suggested_required })
  selected.value = next
}
const sourceText = s => t(`onboardingFile.source.${s.kind}`, { module: s.module || '' }) + (s.required ? ` · ${t('onboardingFile.required')}` : '')
const canNext = computed(() => step.value !== 1 || (form.value.first_name && form.value.last_name && form.value.hire_date))

const next = async () => {
  if (step.value === 1) await loadProposal()
  step.value++
}

const create = async () => {
  busy.value = true
  try {
    const items = Object.entries(selected.value).map(([k, v]) => ({ kind: k.split(':')[0], code: k.split(':')[1], required: v.required }))
    const created = await $api('/workforce/onboarding-files', { method: 'POST', body: { ...form.value, items, email: form.value.email || null, candidate_email: form.value.candidate_email || form.value.email || null } })
    const res = await $api(`/workforce/onboarding-files/${created.id}/link`, { method: 'POST', body: { days: form.value.days } })

    link.value = res.url
    emit('created')
  }
  catch (e) { toast(e?.data?.message || t('onboardingFile.error'), 'error') }
  finally { busy.value = false }
}

const copy = async () => {
  await navigator.clipboard.writeText(link.value)
  toast(t('onboardingFile.copied'), 'success')
}
</script>

<template>
  <AppDialog
    :model-value="isDialogVisible"
    :title="t('onboardingFile.inviteTitle')"
    :subtitle="t('onboardingFile.inviteSubtitle')"
    :max-width="720"
    @update:model-value="v => emit('update:isDialogVisible', v)"
  >
    <!-- Lien généré : affiché une seule fois -->
    <template v-if="link">
      <VAlert
        type="success"
        variant="tonal"
        class="mb-4"
      >
        {{ t('onboardingFile.linkReady') }}
      </VAlert>
      <AppTextField
        :model-value="link"
        readonly
        :label="t('onboardingFile.link')"
        data-test="onboarding-link"
      />
      <p class="text-body-2 text-medium-emphasis mt-3 mb-0">
        {{ t('onboardingFile.linkHint') }}
      </p>
    </template>

    <template v-else-if="step === 1">
      <VRow>
        <VCol cols="12" sm="6"><AppTextField v-model="form.first_name" :label="t('onboardingFile.firstName')" data-test="onb-first-name" /></VCol>
        <VCol cols="12" sm="6"><AppTextField v-model="form.last_name" :label="t('onboardingFile.lastName')" data-test="onb-last-name" /></VCol>
        <VCol cols="12" sm="6"><AppTextField v-model="form.email" :label="t('onboardingFile.email')" type="email" /></VCol>
        <VCol cols="12" sm="6"><AppDateTimePicker v-model="form.hire_date" :label="t('onboardingFile.hireDate')" data-test="onb-hire-date" /></VCol>
        <VCol cols="12"><AppSelect v-model="form.job_role_id" :items="jobs" item-title="title" item-value="id" clearable :label="t('onboardingFile.position')" /></VCol>
      </VRow>
    </template>

    <template v-else-if="step === 2">
      <p class="text-body-2 text-medium-emphasis">
        {{ t('onboardingFile.itemsHint') }}
      </p>
      <div
        v-for="item in proposal.items"
        :key="item.kind + item.code"
        class="onb-item"
        :data-test="`onb-item-${item.kind}-${item.code}`"
        :data-selected="!!selected[`${item.kind}:${item.code}`]"
      >
        <VCheckbox
          :model-value="!!selected[`${item.kind}:${item.code}`]"
          hide-details
          density="compact"
          :aria-label="itemLabel(item)"
          @update:model-value="toggle(item)"
        />
        <div class="onb-item__main">
          <div class="text-body-1">
            {{ itemLabel(item) }}<span
              v-if="item.sensitive"
              class="text-body-2 text-medium-emphasis"
            > · {{ t('onboardingFile.sensitive') }}</span>
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ item.kind === 'document' ? t('onboardingFile.document') : t('onboardingFile.field') }}<template v-if="item.sources.length">
              · {{ item.sources.map(sourceText).join(' · ') }}
            </template><template v-if="item.divergence">
              · <span class="text-warning">{{ t(`onboardingFile.divergence.${item.divergence}`) }}</span>
            </template>
          </div>
        </div>
        <VSwitch
          v-if="selected[`${item.kind}:${item.code}`]"
          v-model="selected[`${item.kind}:${item.code}`].required"
          hide-details
          density="compact"
          :label="t('onboardingFile.required')"
        />
      </div>
    </template>

    <template v-else>
      <VRow>
        <VCol cols="12" sm="6"><AppSelect v-model="form.purpose" :items="proposal.purposes.map(p => ({ value: p, title: t(`onboardingFile.purpose.${p}`) }))" :label="t('onboardingFile.purposeLabel')" /></VCol>
        <VCol cols="12" sm="6"><AppSelect v-model="form.legal_basis" :items="proposal.legal_bases.map(b => ({ value: b, title: t(`onboardingPortal.basis.${b}`) }))" :label="t('onboardingFile.legalBasis')" data-test="onb-legal-basis" /></VCol>
        <VCol cols="12"><AppTextarea v-model="form.notice_text" rows="3" :label="t('onboardingFile.notice')" /></VCol>
        <VCol cols="12" sm="8"><AppTextField v-model="form.candidate_email" type="email" :label="t('onboardingFile.candidateEmail')" :hint="t('onboardingFile.candidateEmailHint')" persistent-hint /></VCol>
        <VCol cols="12" sm="4"><AppTextField v-model.number="form.days" type="number" min="1" max="30" :label="t('onboardingFile.days')" /></VCol>
      </VRow>
      <VAlert
        type="info"
        variant="tonal"
        density="compact"
        class="mt-2"
      >
        {{ t('onboardingFile.declarationNotice') }}
      </VAlert>
    </template>

    <template #actions>
      <VBtn
        variant="text"
        @click="emit('update:isDialogVisible', false)"
      >
        {{ link ? t('common.close') : t('common.cancel') }}
      </VBtn>
      <VBtn
        v-if="link"
        color="primary"
        prepend-icon="tabler-copy"
        @click="copy"
      >
        {{ t('onboardingFile.copy') }}
      </VBtn>
      <VBtn
        v-else-if="step < 3"
        color="primary"
        :disabled="!canNext || (step === 2 && !Object.keys(selected).length)"
        data-test="onb-next"
        @click="next"
      >
        {{ t('onboardingFile.next') }}
      </VBtn>
      <VBtn
        v-else
        color="primary"
        :loading="busy"
        :disabled="!form.legal_basis || !form.notice_text"
        data-test="onb-create"
        @click="create"
      >
        {{ t('onboardingFile.createLink') }}
      </VBtn>
    </template>
  </AppDialog>
</template>

<style scoped>
.onb-item { display: flex; align-items: center; gap: 8px; padding-block: 6px; flex-wrap: wrap; }
.onb-item + .onb-item { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.onb-item__main { flex: 1; min-inline-size: 200px; }
</style>
