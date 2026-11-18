<script setup>
// ADR-768 (M7 LOT 2) — vue d'ensemble de la fiche unifiée : alertes RH, checklist d'intégration
// (mêmes données que l'ancienne fiche Workforce — read-model EmployeeFileReadModel) et synthèse
// accès / contrat / situation. La rémunération n'apparaît que si le serveur l'a fournie.
import MoneyDisplay from '@/core/components/MoneyDisplay.vue'
import { usePersonStore } from '@/modules/company/people/person.store'
import { useCan } from '@/composables/useCan'
import { displayLocale, formatDate } from '@/utils/datetime'

defineProps({
  accountMeta: { type: Object, required: true },
})

const emit = defineEmits(['go', 'generate-contract', 'manage-access'])

const { t, te, locale } = useI18n()
const store = usePersonStore()
const { can } = useCan()

const emp = computed(() => store.employment)
const contract = computed(() => emp.value?.contract)

const steps = computed(() => {
  const o = emp.value?.onboarding
  if (!o) return []
  const list = [
    { key: 'info', done: !!o.info, can: store.can.edit_hr, action: () => emit('go', 'employment') },
    { key: 'contract', done: !!o.contract, can: store.can.generate_contract, action: () => emit('generate-contract') },
  ]
  if (o.signature_module)
    list.push({ key: 'signature', done: !!o.signed_contract, can: can('workforce_signature.request'), action: () => emit('go', 'documents') })
  list.push({ key: 'account', done: !!o.account, can: store.can.manage_access, action: () => emit('manage-access') })
  if (o.planning_module)
    list.push({ key: 'planning', done: !!o.planning_scheduled, can: store.sections.includes('planning'), action: () => emit('go', 'planning') })

  return list
})
const doneCount = computed(() => steps.value.filter(s => s.done).length)

// ADR-822 — « Au quotidien » : une ligne par fonction RÉELLEMENT disponible (le serveur n'envoie
// planning / time / leave / documents que si le module est actif ET la section autorisée).
const has = key => (store.sections || []).includes(key)
const hm = iso => (iso ? new Date(iso).toLocaleTimeString(displayLocale(), { hour: '2-digit', minute: '2-digit' }) : '')
const daily = computed(() => {
  const e = emp.value
  const rows = []
  if (e?.planning !== undefined && has('planning'))
    rows.push({ key: 'planning', icon: 'tabler-calendar-event', color: 'primary', title: t('personOverview.planning'),
      value: e.planning ? `${formatDate(e.planning.date)} · ${hm(e.planning.start_at)}–${hm(e.planning.end_at)}` : t('personOverview.noShift') })
  if (e?.time !== undefined && has('time'))
    rows.push({ key: 'time', icon: 'tabler-clock', color: 'info', title: t('personOverview.time'),
      value: e.time ? `${formatDate(e.time.date)} · ${t(`workforce.statuses.timeEntry.${e.time.status}`, e.time.status)}` : t('personOverview.noClock') })
  if (e?.leave && has('leave')) {
    const b = e.leave.balances?.[0]
    rows.push({ key: 'leave', icon: 'tabler-beach', color: 'success', title: t('personOverview.leave'),
      value: [b ? t('personOverview.balance', { type: b.type, days: (b.available_hundredths / 100).toLocaleString() }) : null,
        e.leave.pending_requests ? t('personOverview.pending', { count: e.leave.pending_requests }) : null].filter(Boolean).join(' · ') || t('personOverview.noLeave'),
      badge: e.leave.pending_requests || 0 })
  }
  if (e?.documents && has('documents'))
    rows.push({ key: 'documents', icon: 'tabler-file-certificate', color: 'warning', title: t('personOverview.documents'), value: t('personOverview.docCount', { count: e.documents.count }) })

  return rows
})
const contractStatus = st => (te('workforce.statuses.contract.' + st) ? t('workforce.statuses.contract.' + st) : st)
</script>

