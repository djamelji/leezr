<script setup>
// ADR-858 (Leezr 5.0 L5) — quick view of a person (V22 side panel). Reads the SAME person file the full page reads
// (`GET /company/people/{ref}`, facets filtered server-side) and shows only the situation : position and reporting
// line, access, contract basics, onboarding steps, what is missing. Never the compensation, never a sensitive field :
// an overview must not widen what a manager sees. Actions = the server's `can` flags only. Sub-component.
import { $api } from '@/utils/api'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  row: { type: Object, default: null }, // the list row (name, completeness, next action) — shown at once
})

const emit = defineEmits(['update:modelValue'])
const { t, te } = useI18n()

const file = ref(null)
const state = ref('idle') // idle | loading | ready | error

const load = async () => {
  if (!props.row?.person_ref) return
  state.value = 'loading'
  file.value = null
  try {
    file.value = await $api(`/company/people/${props.row.person_ref}`)
    state.value = 'ready'
  }
  catch {
    state.value = 'error'
  }
}

watch(() => [props.modelValue, props.row?.person_ref], ([open]) => { if (open) load() }, { immediate: true })

const close = () => emit('update:modelValue', false)
const ref_ = computed(() => props.row?.person_ref)
const fileTo = tab => (tab ? `/company/people/${ref_.value}/${tab}` : `/company/people/${ref_.value}`)
const initials = name => (name || '?').split(' ').map(p => p.charAt(0)).slice(0, 2).join('').toUpperCase()
const name = computed(() => file.value?.identity?.display_name || props.row?.name || '')
const emp = computed(() => file.value?.employment || null)
const pos = computed(() => emp.value?.position || null)
const contract = computed(() => emp.value?.contract || null) // compensation deliberately ignored
const account = computed(() => file.value?.account || null)
const can = computed(() => file.value?.can || {})
const sections = computed(() => file.value?.available_sections || [])
const subtitle = computed(() => [pos.value?.job_role || props.row?.job_role, pos.value?.department || props.row?.department].filter(Boolean).join(' · '))

