<script setup>
const router = useRouter()
const { t } = useI18n()

const openHelp = () => {
  router.push('/help-center')
}
</script>

<template>
  <IconBtn @click="openHelp">
    <VIcon icon="tabler-help-circle" />
    <VTooltip
      activator="parent"
      open-delay="1000"
      scroll-strategy="close"
    >
      {{ t('common.helpCenter') }}
    </VTooltip>
  </IconBtn>
</template>
