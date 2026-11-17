<script setup>
// ADR-819 (Company 2.0) — MON ESPACE › PARRAINAGE : le salarié voit les postes ouverts, recommande un proche
// (le proche reçoit un lien et confirme lui-même) ou partage un lien de candidature. Il suit ses parrainages
// dans les limites de la confidentialité : jamais la décision des RH ni la candidature elle-même.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

definePage({ meta: { module: 'workforce_referral', permission: 'workforce_referral.refer' } })

const { t, d } = useI18n()
const { toast } = useAppToast()

const data = ref(null)
const loading = ref(true)
const load = async () => {
  loading.value = true
  try { data.value = await $api('/workforce/referrals/mine') }
  finally { loading.value = false }
}
onMounted(load)

const canRecommend = computed(() => ['recommend', 'both'].includes(data.value?.mode))
const canShare = computed(() => ['share', 'both'].includes(data.value?.mode))

// ── Recommander un proche ──
const recommendOpen = ref(false)
const form = ref({})
const sending = ref(false)
const result = ref(null)
const openRecommend = job => {
  form.value = { job_role_id: job.id, position: job.title, first_name: '', last_name: '', email: '', phone: '', note: '' }
  result.value = null
  recommendOpen.value = true
}
const recommend = async () => {
  sending.value = true
  try {
    const { position, ...body } = form.value
    result.value = await $api('/workforce/referrals/recommend', { method: 'POST', body })
    await load()
  }
  catch (e) { toast(Object.values(e?.data?.errors || {})[0]?.[0] || e?.data?.message || t('common.error'), 'error') }
  finally { sending.value = false }
}

// ── Partager un lien ──
const shareOpen = ref(false)
const shared = ref(null)
const share = async job => {
  try {
    shared.value = { ...(await $api('/workforce/referrals/share', { method: 'POST', body: { job_role_id: job.id } })), position: job.title }
    shareOpen.value = true
    await load()
  }
  catch (e) { toast(Object.values(e?.data?.errors || {})[0]?.[0] || e?.data?.message || t('common.error'), 'error') }
}
const copy = async url => {
  try {
    await navigator.clipboard.writeText(url)
    toast(t('myReferrals.copied'), 'success')
  }
  catch { toast(t('myReferrals.copyFailed'), 'warning') }
}
const nativeShare = async url => {
  if (navigator.share)
    await navigator.share({ title: t('myReferrals.shareTitle'), url }).catch(() => {})
  else
    copy(url)
}

const withdraw = async r => {
  try {
    await $api(`/workforce/referrals/${r.id}/withdraw`, { method: 'POST' })
    toast(t('myReferrals.withdrawn'), 'success')
    await load()
  }
  catch (e) { toast(Object.values(e?.data?.errors || {})[0]?.[0] || t('common.error'), 'error') }
}

const STATUS_TONE = { link_shared: 'info', awaiting_candidate: 'info', received: 'primary', closed: 'secondary', withdrawn: 'secondary', expired: 'secondary' }
</script>

