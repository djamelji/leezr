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

const appName = useAppName()
const auth = useAuthStore()

const capabilities = [
  {
    icon: 'tabler-users-group',
    title: 'Manage your clients',
    desc: 'Keep all your contacts, history, and interactions organized in one place.',
  },
  {
    icon: 'tabler-user-shield',
    title: 'Organize your team',
    desc: 'Assign roles, manage access, and keep everyone aligned on what matters.',
  },
  {
    icon: 'tabler-chart-dots-3',
    title: 'Track your operations',
    desc: 'Follow orders, shipments, and tasks from start to finish with full visibility.',
  },
  {
    icon: 'tabler-adjustments-horizontal',
    title: 'Customize your workspace',
    desc: 'Adapt the interface, branding, and features to match how you work.',
  },
  {
    icon: 'tabler-lock',
    title: 'Stay in control',
    desc: 'Secure access, session management, and permissions — you decide who sees what.',
  },
  {
    icon: 'tabler-rocket',
    title: 'Grow with confidence',
    desc: 'Add team members, services, and locations as your business expands.',
  },
]

const industries = [
  { icon: 'tabler-truck-delivery', label: 'Delivery & Logistics' },
  { icon: 'tabler-scissors', label: 'Beauty & Wellness' },
  { icon: 'tabler-shopping-bag', label: 'Retail & Commerce' },
  { icon: 'tabler-heart-handshake', label: 'Associations' },
  { icon: 'tabler-tools', label: 'Service Companies' },
  { icon: 'tabler-building-store', label: 'Growing SMEs' },
]

const reasons = [
  {
    icon: 'tabler-device-desktop',
    title: 'Modern interface',
    desc: 'A clean, intuitive workspace designed for daily use — not a cluttered dashboard.',
  },
  {
    icon: 'tabler-arrows-maximize',
    title: 'Flexible by design',
    desc: 'Activate only what you need. Your workspace adapts to your activity, not the other way around.',
  },
  {
    icon: 'tabler-clock-check',
    title: 'Reliable & secure',
    desc: 'Your data stays protected. Role-based access, encrypted sessions, and full control over your account.',
  },
  {
    icon: 'tabler-trending-up',
    title: 'Designed for growth',
    desc: 'Start small and scale up. Add people, features, and locations without starting over.',
  },
]
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
              Dashboard
            </VBtn>
          </template>
          <template v-else>
            <VBtn
              variant="text"
              to="/login"
              class="d-none d-sm-flex"
            >
              Sign In
            </VBtn>

            <VBtn
              color="primary"
              to="/register"
            >
              Start Free
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
            Manage your business.<br>Simply. Professionally.
          </h1>
          <p class="text-h6 text-medium-emphasis mb-8 mx-auto" style="max-inline-size: 560px;">
            All your clients, team, operations, and growth — in one place.
            The workspace your business deserves.
          </p>
          <div class="d-flex gap-4 justify-center flex-wrap">
            <template v-if="auth.isLoggedIn">
              <VBtn
                color="primary"
                size="large"
                to="/dashboard"
              >
                Go to Dashboard
              </VBtn>
            </template>
            <template v-else>
              <VBtn
                color="primary"
                size="large"
                to="/register"
              >
                Start Free
              </VBtn>
              <VBtn
                variant="outlined"
                size="large"
                to="/login"
              >
                Sign In
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
            All-in-one
          </VChip>
          <h2 class="text-h3 font-weight-bold mb-2">
            Everything you need to run your business
          </h2>
          <p class="text-body-1 text-medium-emphasis mx-auto" style="max-inline-size: 520px;">
            Stop juggling between tools. One workspace to manage your day-to-day, your team, and your growth.
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
            For every industry
          </VChip>
          <h2 class="text-h3 font-weight-bold mb-2">
            Built for real businesses
          </h2>
          <p class="text-body-1 text-medium-emphasis mx-auto" style="max-inline-size: 520px;">
            Whether you run a delivery company, a beauty salon, or a growing team — the workspace adapts to your activity.
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
            Why businesses choose {{ appName }}
          </h2>
        </div>

        <VRow justify="center">
          <VCol
            v-for="(reason, i) in reasons"
            :key="i"
            cols="12"
            sm="6"
            md="3"
          >
            <VCard
              flat
              class="pa-6 h-100 text-center"
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
          Ready to take control of your business?
        </h2>
        <p class="text-h6 text-medium-emphasis mb-8 mx-auto" style="max-inline-size: 480px;">
          Create your workspace in minutes. No credit card required.
        </p>
        <VBtn
          color="primary"
          size="large"
          to="/register"
        >
          Create your workspace
        </VBtn>
      </VContainer>
    </section>

    <!-- ─── Footer ──────────────────────────────────── -->
    <footer class="py-6" :style="{ background: 'rgb(var(--v-theme-surface))' }">
      <VContainer>
        <div class="d-flex flex-wrap justify-center align-center gap-4 text-body-2 text-medium-emphasis">
          <span>&copy; {{ new Date().getFullYear() }} {{ appName }}. All rights reserved.</span>
          <RouterLink
            :to="auth.isLoggedIn ? '/dashboard' : '/login'"
            class="text-primary text-decoration-none"
          >
            {{ auth.isLoggedIn ? 'Dashboard' : 'Sign In' }}
          </RouterLink>
          <span>Privacy</span>
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
