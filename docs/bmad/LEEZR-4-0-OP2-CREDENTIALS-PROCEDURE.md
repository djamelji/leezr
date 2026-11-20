# ADR-833 / OP-2 — Sécurisation des identifiants (procédure guidée)

> Aucun secret dans ce document. Les nouveaux mots de passe sont créés et saisis **par le propriétaire seul**, dans un
> gestionnaire de mots de passe ; ils ne transitent jamais par l'agent, le dépôt, un ticket ou une messagerie.
> État au 2026-11-20 — préparé et contrôlé en lecture seule ; aucune modification de serveur, de base ou de secret
> GitHub n'a été faite.

## 0. Constats (lecture seule, 2026-11-20)

| # | Constat | Gravité |
|---|---|---|
| C1 | Le mot de passe compromis (historique public du dépôt) ouvre encore SSH `jliouidevleezr` et `jliouiprodleezr` ; il est déclaré commun à ISPConfig. | P0 |
| C2 | **PROD** `c1leezr` : les 3 comptes Platform (1 `super_admin`, 2 et 3 `admin`, adresses @leezr.com) et les 5 utilisateurs (adresses @leezr.test, 3 sociétés) acceptent le **mot de passe de démonstration public** du DevSeeder. `https://leezr.com/platform/login` répond 200. | **P0** |
| C3 | Le mot de passe compromis n'est utilisé par **aucun** compte applicatif (Platform ou utilisateur), ni sur DEV ni sur PROD, et n'est la valeur d'**aucune** des 62 clés du `.env` DEV comme PROD (comparaison en mémoire, rien affiché). | info |
| C4 | Journal d'audit PROD : toutes les connexions Platform réussies sont celles du compte 1, depuis des réseaux d'accès grand public marocains et français (à confirmer par le propriétaire) ; les comptes 2 et 3 ne se sont jamais connectés ; les sessions des 7 derniers jours sont toutes anonymes (robots). Aucun signe d'usage frauduleux côté Platform ; les connexions côté sociétés ne sont pas auditées. | à confirmer |
| C5 | DEV : 1 compte Platform et 5 utilisateurs au mot de passe de démonstration (OP-7, déjà préparé). | P1 |
| C6 | sshd : `Include sshd_config.d/*.conf` précède `PasswordAuthentication no` ; le fichier `50-cloud-init.conf` (lisible par root seul) l'emporte → le mot de passe est accepté. | P0 |
| C7 | `authorized_keys` DEV mal formé : la clé `leezr-dev-claude-20261030` est collée à la fin de la ligne RSA → elle est lue comme un commentaire, donc **inactive** ; sa clé privée n'existe pas sur le poste de l'agent. | P2 |
| C8 | La CI (`deploy.yml`, `ssl-ops.yml`, `fix-apache.yml`) se connecte **par clé** (`VPS_SSH_KEY`) au compte `VPS_USER` ; les releases CI appartiennent à l'uid **1001**, compte invisible depuis les comptes web (à identifier par root). La clé `github-actions-deploy` est aussi présente dans les deux comptes web. | dépendance |
| C9 | ISPConfig n'écoute pas sur Internet (8080 fermé) : accès par tunnel SSH uniquement. | info |
| C10 | Copies locales du mot de passe compromis : 2 fichiers de mémoire de l'agent, 3 transcriptions de session (preuves, non supprimées), 6 commits de l'historique public. | P1 |

Sauvegardes : `~/leezr-backups/op2-ssh-20261120/authorized_keys.{jliouidevleezr,jliouiprodleezr}` (600).

## 1. Dépendances à préserver

- **CI GitHub Actions** : clé, pas mot de passe → la rotation et la fermeture du mot de passe SSH ne la cassent pas,
  **à condition** que la clé `github-actions-deploy` reste dans `authorized_keys` du compte `VPS_USER` (uid 1001) et des
  deux comptes web. Les secrets `VPS_*`, `APP_PATH`, `DEPLOY_URL` ne sont **pas** modifiés.
