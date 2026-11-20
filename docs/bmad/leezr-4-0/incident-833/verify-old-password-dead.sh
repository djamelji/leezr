#!/usr/bin/env bash
# ADR-833 / OP-2 step 7 — proves the COMPROMISED password no longer opens SSH DEV, SSH PROD (and, through a tunnel,
# ISPConfig). The password is read from a hidden prompt (or from $OLD_PW already in the environment), never echoed,
# never passed as an argument. Every check EXPECTS a refusal; any success is printed as "ENCORE VALIDE".
#
#   bash verify-old-password-dead.sh [ispconfig-user]
#
# ISPConfig check needs a tunnel already open on localhost:8080
#   (ssh -N -L 8080:127.0.0.1:8080 leezr-dev   — key-based, see the OP-2 procedure).
set -u
HOST=213.32.20.37
if [ -z "${OLD_PW:-}" ]; then read -r -s -p "Ancien mot de passe (non affiché) : " OLD_PW; echo; fi
export SSHPASS="$OLD_PW"
for acct in jliouidevleezr jliouiprodleezr; do
  if sshpass -e ssh -o ConnectTimeout=10 -o PubkeyAuthentication=no -o PreferredAuthentications=password,keyboard-interactive \
       -o NumberOfPasswordPrompts=1 -o StrictHostKeyChecking=yes "$acct@$HOST" true 2>/dev/null; then
    echo "SSH $acct : ENCORE VALIDE  ← KO"
  else
    echo "SSH $acct : refusé (OK)"
  fi
done
methods=$(ssh -o ConnectTimeout=10 -o PreferredAuthentications=none -o StrictHostKeyChecking=yes "nobody@$HOST" 2>&1 | grep -o 'Permission denied (.*)')
echo "Méthodes annoncées par sshd : ${methods:-inconnues}   (attendu : publickey seul)"
if [ -n "${1:-}" ]; then
  code=$(curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' -m 10 -c /dev/null \
    --data-urlencode "username=$1" --data-urlencode "password@-" -d s_mod=login -d s_pg=index \
    https://127.0.0.1:8080/login/index.php <<<"$OLD_PW")
  case "$code" in *index.php*|*dashboard*) echo "ISPConfig $1 : ENCORE VALIDE  ← KO ($code)";; *) echo "ISPConfig $1 : refusé (OK) ($code)";; esac
fi
unset OLD_PW SSHPASS
