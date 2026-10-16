#!/usr/bin/env bash
# PROD leezr.com (ECC) : re-pointe le webroot acme.sh vers /var/www/acme-global (servi par
# l'alias global, prouvé) PUIS renouvelle réellement (preuve end-to-end) et installe au chemin
# ISPConfig prod (web2) + reload Apache. Non destructif (renouvellement SSL de routine).
set +e
ACME="/root/.acme.sh"; AWR="/var/www/acme-global"
echo "===== conf acme.sh de leezr.com (ECC ?) ====="
CONF=$(sudo bash -c "ls $ACME/leezr.com_ecc/leezr.com.conf 2>/dev/null || ls $ACME/leezr.com/leezr.com.conf 2>/dev/null")
echo "conf = ${CONF:-INTROUVABLE}"
ECC=""; [ -n "$CONF" ] && echo "$CONF" | grep -q "_ecc" && ECC="--ecc"
echo "mode ECC: ${ECC:-non}"
echo "avant: $(sudo grep -i Le_Webroot "$CONF" 2>/dev/null)"
sudo sed -i "s#^Le_Webroot=.*#Le_Webroot='$AWR'#" "$CONF" 2>/dev/null
echo "après: $(sudo grep -i Le_Webroot "$CONF" 2>/dev/null)"

echo "===== chemin SSL prod (web2) depuis le vhost ====="
PCRT=$(sudo grep -hiE "SSLCertificateFile " /etc/apache2/sites-available/leezr.com.vhost 2>/dev/null | awk '{print $2}' | head -1)
PKEY=$(sudo grep -hiE "SSLCertificateKeyFile " /etc/apache2/sites-available/leezr.com.vhost 2>/dev/null | awk '{print $2}' | head -1)
echo "crt=$PCRT"; echo "key=$PKEY"

echo "===== renouvellement RÉEL prod (webroot qui marche) ====="
sudo $ACME/acme.sh --renew -d leezr.com $ECC --server letsencrypt --force 2>&1 | tail -18

echo "===== installation au chemin prod + reload ====="
if [ -n "$PCRT" ] && [ -n "$PKEY" ]; then
  sudo $ACME/acme.sh --install-cert -d leezr.com $ECC \
    --key-file "$PKEY" --fullchain-file "$PCRT" \
    --reloadcmd "systemctl reload apache2" 2>&1 | tail -12
else
  echo "chemins SSL prod non résolus — installation ignorée"
fi

echo "===== vérification cert prod servi ====="
echo | openssl s_client -servername leezr.com -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -issuer -enddate 2>/dev/null
sudo $ACME/acme.sh --list 2>/dev/null | grep -iE "Main_Domain|leezr.com"
echo "RUN_MARKER: prod-renew-v1"
