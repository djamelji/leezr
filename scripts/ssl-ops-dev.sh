#!/usr/bin/env bash
# Vérification DURABILITÉ renouvellement SSL — DEV + PROD (lecture seule, root via CI, même VPS).
# Confirme cron acme.sh + config de renouvellement (webroot) + cert servi, pour dev ET prod.
set +e
echo "===== cron acme.sh (moteur auto, commun au serveur) ====="
sudo crontab -l 2>/dev/null | grep -iE "acme" || echo "(cron acme introuvable en crontab root)"
echo ""
echo "===== acme.sh --list (tous les domaines + dates de renouvellement) ====="
sudo /root/.acme.sh/acme.sh --list 2>/dev/null
echo ""
for D in dev.leezr.com leezr.com; do
  echo "===== $D : config de renouvellement (webroot utilisé par le cron) ====="
  sudo grep -hiE "Le_Webroot|Le_NextRenewTimeStr|Le_API" /root/.acme.sh/$D*/$D.conf 2>/dev/null | sed "s#$(printf '\t')# #g"
  echo "--- cert servi pour $D ---"
  echo | openssl s_client -servername "$D" -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -issuer -enddate 2>/dev/null
  echo ""
done
echo "===== alias ACME global (couvre tous les vhosts, dont prod) ====="
ls -la /etc/apache2/conf-enabled/000-acme-global.conf 2>/dev/null && echo "actif" || echo "absent"
echo "RUN_MARKER: verify-prod-v1"
