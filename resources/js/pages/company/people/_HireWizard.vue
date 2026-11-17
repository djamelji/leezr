<script setup>
// ADR-818 — assistant UNIQUE « Embaucher » (sur le moteur d'intégration ADR-796) :
//   1. Personne (champs obligatoires signalés) + POSTE obligatoire (il détermine les exigences) ;
//   2. Exigences du poste (sources expliquées par le serveur, obligatoire / facultatif) ;
//   3. Déclaration de collecte puis MODE : compléter sur place maintenant (même portail, ouvert ici par la RH)
//      ou à distance (lien par e-mail ou à copier, affiché UNE seule fois). Les deux modes alimentent le même dossier.
// Aucun compte, aucun droit, aucun contrat n'est créé ici : la finalisation et l'accès sont des étapes explicites.
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
const mailed = ref(false)
const createdId = ref(null)
const busy = ref(false)

const reset = () => {
  step.value = 1
  onSiteOpened.value = false
  link.value = null
  mailed.value = false
  createdId.value = null
  selected.value = {}
  form.value = { first_name: '', last_name: '', email: '', hire_date: '', candidate_email: '', job_role_id: null, purpose: 'hiring_onboarding', legal_basis: 'contract_preparation', notice_text: t('onboardingFile.defaultNotice'), days: 7 }
}

const loadProposal = async () => {
  const q = form.value.job_role_id ? `?job_role_id=${form.value.job_role_id}` : ''

  proposal.value = await $api(`/workforce/onboarding-files/proposal${q}`)
  selected.value = Object.fromEntries(proposal.value.items.filter(i => i.suggested).map(i => [`${i.kind}:${i.code}`, { required: i.suggested_required }]))
}

const prepare = async () => {
  reset()
  try {
    const res = await $api('/workforce/job-roles')

    // Sans droit rémunération, la liste arrive en objet (clé `compensation_hidden`) : ne garder que les postes.
    jobs.value = Array.isArray(res) ? res : Object.values(res || {}).filter(r => r && typeof r === 'object' && r.id)
  }
  catch { jobs.value = [] }
}
watch(() => props.isDialogVisible, v => { if (v) prepare() })

const toggle = item => {
  const key = `${item.kind}:${item.code}`
  const next = { ...selected.value }

  next[key] ? delete next[key] : (next[key] = { required: item.suggested_required && item.required_allowed })
  selected.value = next
}
const sourceText = s => t(`onboardingFile.source.${s.kind}`, { module: s.module || '' }) + (s.required ? ` · ${t('onboardingFile.required')}` : '')
// ADR-818 : plus de bouton bloqué sans explication — chaque champ manquant est nommé.
const missing = computed(() => step.value !== 1 ? [] : [
  !form.value.first_name && t('onboardingFile.firstName'),
  !form.value.last_name && t('onboardingFile.lastName'),
  !form.value.hire_date && t('onboardingFile.hireDate'),
  !form.value.job_role_id && t('hiring.position'),
].filter(Boolean))
const canNext = computed(() => missing.value.length === 0)
const required = v => !!v || t('validation.required')
const onSiteOpened = ref(false)

const next = async () => {
  if (step.value === 1) await loadProposal()
  step.value++
}

// ADR-796.1 : envoi par e-mail (canal transactionnel ; en DEV, Mailpit local) ou lien à copier (affiché une fois).
const create = async mode => {
  busy.value = true
  try {
    if (!createdId.value) {
      const items = Object.entries(selected.value).map(([k, v]) => ({ kind: k.split(':')[0], code: k.split(':')[1], required: v.required }))
      const created = await $api('/workforce/onboarding-files', { method: 'POST', body: { ...form.value, items, email: form.value.email || null, candidate_email: form.value.candidate_email || form.value.email || null } })

      createdId.value = created.id
    }
    if (mode === 'onsite') {
      // Même portail, ouvert ici : la personne complète son dossier devant la RH (identité vérifiée en personne).
      const res = await $api(`/workforce/onboarding-files/${createdId.value}/on-site`, { method: 'POST' })

      window.open(res.url, '_blank', 'noopener')
      onSiteOpened.value = true
    }
    else if (mode === 'mail') {
      await $api(`/workforce/onboarding-files/${createdId.value}/send`, { method: 'POST', body: { days: form.value.days } })
      mailed.value = true
    }
    else {
      const res = await $api(`/workforce/onboarding-files/${createdId.value}/link`, { method: 'POST', body: { days: form.value.days } })

      link.value = res.url
    }
    emit('created')
  }
  catch (e) { toast(e?.data?.message || e?.data?.error || t('onboardingFile.error'), 'error') }
  finally { busy.value = false }
}
const hasEmail = computed(() => !!(form.value.candidate_email || form.value.email))
// ADR-818 : à distance, une pièce ou information sensible exige un e-mail (code de vérification, ADR-796.1).
// Sur place, l'identité est vérifiée par la RH : le bouton reste disponible. Plus de refus sans explication.
const needsEmail = computed(() => !hasEmail.value && proposal.value.items.some(i => i.sensitive && selected.value[`${i.kind}:${i.code}`]))