<template>
  <div>
    <VAlert
      v-for="(a, i) in emp?.alerts || []"
      :key="i"
      :type="a.level"
      variant="tonal"
      density="compact"
      class="mb-2"
    >
      {{ t('employeeFile.alerts.' + a.key, { count: a.count || 0 }) }}
    </VAlert>

    <VCard
      v-if="steps.length && doneCount < steps.length"
      variant="outlined"
      class="mb-4 border-s-lg border-primary"
      data-test="person-onboarding"
    >
      <VCardText>
        <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
          <div class="text-subtitle-1 font-weight-medium">
            <VIcon
              icon="tabler-checklist"
              start
              size="20"
            />
            {{ t('onboarding.title') }}
          </div>
          <span class="text-caption">{{ doneCount }} / {{ steps.length }}</span>
        </div>
        <VList class="bg-transparent py-0">
          <VListItem
            v-for="step in steps"
            :key="step.key"
            class="px-0"
          >
            <template #prepend>
              <VIcon
                :icon="step.done ? 'tabler-circle-check-filled' : 'tabler-circle'"
                :color="step.done ? 'success' : 'disabled'"
                class="me-3"
              />
            </template>
            <VListItemTitle :class="step.done ? 'text-medium-emphasis' : 'font-weight-medium'">
              {{ t('onboarding.steps.' + step.key) }}
            </VListItemTitle>
            <template #append>
              <VChip
                v-if="step.done"
                size="small"
                color="success"
                variant="tonal"
                label
              >
                {{ t('onboarding.ok') }}
              </VChip>
              <VBtn
                v-else-if="step.can"
                size="small"
                variant="tonal"
                @click="step.action"
              >
                {{ t('onboarding.do') }}
              </VBtn>
            </template>
          </VListItem>
        </VList>
      </VCardText>
    </VCard>

    <VRow>
      <!-- Emploi : poste métier, rattachement, contrat (les droits logiciels sont dans « Accès ») -->
      <VCol
        cols="12"
        md="7"
      >
        <VCard
          v-if="emp"
          variant="outlined"
          data-test="person-overview-employment"
        >
          <VCardItem>
            <VCardTitle>{{ t('personOverview.employment') }}</VCardTitle>
            <template #append>
              <VBtn
                v-if="has('employment')"
                variant="text"
                size="small"
                @click="emit('go', 'employment')"
              >
                {{ t('personOverview.seeAll') }}
              </VBtn>
            </template>
          </VCardItem>
          <VList
            density="compact"
            class="pt-0"
          >
            <VListItem prepend-icon="tabler-briefcase">
              <VListItemSubtitle>{{ t('personOverview.position') }}</VListItemSubtitle>
              <VListItemTitle>{{ emp.position?.job_role || t('personOverview.notSet') }}</VListItemTitle>
            </VListItem>
            <VListItem prepend-icon="tabler-sitemap">
              <VListItemSubtitle>{{ t('personOverview.department') }}</VListItemSubtitle>
              <VListItemTitle>
                {{ emp.position?.department || t('personOverview.notSet') }}<span
                  v-if="emp.position?.manager"
                  class="text-medium-emphasis"
                > · {{ t('personOverview.manager', { name: emp.position.manager }) }}</span>
              </VListItemTitle>
            </VListItem>
            <VListItem prepend-icon="tabler-file-text">
              <VListItemSubtitle>{{ t('employeeFile.tabs.contract') }}</VListItemSubtitle>
              <VListItemTitle v-if="contract">
                {{ contract.contract_type?.toUpperCase() }} · {{ contract.weekly_hours }} h / {{ t('personOverview.week') }}
                <span v-if="contract.start_date"> · {{ t('personOverview.since', { date: formatDate(contract.start_date) }) }}</span>
              </VListItemTitle>
              <VListItemTitle
                v-else
                class="text-warning"
              >
                {{ t('employeeFile.noContract') }}
              </VListItemTitle>
              <template
                v-if="contract"
                #append
              >
                <VChip
                  size="small"
                  label
                  :color="contract.status === 'active' ? 'success' : 'secondary'"
                >
                  {{ contractStatus(contract.status) }}
                </VChip>
              </template>
            </VListItem>
            <VListItem
              v-if="contract?.compensation && store.can.view_compensation"
              prepend-icon="tabler-coins"
              data-test="person-overview-salary"
            >
              <VListItemSubtitle>{{ t('employeeFile.salary') }}</VListItemSubtitle>
              <VListItemTitle class="d-flex align-center gap-1">
                <MoneyDisplay
                  :amount="contract.compensation.base_salary_cents"
                  :currency="contract.compensation.currency || 'EUR'"
                  color="high-emphasis"
                />
                <span class="text-medium-emphasis">/ {{ te('employees.payFrequency.' + contract.compensation.pay_frequency) ? t('employees.payFrequency.' + contract.compensation.pay_frequency) : contract.compensation.pay_frequency }}</span>
              </VListItemTitle>
            </VListItem>
          </VList>
        </VCard>

        <VCard
          v-else-if="store.can.create_hr_record"
          variant="outlined"
          data-test="person-overview-no-hr"
        >
          <VCardItem
            :title="t('people.hrLink.noRecordTitle')"
            :subtitle="t('people.hrLink.noRecordHint')"
          />
          <VCardActions class="px-6 pb-4">
            <VBtn
              variant="tonal"
              prepend-icon="tabler-id-badge-2"
              @click="emit('go', 'create-hr')"
            >
              {{ t('people.hrLink.createTitle') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>

      <!-- Au quotidien + accès logiciel -->
      <VCol
        cols="12"
        md="5"
      >
        <VCard
          variant="outlined"
          data-test="person-overview-daily"
        >
          <VCardItem :title="t('personOverview.daily')" />
          <VList class="pt-0">
            <VListItem
              v-for="row in daily"
              :key="row.key"
              :data-test="`person-overview-${row.key}`"
              @click="emit('go', row.key)"
            >
              <template #prepend>
                <VAvatar
                  :color="row.color"
                  variant="tonal"
                  rounded
                  size="36"
                  class="me-1"
                >
                  <VIcon
                    :icon="row.icon"
                    size="20"
                  />
                </VAvatar>
              </template>
              <VListItemTitle>{{ row.title }}</VListItemTitle>
              <VListItemSubtitle>{{ row.value }}</VListItemSubtitle>
              <template #append>
                <VBadge
                  v-if="row.badge"
                  :content="row.badge"
                  color="warning"
                  inline
                />
                <VIcon
                  icon="tabler-chevron-right"
                  size="18"
                  class="text-disabled"
                />
              </template>
            </VListItem>
            <VListItem
              :data-test="'person-overview-access'"
              @click="has('access') ? emit('go', 'access') : null"
            >
              <template #prepend>
                <VAvatar
                  :color="accountMeta.color"
                  variant="tonal"
                  rounded
                  size="36"
                  class="me-1"
                >
                  <VIcon
                    icon="tabler-key"
                    size="20"
                  />
                </VAvatar>
              </template>
              <VListItemTitle>{{ t('people.tabs.access') }}</VListItemTitle>
              <VListItemSubtitle>{{ [accountMeta.label, store.account.role?.name].filter(Boolean).join(' · ') }}</VListItemSubtitle>
              <template
                v-if="has('access')"
                #append
              >
                <VIcon
                  icon="tabler-chevron-right"
                  size="18"
                  class="text-disabled"
                />
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
