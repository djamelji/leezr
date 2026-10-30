<script setup>
// W-SIGNATURE-1 (ADR-651) — parcours PUBLIC du signataire (lien sécurisé par token).
import { $api } from '@/utils/api'
import { refreshCsrf } from '@/utils/csrf'
import { webcryptoSupported, signPersonal, signRawBase64 } from '@/utils/webcryptoSigner'

definePage({
  meta: { layout: 'blank', public: true },
})

const { t } = useI18n()
const route = useRoute()
const token = computed(() => route.params.token)

const loading = ref(true)
const error = ref(null)
const data = ref(null)
const acting = ref(false)
const done = ref(null) // 'signed' | 'declined'
const declineMode = ref(false)
const declineReason = ref('')
const consent = ref(false) // §4 — consentement explicite requis avant de signer
const documentViewed = ref(false)
const otp = ref('') // W-SIGNATURE-12 — step-up 2FA quand la politique l'exige
const auth = computed(() => data.value?.authentication || {})

const load = async () => {
  loading.value = true
  error.value = null
  try {
    await refreshCsrf()
    data.value = await $api(`/public/signature/${token.value}`)
  } catch (e) {
    error.value = e.response?.status === 404 ? t('signaturePublic.invalid') : (e.response?._data?.message || t('common.error'))
  } finally { loading.value = false }
}

onMounted(load)

const documentUrl = computed(() => `/api/public/signature/${token.value}/document`)

const isExternalPades = computed(() => data.value?.request?.required_format === 'pades_external')

const sign = async () => {
  acting.value = true
  error.value = null
  try {
    // W-SIGNATURE-7 — PAdES EXTERNE : flux 2 phases. Le navigateur (1) prouve la possession de sa clé,
    // le serveur émet le certificat + renvoie les octets `to_sign`, puis le navigateur (2) signe ces
    // octets avec la MÊME clé privée NON extractible. Aucune clé privée n'est transmise.
    if (isExternalPades.value && webcryptoSupported() && data.value?.personal_signing) {
      let prep
      try {
        const { publicKeySpki, signatureP1363 } = await signPersonal(data.value.personal_signing)
        prep = await $api(`/public/signature/${token.value}/prepare`, {
          method: 'POST',
          body: { public_key_spki: publicKeySpki, signature_p1363: signatureP1363, otp: otp.value || undefined },
        })
      } catch (e) {
        error.value = e.response?._data?.message || t('signaturePublic.webcryptoError')

        return
      }
      const padesSig = await signRawBase64(prep.to_sign_b64)
      const r = await $api(`/public/signature/${token.value}/sign`, { method: 'POST', body: { pades_signature_p1363: padesSig } })
      done.value = 'signed'
      data.value.request.status = r.status

      return
    }

    let body = {}
    // Signature PERSONNELLE (W-SIGNATURE-4) : le navigateur signe avec la clé privée NON extractible.
    if (webcryptoSupported() && data.value?.personal_signing) {
      try {
        const { publicKeySpki, signatureP1363 } = await signPersonal(data.value.personal_signing)
        body = { public_key_spki: publicKeySpki, signature_p1363: signatureP1363 }
      } catch (e) {
        error.value = t('signaturePublic.webcryptoError')

        return
      }
    }
    const r = await $api(`/public/signature/${token.value}/sign`, { method: 'POST', body })
    done.value = 'signed'
    data.value.request.status = r.status
  } catch (e) { error.value = e.response?._data?.message || t('common.error') } finally { acting.value = false }
}
const decline = async () => {
  acting.value = true
  try {
    const r = await $api(`/public/signature/${token.value}/decline`, { method: 'POST', body: { reason: declineReason.value } })
    done.value = 'declined'
    data.value.request.status = r.status
  } catch (e) { error.value = e.response?._data?.message || t('common.error') } finally { acting.value = false }
}
</script>