const copy = async () => {
  await navigator.clipboard.writeText(link.value)
  toast(t('onboardingFile.copied'), 'success')
}
// ADR-818 : ouverture directe (?new=1) — la boîte est déjà visible au montage.
if (props.isDialogVisible) prepare()
</script>

<template>
  <AppDialog
    :model-value="isDialogVisible"
    :title="t('hiring.wizardTitle')"
    :subtitle="t(`hiring.step${step}`)"
    :max-width="720"
    @update:model-value="v => emit('update:isDialogVisible', v)"
  >
    <!-- Lien généré : affiché une seule fois -->
    <VAlert
      v-if="onSiteOpened"
      type="success"
      variant="tonal"
      data-test="hiring-onsite-opened"
    >
      {{ t('hiring.onSiteOpened') }}
    </VAlert>
    <VAlert
      v-else-if="mailed"
      type="success"
      variant="tonal"
      data-test="onboarding-mailed"
    >
      {{ t('onboardingFile.mailSentLong') }}
    </VAlert>
    <template v-else-if="link">
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
        <VCol cols="12" sm="6"><AppTextField v-model="form.first_name" :label="`${t('onboardingFile.firstName')} *`" :rules="[required]" data-test="onb-first-name" /></VCol>
        <VCol cols="12" sm="6"><AppTextField v-model="form.last_name" :label="`${t('onboardingFile.lastName')} *`" :rules="[required]" data-test="onb-last-name" /></VCol>
        <VCol cols="12" sm="6"><AppTextField v-model="form.email" :label="t('hiring.email')" type="email" :hint="t('hiring.emailHint')" persistent-hint data-test="onb-email" /></VCol>
        <VCol cols="12" sm="6"><AppDateTimePicker v-model="form.hire_date" :label="`${t('onboardingFile.hireDate')} *`" :rules="[required]" data-test="onb-hire-date" /></VCol>
        <VCol cols="12"><AppSelect v-model="form.job_role_id" :items="jobs" item-title="title" item-value="id" :label="`${t('hiring.position')} *`" :rules="[required]" :hint="t('hiring.positionHint')" persistent-hint data-test="onb-position" /></VCol>
      </VRow>
      <p class="text-caption text-medium-emphasis mt-3 mb-0">
        {{ t('hiring.requiredLegend') }}
      </p>
      <VAlert
        v-if="!jobs.length"
        type="warning"
        variant="tonal"
        density="compact"
        class="mt-3"
      >
        {{ t('hiring.noPosition') }}
        <RouterLink to="/company/workforce/organization/positions">{{ t('hiring.createPosition') }}</RouterLink>
      </VAlert>
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
          v-if="selected[`${item.kind}:${item.code}`] && item.required_allowed"
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
      <VAlert
        v-if="needsEmail"
        type="warning"
        variant="tonal"
        density="compact"
        class="mt-2"
        data-test="hiring-needs-email"
      >
        {{ t('hiring.needsEmail') }}
      </VAlert>
    </template>

    <template #actions>
      <span
        v-if="step === 1 && missing.length"
        class="text-caption text-medium-emphasis me-auto"
        data-test="hiring-missing"
      >{{ t('hiring.missing', { fields: missing.join(', ') }) }}</span>
      <VBtn
        variant="text"
        @click="emit('update:isDialogVisible', false)"
      >
        {{ link || mailed || onSiteOpened ? t('common.close') : t('common.cancel') }}
      </VBtn>
      <template v-if="onSiteOpened" />
      <VBtn
        v-else-if="link && !mailed"
        color="primary"
        prepend-icon="tabler-copy"
        @click="copy"
      >
        {{ t('onboardingFile.copy') }}
      </VBtn>
      <VBtn
        v-else-if="mailed"
        color="primary"
        @click="emit('update:isDialogVisible', false)"
      >
        {{ t('common.close') }}
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
      <template v-else>
        <VBtn
          color="primary"
          variant="tonal"
          prepend-icon="tabler-device-desktop"
          :loading="busy"
          :disabled="!form.legal_basis || !form.notice_text"
          data-test="hiring-onsite"
          @click="create('onsite')"
        >
          {{ t('hiring.onSite') }}
        </VBtn>
        <VBtn
          variant="tonal"
          :loading="busy"
          data-test="onb-create"
          :disabled="!form.legal_basis || !form.notice_text || needsEmail"
          @click="create('link')"
        >
          {{ t('onboardingFile.createLink') }}
        </VBtn>
        <VBtn
          color="primary"
          prepend-icon="tabler-mail-forward"
          :loading="busy"
          :disabled="!form.legal_basis || !form.notice_text || !hasEmail"
          data-test="onb-create-mail"
          @click="create('mail')"
        >
          {{ t('onboardingFile.createMail') }}
        </VBtn>
      </template>
    </template>
  </AppDialog>
</template>

<style scoped>
.onb-item { display: flex; align-items: center; gap: 8px; padding-block: 6px; flex-wrap: wrap; }
.onb-item + .onb-item { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.onb-item__main { flex: 1; min-inline-size: 200px; }
</style>