- **ISPConfig gère les comptes shell** : changer le mot de passe d'un shell user par `passwd` serait écrasé ; passer par
  le panneau. Le champ « SSH-RSA Public Key » du même formulaire est synchronisé vers `authorized_keys` : **ne pas le
  vider**. Vérifier après chaque enregistrement que `authorized_keys` est intact (comparaison avec la sauvegarde).
- **Applications** : aucune dépendance au mot de passe compromis (C3) → aucune variable `.env` à changer.

## 2. Rotation des trois mots de passe (par le propriétaire)

Prérequis : un gestionnaire de mots de passe ; trois entrées distinctes, générées (≥ 24 caractères aléatoires).

**Étape 2.0 — ouvrir l'accès ISPConfig.** Depuis votre terminal (pas celui de l'agent), avec votre accès
d'administration habituel :
`ssh -N -L 8080:127.0.0.1:8080 <compte-admin>@213.32.20.37` puis ouvrir `https://127.0.0.1:8080/`.
Garder **une session SSH root ouverte** pendant toute l'opération (filet de sécurité).

**Étape 2.1 — ISPConfig (compte administrateur du panneau).** Menu *Tools → Password and Language* (ou *System → CP
Users → admin* selon la version) → nouveau mot de passe (entrée n° 1 du gestionnaire) → *Save*. Se déconnecter et se
reconnecter pour vérifier.

**Étape 2.2 — SSH DEV.** *Sites → Command Line → Shell-User → `jliouidevleezr`* → champ *Password* (entrée n° 2) →
*Save*. Ne pas toucher au champ de clé publique.

**Étape 2.3 — SSH PROD.** Même écran, `jliouiprodleezr` (entrée n° 3) → *Save*.

**Étape 2.4 — autres comptes système.** Si `root` ou `debian` ont un jour partagé ce mot de passe : `passwd root`,
`passwd debian` dans la session root (entrées distinctes). Vérifier aussi le compte d'accès à la console de
l'hébergeur.

**Étape 2.5 — contrôle.** Dire « rotation faite » à l'agent ; il vérifie (lecture seule) que les deux
`authorized_keys` sont identiques aux sauvegardes et que la CI peut toujours se connecter (aucun déploiement lancé).

## 3. PROD — comptes au mot de passe public (C2) — décision requise

Option recommandée, **par le propriétaire** sans écriture de l'agent en base :
1. Se connecter à `https://leezr.com/platform/login` avec le compte 1 et changer son mot de passe dans *Compte*
   (`PUT /api/platform/me/password`).
2. Pour les comptes Platform 2, 3 et les utilisateurs 1–5 (@leezr.test, non joignables par e-mail) : autoriser
   **OP-2bis** — exécution sur PROD de `lock-demo-accounts.php` (mot de passe aléatoire jamais affiché, sessions et jetons
   révoqués), simulation d'abord, avec sauvegarde des deux tables concernées. Le script refuse aujourd'hui `c1leezr` ;
   OP-2bis ajoute un drapeau explicite `--allow-production --confirm-database=c1leezr`, testé sur clone avant usage.

## 4. Sécurisation SSH

**4.1 Clé dédiée à l'agent (poste local, par le propriétaire — passphrase gardée dans le trousseau macOS)**
```
ssh-keygen -t ed25519 -a 100 -f ~/.ssh/leezr_dev_agent -C "leezr-dev-agent-20261120"
ssh-add --apple-use-keychain ~/.ssh/leezr_dev_agent
```
`~/.ssh/config` :
```
Host leezr-dev
  HostName 213.32.20.37
  User jliouidevleezr
  IdentityFile ~/.ssh/leezr_dev_agent
  IdentitiesOnly yes
  AddKeysToAgent yes
  UseKeychain yes
```

