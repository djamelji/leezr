<script setup>
// ADR-819 (Company 2.0) — DOSSIER D'EMBAUCHE : un seul dossier par embauche, quel que soit son point de départ
// (sur place, à distance) et la personne qui le reprend. Progression par élément, actions selon l'étape,
// historique et participants. Toute écriture porte la révision chargée : si le candidat ou un autre
// gestionnaire a modifié le dossier entre-temps, le serveur refuse (409) et la page propose de recharger.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import OnboardingReviewDialog from '../../people/_OnboardingReviewDialog.vue'
import HireFinalizeDialog from './_HireFinalizeDialog.vue'

definePage({ meta: { module: 'workforce', permission: 'workforce.manage' } })

const { t, te, d } = useI18n()
const route = useRoute()
const { toast } = useAppToast()

const id = computed(() => Number(route.params.id))
const file = ref(null)
const notFound = ref(false)
const stale = ref(false)
const tab = ref('items')
const busy = ref(null)

const load = async () => {
  try {
    file.value = await $api(`/workforce/onboarding-files/${id.value}/file`)
    stale.value = false
  }
  catch (e) {
    if (e?.response?.status === 404 || e?.status === 404)
      notFound.value = true
  }
}
onMounted(load)

const docLabel = i => (te(`documents.type.${i.code}`) ? t(`documents.type.${i.code}`) : i.label)
const STATE_TONE = { to_fill: 'secondary', in_progress: 'info', provided: 'primary', to_verify: 'warning', refused: 'error', validated: 'success', not_applicable: 'secondary' }
const BUCKET_TONE = { waiting_company: 'secondary', waiting_candidate: 'info', to_verify: 'warning', to_finalize: 'primary', done: 'success', abandoned: 'secondary' }
const progressPct = computed(() => Math.round(100 * (file.value?.progress?.done || 0) / Math.max(1, file.value?.progress?.total || 0)))
const open = computed(() => file.value && !['done', 'abandoned'].includes(file.value.bucket))
const missing = computed(() => [...(file.value?.fields || []).map(i => ({ ...i, kind: 'field' })), ...(file.value?.documents || []).map(i => ({ ...i, kind: 'document' }))].filter(i => i.required && ['to_fill', 'refused'].includes(i.state)))

// Une écriture refusée parce que le dossier a changé : on le dit, on propose de recharger.
const run = async (key, fn, okMessage) => {
  busy.value = key
  try {
    await fn()
    if (okMessage)
      toast(okMessage, 'success')
    await load()
  }
  catch (e) {
    if (e?.data?.code === 'stale_revision') {
      stale.value = true

      return
    }
    toast(Object.values(e?.data?.errors || {})[0]?.[0] || e?.data?.message || t('common.error'), 'error')
  }
  finally { busy.value = null }
}

const onSite = () => run('onsite', async () => {
  const res = await $api(`/workforce/onboarding-files/${id.value}/on-site`, { method: 'POST' })

  window.open(res.url, '_blank', 'noopener')
}, t('hiring.onSiteOpened'))

const sendLink = () => run('send', () => $api(`/workforce/onboarding-files/${id.value}/send`, { method: 'POST', body: { days: 7 } }), t('hiringFile.linkSent'))

const changesOpen = ref(false)
const changesMessage = ref('')
const requestChanges = () => run('changes', async () => {
  await $api(`/workforce/onboarding-files/${id.value}/request-changes`, { method: 'POST', body: { message: changesMessage.value, revision: file.value.revision } })
  changesOpen.value = false
  changesMessage.value = ''
}, t('hiringFile.changesRequested'))

const abandonOpen = ref(false)
const abandon = () => run('abandon', async () => {
  await $api(`/workforce/onboarding-files/${id.value}/revoke`, { method: 'POST', body: { revision: file.value.revision } })
  abandonOpen.value = false
}, t('hiringFile.abandoned'))

const reviewOpen = ref(false)
const finalizeOpen = ref(false)

const historyLabel = h => (te(`hiringFile.history.${h.action.replace('onboarding.', '')}`) ? t(`hiringFile.history.${h.action.replace('onboarding.', '')}`) : h.action)
const actorLabel = h => (h.actor_type === 'candidate' ? t('hiringFile.candidate') : h.actor_type === 'system' ? t('hiringFile.system') : (h.actor || '—'))
</script>

