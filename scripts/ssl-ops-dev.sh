#!/usr/bin/env bash
# VÉRIFICATION durabilité SSL (dev + prod) — LECTURE SEULE. Ne ré-émet jamais (pas de --force,
# pas de quota Let's Encrypt). Confirme : cron acme.sh + webroots de renouvellement + certs servis.
# Le fix a été appliqué via les itérations précédentes (alias ACME global + webroots → /var/www/acme-global).
set +e
echo "===== cron acme.sh (moteur de renouvellement auto) ====="
sudo crontab -l 2>/dev/null | grep -iE "acme" || echo "(cron acme introuvable)"
echo ""
echo "===== acme.sh --list (domaines + dates de renouvellement) ====="
sudo /root/.acme.sh/acme.sh --list 2>/dev/null
echo ""
echo "===== alias ACME global (sert le challenge pour TOUS les vhosts) ====="
ls -la /etc/apache2/conf-enabled/000-acme-global.conf 2>/dev/null && echo "actif" || echo "ABSENT"
echo ""
for C in /root/.acme.sh/dev.leezr.com/dev.leezr.com.conf /root/.acme.sh/leezr.com_ecc/leezr.com.conf; do
  echo "--- $(basename $(dirname "$C")) : webroot de renouvellement ---"
  sudo grep -hiE "Le_Webroot|Le_NextRenewTimeStr" "$C" 2>/dev/null | sed "s#$(printf '\t')# #g"
done
echo ""
echo "===== certs servis (local) ====="
for D in dev.leezr.com leezr.com; do
  printf "%-16s " "$D"; echo | openssl s_client -servername "$D" -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null
done
echo "RUN_MARKER: verify-safe-v1"
