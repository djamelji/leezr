<script setup>
import NavbarThemeSwitcher from '@/layouts/components/NavbarThemeSwitcher.vue'
import { useAppName } from '@/composables/useAppName'
import { useAuthStore } from '@/core/stores/auth'

definePage({
  meta: {
    layout: 'blank',
    public: true,
  },
})

usePublicTheme()

const { t } = useI18n()
const appName = useAppName()
const auth = useAuthStore()

const capabilities = computed(() => [
  {
    icon: 'tabler-users-group',
    title: t('landing.manageClients'),
    desc: t('landing.manageClientsDesc'),
  },
  {
    icon: 'tabler-user-shield',
    title: t('landing.organizeTeam'),
    desc: t('landing.organizeTeamDesc'),
  },
  {
    icon: 'tabler-chart-dots-3',
    title: t('landing.trackOperations'),
    desc: t('landing.trackOperationsDesc'),
  },
  {
    icon: 'tabler-adjustments-horizontal',
    title: t('landing.customizeWorkspace'),
    desc: t('landing.customizeWorkspaceDesc'),
  },
  {
    icon: 'tabler-lock',
    title: t('landing.stayInControl'),
    desc: t('landing.stayInControlDesc'),
  },
  {
    icon: 'tabler-rocket',
    title: t('landing.growWithConfidence'),
    desc: t('landing.growWithConfidenceDesc'),
  },
])

const industries = computed(() => [
  { icon: 'tabler-truck-delivery', label: t('landing.deliveryLogistics') },
  { icon: 'tabler-scissors', label: t('landing.beautyWellness') },
  { icon: 'tabler-shopping-bag', label: t('landing.retailCommerce') },
  { icon: 'tabler-heart-handshake', label: t('landing.associations') },
  { icon: 'tabler-tools', label: t('landing.serviceCompanies') },
  { icon: 'tabler-building-store', label: t('landing.growingSMEs') },
])

const reasons = computed(() => [
  {
    icon: 'tabler-device-desktop',
    title: t('landing.modernInterface'),
    desc: t('landing.modernInterfaceDesc'),
  },
  {
    icon: 'tabler-arrows-maximize',
    title: t('landing.flexibleByDesign'),
    desc: t('landing.flexibleByDesignDesc'),
  },
  {
    icon: 'tabler-clock-check',
    title: t('landing.reliableSecure'),
    desc: t('landing.reliableSecureDesc'),
  },
  {
    icon: 'tabler-trending-up',
    title: t('landing.designedForGrowth'),
    desc: t('landing.designedForGrowthDesc'),
  },
])
</script>

