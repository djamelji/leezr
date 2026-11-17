<script setup>
// ADR-818 — espace « Embauches » : tous les dossiers d'embauche (sur place ou à distance), leur progression,
// les pièces bloquées, la vérification, la reprise sur place, la finalisation et l'historique.
// Un seul moteur (dossiers d'intégration ADR-796) ; un seul assistant (« Embaucher »).
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import HireWizard from '../../people/_HireWizard.vue'
import OnboardingReviewDialog from '../../people/_OnboardingReviewDialog.vue'
import HireFinalizeDialog from './_HireFinalizeDialog.vue'

definePage({ meta: { module: 'workforce', permission: 'workforce.manage' } })

const { t, locale } = useI18n()
const route = useRoute()
const { toast } = useAppToast()

const items = ref([])
const loading = ref(true)
const filter = ref('open')
const wizardOpen = ref(route.query.new === '1')
const reviewId = ref(null)
const finalizeFile = ref(null)

const load = async () => {
  loading.value = true
  try { items.value = (await $api('/workforce/onboarding-files')).invitations || [] }
  catch { items.value = [] }
  finally { loading.value = false }
}
onMounted(load)

// ADR-819 — file de suivi calculée par le serveur (`bucket`) : qui doit agir maintenant.
const stage = i => i.bucket || 'waiting_company'
const STAGE_TONE = { waiting_company: 'secondary', waiting_candidate: 'info', to_verify: 'warning', to_finalize: 'primary', done: 'success', abandoned: 'secondary' }
const OPEN = ['waiting_company', 'waiting_candidate', 'to_verify', 'to_finalize']
const counts = computed(() => items.value.reduce((acc, i) => { acc[stage(i)] = (acc[stage(i)] || 0) + 1; return acc }, {}))
// ADR-821 : les dossiers les plus récents d'abord (un dossier qui vient d'arriver ne doit pas être en page 2)
const visible = computed(() => items.value.filter(i => filter.value === 'all'
  || (filter.value === 'open' && OPEN.includes(stage(i)))
  || stage(i) === filter.value).sort((a, b) => b.id - a.id))
const progress = i => Math.round(100 * (i.fields_filled + i.documents_deposited) / Math.max(1, i.fields_total + i.documents_total))

const onSite = async i => {
  try {
    const res = await $api(`/workforce/onboarding-files/${i.id}/on-site`, { method: 'POST' })

    window.open(res.url, '_blank', 'noopener')
    toast(t('hiring.onSiteOpened'), 'success')
    load()
  }
  catch (e) { toast(Object.values(e?.data?.errors || {})[0]?.[0] || e?.data?.message || t('common.error'), 'error') }
}

const headers = computed(() => [
  { title: t('hiring.col.person'), key: 'name' },
  { title: t('hiring.col.position'), key: 'job_role' },
  { title: t('hiring.col.stage'), key: 'stage', sortable: false },
  { title: t('hiring.col.progress'), key: 'progress', sortable: false },
  { title: t('hiring.col.start'), key: 'hire_date' },
  { title: '', key: 'actions', sortable: false, align: 'end' },
])
const FILTERS = ['open', 'waiting_company', 'waiting_candidate', 'to_verify', 'to_finalize', 'done', 'abandoned', 'all']

