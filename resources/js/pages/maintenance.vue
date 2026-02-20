<script setup>
import '@lottiefiles/lottie-player'

definePage({
  meta: {
    layout: 'blank',
    public: true,
  },
})

import { useAppName, setAppName } from '@/composables/useAppName'

usePublicTheme()

const appName = useAppName()
const { bindPlayer } = useMaintenanceTheme()

const email = ref('')
const hpField = ref('')
const submitted = ref(false)
const submitting = ref(false)
const loading = ref(true)

const ucPlayer = ref(null)
const arrowPlayer = ref(null)

// Bind animated players — recolors reactively on theme change
bindPlayer(ucPlayer, 'under-construction')
bindPlayer(arrowPlayer, 'arrow')

const pageData = reactive({
  headline: "We'll be back soon!",
  subheadline: "We're working hard to improve the experience.",
  description: 'Our website is currently undergoing scheduled maintenance.',
  cta_text: 'Notify Me',
  list_slug: 'maintenance',
})

onMounted(async () => {
  try {
    const res = await fetch('/api/audience/maintenance-page')

    if (res.ok) {
      const data = await res.json()

      if (data.app_name) setAppName(data.app_name)
      pageData.headline = data.headline || pageData.headline
      pageData.subheadline = data.subheadline ?? ''
      pageData.description = data.description ?? ''
      pageData.cta_text = data.cta_text || pageData.cta_text
      pageData.list_slug = data.list_slug || pageData.list_slug
    }
  }
  catch {
    // Use defaults
  }
  finally {
    loading.value = false
  }
})

async function handleSubscribe() {
  if (!email.value || submitting.value)
    return

  submitting.value = true

  try {
    await fetch('/api/audience/subscribe', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        list_slug: pageData.list_slug,
        email: email.value,
        hp_field: hpField.value,
      }),
    })

    submitted.value = true
  }
  catch {
    // Silently handle
  }
  finally {
    submitting.value = false
  }
}
</script>

<template>
  <!-- Outer wrapper replaces body { display: flex; min-height: 100vh; } -->
  <div class="page">
    <div class="grid">
      <div class="left">
        <p class="brand">
          {{ appName.toLowerCase() }}<span class="dot">.</span>
        </p>

        <h1 class="title">
          {{ pageData.headline }}
        </h1>
        <p
          v-if="pageData.subheadline"
          class="subtitle"
        >
          {{ pageData.subheadline }}
        </p>
        <p
          v-if="pageData.description"
          class="description"
        >
          {{ pageData.description }}
        </p>

        <form
          v-if="!submitted"
          @submit.prevent="handleSubscribe"
        >
          <!-- Honeypot -->
          <input
            v-model="hpField"
            type="text"
            name="hp_field"
            style="position: absolute; left: -9999px; opacity: 0;"
            tabindex="-1"
            autocomplete="off"
          >
          <input
            v-model="email"
            type="email"
            placeholder="Enter your email"
            required
          >
          <button
            type="submit"
            :disabled="submitting"
          >
            {{ pageData.cta_text }}
          </button>
          <lottie-player
            ref="arrowPlayer"
            loop
            autoplay
            class="arrow"
          />
        </form>

        <div
          v-else
          class="success"
        >
          <VIcon
            icon="tabler-circle-check"
            size="28"
            color="success"
          />
          <span>You're on the list. We'll notify you when we're back online.</span>
        </div>
      </div>

      <div>
        <lottie-player
          ref="ucPlayer"
          loop
          autoplay
          class="under-construction"
        />
      </div>
    </div>
  </div>
</template>

<style lang="scss" scoped>
/* RESET STYLES
–––––––––––––––––––––––––––––––––––––––––––––––––– */
* {
  padding: 0;
  margin: 0;
  box-sizing: border-box;
  font-weight: normal;
}

button {
  cursor: pointer;
}

button,
input {
  font-family: inherit;
  font-size: 100%;
  border: none;
}

/* BODY REPLACEMENT — wraps .grid like CodePen body
–––––––––––––––––––––––––––––––––––––––––––––––––– */
.page {
  display: flex;
  min-height: 100vh;
  font-size: clamp(16px, 2.5vw, 24px);
  line-height: 1.2;
  background: rgb(var(--v-theme-background));
  color: rgb(var(--v-theme-on-background));
}

/* MAIN STYLES
–––––––––––––––––––––––––––––––––––––––––––––––––– */
.grid {
  width: 100%;
  margin: auto;
}

.grid .left {
  max-width: 90%;
  width: 600px;
  margin: 20px auto 0;
}

.grid .left .brand {
  font-weight: 700;
  font-size: 65px;
  line-height: 1;
  margin-bottom: 40px;

  .dot {
    color: rgb(var(--v-theme-primary));
    margin-left: 0;
    font-weight: bold;
  }
}

.grid .left .title {
  font-size: clamp(30px, 2.5vw, 50px);
  line-height: clamp(35px, 2.5vw, 55px);
  font-weight: 800;
  margin-bottom: 40px;
}

.grid .left .subtitle {
  margin-bottom: 10px;
}

.grid .left .description {
  opacity: 0.5;
  font-size: 0.85em;
  line-height: 1.5;
  margin-bottom: 10px;
}

.grid .left form {
  position: relative;
}

.grid .left input,
.grid .left button {
  width: 100%;
  padding: 15px;
}

.grid .left input {
  font-weight: 300;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  outline: none;

  &::placeholder {
    color: rgba(var(--v-theme-on-surface), 0.4);
  }
}

.grid .left button {
  color: rgb(var(--v-theme-on-primary));
  background: rgb(var(--v-theme-primary));

  &:disabled {
    opacity: 0.6;
    cursor: wait;
  }
}

.grid .left .success {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 15px 0;
}

/* ANIMATION STYLES
–––––––––––––––––––––––––––––––––––––––––––––––––– */
.arrow {
  position: absolute;
  right: 0;
  top: 100%;
  width: 100px;
  height: 100px;
}

.under-construction {
  width: 300px;
  height: 300px;
  margin: 20px auto 0;
}

/* MQ STYLES
–––––––––––––––––––––––––––––––––––––––––––––––––– */
@media (min-width: 768px) {
  .under-construction {
    width: 500px;
    height: 500px;
  }
}

@media (min-width: 1200px) {
  .grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    align-items: center;
  }

  .grid .left {
    padding-left: 20%;
    margin: 0;
  }

  .grid .left {
    width: auto;
  }

  .under-construction {
    margin: 0 auto;
  }
}

@media (min-width: 1500px) {
  .grid .left {
    max-width: 80%;
  }

  .grid .left input {
    border-radius: 30px;
  }

  .grid .left button {
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: auto;
    border-radius: 0 30px 30px 0;
  }
}

@media (min-width: 1500px) and (min-height: 900px) {
  .under-construction {
    width: 700px;
    height: 700px;
  }
}
</style>
