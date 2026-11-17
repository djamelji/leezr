<script setup>
// ADR-819 (Company 2.0) — ÉQUIPE › PARRAINAGES : candidatures reçues par parrainage (consenties uniquement),
// examen, doublons, demande de complément, décision, conversion en dossier d'embauche (moteur existant,
// sans ressaisie, sans compte). Onglet Réglages : modes, postes ouverts, notifications, récompense, rétention.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

// ADR-820 — onglet dans le chemin (/referrals/settings), nom historique conservé.
definePage({ name: 'company-workforce-referrals', meta: { module: 'workforce_referral', permission: 'workforce_referral.review' } })

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const { can } = useCan()
const { toast } = useAppToast()

const tab = ref((route.params.tab || route.query.tab) === 'settings' && can('workforce_referral.manage') ? 'settings' : 'applications')
watch(tab, v => router.replace({ name: 'company-workforce-referrals', params: { tab: v === 'settings' ? 'settings' : undefined } }), { immediate: !!route.query.tab })
const queue = ref({ items: [], awaiting_consent: 0 })
const loading = ref(true)
const filter = ref('open')
const load = async () => {
  loading.value = true
  try { queue.value = await $api('/workforce/referrals') }
  finally { loading.value = false }
}
onMounted(async () => {
  await load()
  if (route.query.open)
    openDetail(Number(route.query.open))
})

const OPEN = ['submitted', 'in_review', 'info_requested', 'accepted']
const STATUS_TONE = { submitted: 'warning', in_review: 'info', info_requested: 'info', accepted: 'primary', declined: 'secondary', converted: 'success' }
const visible = computed(() => queue.value.items.filter(r => filter.value === 'all' || (filter.value === 'open' ? OPEN.includes(r.status) : r.status === filter.value)))
const headers = computed(() => [
  { title: t('referrals.col.candidate'), key: 'name' },
  { title: t('referrals.col.position'), key: 'position' },
  { title: t('referrals.col.referrer'), key: 'referrer' },
  { title: t('referrals.col.received'), key: 'submitted_at' },
  { title: t('referrals.col.status'), key: 'status', align: 'end' },
])

// ── Détail & décision ──
const detail = ref(null)
const drawer = ref(false)
const note = ref('')
const hireDate = ref(new Date(Date.now() + 14 * 864e5).toISOString().slice(0, 10))
const acting = ref(null)
const openDetail = async id => {
  detail.value = await $api(`/workforce/referrals/${id}`).catch(() => null)
  note.value = ''
  drawer.value = !!detail.value
  load()
}
const decide = async decision => {
  acting.value = decision
  try {
    const res = await $api(`/workforce/referrals/${detail.value.id}/decision`, { method: 'POST', body: { decision, note: note.value || null } })
    detail.value = res.referral
    if (decision === 'request_info')
      toast(res.mailed ? t('referrals.infoMailed') : t('referrals.infoNotMailed'), res.mailed ? 'success' : 'info')
    else
      toast(t(`referrals.decided.${decision}`), 'success')
    note.value = ''
    load()
  }
  catch (e) { toast(Object.values(e?.data?.errors || {})[0]?.[0] || e?.data?.message || t('common.error'), 'error') }
  finally { acting.value = null }
}
const convert = async () => {
  acting.value = 'convert'
  try {
    const res = await $api(`/workforce/referrals/${detail.value.id}/convert`, { method: 'POST', body: { hire_date: hireDate.value } })
    toast(t('referrals.converted'), 'success')
    router.push(`/company/workforce/hiring/${res.hiring_file_id}`)
  }
  catch (e) { toast(Object.values(e?.data?.errors || {})[0]?.[0] || e?.data?.message || t('common.error'), 'error') }
  finally { acting.value = null }
}

// ── Réglages ──
const settings = ref(null)
const positions = ref([])
const savingSettings = ref(false)
const loadSettings = async () => {
  settings.value = await $api('/workforce/referrals/settings')
  const list = await $api('/workforce/job-roles').catch(() => [])
  const arr = Array.isArray(list) ? list : Object.values(list || {}).filter(r => r && typeof r === 'object' && r.id)
  positions.value = arr.map(p => ({ title: p.title, value: p.id }))
}
watch(tab, v => { if (v === 'settings' && !settings.value) loadSettings() }, { immediate: true })
const saveSettings = async () => {
  savingSettings.value = true
  try {
    settings.value = await $api('/workforce/referrals/settings', { method: 'PUT', body: settings.value })
    toast(t('referrals.settings.saved'), 'success')
  }
  catch (e) { toast(Object.values(e?.data?.errors || {})[0]?.[0] || t('common.error'), 'error') }
  finally { savingSettings.value = false }
}
const MODES = computed(() => ['both', 'recommend', 'share'].map(v => ({ value: v, title: t(`referrals.settings.mode.${v}`) })))