<template>
  <div class="d-flex align-center justify-center" style="min-block-size: 100vh;">
    <VCard
      width="100%"
      max-width="520"
      class="ma-4"
    >
      <VCardText class="pa-6">
        <div class="text-center mb-4">
          <VIcon icon="tabler-signature" size="40" color="primary" class="mb-2" />
          <h5 class="text-h5">
            {{ t('signaturePublic.title') }}
          </h5>
        </div>

        <div v-if="loading" class="text-center py-8">
          <VProgressCircular indeterminate color="primary" />
        </div>

        <VAlert v-else-if="error" type="error" variant="tonal">
          {{ error }}
        </VAlert>

        <!-- Confirmation finale -->
        <div v-else-if="done" class="text-center py-4">
          <VIcon
            :icon="done === 'signed' ? 'tabler-circle-check' : 'tabler-circle-x'"
            :color="done === 'signed' ? 'success' : 'error'"
            size="56"
            class="mb-3"
          />
          <h6 class="text-h6 mb-1">
            {{ done === 'signed' ? t('signaturePublic.signedTitle') : t('signaturePublic.declinedTitle') }}
          </h6>
          <p class="text-body-2 text-medium-emphasis">
            {{ t('signaturePublic.closeHint') }}
          </p>
        </div>

        <template v-else-if="data">
          <VList class="mb-2">
            <VListItem :title="data.request.document_label" :subtitle="t('signaturePublic.document')" />
            <VListItem :title="data.signer.name" :subtitle="t('signaturePublic.signer')" />
          </VList>

          <VAlert
            :type="data.request.certified ? 'info' : 'warning'"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ data.request.certified ? t('signaturePublic.certifiedNotice') : t('signaturePublic.uncertifiedNotice') }}
          </VAlert>

          <VBtn
            block
            variant="tonal"
            prepend-icon="tabler-file-text"
            :href="documentUrl"
            target="_blank"
            class="mb-4"
            @click="documentViewed = true"
          >
            {{ t('signaturePublic.viewDocument') }}
          </VBtn>

          <template v-if="data.can_act">
            <template v-if="!declineMode">
              <VAlert
                v-if="data.personal_signing"
                type="success"
                variant="tonal"
                density="compact"
                class="mb-3"
              >
                <VIcon icon="tabler-shield-lock" start size="16" />
                {{ t('signaturePublic.personalNote') }}
              </VAlert>

              <!-- W-SIGNATURE-12 — authentification de compte requise (signataire interne) -->
              <VAlert
                v-if="auth.needs_account_auth"
                type="warning"
                variant="tonal"
                density="compact"
                class="mb-3"
              >
                <VIcon icon="tabler-user-shield" start size="16" />
                {{ t('signaturePublic.authRequired') }}
              </VAlert>

              <!-- Step-up 2FA : code requis quand la politique Market l'exige -->
              <AppTextField
                v-if="auth.needs_mfa_step_up"
                v-model="otp"
                :label="t('signaturePublic.otpLabel')"
                :hint="t('signaturePublic.otpHint')"
                persistent-hint
                maxlength="12"
                class="mb-3"
              />

              <!-- §4 — consentement explicite requis avant de signer -->
              <VCheckbox
                v-model="consent"
                density="compact"
                class="mb-1"
              >
                <template #label>
                  <span class="text-body-2">{{ t('signaturePublic.consent') }}</span>
                </template>
              </VCheckbox>

              <!-- §4 — détails techniques/vérification repliés (jargon masqué par défaut) -->
              <VExpansionPanels variant="accordion" class="mb-3">
                <VExpansionPanel>
                  <VExpansionPanelTitle class="text-caption">
                    {{ t('signaturePublic.advancedTitle') }}
                  </VExpansionPanelTitle>
                  <VExpansionPanelText>
                    <div class="text-caption text-medium-emphasis">
                      <div>{{ t('signaturePublic.advancedTrust') }}</div>
                      <div v-if="data.personal_signing" class="mt-1">
                        {{ t('signaturePublic.advancedAlg') }} : {{ data.personal_signing.algorithm }}
                      </div>
                      <div v-if="data.authentication" class="mt-1">
                        {{ t('signaturePublic.advancedIdentity') }} : {{ t(`signaturePublic.assurance.${data.authentication.assurance}`) }}
                      </div>
                    </div>
                  </VExpansionPanelText>
                </VExpansionPanel>
              </VExpansionPanels>

              <VBtn
                block
                color="success"
                prepend-icon="tabler-writing-sign"
                :loading="acting"
                :disabled="!consent"
                class="mb-2"
                @click="sign"
              >
                {{ t('signaturePublic.sign') }}
              </VBtn>
              <VBtn
                block
                variant="outlined"
                color="error"
                :disabled="acting"
                @click="declineMode = true"
              >
                {{ t('signaturePublic.decline') }}
              </VBtn>
            </template>
            <template v-else>
              <AppTextarea
                v-model="declineReason"
                :label="t('signaturePublic.declineReason')"
                rows="2"
                class="mb-2"
              />
              <div class="d-flex gap-2">
                <VBtn variant="tonal" color="secondary" @click="declineMode = false">
                  {{ t('common.back') }}
                </VBtn>
                <VSpacer />
                <VBtn color="error" :loading="acting" @click="decline">
                  {{ t('signaturePublic.confirmDecline') }}
                </VBtn>
              </div>
            </template>
          </template>
          <VAlert v-else type="info" variant="tonal" density="compact">
            {{ t('signaturePublic.notActionable') }}
          </VAlert>
        </template>
      </VCardText>
    </VCard>
  </div>
</template>
