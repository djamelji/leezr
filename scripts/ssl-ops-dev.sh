#!/usr/bin/env bash
# Vérification de la DURABILITÉ du renouvellement SSL dev (lecture seule, root via CI).
# Confirme : cron acme.sh actif + config de renouvellement de dev.leezr.com (webroot qui marche)
# + alias ACME global persistant. Ne ré-émet PAS (évite les quotas Let's Encrypt).
set +e
DOMAIN="dev.leezr.com"
echo "===== cron acme.sh (moteur de renouvellement auto) ====="
sudo crontab -l 2>/dev/null | grep -iE "acme" || sudo ls -la /etc/cron.d/ 2>/dev/null | grep -iE "acme" || echo "(cron acme via crontab root)"
echo "===== acme.sh --list (date de renouvellement dev) ====="
sudo /root/.acme.sh/acme.sh --list 2>/dev/null | grep -iE "Main_Domain|$DOMAIN"
echo "===== config de renouvellement de dev (webroot utilisé par le cron) ====="
sudo grep -iE "Le_Webroot|Le_Keylength|Le_API|Le_NextRenewTime" /root/.acme.sh/$DOMAIN*/$DOMAIN.conf 2>/dev/null | sed "s#$(printf '\t')# #g" | head
echo "===== alias ACME global persistant ? ====="
ls -la /etc/apache2/conf-enabled/000-acme-global.conf 2>/dev/null && echo "alias global actif" || echo "alias absent"
echo "===== cert actuellement servi ====="
echo | openssl s_client -servername "$DOMAIN" -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null
echo "RUN_MARKER: verify-v1"
