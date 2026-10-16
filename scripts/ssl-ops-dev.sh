#!/usr/bin/env bash
# VÉRIFICATION durabilité SSL — LECTURE SEULE (dev + prod + panel). Ne ré-émet/re-provisionne
# jamais. Confirme cron acme.sh + domaines gérés + webroots + certs servis.
set +e
echo "===== cron acme.sh (renouvellement auto) ====="
sudo crontab -l 2>/dev/null | grep -iE "acme" || echo "(cron acme introuvable)"
echo "===== alias ACME global (sert le challenge pour tous les vhosts) ====="
ls -la /etc/apache2/conf-enabled/000-acme-global.conf 2>/dev/null >/dev/null && echo "actif" || echo "ABSENT"
echo "===== acme.sh --list (domaines leezr + dates de renouvellement) ====="
sudo /root/.acme.sh/acme.sh --list 2>/dev/null | grep -iE "Main_Domain|leezr"
echo "===== certs servis (local) ====="
for D in dev.leezr.com leezr.com panel.leezr.com; do
  printf "%-18s " "$D"; echo | openssl s_client -servername "$D" -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -subject -enddate 2>/dev/null
done
echo "RUN_MARKER: verify-all-v1"
