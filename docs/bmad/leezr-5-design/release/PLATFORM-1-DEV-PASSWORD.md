# DEV · compte Platform n° 1 : changement du mot de passe public (ADR-862)

**Qui** : le propriétaire du compte, et lui seul. L'agent ne choisit, ne voit, ne saisit et n'enregistre jamais le nouveau mot de passe. Celui-ci n'apparaît ni dans un log, ni dans un commit, ni dans un rapport.

**État constaté le 2026-11-22** par le contrôle en lecture seule `platform1-check.sh` :

- le compte accepte encore le mot de passe **public** de démonstration ;
- 2FA désactivée ;
- 0 session ouverte ;
- aucun changement de mot de passe journalisé ;
- c'est le **seul** compte Platform de DEV.

## 0. Avant tout : confirmer la méthode de récupération

Le compte n'est pas verrouillé, et ne doit pas l'être tant qu'une récupération n'est pas confirmée.

- DEV n'envoie pas d'e-mails (`MAIL_MAILER=log`) et il n'existe aucun autre compte Platform. La seule récupération possible passe par le serveur :
  1. demander « Mot de passe oublié » sur `https://dev.leezr.com/platform/forgot-password` ;
  2. le message de réinitialisation, avec son lien à usage unique, est écrit dans `/var/www/clients/client1/web3/shared/storage/logs/` ;
  3. le lire depuis un accès SSH au serveur web3.
- **Vérifié par l'agent le 2026-11-22, en lecture seule** (ADR-863). Aucune réinitialisation n'a été déclenchée, aucun jeton créé.
  - `MAIL_MAILER=log`, et le canal de log par défaut est `stack` → `single`. Le message de réinitialisation est donc écrit dans `shared/storage/logs/laravel.log`.
  - Ce dossier appartient à `web3`, le compte même de l'accès SSH `leezr-dev`, qui le lit.
  - Les routes `POST /api/platform/forgot-password` et `POST /api/platform/reset-password` existent, limitées à 5 essais par minute.
  - Une réinitialisation ferme toutes les sessions ouvertes (`AccountSecurity::revokeSessions`, ADR-838).
- **À confirmer par le propriétaire avant l'étape 1** : il ouvre bien une session SSH sur web3, avec sa propre clé ou l'alias `leezr-dev`, et liste ce dossier de logs. Si ce n'est pas le cas, on s'arrête là.

## 1. Changer le mot de passe (propriétaire)

1. Se connecter sur `https://dev.leezr.com/platform/login`.
2. Ouvrir **Mon compte › Sécurité** (`/platform/account/security`).
3. Saisir un nouveau mot de passe **généré par le gestionnaire de mots de passe** : au moins 8 caractères, majuscules et minuscules, chiffres, symboles, et absent des fuites connues. La politique est appliquée par le serveur.
4. Ne le coller nulle part ailleurs : ni dans le chat, ni dans un terminal, ni dans un ticket.

## 2. Ce que le serveur fait seul (ADR-838)

- Les autres sessions du compte sont supprimées. Elles sont stockées en base sur DEV (`SESSION_DRIVER=database`), donc la suppression est effective. La session en cours reste ouverte.
- Le jeton « se souvenir de moi » est renouvelé et les jetons Sanctum sont supprimés.
- L'action est journalisée (`platform_user.password_changed`) et un avis de sécurité est émis ; sur DEV, il est écrit dans le log.

## 3. Vérifier l'accès après le changement

1. Le propriétaire se déconnecte, puis se reconnecte avec le nouveau mot de passe, depuis le gestionnaire. La connexion doit aboutir.
2. Contrôle en lecture seule, que l'agent peut lancer :

   ```
   ssh leezr-dev 'bash -s' < docs/bmad/leezr-5-design/release/platform1-check.sh
   ```

   Résultat attendu :
   - `public demo password accepted=no` ;
   - `last password change logged=<date du jour>` ;
   - `sessions=1`, celle qui vient d'être ouverte.
3. Recommandé : activer la **2FA** dans le même onglet Sécurité, et ranger les codes de secours dans le gestionnaire. Le contrôle doit alors afficher `2fa=on`.

## 4. En cas de perte d'accès

Suivre la récupération de l'étape 0 : « Mot de passe oublié », puis lire le lien dans le log du serveur par SSH. Le jeton de réinitialisation est à usage unique et expire seul.

## Ce qui n'est pas fait

- Aucun `UPDATE` SQL du mot de passe, aucun verrouillage, aucun mot de passe aléatoire posé par script : OP-7 avait volontairement exclu ce compte, et cela reste le cas.
- PROD n'est pas concernée par cette procédure : OP-2bis reste à appliquer par le propriétaire.
