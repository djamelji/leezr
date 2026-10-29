<script setup>
// W-SIGNATURE-1 (ADR-651) — parcours PUBLIC du signataire (lien sécurisé par token).
import { $api } from '@/utils/api'
import { refreshCsrf } from '@/utils/csrf'

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

const sign = async () => {
  acting.value = true
  try {
    const r = await $api(`/public/signature/${token.value}/sign`, { method: 'POST' })
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
          >
            {{ t('signaturePublic.viewDocument') }}
          </VBtn>

          <template v-if="data.can_act">
            <template v-if="!declineMode">
              <VBtn
                block
                color="success"
                prepend-icon="tabler-writing-sign"
                :loading="acting"
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
