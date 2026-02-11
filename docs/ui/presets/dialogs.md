# Presets UI — Dialogs

> Modals et dialogs réutilisables.
> Source : `resources/ui/presets/dialogs/`

## Disponibles dans Vuexy

| Dialog | Usage | Props clés | Générique ? |
|--------|-------|------------|-------------|
| ConfirmDialog | Confirmation action (3 états) | confirmationQuestion, confirmTitle/Msg, cancelTitle/Msg | Oui |
| UserInfoEditDialog | Edition profil user | userData | Non (user) |
| AddEditRoleDialog | Matrice permissions rôle | rolePermissions | Non (ACL) |
| AddEditPermissionDialog | Création permission | permissionName | Non (ACL) |
| CreateAppDialog | Wizard 5 steps | - | Non (app creation) |
| TwoFactorAuthDialog | Choix méthode 2FA | smsCode, authAppCode | Non (auth) |
| AddAuthenticatorAppDialog | Setup 2FA + QR | authCode | Non (auth) |
| EnableOneTimePasswordDialog | Vérification SMS | mobileNumber | Non (auth) |
| CardAddEditDialog | Ajout/edit carte bancaire | cardDetails | Non (paiement) |
| AddEditAddressDialog | Ajout/edit adresse | billingAddress | Non (ecommerce) |
| AddPaymentMethodDialog | Liste méthodes paiement | - | Non (paiement) |
| PaymentProvidersDialog | Fournisseurs paiement | - | Non (paiement) |
| PricingPlanDialog | Plans tarifaires | - | Oui (wrapper) |
| UserUpgradePlanDialog | Upgrade abonnement | - | Non (subscription) |
| ShareProjectDialog | Partage projet + permissions | - | Non (collaboration) |
| ReferAndEarnDialog | Programme parrainage | - | Non (referral) |

### Pattern commun
```vue
<template>
  <VDialog v-model:isDialogVisible="props.isDialogVisible">
    <DialogCloseBtn @click="emit('update:isDialogVisible', false)" />
    <!-- contenu -->
  </VDialog>
</template>
```

## Extraits

_Aucun pour l'instant._