<template>
  <div class="landing-page">
    <!-- ─── Navbar ──────────────────────────────────── -->
    <VAppBar
      flat
      class="landing-navbar"
      :style="{ background: 'rgba(var(--v-theme-surface), 0.9)', backdropFilter: 'blur(8px)' }"
    >
      <VContainer class="d-flex align-center">
        <RouterLink
          to="/"
          class="d-flex align-center text-decoration-none"
        >
          <BrandLogo size="md" />
        </RouterLink>

        <VSpacer />

        <div class="d-flex align-center gap-2">
          <NavbarThemeSwitcher />

          <template v-if="auth.isLoggedIn">
            <VBtn
              color="primary"
              to="/dashboard"
            >
              {{ t('Dashboard') }}
            </VBtn>
          </template>
          <template v-else>
            <VBtn
              variant="text"
              to="/login"
              class="d-none d-sm-flex"
            >
              {{ t('landing.signIn') }}
            </VBtn>

            <VBtn
              color="primary"
              to="/register"
            >
              {{ t('landing.startFree') }}
            </VBtn>
          </template>
        </div>
      </VContainer>
    </VAppBar>

    <!-- ─── Hero ────────────────────────────────────── -->
    <section class="hero-section">
      <VContainer>
        <div class="hero-content text-center mx-auto">
          <h1 class="hero-title mb-6">
            {{ t('landing.heroTitle') }}
          </h1>
          <p class="text-h6 text-medium-emphasis mb-8 mx-auto" style="max-inline-size: 560px;">
            {{ t('landing.heroSubtitle') }}
          </p>
          <div class="d-flex gap-4 justify-center flex-wrap">
            <template v-if="auth.isLoggedIn">
              <VBtn
                color="primary"
                size="large"
                to="/dashboard"
              >
                {{ t('landing.goToDashboard') }}
              </VBtn>
            </template>
            <template v-else>
              <VBtn
                color="primary"
                size="large"
                to="/register"
              >
                {{ t('landing.startFree') }}
              </VBtn>
              <VBtn
                variant="outlined"
                size="large"
                to="/login"
              >
                {{ t('landing.signIn') }}
              </VBtn>
            </template>
          </div>
        </div>
      </VContainer>
    </section>

    <!-- ─── What You Can Do ─────────────────────────── -->
    <section class="py-16 bg-surface">
      <VContainer>
        <div class="text-center mb-12">
          <VChip
            label
            color="primary"
            size="small"
            class="mb-4"
          >
            {{ t('landing.allInOne') }}
          </VChip>
          <h2 class="text-h3 font-weight-bold mb-2">
            {{ t('landing.everythingYouNeed') }}
          </h2>
          <p class="text-body-1 text-medium-emphasis mx-auto" style="max-inline-size: 520px;">
            {{ t('landing.stopJuggling') }}
          </p>
        </div>

        <VRow>
          <VCol
            v-for="(item, i) in capabilities"
            :key="i"
            cols="12"
            sm="6"
            md="4"
          >
            <div class="d-flex flex-column align-center text-center pa-6 h-100">
              <VAvatar
                variant="tonal"
                color="primary"
                size="64"
                class="mb-4"
              >
                <VIcon
                  :icon="item.icon"
                  size="32"
                />
              </VAvatar>
              <h5 class="text-h5 font-weight-bold mb-2">
                {{ item.title }}
              </h5>
              <p class="text-body-1 text-medium-emphasis mb-0" style="max-inline-size: 310px;">
                {{ item.desc }}
              </p>
            </div>
          </VCol>
        </VRow>
      </VContainer>
    </section>

    <!-- ─── Built for Real Businesses ───────────────── -->
    <section class="py-16">
      <VContainer>
        <div class="text-center mb-12">
          <VChip
            label
            color="primary"
            size="small"
            class="mb-4"
          >
            {{ t('landing.forEveryIndustry') }}
          </VChip>
          <h2 class="text-h3 font-weight-bold mb-2">
            {{ t('landing.builtForRealBusinesses') }}
          </h2>
          <p class="text-body-1 text-medium-emphasis mx-auto" style="max-inline-size: 520px;">
            {{ t('landing.builtForRealDesc') }}
          </p>
        </div>

        <VRow justify="center">
          <VCol
            v-for="(industry, i) in industries"
            :key="i"
            cols="6"
            sm="4"
            md="2"
          >
            <div class="d-flex flex-column align-center text-center pa-4">
              <VAvatar
                variant="tonal"
                color="primary"
                size="56"
                class="mb-3"
              >
                <VIcon
                  :icon="industry.icon"
                  size="28"
                />
              </VAvatar>
              <span class="text-body-2 font-weight-medium">
                {{ industry.label }}
              </span>
            </div>
          </VCol>
        </VRow>
      </VContainer>
    </section>

    <!-- ─── Why Choose Us ───────────────────────────── -->
    <section class="py-16 bg-surface">
      <VContainer>
        <div class="text-center mb-12">
          <h2 class="text-h3 font-weight-bold">
            {{ t('landing.whyChoose', { app: appName }) }}
          </h2>
        </div>

        <VRow
          justify="center"
          class="card-grid card-grid-sm"
        >
          <VCol
            v-for="(reason, i) in reasons"
            :key="i"
            cols="12"
            sm="6"
            md="3"
          >
            <VCard
              flat
              class="pa-6 text-center"
              :style="{ border: '1px solid rgba(var(--v-border-color), var(--v-border-opacity))' }"
            >
              <VIcon
                :icon="reason.icon"
                color="primary"
                size="40"
                class="mb-4"
              />
              <h6 class="text-h6 font-weight-bold mb-2">
                {{ reason.title }}
              </h6>
              <p class="text-body-2 text-medium-emphasis mb-0">
                {{ reason.desc }}
              </p>
            </VCard>
          </VCol>
        </VRow>
      </VContainer>
    </section>

    <!-- ─── Final CTA ───────────────────────────────── -->
    <section
      class="py-16"
      :class="$vuetify.theme.current.dark ? 'cta-bg-dark' : 'cta-bg-light'"
    >
      <VContainer class="text-center">
        <h2 class="text-h3 font-weight-bold text-primary mb-2">
          {{ t('landing.readyToTakeControl') }}
        </h2>
        <p class="text-h6 text-medium-emphasis mb-8 mx-auto" style="max-inline-size: 480px;">
          {{ t('landing.createInMinutes') }}
        </p>
        <VBtn
          color="primary"
          size="large"
          to="/register"
        >
          {{ t('landing.createYourWorkspace') }}
        </VBtn>
      </VContainer>
    </section>

    <!-- ─── Footer ──────────────────────────────────── -->
    <footer class="py-6" :style="{ background: 'rgb(var(--v-theme-surface))' }">
      <VContainer>
        <div class="d-flex flex-wrap justify-center align-center gap-4 text-body-2 text-medium-emphasis">
          <span>&copy; {{ new Date().getFullYear() }} {{ appName }}. {{ t('landing.allRightsReserved') }}</span>
          <RouterLink
            :to="auth.isLoggedIn ? '/dashboard' : '/login'"
            class="text-primary text-decoration-none"
          >
            {{ auth.isLoggedIn ? t('Dashboard') : t('landing.signIn') }}
          </RouterLink>
          <span>{{ t('landing.privacy') }}</span>
        </div>
      </VContainer>
    </footer>
  </div>
</template>

<style lang="scss" scoped>
.landing-page {
  background: rgb(var(--v-theme-background));
  color: rgb(var(--v-theme-on-background));
}

.landing-navbar {
  border-block-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.hero-section {
  background: linear-gradient(
    135deg,
    rgba(var(--v-theme-primary), 0.1),
    rgba(var(--v-theme-primary), 0.02)
  );
  padding-block: calc(64px + 4rem) 4rem;
}

.hero-content {
  max-inline-size: 700px;
}

.hero-title {
  font-size: clamp(2rem, 5vw, 3.25rem);
  font-weight: 800;
  line-height: 1.2;
}

.cta-bg-light {
  background: linear-gradient(
    135deg,
    rgba(var(--v-theme-primary), 0.06),
    rgba(var(--v-theme-primary), 0.02)
  );
}

.cta-bg-dark {
  background: linear-gradient(
    135deg,
    rgba(var(--v-theme-primary), 0.12),
    rgba(var(--v-theme-primary), 0.04)
  );
}
</style>