<template>
  <div>
    <RouterLink
      to="/company/workforce/hiring"
      class="text-body-2 d-inline-flex align-center gap-1 mb-3"
    >
      <VIcon
        icon="tabler-arrow-left"
        size="16"
      />{{ t('hiring.title') }}
    </RouterLink>

    <VAlert
      v-if="notFound"
      type="warning"
      variant="tonal"
      :text="t('hiringFile.notFound')"
      data-test="hiring-file-not-found"
    />

    <template v-else-if="file">
      <AppPageHeader
        :title="file.name"
        :description="[file.job_role, file.hire_date ? t('hiringFile.start', { date: d(new Date(file.hire_date), 'short') }) : null].filter(Boolean).join(' · ')"
      >
        <template #actions>
          <VChip
            :color="BUCKET_TONE[file.bucket]"
            label
            data-test="hiring-file-bucket"
          >
            {{ t(`hiring.bucket.${file.bucket}`) }}
          </VChip>
        </template>
      </AppPageHeader>

      <VAlert
        v-if="stale"
        type="warning"
        variant="tonal"
        class="mb-4"
        data-test="hiring-file-stale"
      >
        <div class="d-flex flex-wrap align-center gap-3">
          <span class="flex-grow-1">{{ t('hiringFile.stale') }}</span>
          <VBtn
            size="small"
            variant="tonal"
            @click="load"
          >
            {{ t('hiringFile.reload') }}
          </VBtn>
        </div>
      </VAlert>

      <VRow class="mb-2">
        <!-- Progression + prochaine action -->
        <VCol
          cols="12"
          md="8"
        >
          <VCard class="h-100">
            <VCardText>
              <div class="d-flex align-center gap-3 mb-2">
                <span class="text-h6">{{ progressPct }} %</span>
                <VProgressLinear
                  :model-value="progressPct"
                  height="8"
                  rounded
                  :color="progressPct === 100 ? 'success' : 'primary'"
                />
              </div>
              <div class="text-body-2 text-medium-emphasis mb-4">
                {{ t('hiringFile.progressDetail', { done: file.progress.done, total: file.progress.total, ok: file.progress.validated }) }}
              </div>
              <div
                v-if="file.bucket === 'to_finalize' || file.bucket === 'to_verify'"
                class="mb-3"
              >
                <div
                  v-if="missing.length"
                  class="text-body-2"
                >
                  <strong>{{ t('hiringFile.missingTitle') }}</strong>
                  <ul class="ms-4">
                    <li
                      v-for="m in missing"
                      :key="`${m.kind}-${m.code}`"
                    >
                      {{ m.kind === 'document' ? docLabel(m) : m.label }}
                    </li>
                  </ul>
                </div>
                <div
                  v-if="file.blocking_documents?.length"
                  class="text-body-2 text-error"
                >
                  {{ t('hiring.blocked', { n: file.blocking_documents.length }) }}
                </div>
              </div>
              <div class="d-flex flex-wrap gap-2">
                <VBtn
                  v-if="file.bucket === 'to_verify'"
                  color="warning"
                  prepend-icon="tabler-checklist"
                  data-test="hiring-file-review"
                  @click="reviewOpen = true"
                >
                  {{ t('hiring.review') }}
                </VBtn>
                <VBtn
                  v-if="file.bucket === 'to_finalize'"
                  color="primary"
                  prepend-icon="tabler-circle-check"
                  data-test="hiring-file-finalize"
                  @click="finalizeOpen = true"
                >
                  {{ t('hiring.finalize') }}
                </VBtn>
                <VBtn
                  v-if="open && file.bucket !== 'to_finalize'"
                  :variant="file.bucket === 'waiting_company' ? 'flat' : 'tonal'"
                  :color="file.bucket === 'waiting_company' ? 'primary' : undefined"
                  prepend-icon="tabler-device-desktop"
                  :loading="busy === 'onsite'"
                  data-test="hiring-file-onsite"
                  @click="onSite"
                >
                  {{ t('hiring.continueOnSite') }}
                </VBtn>
                <VBtn
                  v-if="open && file.bucket !== 'to_finalize' && file.has_email && file.delivery?.channel"
                  variant="tonal"
                  prepend-icon="tabler-mail-forward"
                  :loading="busy === 'send'"
                  data-test="hiring-file-send"
                  @click="sendLink"
                >
                  {{ file.reminders ? t('hiringFile.remind') : t('hiringFile.sendLink') }}
                </VBtn>
                <VBtn
                  v-if="file.bucket === 'to_verify' || file.bucket === 'to_finalize'"
                  variant="tonal"
                  prepend-icon="tabler-message-2-question"
                  data-test="hiring-file-changes"
                  @click="changesOpen = true"
                >
                  {{ t('hiringFile.requestChanges') }}
                </VBtn>
                <VBtn
                  v-if="file.finalized"
                  variant="tonal"
                  prepend-icon="tabler-id"
                  :to="`/company/people/${file.person_ref}`"
                >
                  {{ t('hiringFile.openPerson') }}
                </VBtn>
                <VSpacer />
                <VBtn
                  v-if="open"
                  variant="text"
                  color="error"
                  data-test="hiring-file-abandon"
                  @click="abandonOpen = true"
                >
                  {{ t('hiringFile.abandon') }}
                </VBtn>
              </div>
            </VCardText>
          </VCard>
        </VCol>
        <!-- Participants -->
        <VCol
          cols="12"
          md="4"
        >
          <VCard
            class="h-100"
            :title="t('hiringFile.participants')"
          >
            <VList density="compact">
              <VListItem
                v-for="(p, i) in file.participants"
                :key="i"
                :prepend-icon="p.kind === 'candidate' ? 'tabler-user' : 'tabler-user-shield'"
                :title="p.kind === 'candidate' ? t('hiringFile.candidate') : p.name"
                :subtitle="t('hiringFile.actions', { n: p.actions })"
              />
              <VListItem
                v-if="!file.participants.length"
                :title="t('hiringFile.noParticipant')"
              />
            </VList>
            <VCardText
              v-if="file.reminders"
              class="text-caption text-medium-emphasis pt-0"
            >
              {{ t('hiringFile.reminders', { n: file.reminders }) }}
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VTabs
        v-model="tab"
        class="mb-4"
      >
        <VTab value="items">
          {{ t('hiringFile.tabs.items') }}
        </VTab>
        <VTab
          value="history"
          data-test="hiring-file-tab-history"
        >
          {{ t('hiringFile.tabs.history') }}
        </VTab>
      </VTabs>

      <VWindow v-model="tab">
        <VWindowItem value="items">
          <VRow>
            <VCol
              v-for="section in ['fields', 'documents']"
              :key="section"
              cols="12"
              md="6"
            >
              <VCard :title="t(`hiringFile.${section}`)">
                <VList>
                  <VListItem
                    v-for="i in file[section]"
                    :key="i.code"
                    :title="section === 'documents' ? docLabel(i) : i.label"
                    :subtitle="i.required ? t('hiringFile.required') : t('hiringFile.optional')"
                    :data-test="`hiring-item-${i.code}`"
                  >
                    <template #append>
                      <VChip
                        size="small"
                        label
                        :color="STATE_TONE[i.state]"
                        :data-test="`hiring-item-state-${i.code}`"
                      >
                        {{ t(`hiringFile.state.${i.state}`) }}
                      </VChip>
                    </template>
                  </VListItem>
                  <VListItem
                    v-if="!file[section].length"
                    :title="t('hiringFile.none')"
                  />
                </VList>
              </VCard>
            </VCol>
          </VRow>
        </VWindowItem>
        <VWindowItem value="history">
          <VCard>
            <VTimeline
              density="compact"
              align="start"
              truncate-line="both"
              class="pa-4"
              data-test="hiring-file-history"
            >
              <VTimelineItem
                v-for="(h, i) in file.history"
                :key="i"
                size="x-small"
                :dot-color="h.actor_type === 'candidate' ? 'info' : 'primary'"
              >
                <div class="d-flex flex-wrap justify-space-between gap-2">
                  <span class="font-weight-medium">{{ historyLabel(h) }}</span>
                  <span class="text-caption text-medium-emphasis">{{ h.at ? d(new Date(h.at), 'long') : '' }}</span>
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ actorLabel(h) }}
                </div>
              </VTimelineItem>
            </VTimeline>
          </VCard>
        </VWindowItem>
      </VWindow>
    </template>

    <OnboardingReviewDialog
      :is-dialog-visible="reviewOpen"
      :invitation-id="reviewOpen ? id : null"
      @update:is-dialog-visible="v => { reviewOpen = v }"
      @changed="load"
    />
    <HireFinalizeDialog
      :is-dialog-visible="finalizeOpen"
      :file="finalizeOpen ? { id, name: file?.name, job_role: file?.job_role, hire_date: file?.hire_date, person_ref: file?.person_ref, revision: file?.revision } : null"
      @update:is-dialog-visible="v => { finalizeOpen = v }"
      @finalized="load"
    />

    <VDialog
      v-model="changesOpen"
      max-width="520"
    >
      <VCard :title="t('hiringFile.requestChanges')">
        <VCardText>
          <AppTextarea
            v-model="changesMessage"
            :label="t('hiringFile.changesMessage')"
            rows="3"
            autofocus
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="changesOpen = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :disabled="!changesMessage.trim()"
            :loading="busy === 'changes'"
            @click="requestChanges"
          >
            {{ t('hiringFile.send') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="abandonOpen"
      max-width="480"
    >
      <VCard :title="t('hiringFile.abandon')">
        <VCardText>{{ t('hiringFile.abandonConfirm') }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="abandonOpen = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            :loading="busy === 'abandon'"
            data-test="hiring-file-abandon-confirm"
            @click="abandon"
          >
            {{ t('hiringFile.abandon') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
