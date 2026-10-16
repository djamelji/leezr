#!/usr/bin/env bash
# Fiabilise le renouvellement PROD (leezr.com) : re-pointe son webroot acme.sh vers le dir
# servi par l'alias ACME global (/var/www/acme-global), déjà prouvé sur dev. Sans ré-émission
# (pas d'appel Let's Encrypt → pas de quota). Vérifie le service d'un jeton EXISTANT pour dev+prod.
set +e
ACME_WEBROOT="/var/www/acme-global"
CHAL="$ACME_WEBROOT/.well-known/acme-challenge"

echo "===== 1. Test service d'un jeton EXISTANT via l'alias global (dev + prod) ====="
echo "selftest-$(date +%s)" | sudo tee "$CHAL/selftest" >/dev/null
for H in dev.leezr.com leezr.com www.leezr.com; do
  printf "%-20s -> " "$H"; curl -sS -m 10 "http://$H/.well-known/acme-challenge/selftest" 2>&1 | head -c 40; echo
done
sudo rm -f "$CHAL/selftest"

echo "===== 2. Re-pointage du webroot de renouvellement de leezr.com (+ www) ====="
for CONF in /root/.acme.sh/leezr.com/leezr.com.conf; do
  if sudo test -f "$CONF"; then
    sudo cp "$CONF" "$CONF.bak-$(date +%Y%m%d)" 2>/dev/null
    echo "avant : $(sudo grep -i Le_Webroot "$CONF")"
    sudo sed -i "s#^Le_Webroot=.*#Le_Webroot='$ACME_WEBROOT'#" "$CONF"
    # Certaines versions acme.sh stockent aussi Le_Webroot sous Le_Webroot_<idx> ; normaliser le principal
    echo "après : $(sudo grep -i Le_Webroot "$CONF")"
  else
    echo "conf leezr.com introuvable: $CONF"
  fi
done

echo "===== 3. Dry-run renouvellement prod (valide le challenge SANS ré-émettre) ====="
sudo /root/.acme.sh/acme.sh --renew -d leezr.com --server letsencrypt --dry-run 2>&1 | tail -15

echo "===== 4. État final (webroots + renouvellements + certs) ====="
for D in dev.leezr.com leezr.com; do
  echo "--- $D ---"
  sudo grep -hiE "Le_Webroot|Le_NextRenewTimeStr" /root/.acme.sh/$D/$D.conf 2>/dev/null | sed "s#$(printf '\t')# #g"
  echo | openssl s_client -servername "$D" -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null
done
echo "RUN_MARKER: prod-fix-v1"
