#!/usr/bin/env bash
# VÉRIFICATION durabilité SSL — LECTURE SEULE (dev + prod + panel). Ne modifie rien.
set +e
echo "===== cron acme.sh ====="; sudo crontab -l 2>/dev/null | grep -iE "acme" || echo "(introuvable)"
echo "===== alias ACME global ====="; ls /etc/apache2/conf-enabled/000-acme-global.conf >/dev/null 2>&1 && echo "actif" || echo "ABSENT"
echo "===== acme.sh --list (leezr) ====="; sudo /root/.acme.sh/acme.sh --list 2>/dev/null | grep -iE "Main_Domain|leezr"
echo "===== vhosts leezr actifs ====="; sudo apache2ctl -S 2>/dev/null | grep -iE "leezr" | head
echo "===== certs servis ====="
for D in dev.leezr.com leezr.com panel.leezr.com; do printf "%-18s " "$D"; echo | openssl s_client -servername "$D" -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -subject -enddate 2>/dev/null; done
echo "RUN_MARKER: verify-all-v2"