// Dates dans la langue de l'interface (aucun format nommé n'est déclaré dans l'i18n).
const fmtDate = v => (v ? new Date(v).toLocaleDateString(locale.value) : '')
const fmtDateTime = v => (v ? new Date(v).toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' }) : '')
</script>

<template>
  <div>
    <AppPageHeader
      :title="t('hiring.title')"
      :description="t('hiring.subtitle')"
    >
      <template #actions>
        <VBtn
          color="primary"
          prepend-icon="tabler-user-plus"
          data-test="hiring-new"
          @click="wizardOpen = true"
        >
          {{ t('hiring.hire') }}
        </VBtn>
      </template>
    </AppPageHeader>

    <VChipGroup
      v-model="filter"
      mandatory
      selected-class="text-primary"
      class="mb-4"
    >
      <VChip
        v-for="f in FILTERS"
        :key="f"
        :value="f"
        variant="tonal"
        :data-test="`hiring-filter-${f}`"
      >
        {{ t(`hiring.filter.${f}`) }}<template v-if="f !== 'all' && f !== 'open' && counts[f]">
          · {{ counts[f] }}
        </template>
      </VChip>
    </VChipGroup>

    <VCard>
      <VDataTable
        :headers="headers"
        :items="visible"
        :loading="loading"
        :items-per-page="25"
        data-test="hiring-table"
      >
        <template #item.name="{ item }">
          <RouterLink
            :to="`/company/workforce/hiring/${item.id}`"
            class="font-weight-medium text-high-emphasis"
            :data-test="`hiring-file-link-${item.id}`"
          >
            {{ item.name }}
          </RouterLink>
        </template>
        <template #item.job_role="{ item }">
          {{ item.job_role || '—' }}
        </template>
        <template #item.stage="{ item }">
          <VChip
            size="small"
            variant="tonal"
            :color="STAGE_TONE[stage(item)]"
            :data-test="`hiring-stage-${item.id}`"
          >
            {{ t(`hiring.bucket.${stage(item)}`) }}
          </VChip>
          <div
            v-if="item.documents_blocked"
            class="text-caption text-error mt-1"
          >
            {{ t('hiring.blocked', { n: item.documents_blocked }) }}
          </div>
        </template>
        <template #item.progress="{ item }">
          <div
            class="d-flex align-center gap-2"
            style="min-inline-size: 140px;"
          >
            <VProgressLinear
              :model-value="progress(item)"
              height="6"
              rounded
              :color="progress(item) === 100 ? 'success' : 'primary'"
            />
            <span class="text-caption">{{ progress(item) }} %</span>
          </div>
          <div class="text-caption text-medium-emphasis">
            {{ t('hiring.progressDetail', { f: item.fields_filled, ft: item.fields_total, dd: item.documents_deposited, dt: item.documents_total }) }}
          </div>
        </template>
        <template #item.hire_date="{ item }">
          {{ item.hire_date ? fmtDate(item.hire_date) : '—' }}
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex justify-end gap-1 flex-wrap">
            <VBtn
              v-if="['waiting_company', 'waiting_candidate'].includes(stage(item))"
              size="small"
              variant="text"
              prepend-icon="tabler-device-desktop"
              :data-test="`hiring-onsite-${item.id}`"
              @click="onSite(item)"
            >
              {{ t('hiring.continueOnSite') }}
            </VBtn>
            <VBtn
              v-if="stage(item) === 'to_finalize'"
              size="small"
              color="primary"
              :data-test="`hiring-finalize-${item.id}`"
              @click="finalizeFile = item"
            >
              {{ t('hiring.finalize') }}
            </VBtn>
            <VBtn
              size="small"
              :variant="stage(item) === 'to_verify' ? 'flat' : 'text'"
              :color="stage(item) === 'to_verify' ? 'warning' : undefined"
              :data-test="`hiring-open-${item.id}`"
              :to="stage(item) === 'to_verify' ? undefined : `/company/workforce/hiring/${item.id}`"
              @click="stage(item) === 'to_verify' ? (reviewId = item.id) : null"
            >
              {{ stage(item) === 'to_verify' ? t('hiring.review') : t('hiring.details') }}
            </VBtn>
          </div>
        </template>
        <template #no-data>
          <div class="text-center py-8 text-medium-emphasis">
            <VIcon
              icon="tabler-user-plus"
              size="36"
              class="mb-2"
            />
            <div>{{ t('hiring.empty') }}</div>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <HireWizard
      v-model:is-dialog-visible="wizardOpen"
      @created="load"
    />
    <OnboardingReviewDialog
      :is-dialog-visible="reviewId !== null"
      :invitation-id="reviewId"
      @update:is-dialog-visible="v => { if (!v) reviewId = null }"
      @changed="load"
    />
    <HireFinalizeDialog
      :is-dialog-visible="finalizeFile !== null"
      :file="finalizeFile"
      @update:is-dialog-visible="v => { if (!v) finalizeFile = null }"
      @finalized="load"
    />
  </div>
</template>