**4.2 Installation (autorisation requise)** — dans `authorized_keys` de `jliouidevleezr` uniquement, avec restrictions :
`no-agent-forwarding,no-X11-forwarding,no-port-forwarding ssh-ed25519 AAAA… leezr-dev-agent-20261120`.
Même opération : réparer la ligne 2 (séparer la clé RSA, retirer `leezr-dev-claude-20261030` inactive et orpheline).
Idéalement via le champ ISPConfig pour que le panneau ne l'écrase pas.

**4.3 Test** — `ssh -o PasswordAuthentication=no -o BatchMode=yes leezr-dev 'id'` → `uid=5006(web3)`.

**4.4 Fermeture du mot de passe SSH (autorisation requise, session root ouverte)**
```
cat /etc/ssh/sshd_config.d/50-cloud-init.conf          # attendu : PasswordAuthentication yes
cp -a /etc/ssh/sshd_config.d/50-cloud-init.conf /root/50-cloud-init.conf.bak-20261120
printf 'PasswordAuthentication no\nKbdInteractiveAuthentication no\n' > /etc/ssh/sshd_config.d/00-leezr-hardening.conf
sed -i 's/^PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config.d/50-cloud-init.conf
sshd -t && sshd -T | grep -Ei '^(passwordauthentication|kbdinteractiveauthentication|pubkeyauthentication|permitrootlogin|authenticationmethods) '
systemctl reload ssh
```
Avant le `reload` : vérifier que **chaque** compte encore utilisé (root/debian, uid 1001 de la CI, deux comptes web)
possède une clé fonctionnelle. Après : depuis un **second** terminal, connexion par clé OK et mot de passe refusé ;
seulement alors fermer la session de secours. Retour arrière : restaurer la sauvegarde, `systemctl reload ssh`.
Ajouter `PermitRootLogin prohibit-password` si `sshd -T` montre `yes`.

**4.5 Inventaire des clés (root, lecture seule)**
```
getent passwd 1001
for f in /root/.ssh/authorized_keys /home/*/.ssh/authorized_keys /var/www/clients/client*/web*/home/*/.ssh/authorized_keys $(getent passwd 1001 | cut -d: -f6)/.ssh/authorized_keys; do
  [ -f "$f" ] && { echo "== $f"; ssh-keygen -lf "$f"; }; done
```
Clés connues : `github-actions-deploy` (CI, ED25519 `SHA256:yltpSJSk…`), `jlioui-rsa-key-1769725905` (RSA
`SHA256:xHJj8Bke…`, créée le 2026-01-29, jour d'installation du serveur — présumée clé du propriétaire, à confirmer),
`leezr-dev-claude-20261030` (inactive, à retirer). Toute autre empreinte = propriétaire inconnu → révocation (4.6).

**4.6 Révocation (autorisation par clé)** — retirer la ligne correspondante (ou le champ ISPConfig), sauvegarde préalable,
`ssh-keygen -lf` avant/après. Jamais la clé `github-actions-deploy` sans remplacement préalable du secret `VPS_SSH_KEY`.

## 5. Nettoyage des copies du mot de passe compromis (agent, après §2 et §6)

Retirer la valeur de `memory/ops-vps-deploy.md` et `memory/baseline-2026-07-audit.md` ; remplacer le script SSH de
l'agent par l'hôte `leezr-dev` (clé). Les 3 transcriptions et l'historique Git public restent des preuves : la valeur y
devient inerte après rotation ; une réécriture d'historique est une décision distincte.

## 6. Preuve finale

`bash docs/bmad/leezr-4-0/incident-833/verify-old-password-dead.sh [utilisateur-ispconfig]` — attendu :
SSH DEV refusé, SSH PROD refusé, méthodes annoncées `publickey`, ISPConfig refusé. Puis, en lecture seule, contrôle
applicatif : 0 compte Platform/utilisateur PROD au mot de passe public (après §3).
