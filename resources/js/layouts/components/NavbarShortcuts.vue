<script setup>
import { usePlatformNav } from '@/composables/usePlatformNav'

const { navItems } = usePlatformNav()

const shortcuts = computed(() =>
  navItems.value
    .filter(item => !item.heading && item.to)
    .slice(0, 6)
    .map(item => ({
      icon: item.icon?.icon ?? 'tabler-link',
      title: item.title,
      subtitle: '',
      to: item.to,
    })),
)
</script>

<template>
  <Shortcuts :shortcuts="shortcuts" />
</template>