<template>
  <div>
    <AppPageHeader
      :title="t('myReferrals.title')"
      :description="t('myReferrals.subtitle')"
    />

    <VProgressLinear
      v-if="loading && !data"
      indeterminate
    />

    <template v-else-if="data">
      <VAlert
        v-if="!data.mode"
        type="info"
        variant="tonal"
        class="mb-4"
        :text="t('myReferrals.notConfigured')"
      />
      <VAlert
        v-if="data.reward_note"
        type="success"
        variant="tonal"
        class="mb-4"
        icon="tabler-gift"
        :title="t('myReferrals.reward')"
        :text="data.reward_note"
      />

      <h5 class="text-h5 mb-3">
        {{ t('myReferrals.openPositions') }}
      </h5>
      <VRow class="card-grid card-grid-sm mb-6">
        <VCol
          v-for="job in data.opportunities"
          :key="job.id"
          cols="12"
          sm="6"
          lg="4"
        >
          <VCard :data-test="`referral-opportunity-${job.id}`">
            <VCardItem
              :title="job.title"
              :subtitle="job.department || ''"
            />
            <VCardText
              v-if="job.description"
              class="text-body-2"
            >
              {{ job.description }}
            </VCardText>
            <VCardActions class="flex-wrap gap-2">
              <VBtn
                v-if="canRecommend"
                variant="tonal"
                prepend-icon="tabler-user-heart"
                :data-test="`referral-recommend-${job.id}`"
                @click="openRecommend(job)"
              >
                {{ t('myReferrals.recommend') }}
              </VBtn>
              <VBtn
                v-if="canShare"
                variant="text"
                prepend-icon="tabler-share"
                :data-test="`referral-share-${job.id}`"
                @click="share(job)"
              >
                {{ t('myReferrals.share') }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VCol>
        <VCol
          v-if="!data.opportunities.length"
          cols="12"
        >
          <p class="text-medium-emphasis mb-0">
            {{ t('myReferrals.noPosition') }}
          </p>
        </VCol>
      </VRow>

      <h5 class="text-h5 mb-3">
        {{ t('myReferrals.mine') }}
      </h5>
      <VCard>
        <VList
          v-if="data.mine.length"
          data-test="referral-mine"
        >
          <VListItem
            v-for="r in data.mine"
            :key="r.id"
            :title="r.name || t('myReferrals.sharedLink')"
            :subtitle="[r.position, d(new Date(r.created_at), 'short')].filter(Boolean).join(' · ')"
          >
            <template #append>
              <div class="d-flex align-center gap-2">
                <VChip
                  size="small"
                  label
                  :color="STATUS_TONE[r.status]"
                >
                  {{ t(`myReferrals.status.${r.status}`) }}
                </VChip>
                <IconBtn
                  v-if="['link_shared', 'awaiting_candidate'].includes(r.status)"
                  size="small"
                  :aria-label="t('myReferrals.withdraw')"
                  :title="t('myReferrals.withdraw')"
                  @click="withdraw(r)"
                >
                  <VIcon icon="tabler-x" />
                </IconBtn>
              </div>
            </template>
          </VListItem>
        </VList>
        <VCardText
          v-else
          class="text-medium-emphasis"
        >
          {{ t('myReferrals.none') }}
        </VCardText>
        <VCardText class="text-caption text-medium-emphasis pt-0">
          {{ t('myReferrals.privacy') }}
        </VCardText>
      </VCard>
    </template>

    <!-- Recommander -->
    <VDialog
      v-model="recommendOpen"
      max-width="520"
    >
      <VCard :title="t('myReferrals.recommendTitle', { position: form.position })">
        <VCardText v-if="!result">
          <div class="d-flex flex-column gap-4">
            <AppTextField
              v-model="form.first_name"
              :label="`${t('referralPortal.firstName')} *`"
              data-test="recommend-first-name"
            />
            <AppTextField
              v-model="form.last_name"
              :label="t('referralPortal.lastName')"
            />
            <AppTextField
              v-model="form.email"
              type="email"
              :label="t('referralPortal.email')"
              data-test="recommend-email"
            />
            <AppTextField
              v-model="form.phone"
              type="tel"
              :label="t('referralPortal.phone')"
            />
            <AppTextarea
              v-model="form.note"
              :label="t('myReferrals.note')"
              rows="2"
            />
            <p class="text-caption text-medium-emphasis mb-0">
              {{ t('myReferrals.recommendHint') }}
            </p>
          </div>
        </VCardText>
        <VCardText v-else>
          <VAlert
            :type="result.mailed ? 'success' : 'info'"
            variant="tonal"
            class="mb-3"
            :text="result.mailed ? t('myReferrals.mailed') : t('myReferrals.notMailed')"
          />
          <AppTextField
            :model-value="result.url"
            readonly
            :label="t('myReferrals.link')"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <template v-if="!result">
            <VBtn
              variant="text"
              @click="recommendOpen = false"
            >
              {{ t('common.cancel') }}
            </VBtn>
            <VBtn
              color="primary"
              :disabled="!form.first_name || (!form.email && !form.phone)"
              :loading="sending"
              data-test="recommend-confirm"
              @click="recommend"
            >
              {{ t('myReferrals.recommendConfirm') }}
            </VBtn>
          </template>
          <template v-else>
            <VBtn
              variant="text"
              @click="copy(result.url)"
            >
              {{ t('myReferrals.copy') }}
            </VBtn>
            <VBtn
              color="primary"
              @click="recommendOpen = false"
            >
              {{ t('common.close') }}
            </VBtn>
          </template>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Lien partagé -->
    <VDialog
      v-model="shareOpen"
      max-width="520"
    >
      <VCard :title="t('myReferrals.shareTitleFor', { position: shared?.position })">
        <VCardText>
          <p class="text-body-2">
            {{ t('myReferrals.shareHint') }}
          </p>
          <AppTextField
            :model-value="shared?.url"
            readonly
            :label="t('myReferrals.link')"
            data-test="referral-share-url"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="copy(shared.url)"
          >
            {{ t('myReferrals.copy') }}
          </VBtn>
          <VBtn
            color="primary"
            prepend-icon="tabler-share"
            @click="nativeShare(shared.url)"
          >
            {{ t('myReferrals.shareAction') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
