<script setup>
import '@lottiefiles/lottie-player'
import { useAppName } from '@/composables/useAppName'

const appName = useAppName()

const props = defineProps({
  headline: { type: String, default: '' },
  subheadline: { type: String, default: '' },
  description: { type: String, default: '' },
  ctaText: { type: String, default: 'Notify Me' },
})

const emit = defineEmits(['update:headline', 'update:subheadline', 'update:description', 'update:ctaText'])

const { bindPlayer } = useMaintenanceTheme()

const headlineEl = ref(null)
const subheadlineEl = ref(null)
const descriptionEl = ref(null)
const ctaEl = ref(null)
const ucPlayer = ref(null)

// Bind animated player — recolors reactively on theme change
bindPlayer(ucPlayer, 'under-construction')

function syncEl(el, value) {
  if (el.value && el.value !== document.activeElement)
    el.value.textContent = value ?? ''
}

onMounted(() => {
  if (headlineEl.value) headlineEl.value.textContent = props.headline ?? ''
  if (subheadlineEl.value) subheadlineEl.value.textContent = props.subheadline ?? ''
  if (descriptionEl.value) descriptionEl.value.textContent = props.description ?? ''
  if (ctaEl.value) ctaEl.value.textContent = props.ctaText ?? ''
})

watch(() => props.headline, val => syncEl(headlineEl, val))
watch(() => props.subheadline, val => syncEl(subheadlineEl, val))
watch(() => props.description, val => syncEl(descriptionEl, val))
watch(() => props.ctaText, val => syncEl(ctaEl, val))

function onBlur(field, event) {
  emit(`update:${field}`, event.target.textContent.trim())
}

function onPaste(event) {
  event.preventDefault()
  document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') ?? '')
}

function onKeydownSingle(event) {
  if (event.key === 'Enter') {
    event.preventDefault()
    event.target.blur()
  }
}
</script>

<template>
  <div class="mp">
    <div class="mp-left">
      <p class="mp-brand">
        {{ appName.toLowerCase() }}<span class="dot">.</span>
      </p>

      <h1
        ref="headlineEl"
        class="mp-title"
        contenteditable="plaintext-only"
        data-placeholder="Click to edit headline"
        @blur="onBlur('headline', $event)"
        @paste="onPaste"
        @keydown="onKeydownSingle"
      />

      <p
        ref="subheadlineEl"
        class="mp-subtitle"
        contenteditable="plaintext-only"
        data-placeholder="Click to edit subheadline"
        @blur="onBlur('subheadline', $event)"
        @paste="onPaste"
        @keydown="onKeydownSingle"
      />

      <p
        ref="descriptionEl"
        class="mp-description"
        contenteditable="plaintext-only"
        data-placeholder="Click to edit description"
        @blur="onBlur('description', $event)"
        @paste="onPaste"
      />

      <div class="mp-form">
        <input
          type="email"
          placeholder="Enter your email"
          disabled
        >
        <div
          ref="ctaEl"
          class="mp-submit"
          contenteditable="plaintext-only"
          data-placeholder="Button text"
          @blur="onBlur('ctaText', $event)"
          @paste="onPaste"
          @keydown="onKeydownSingle"
        />
      </div>
    </div>

    <div class="mp-right">
      <lottie-player
        ref="ucPlayer"
        loop
        autoplay
        class="mp-decor"
      />
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

input {
  font-family: inherit;
  font-size: 100%;
  border: none;
}

/* MAIN STYLES — CodePen layout scaled for preview
–––––––––––––––––––––––––––––––––––––––––––––––––– */
.mp {
  width: 100%;
  min-height: 400px;
  display: flex;
  align-items: center;
  font-size: clamp(14px, 2vw, 20px);
  line-height: 1.2;
  background: rgb(var(--v-theme-background));
  color: rgb(var(--v-theme-on-background));
  border-radius: 8px;
  padding: 2rem;
}

.mp-left {
  max-width: 90%;
  width: 600px;
  margin: 20px auto 0;
}

.mp-brand {
  font-weight: 700;
  font-size: 45px;
  line-height: 1;
  margin-bottom: 30px;

  .dot {
    color: rgb(var(--v-theme-primary));
    margin-left: 0;
    font-weight: bold;
  }
}

.mp-title {
  font-size: clamp(24px, 2.5vw, 40px);
  line-height: clamp(28px, 2.5vw, 44px);
  font-weight: 800;
  margin-bottom: 40px;
}

.mp-subtitle {
  margin-bottom: 10px;
}

.mp-description {
  opacity: 0.5;
  font-size: 0.85em;
  line-height: 1.5;
  margin-bottom: 10px;
}

.mp-form {
  position: relative;
}

.mp-form input {
  width: 100%;
  padding: 15px;
  font-weight: 300;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  outline: none;

  &::placeholder {
    color: rgba(var(--v-theme-on-surface), 0.4);
  }

  &:disabled {
    opacity: 0.5;
    cursor: default;
  }
}

.mp-submit {
  width: 100%;
  padding: 15px;
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
  font-weight: 600;
  text-align: center;
  cursor: text;
  min-height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.mp-right {
  display: none;
}

.mp-decor {
  width: 200px;
  height: 200px;
}

/* Contenteditable affordance
–––––––––––––––––––––––––––––––––––––––––––––––––– */
[contenteditable] {
  outline: none;
  border-radius: 4px;
  transition: box-shadow 0.15s;

  &:hover {
    box-shadow: 0 0 0 2px rgba(var(--v-theme-on-background), 0.08);
  }

  &:focus {
    box-shadow: 0 0 0 2px rgba(var(--v-theme-primary), 0.3);
  }

  &:empty::before {
    content: attr(data-placeholder);
    color: rgba(var(--v-theme-on-background), 0.25);
    pointer-events: none;
  }
}

.mp-submit[contenteditable] {
  &:hover {
    box-shadow: 0 0 0 3px rgba(var(--v-theme-primary), 0.3);
  }

  &:focus {
    box-shadow: 0 0 0 3px rgba(var(--v-theme-primary), 0.5);
  }

  &:empty::before {
    color: rgba(var(--v-theme-on-primary), 0.5);
  }
}

/* MQ STYLES — same breakpoints as CodePen, scaled for preview
–––––––––––––––––––––––––––––––––––––––––––––––––– */
@media (min-width: 960px) {
  .mp-left {
    flex: 1;
    margin: 0;
    padding-left: 5%;
    width: auto;
  }

  .mp-right {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    padding: 0 2rem;
  }
}

@media (min-width: 1500px) {
  .mp-left {
    max-width: 80%;
  }

  .mp-form input {
    border-radius: 30px;
  }

  .mp-submit {
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: auto;
  }
}
</style>