const EMP_TONE = { active: 'success', preparatory: 'info', on_leave: 'info', suspended: 'warning', terminated: 'secondary', inactive: 'secondary' }
const ACCESS_TONE = { connected: 'success', active: 'success', invited: 'warning', pending_acceptance: 'warning', invitation_expired: 'secondary', suspended: 'error', none: 'secondary' }
const contractType = c => (te(`employees.contractTypes.${c}`) ? t(`employees.contractTypes.${c}`) : c)
const fmtDate = d => (d ? new Date(d).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

// onboarding steps from the server's own checklist ; a step of an inactive module is not shown
const steps = computed(() => {
  const o = emp.value?.onboarding
  if (!o) return []

  return [
    { key: 'info', done: o.info, show: true },
    { key: 'contract', done: o.contract, show: true },
    { key: 'signed_contract', done: o.signed_contract, show: o.signature_module },
    { key: 'account', done: o.account, show: true },
    { key: 'planning', done: o.planning_scheduled, show: o.planning_module },
  ].filter(s => s.show)
})

const stepsDone = computed(() => steps.value.filter(s => s.done).length)
const completeness = computed(() => props.row?.completeness || null)
const missing = computed(() => (completeness.value ? completeness.value.total - completeness.value.filled : null))

// authorised actions only — each opens the right tab of the full file
const actions = computed(() => {
  const c = can.value
  const out = []
  if (c.edit_hr && sections.value.includes('employment')) out.push({ key: 'employment', icon: 'tabler-briefcase', label: 'l5.preview.actions.employment', to: fileTo('employment') })
  if ((c.manage_access || c.invite) && sections.value.includes('access')) out.push({ key: 'access', icon: 'tabler-key', label: account.value?.has_account ? 'l5.preview.actions.access' : 'l5.preview.actions.invite', to: fileTo('access') })
  if (sections.value.includes('documents')) out.push({ key: 'documents', icon: 'tabler-files', label: 'l5.preview.actions.documents', to: fileTo('documents') })
  if (missing.value) out.push({ key: 'personal', icon: 'tabler-forms', label: 'l5.preview.actions.complete', to: fileTo('personal') })

  return out
})
</script>

<template>
  <VNavigationDrawer
    :model-value="modelValue"
    temporary
    location="end"
    width="440"
    class="lz-preview"
    :aria-label="t('l5.preview.title', { name })"
    data-test="person-preview"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div class="lz-preview__hero">
      <IconBtn
        class="lz-preview__close"
        :aria-label="t('common.close')"
        @click="close"
      >
        <VIcon icon="tabler-x" />
      </IconBtn>
      <div class="lz-preview__avatar">
        <VImg
          v-if="file?.identity?.avatar"
          :src="file.identity.avatar"
          cover
        />
        <span v-else>{{ initials(name) }}</span>
      </div>
      <div class="lz-preview__name">
        {{ name }}
      </div>
      <div
        v-if="subtitle"
        class="lz-preview__sub"
      >
        {{ subtitle }}
      </div>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <VChip
          v-if="row?.employment_status"
          size="small"
          variant="flat"
          class="lz-preview__chip"
        >
          <span
            class="lz-dot me-2"
            :class="`bg-${EMP_TONE[row.employment_status] || 'secondary'}`"
          />{{ t('people.empStatus.' + row.employment_status, row.employment_status) }}
        </VChip>
        <VChip
          v-if="account && account.state && file?.can?.view_access"
          size="small"
          variant="flat"
          class="lz-preview__chip"
        >
          <span
            class="lz-dot me-2"
            :class="`bg-${ACCESS_TONE[account.state] || 'secondary'}`"
          />{{ t(`l5.access.${account.state}`, account.state) }}
        </VChip>
      </div>
    </div>

    <div
      v-if="state === 'loading'"
      class="pa-6"
    >
      <VSkeletonLoader type="list-item-two-line@4" />
    </div>
    <div
      v-else-if="state === 'error'"
      class="pa-6"
    >
      <VAlert
        type="warning"
        variant="tonal"
      >
        {{ t('l5.preview.error') }}
        <template #append>
          <VBtn
            size="small"
            variant="text"
            @click="load"
          >
            {{ t('common.retry') }}
          </VBtn>
        </template>
      </VAlert>
    </div>

    <div
      v-else-if="state === 'ready'"
      class="lz-preview__body lzr-stagger"
    >
      <!-- next step -->
      <section
        v-if="row?.next_action"
        class="lz-next lzr-enter"
        data-test="person-preview-next"
      >
        <VIcon
          icon="tabler-bolt"
          size="20"
        />
        <div class="flex-grow-1 min-w-0">
          <div class="text-caption text-medium-emphasis">
            {{ t('l5.preview.next') }}
          </div>
          <div class="font-weight-medium">
            {{ t(`l5.next.${row.next_action}`) }}
          </div>
        </div>
      </section>

      <!-- position & reporting line -->
      <section
        v-if="pos"
        class="lzr-enter"
      >
        <h3 class="lz-h">
          {{ t('l5.preview.position') }}
        </h3>
        <div class="lz-kv">
          <span>{{ t('l5.preview.jobRole') }}</span><strong>{{ pos.job_role || t('l5.preview.none') }}</strong>
          <span>{{ t('l5.preview.department') }}</span><strong>{{ pos.department || t('l5.preview.none') }}</strong>
          <span>{{ t('l5.preview.manager') }}</span><strong>{{ pos.manager || t('l5.preview.none') }}</strong>
          <template v-if="pos.establishment">
            <span>{{ t('l5.preview.establishment') }}</span><strong>{{ pos.establishment }}</strong>
          </template>
        </div>
      </section>

      <!-- contract (basics only — never the pay) -->
      <section
        v-if="contract"
        class="lzr-enter"
      >
        <h3 class="lz-h">
          {{ t('l5.preview.contract') }}
        </h3>
        <div class="lz-kv">
          <span>{{ t('l5.preview.contractType') }}</span><strong>{{ contract.contract_type ? contractType(contract.contract_type) : '—' }}</strong>
          <span>{{ t('l5.preview.since') }}</span><strong>{{ fmtDate(contract.start_date) }}</strong>
          <span>{{ t('l5.preview.contractStatus') }}</span><strong>{{ te(`l5.contractStatus.${contract.status}`) ? t(`l5.contractStatus.${contract.status}`) : (contract.status || '—') }}</strong>
        </div>
      </section>

      <!-- onboarding -->
      <section
        v-if="steps.length"
        class="lzr-enter"
        data-test="person-preview-onboarding"
      >
        <h3 class="lz-h d-flex align-center">
          {{ t('l5.preview.onboarding') }}
          <VSpacer />
          <span class="text-caption text-medium-emphasis">{{ stepsDone }}/{{ steps.length }}</span>
        </h3>
        <VProgressLinear
          :model-value="(stepsDone / steps.length) * 100"
          :color="stepsDone === steps.length ? 'success' : 'primary'"
          rounded
          height="6"
          class="mb-3"
        />
        <ul class="lz-steps">
          <li
            v-for="s in steps"
            :key="s.key"
            :class="{ 'lz-steps--done': s.done }"
          >
            <VIcon
              :icon="s.done ? 'tabler-circle-check-filled' : 'tabler-circle-dashed'"
              :color="s.done ? 'success' : undefined"
              size="18"
            />
            {{ t(`l5.step.${s.key}`) }}
          </li>
        </ul>
      </section>

      <!-- what is missing (completeness is an access-administration figure : only when the list served it) -->
      <section
        v-if="completeness && completeness.total"
        class="lzr-enter"
        data-test="person-preview-missing"
      >
        <h3 class="lz-h">
          {{ t('l5.preview.file') }}
        </h3>
        <div class="d-flex align-center gap-3">
          <VProgressCircular
            :model-value="(completeness.filled / completeness.total) * 100"
            :color="completeness.complete ? 'success' : 'warning'"
            size="44"
            width="5"
          >
            <span class="text-caption font-weight-bold">{{ completeness.filled }}/{{ completeness.total }}</span>
          </VProgressCircular>
          <div class="text-body-2">
            {{ completeness.complete ? t('l5.preview.fileComplete') : t('l5.preview.fileMissing', { n: missing }, missing) }}
          </div>
        </div>
      </section>
    </div>
    <!-- actions : always visible footer (ADR-858 fix — the body scrolls, the actions never leave the screen) -->
    <footer
    v-if="state === 'ready'"
    class="lz-preview__actions"
  >
      <VBtn
        color="primary"
        block
        :to="fileTo()"
        prepend-icon="tabler-id"
        data-test="person-preview-open"
      >
        {{ t('l5.preview.openFile') }}
      </VBtn>
      <div class="lz-preview__grid">
        <VBtn
          v-for="a in actions"
          :key="a.key"
          variant="tonal"
          :to="a.to"
          :prepend-icon="a.icon"
          :data-test="`person-preview-action-${a.key}`"
        >
          {{ t(a.label) }}
        </VBtn>
      </div>
    </footer>
  </VNavigationDrawer>
</template>

<style scoped>
.lz-preview :deep(.v-navigation-drawer__content) { display: flex; flex-direction: column; overflow: hidden !important; }
.lz-preview__hero { flex: 0 0 auto; }
.lz-preview__hero {
  position: relative; padding: 2rem 1.5rem 1.5rem; color: #fff;
  background: radial-gradient(120% 140% at 100% 0%, rgba(255, 255, 255, .16) 0%, transparent 55%),
    linear-gradient(135deg, rgb(var(--v-theme-primary)), color-mix(in srgb, rgb(var(--v-theme-primary)) 60%, #0b1530));
}
.lz-preview__close { position: absolute; inset-block-start: .75rem; inset-inline-end: .75rem; color: #fff !important; }
.lz-preview__avatar {
  inline-size: 64px; block-size: 64px; border-radius: 50%; overflow: hidden; background: #fff; color: rgb(var(--v-theme-primary));
  display: grid; place-items: center; font-size: 1.375rem; font-weight: 700; box-shadow: 0 6px 18px -8px rgba(0, 0, 0, .45);
}
.lz-preview__name { font-size: 1.375rem; font-weight: 700; letter-spacing: -.02em; margin-block-start: .875rem; line-height: 1.2; }
.lz-preview__sub { color: rgba(255, 255, 255, .82); font-size: .875rem; margin-block-start: .25rem; }
.lz-preview__chip { background: rgba(255, 255, 255, .16) !important; color: #fff !important; }
.lz-dot { display: inline-block; inline-size: 8px; block-size: 8px; border-radius: 50%; }
.lz-preview__body { flex: 1 1 auto; min-block-size: 0; overflow-y: auto; overscroll-behavior: contain; padding: 1.25rem 1.5rem 1.5rem; display: flex; flex-direction: column; gap: 1.375rem; }
.lz-h { font-size: .75rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: rgba(var(--v-theme-on-surface), .55); margin-block-end: .625rem; }
.lz-kv { display: grid; grid-template-columns: auto 1fr; gap: .5rem 1rem; align-items: baseline; font-size: .875rem; }
.lz-kv > span { color: rgba(var(--v-theme-on-surface), .6); }
.lz-kv > strong { justify-self: end; font-weight: 500; text-align: end; min-inline-size: 0; overflow-wrap: anywhere; }
.lz-next { display: flex; align-items: center; gap: .75rem; padding: .75rem .875rem; border-radius: 12px; color: rgb(var(--v-theme-warning)); background: rgba(var(--v-theme-warning), .1); }
.lz-next .font-weight-medium { color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.lz-steps { list-style: none; padding: 0; margin: 0; display: grid; gap: .5rem; font-size: .875rem; }
.lz-steps li { display: flex; align-items: center; gap: .5rem; }
.lz-steps li:not(.lz-steps--done) { color: rgba(var(--v-theme-on-surface), .75); }
.lz-preview__actions { flex: 0 0 auto; display: grid; gap: .625rem; padding: .875rem 1.5rem 1rem; border-block-start: 1px solid rgba(var(--v-border-color), .12); background: rgb(var(--v-theme-surface)); }
.lz-preview__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: .5rem; }
@media (max-height: 760px) { .lz-preview__hero { padding-block: 1.25rem 1rem; } .lz-preview__avatar { inline-size: 52px; block-size: 52px; font-size: 1.125rem; } }
</style>
