<script setup>
// ADR-819 (Company 2.0) — page PUBLIQUE de candidature par parrainage, mobile d'abord, sans compte.
// Le jeton est dans le fragment d'URL (#t=…) : jamais envoyé à un serveur ni dans un Referer. Il est retiré
// de la barre d'adresse et gardé en mémoire le temps de la candidature. Consentement explicite obligatoire.
import { ofetch } from 'ofetch'
import { getXsrfToken, refreshCsrf } from '@/utils/csrf'

definePage({ meta: { layout: 'blank', public: true } })

const { t } = useI18n()
const api = ofetch.create({
  baseURL: '/api/public/referral',
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

const state = ref('loading') // loading | ready | invalid | done
const view = ref(null)
const form = ref({ first_name: '', last_name: '', email: '', phone: '', message: '', consent: false, website: '' })
const errors = ref({})
const sending = ref(false)
let token = null

onMounted(async () => {
  token = new URLSearchParams(window.location.hash.slice(1)).get('t')
  history.replaceState(null, '', window.location.pathname)
  if (!token) {
    state.value = 'invalid'

    return
  }
  try {
    await refreshCsrf()
    view.value = await api('/view', { method: 'POST', body: { token } })
    if (view.value.prefill)
      form.value = { ...form.value, ...Object.fromEntries(Object.entries(view.value.prefill).map(([k, v]) => [k, v || ''])) }
    state.value = 'ready'
  }
  catch { state.value = 'invalid' }
})

const submit = async () => {
  sending.value = true
  errors.value = {}
  try {
    const body = { token, ...form.value }
    if (!body.website)
      delete body.website
    await api('/apply', { method: 'POST', body })
    state.value = 'done'
  }
  catch (e) {
    if (e?.status === 404 || e?.statusCode === 404)
      state.value = 'invalid'
    else
      errors.value = e?.data?.errors || { form: [e?.data?.message || t('referralPortal.error')] }
  }
  finally { sending.value = false }
}
const err = k => errors.value[k]?.[0]
</script>

<template>
  <div class="referral-portal pa-4">
    <VCard
      max-width="560"
      class="mx-auto"
    >
      <VCardText
        v-if="state === 'loading'"
        class="text-center py-10"
      >
        <VProgressCircular indeterminate />
      </VCardText>

      <VCardText
        v-else-if="state === 'invalid'"
        class="text-center py-10"
        data-test="referral-invalid"
      >
        <VIcon
          icon="tabler-link-off"
          size="40"
          class="mb-3"
        />
        <h5 class="text-h5 mb-2">
          {{ t('referralPortal.invalidTitle') }}
        </h5>
        <p class="text-body-1 mb-0">
          {{ t('referralPortal.invalid') }}
        </p>
      </VCardText>

      <VCardText
        v-else-if="state === 'done'"
        class="text-center py-10"
        data-test="referral-done"
      >
        <VIcon
          icon="tabler-circle-check"
          color="success"
          size="44"
          class="mb-3"
        />
        <h5 class="text-h5 mb-2">
          {{ t('referralPortal.doneTitle') }}
        </h5>
        <p class="text-body-1 mb-0">
          {{ t('referralPortal.done', { company: view?.company }) }}
        </p>
      </VCardText>

      <template v-else>
        <VCardText>
          <div class="text-overline text-medium-emphasis">
            {{ view.company }}
          </div>
          <h4 class="text-h4 mb-2">
            {{ view.position || t('referralPortal.opportunity') }}
          </h4>
          <p
            v-if="view.description"
            class="text-body-1"
          >
            {{ view.description }}
          </p>
          <VAlert
            v-if="view.mode === 'info'"
            type="info"
            variant="tonal"
            class="mb-2"
            :title="t('referralPortal.infoTitle')"
            :text="view.info_request"
          />
        </VCardText>
        <VDivider />
        <VCardText class="d-flex flex-column gap-4">
          <AppTextField
            v-model="form.first_name"
            :label="`${t('referralPortal.firstName')} *`"
            :error-messages="err('first_name')"
            autocomplete="given-name"
            data-test="referral-first-name"
          />
          <AppTextField
            v-model="form.last_name"
            :label="`${t('referralPortal.lastName')} *`"
            :error-messages="err('last_name')"
            autocomplete="family-name"
            data-test="referral-last-name"
          />
          <AppTextField
            v-model="form.email"
            type="email"
            :label="`${t('referralPortal.email')} *`"
            :error-messages="err('email')"
            autocomplete="email"
            data-test="referral-email"
          />
          <AppTextField
            v-model="form.phone"
            type="tel"
            :label="t('referralPortal.phone')"
            :error-messages="err('phone')"
            autocomplete="tel"
          />
          <AppTextarea
            v-model="form.message"
            :label="view.mode === 'info' ? t('referralPortal.infoAnswer') : t('referralPortal.message')"
            rows="3"
            :error-messages="err('message')"
            data-test="referral-message"
          />
          <!-- Pot de miel : invisible pour une personne, rempli par les robots -->
          <input
            v-model="form.website"
            type="text"
            name="website"
            tabindex="-1"
            autocomplete="off"
            aria-hidden="true"
            class="referral-hp"
          >
          <VCheckbox
            v-model="form.consent"
            :error-messages="err('consent')"
            hide-details="auto"
            data-test="referral-consent"
          >
            <template #label>
              <span class="text-body-2">{{ view.consent_text }}</span>
            </template>
          </VCheckbox>
          <VAlert
            v-if="err('form')"
            type="error"
            variant="tonal"
            density="compact"
            :text="err('form')"
          />
          <VBtn
            block
            size="large"
            :disabled="!form.consent || !form.first_name || !form.last_name || !form.email"
            :loading="sending"
            data-test="referral-submit"
            @click="submit"
          >
            {{ view.mode === 'info' ? t('referralPortal.sendInfo') : t('referralPortal.apply') }}
          </VBtn>
        </VCardText>
      </template>
    </VCard>
  </div>
</template>

<style scoped>
.referral-portal {
  min-block-size: 100vh;
  background: rgb(var(--v-theme-background));
}

.referral-hp {
  position: absolute;
  inline-size: 1px;
  block-size: 1px;
  inset-inline-start: -9999px;
  opacity: 0;
}
</style>
