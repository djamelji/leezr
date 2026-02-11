# Presets UI — Auth

> Pages et composants d'authentification.
> Source : `resources/ui/presets/auth/`

## Pages disponibles

| Page | Variantes | Layout | Description |
|------|-----------|--------|-------------|
| Login | v1 (simple), v2 (illustration) | blank | Email + password + remember me |
| Register | v1, v2, multi-steps | blank | Username + email + password |
| Forgot Password | v1, v2 | blank | Email pour reset |
| Reset Password | v1, v2 | blank | Nouveau password |
| Verify Email | v1, v2 | blank | Vérification email |
| Two Steps | v1, v2 | blank | Code 2FA (OTP input) |

### Différence v1 vs v2
- **v1** : Form centré, design minimal
- **v2** : Form + illustration latérale, plus riche visuellement

### Multi-step Register
- 4 étapes dans un stepper
- Account details → Personal info → Billing → Submit

## Composants auth associés

| Composant | Description |
|-----------|-------------|
| AuthProvider | Boutons OAuth (Facebook, Twitter, GitHub, Google) |
| TwoFactorAuthDialog | Choix méthode 2FA |
| AddAuthenticatorAppDialog | Setup authenticator + QR |
| EnableOneTimePasswordDialog | Vérification SMS |

## Pages erreur/misc

| Page | Route | Description |
|------|-------|-------------|
| `[...error].vue` | 404 | Page not found |
| `not-authorized.vue` | 401 | Accès refusé |
| `coming-soon.vue` | - | Bientôt disponible |
| `under-maintenance.vue` | - | Maintenance |

## Système auth complet

- Guards navigation dans `plugins/1.router/guards.js`
- Cookies : `accessToken`, `userData`
- Fake API login : `plugins/fake-api/handlers/auth/`

## Extraits

_Aucun pour l'instant._
