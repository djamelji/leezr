<script setup>
import { useAuthStore } from '@/core/stores/auth'
import { useNavStore } from '@/core/stores/nav'
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'

const auth = useAuthStore()

const userData = computed(() => auth.user)

const logout = async () => {
  await auth.logout()
  window.location.href = '/login'
}

// ADR-775 : the personal entries come from the navigation (manifest-driven, placed in the avatar
// menu by the server) — « Mon espace » for a manager, the account settings for everyone.
const navStore = useNavStore()
const { t } = useI18n()

const userProfileList = computed(() => {
  const personal = navStore.companyPersonalMenu.map(item => ({
    type: 'navItem',
    key: item.key,
    icon: item.icon,
    title: t(item.labelKey || `nav.company.${item.key}`),
    to: item.to,
  }))

  const items = personal.length
    ? personal
    : [{ type: 'navItem', key: 'account-settings', icon: 'tabler-settings', title: t('nav.company.account-settings'), to: { name: 'account-settings-tab', params: { tab: 'account' } } }]

  return [{ type: 'divider', key: 'd1' }, ...items, { type: 'divider', key: 'd2' }]
})
</script>

<template>
  <VBadge
    v-if="userData"
    dot
    bordered
    location="bottom right"
    offset-x="1"
    offset-y="2"
    color="success"
  >
    <VAvatar
      size="38"
      class="cursor-pointer"
      role="button"
      tabindex="0"
      aria-label="Menu du compte"
      :color="!(userData && userData.avatar) ? 'primary' : undefined"
      :variant="!(userData && userData.avatar) ? 'tonal' : undefined"
    >
      <VImg
        v-if="userData && userData.avatar"
        :src="userData.avatar"
      />
      <VIcon
        v-else
        icon="tabler-user"
      />

      <!-- SECTION Menu -->
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
                <VBadge
                  dot
                  location="bottom right"
                  offset-x="3"
                  offset-y="3"
                  color="success"
                  bordered
                >
                  <VAvatar
                    :color="!(userData && userData.avatar) ? 'primary' : undefined"
                    :variant="!(userData && userData.avatar) ? 'tonal' : undefined"
                  >
                    <VImg
                      v-if="userData && userData.avatar"
                      :src="userData.avatar"
                    />
                    <VIcon
                      v-else
                      icon="tabler-user"
                    />
                  </VAvatar>
                </VBadge>
              </VListItemAction>

              <div>
                <h6 class="text-h6 font-weight-medium">
                  {{ userData.display_name }}
                </h6>
                <VListItemSubtitle class="text-capitalize text-disabled">
                  {{ auth.currentCompany?.company_role?.name || auth.currentCompany?.role || '' }}
                </VListItemSubtitle>
              </div>
            </div>
          </VListItem>

          <PerfectScrollbar :options="{ wheelPropagation: false }">
            <template
              v-for="item in userProfileList"
              :key="item.key"
            >
              <VListItem
                v-if="item.type === 'navItem'"
                :to="item.to"
                :data-test="`avatar-menu-${item.key}`"
              >
                <template #prepend>
                  <VIcon
                    :icon="item.icon"
                    size="22"
                  />
                </template>

                <VListItemTitle>{{ item.title }}</VListItemTitle>

                <template
                  v-if="item.badgeProps"
                  #append
                >
                  <VBadge
                    rounded="sm"
                    class="me-3"
                    v-bind="item.badgeProps"
                  />
                </template>
              </VListItem>

              <VDivider
                v-else
                class="my-2"
              />
            </template>

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
          </PerfectScrollbar>
        </VList>
      </VMenu>
      <!-- !SECTION -->
    </VAvatar>
  </VBadge>
</template>
