<script setup>
// ADR-796 (L6) — dossier d'intégration d'une recrue, SANS compte Leezr. Le jeton du lien est dans le
// fragment d'URL (#t=…) : jamais envoyé à un serveur ni dans un Referer. Il est échangé une seule fois
// (corps de requête) contre un cookie HttpOnly, puis retiré de la barre d'adresse. Client HTTP dédié :
// aucun contexte Company, aucune redirection de connexion. Tout statut vient du serveur.
import { ofetch } from 'ofetch'
import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'
import { getXsrfToken, refreshCsrf } from '@/utils/csrf'

definePage({ meta: { layout: 'blank', public: true } })

const { t, te } = useI18n()
const docLabel = d => (te(`documents.type.${d.code}`) ? t(`documents.type.${d.code}`) : d.label)
// Protection CSRF conservée (mêmes utilitaires que l'application) : cookie XSRF puis en-tête sur chaque écriture.
const api = ofetch.create({
  baseURL: '/api/public/onboarding',
  credentials: 'include',
  headers: { Accept: 'application/json' },
  onRequest({ options }) {
    const token = getXsrfToken()
    if (!token)
      return
    if (options.headers instanceof Headers)
      options.headers.set('X-XSRF-TOKEN', token)
    else
      options.headers = new Headers({ ...(options.headers || {}), 'X-XSRF-TOKEN': token })
  },
})

const file = ref(null)
const state = ref('loading') // loading | ready | invalid
const form = ref({})
const saving = ref(false)
const message = ref(null)
const code = ref('')
const codeSent = ref(false)
const uploading = ref(null)

const apply = data => {
  file.value = data
  form.value = Object.fromEntries(data.fields.filter(f => !f.locked).map(f => [f.code, f.value]))
  state.value = 'ready'
}

const errorText = e => {
  const errors = e?.data?.errors

  return errors ? Object.values(errors).flat()[0] : (e?.data?.message || t('onboardingPortal.error'))
}

onMounted(async () => {
  const token = new URLSearchParams(window.location.hash.slice(1)).get('t')
  try {
    await refreshCsrf()
    if (token) {
      history.replaceState(null, '', window.location.pathname) // le jeton quitte immédiatement l'URL
      apply(await api('/session', { method: 'POST', body: { token } }))
    }
    else {
      apply(await api(''))
    }
  }
  catch { state.value = 'invalid' }
})

const editableFields = computed(() => (file.value?.fields || []).filter(f => !f.locked).map(f => ({ ...f, required: f.required && file.value.writable })))
const lockedCount = computed(() => (file.value?.fields || []).filter(f => f.locked).length + (file.value?.documents || []).filter(d => d.locked).length)
const progress = computed(() => (file.value ? Math.round(100 * file.value.progress.done / Math.max(1, file.value.progress.total)) : 0))
const labelOf = code => {
  const d = (file.value?.documents || []).find(i => i.code === code)

  return d ? docLabel(d) : ((file.value?.fields || []).find(i => i.code === code)?.label || '')
}

const changed = computed(() => Object.fromEntries(Object.entries(form.value).filter(([c, v]) => (v ?? '') !== (file.value.fields.find(f => f.code === c)?.value ?? ''))))

const save = async () => {
  if (!Object.keys(changed.value).length)
    return
  saving.value = true
  message.value = null
  try { apply(await api('/values', { method: 'PUT', body: { values: changed.value } })); message.value = { type: 'success', text: t('onboardingPortal.saved') } }
  catch (e) { message.value = { type: 'error', text: errorText(e) } }
  finally { saving.value = false }
}

const upload = async (doc, files) => {
  const list = Array.isArray(files) ? files : (files ? [files] : [])
  if (!list.length)
    return
  const body = new FormData()

  list.forEach(f => body.append('files[]', f))
  uploading.value = doc.code
  message.value = null
  try { apply(await api(`/documents/${doc.code}`, { method: 'POST', body })); message.value = { type: 'success', text: t('onboardingPortal.uploaded') } }
  catch (e) { message.value = { type: 'error', text: errorText(e) } }
  finally { uploading.value = null }
}

const sendCode = async () => {
  try { await api('/code', { method: 'POST' }); codeSent.value = true }
  catch (e) { message.value = { type: 'error', text: errorText(e) } }
}

const verify = async () => {
  try { apply(await api('/verify', { method: 'POST', body: { code: code.value } })); code.value = '' }
  catch (e) { message.value = { type: 'error', text: errorText(e) } }
}

