<script setup>
import { useNavStore } from '@/core/stores/nav'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const navStore = useNavStore()

const isPlatform = computed(() => !!route.meta?.platform)

const footerLinks = computed(() => {
  const links = isPlatform.value
    ? navStore.platformFooterLinks
    : navStore.companyFooterLinks

  return (links || []).slice().sort((a, b) => a.sortOrder - b.sortOrder)
})

const navigate = link => {
  if (link.to) router.push(link.to)
  else if (link.href) window.open(link.href, '_blank')
}
</script>

<template>
  <div class="h-100 d-flex align-center justify-md-space-between justify-center">
    <!-- 👉 Footer: left content -->
    <span class="d-flex align-center text-medium-emphasis">
      &copy;
      {{ new Date().getFullYear() }}
      Made With
      <VIcon
        icon="tabler-heart-filled"
        color="error"
        size="1.25rem"
        class="mx-1"
      />
      By <a
        href="https://leezr.com"
        target="_blank"
        rel="noopener noreferrer"
        class="text-primary ms-1"
      >leezr.com</a>
    </span>
    <!-- 👉 Footer: right content -->
    <span
      v-if="footerLinks.length"
      class="d-md-flex gap-x-4 text-primary d-none"
    >
      <a
        v-for="link in footerLinks"
        :key="link.key"
        class="d-inline-flex align-center gap-1 cursor-pointer"
        @click="navigate(link)"
      >
        <VIcon
          v-if="link.icon"
          :icon="link.icon"
          size="18"
        />
        {{ t(link.label) }}
      </a>
    </span>
  </div>
</template>
