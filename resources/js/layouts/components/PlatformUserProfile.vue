<script setup>
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

const router = useRouter()
const platformAuth = usePlatformAuthStore()

const userData = computed(() => platformAuth.user)

const logout = async () => {
  await platformAuth.logout()
  await router.push('/platform/login')
}
</script>

<template>
  <VBadge
    v-if="userData"
    dot
    bordered
    location="bottom right"
    offset-x="1"
    offset-y="2"
    color="error"
  >
    <VAvatar
      size="38"
      class="cursor-pointer"
      color="error"
      variant="tonal"
    >
      <VIcon icon="tabler-user-shield" />

      <VMenu
        activator="parent"
        width="240"
        location="bottom end"
        offset="12px"
      >
        <VList>
          <VListItem>
            <div class="d-flex gap-2 align-center">
              <VListItemAction>
                <VAvatar
                  color="error"
                  variant="tonal"
                >
                  <VIcon icon="tabler-user-shield" />
                </VAvatar>
              </VListItemAction>

              <div>
                <h6 class="text-h6 font-weight-medium">
                  {{ userData.name }}
                </h6>
                <VListItemSubtitle class="text-disabled">
                  Platform Admin
                </VListItemSubtitle>
              </div>
            </div>
          </VListItem>

          <VDivider class="my-2" />

          <div class="px-4 py-2">
            <VBtn
              block
              size="small"
              color="error"
              append-icon="tabler-logout"
              @click="logout"
            >
              Logout
            </VBtn>
          </div>
        </VList>
      </VMenu>
    </VAvatar>
  </VBadge>
</template>