const submit = async () => {
  await save()
  try { apply(await api('/submit', { method: 'POST' })) }
  catch (e) { message.value = { type: 'error', text: errorText(e) } }
}
</script>

<template>
  <div class="onb">
    <div
      v-if="state === 'loading'"
      class="onb__center"
    >
      <VProgressCircular indeterminate />
    </div>

    <VCard
      v-else-if="state === 'invalid'"
      class="onb__card"
      data-test="onboarding-invalid"
    >
      <VCardText class="text-center py-10">
        <VIcon
          icon="tabler-link-off"
          size="40"
          class="mb-4 text-medium-emphasis"
        />
        <h1 class="text-h5 mb-2">
          {{ t('onboardingPortal.invalidTitle') }}
        </h1>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ t('onboardingPortal.invalidText') }}
        </p>
      </VCardText>
    </VCard>

    <template v-else>
      <header class="onb__header">
        <div class="text-overline">
          {{ file.company.name }}
        </div>
        <h1 class="text-h4 mb-1">
          {{ t('onboardingPortal.title') }}
        </h1>
        <p class="text-body-1 text-medium-emphasis mb-3">
          {{ t('onboardingPortal.subtitle', { company: file.company.name }) }}
        </p>
        <VProgressLinear
          :model-value="progress"
          height="8"
          rounded
          color="primary"
          :aria-label="t('onboardingPortal.progress', { done: file.progress.done, total: file.progress.total })"
        />
        <div class="text-body-2 text-medium-emphasis mt-1">
          {{ t('onboardingPortal.progress', { done: file.progress.done, total: file.progress.total }) }}
        </div>
      </header>

      <VAlert
        v-if="file.status === 'submitted'"
        type="success"
        variant="tonal"
        class="mb-4"
        data-test="onboarding-submitted"
      >
        {{ t('onboardingPortal.submittedText', { company: file.company.name }) }}
      </VAlert>
      <VAlert
        v-if="file.hr_message"
        type="warning"
        variant="tonal"
        class="mb-4"
        :title="t('onboardingPortal.changesTitle')"
      >
        {{ file.hr_message }}
      </VAlert>
      <VAlert
        v-if="message"
        :type="message.type"
        variant="tonal"
        density="compact"
        class="mb-4"
        role="status"
      >
        {{ message.text }}
      </VAlert>

      <!-- Information fournie à la personne (déclarée par l'entreprise) -->
      <VCard class="onb__card mb-4">
        <VCardText>
          <h2 class="text-h6 mb-2">
            {{ t('onboardingPortal.whyTitle') }}
          </h2>
          <p
            v-if="file.notice"
            class="text-body-2 mb-2 onb__notice"
          >
            {{ file.notice }}
          </p>
          <p class="text-body-2 text-medium-emphasis mb-0">
            {{ t('onboardingPortal.declared', { basis: t(`onboardingPortal.basis.${file.legal_basis}`) }) }}
          </p>
        </VCardText>
      </VCard>

      <!-- Vérification (informations sensibles) -->
      <VCard
        v-if="(lockedCount || file.verification_required) && !file.verified && file.writable"
        class="onb__card mb-4"
        data-test="onboarding-verify"
      >
        <VCardText>
          <h2 class="text-h6 mb-2">
            {{ t('onboardingPortal.verifyTitle') }}
          </h2>
          <p class="text-body-2 mb-3">
            {{ file.verification_locked ? t('onboardingPortal.verificationLocked') : (file.verification_available ? t('onboardingPortal.verifyText', { n: lockedCount }) : t('onboardingPortal.noChannel')) }}
          </p>
          <template v-if="file.verification_available && !file.verification_locked">
            <VBtn
              v-if="!codeSent"
              variant="tonal"
              @click="sendCode"
            >
              {{ t('onboardingPortal.sendCode') }}
            </VBtn>
            <div
              v-else
              class="d-flex gap-2 align-center flex-wrap"
            >
              <AppTextField
                v-model="code"
                :label="t('onboardingPortal.code')"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                class="onb__code"
              />
              <VBtn
                color="primary"
                :disabled="code.length !== 6"
                @click="verify"
              >
                {{ t('onboardingPortal.verify') }}
              </VBtn>
            </div>
          </template>
        </VCardText>
      </VCard>

      <!-- Informations -->
      <VCard
        v-if="file.fields.length"
        class="onb__card mb-4"
      >
        <VCardText>
          <h2 class="text-h6 mb-4">
            {{ t('onboardingPortal.fieldsTitle') }}
          </h2>
          <DynamicFormRenderer
            v-model="form"
            :fields="editableFields"
            :disabled="!file.writable"
          />
          <p
            v-for="f in file.fields.filter(x => x.locked)"
            :key="f.code"
            class="text-body-2 text-medium-emphasis mb-1"
          >
            <VIcon
              icon="tabler-lock"
              size="16"
              class="me-1"
            />{{ f.label }} — {{ t('onboardingPortal.lockedField') }}
          </p>
          <div
            v-if="file.writable"
            class="d-flex justify-end mt-2"
          >
            <VBtn
              variant="tonal"
              :loading="saving"
              :disabled="!Object.keys(changed).length"
              data-test="onboarding-save"
              @click="save"
            >
              {{ t('onboardingPortal.saveDraft') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>

      <!-- Documents -->
      <VCard
        v-if="file.documents.length"
        class="onb__card mb-4"
      >
        <VCardText>
          <h2 class="text-h6 mb-4">
            {{ t('onboardingPortal.documentsTitle') }}
          </h2>
          <div
            v-for="d in file.documents"
            :key="d.code"
            class="onb__doc"
            :data-test="`onboarding-doc-${d.code}`"
          >
            <div class="onb__doc-main">
              <div class="text-body-1 font-weight-medium">
                {{ docLabel(d) }}<span
                  v-if="d.required"
                  class="text-error"
                > *</span>
              </div>
              <div class="text-body-2 text-medium-emphasis">
                <template v-if="d.scan">
                  <span :class="d.scan === 'scanning' ? '' : 'text-error'">{{ t(`onboardingPortal.scan.${d.scan}`, { name: d.file_name }) }}</span>
                </template>
                <template v-else>
                  {{ d.deposited ? t('onboardingPortal.received', { name: d.file_name }) : (d.locked ? t('onboardingPortal.lockedField') : t('onboardingPortal.notReceived')) }}
                </template>
              </div>
            </div>
            <VFileInput
              v-if="file.writable && !d.locked"
              :label="d.deposited || d.scan === 'scanning' ? t('onboardingPortal.replace') : t('onboardingPortal.choose')"
              accept=".pdf,.jpg,.jpeg,.png"
              density="compact"
              hide-details
              :loading="uploading === d.code"
              prepend-icon=""
              prepend-inner-icon="tabler-upload"
              class="onb__file"
              @update:model-value="files => upload(d, files)"
            />
          </div>
        </VCardText>
      </VCard>

      <!-- Récapitulatif & soumission -->
      <VCard
        v-if="file.writable"
        class="onb__card mb-8"
      >
        <VCardText>
          <h2 class="text-h6 mb-2">
            {{ t('onboardingPortal.summaryTitle') }}
          </h2>
          <p
            v-if="file.missing.required.length"
            class="text-body-2 mb-3"
            data-test="onboarding-missing"
          >
            {{ t('onboardingPortal.missing') }} {{ file.missing.required.map(labelOf).join(', ') }}
          </p>
          <p
            v-else-if="file.verification_required && !file.verified"
            class="text-body-2 mb-3"
            data-test="onboarding-verify-needed"
          >
            {{ t('onboardingPortal.verifyBeforeSubmit') }}
          </p>
          <p
            v-else
            class="text-body-2 mb-3"
          >
            {{ t('onboardingPortal.ready') }}
          </p>
          <VBtn
            color="primary"
            block
            size="large"
            :disabled="file.missing.required.length > 0 || (file.verification_required && !file.verified)"
            data-test="onboarding-submit"
            @click="submit"
          >
            {{ t('onboardingPortal.submit') }}
          </VBtn>
        </VCardText>
      </VCard>
    </template>
  </div>
</template>

<style scoped>
/* ADR-796 — mobile d'abord : une colonne, cartes du design system, aucune navigation Company. */
.onb { max-inline-size: 640px; margin-inline: auto; padding: 24px 16px 48px; min-block-size: 100vh; }
.onb__center { display: flex; justify-content: center; padding-block-start: 30vh; }
.onb__header { margin-block-end: 20px; }
.onb__notice { white-space: pre-line; }
.onb__code { max-inline-size: 180px; }
.onb__doc { display: flex; align-items: center; gap: 12px; padding-block: 12px; flex-wrap: wrap; }
.onb__doc + .onb__doc { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.onb__doc-main { flex: 1; min-inline-size: 200px; }
.onb__file { flex: 0 1 240px; min-inline-size: 200px; }
</style>