// Dates dans la langue de l'interface (aucun format nommé n'est déclaré dans l'i18n).
const fmtDate = v => (v ? new Date(v).toLocaleDateString(locale.value) : '')
const fmtDateTime = v => (v ? new Date(v).toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' }) : '')
</script>

<template>
  <div>
    <AppPageHeader
      :title="t('referrals.title')"
      :description="t('referrals.subtitle')"
    />

    <VTabs
      v-model="tab"
      class="mb-4"
    >
      <VTab value="applications">
        {{ t('referrals.tabs.applications') }}
      </VTab>
      <VTab
        v-if="can('workforce_referral.manage')"
        value="settings"
        data-test="referrals-tab-settings"
      >
        {{ t('referrals.tabs.settings') }}
      </VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem value="applications">
        <VAlert
          v-if="queue.awaiting_consent"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4"
          :text="t('referrals.awaitingConsent', { n: queue.awaiting_consent })"
        />
        <VChipGroup
          v-model="filter"
          mandatory
          selected-class="text-primary"
          class="mb-4"
        >
          <VChip
            v-for="f in ['open', 'accepted', 'converted', 'declined', 'all']"
            :key="f"
            :value="f"
            variant="tonal"
          >
            {{ t(`referrals.filter.${f}`) }}
          </VChip>
        </VChipGroup>
        <VCard>
          <VDataTable
            :headers="headers"
            :items="visible"
            :loading="loading"
            :items-per-page="25"
            data-test="referrals-table"
            @click:row="(_, { item }) => openDetail(item.id)"
          >
            <template #item.name="{ item }">
              <span class="font-weight-medium">{{ [item.first_name, item.last_name].filter(Boolean).join(' ') }}</span>
            </template>
            <template #item.submitted_at="{ item }">
              {{ item.submitted_at ? fmtDate(item.submitted_at) : '—' }}
            </template>
            <template #item.status="{ item }">
              <VChip
                size="small"
                label
                :color="STATUS_TONE[item.status]"
              >
                {{ t(`referrals.status.${item.status}`) }}
              </VChip>
            </template>
            <template #no-data>
              <div class="text-center py-8 text-medium-emphasis">
                {{ t('referrals.empty') }}
              </div>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem
        v-if="can('workforce_referral.manage')"
        value="settings"
      >
        <VCard v-if="settings">
          <VCardText class="d-flex flex-column gap-4">
            <AppSelect
              v-model="settings.mode"
              :items="MODES"
              :label="t('referrals.settings.modeLabel')"
            />
            <AppSelect
              v-model="settings.job_role_ids"
              :items="positions"
              :label="t('referrals.settings.positions')"
              multiple
              chips
              closable-chips
              :hint="t('referrals.settings.positionsHint')"
              persistent-hint
              data-test="referrals-settings-positions"
            />
            <VSwitch
              v-model="settings.notify_reviewers"
              :label="t('referrals.settings.notify')"
              hide-details
            />
            <AppTextarea
              v-model="settings.reward_note"
              :label="t('referrals.settings.reward')"
              :hint="t('referrals.settings.rewardHint')"
              persistent-hint
              rows="2"
            />
            <VRow>
              <VCol
                cols="12"
                sm="6"
              >
                <AppTextField
                  v-model.number="settings.link_days"
                  type="number"
                  min="1"
                  max="60"
                  :label="t('referrals.settings.linkDays')"
                />
              </VCol>
              <VCol
                cols="12"
                sm="6"
              >
                <AppTextField
                  v-model.number="settings.retention_days"
                  type="number"
                  min="30"
                  max="730"
                  :label="t('referrals.settings.retention')"
                  :hint="t('referrals.settings.retentionHint')"
                  persistent-hint
                />
              </VCol>
            </VRow>
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn
              color="primary"
              :loading="savingSettings"
              data-test="referrals-settings-save"
              @click="saveSettings"
            >
              {{ t('common.save') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- Détail d'une candidature -->
    <VNavigationDrawer
      v-model="drawer"
      temporary
      location="end"
      width="440"
    >
      <template v-if="detail">
        <VCardItem
          :title="[detail.first_name, detail.last_name].filter(Boolean).join(' ')"
          :subtitle="detail.position"
        >
          <template #append>
            <IconBtn
              :aria-label="t('common.close')"
              @click="drawer = false"
            >
              <VIcon icon="tabler-x" />
            </IconBtn>
          </template>
        </VCardItem>
        <VDivider />
        <VCardText class="d-flex flex-column gap-3">
          <VChip
            label
            :color="STATUS_TONE[detail.status]"
            class="align-self-start"
          >
            {{ t(`referrals.status.${detail.status}`) }}
          </VChip>
          <div class="text-body-2">
            <div>{{ detail.email }}</div>
            <div v-if="detail.phone">
              {{ detail.phone }}
            </div>
            <div class="text-medium-emphasis">
              {{ t('referrals.referredBy', { name: detail.referrer || '—' }) }} · {{ t(`referrals.channel.${detail.channel}`) }}
            </div>
          </div>
          <div v-if="detail.candidate_message">
            <div class="text-caption text-medium-emphasis">
              {{ t('referrals.candidateMessage') }}
            </div>
            <div class="text-body-2">
              {{ detail.candidate_message }}
            </div>
          </div>
          <div v-if="detail.referrer_note">
            <div class="text-caption text-medium-emphasis">
              {{ t('referrals.referrerNote') }}
            </div>
            <div class="text-body-2">
              {{ detail.referrer_note }}
            </div>
          </div>
          <VAlert
            v-if="detail.duplicates?.length"
            type="warning"
            variant="tonal"
            density="compact"
            :title="t('referrals.duplicates')"
            data-test="referral-duplicates"
          >
            <div
              v-for="dup in detail.duplicates"
              :key="`${dup.kind}-${dup.id}`"
            >
              {{ t(`referrals.duplicateKind.${dup.kind}`) }} : {{ dup.label }}
            </div>
          </VAlert>
          <VAlert
            v-if="detail.status === 'info_requested'"
            type="info"
            variant="tonal"
            density="compact"
            :text="t('referrals.waitingInfo', { q: detail.info_request })"
          />

          <template v-if="['submitted', 'in_review', 'info_requested', 'accepted'].includes(detail.status)">
            <AppTextarea
              v-model="note"
              :label="t('referrals.note')"
              :hint="t('referrals.noteHint')"
              persistent-hint
              rows="2"
            />
            <div
              v-if="detail.status !== 'accepted'"
              class="d-flex flex-wrap gap-2"
            >
              <VBtn
                color="primary"
                :loading="acting === 'accept'"
                data-test="referral-accept"
                @click="decide('accept')"
              >
                {{ t('referrals.accept') }}
              </VBtn>
              <VBtn
                variant="tonal"
                :disabled="!note.trim()"
                :loading="acting === 'request_info'"
                @click="decide('request_info')"
              >
                {{ t('referrals.requestInfo') }}
              </VBtn>
              <VBtn
                variant="text"
                color="error"
                :loading="acting === 'decline'"
                data-test="referral-decline"
                @click="decide('decline')"
              >
                {{ t('referrals.decline') }}
              </VBtn>
            </div>
            <template v-if="detail.status === 'accepted' && can('workforce.manage')">
              <AppTextField
                v-model="hireDate"
                type="date"
                :label="t('referrals.hireDate')"
              />
              <VBtn
                color="primary"
                prepend-icon="tabler-user-plus"
                :loading="acting === 'convert'"
                data-test="referral-convert"
                @click="convert"
              >
                {{ t('referrals.convert') }}
              </VBtn>
              <p class="text-caption text-medium-emphasis mb-0">
                {{ t('referrals.convertHint') }}
              </p>
            </template>
          </template>
          <VBtn
            v-if="detail.hiring_file_id"
            variant="tonal"
            :to="`/company/workforce/hiring/${detail.hiring_file_id}`"
          >
            {{ t('referrals.openHiringFile') }}
          </VBtn>
        </VCardText>
      </template>
    </VNavigationDrawer>
  </div>
</template>
