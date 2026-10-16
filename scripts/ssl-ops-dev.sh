#!/usr/bin/env bash
# Réparation durable du renouvellement SSL de dev.leezr.com (exécuté en root/sudo via le CI).
# Mécanisme réel = acme.sh (clients sites). Cause : challenge HTTP-01 non servi en statique.
# Fix : Alias ACME GLOBAL (sert /.well-known/acme-challenge pour tous les vhosts) + ré-émission
# acme.sh (webroot) + installation au chemin ISPConfig + reload Apache. Non destructif.
set +e
DOMAIN="dev.leezr.com"
SSLDIR="/var/www/clients/client1/web3/ssl"
ACME_WEBROOT="/var/www/acme-global"
ACME="/root/.acme.sh/acme.sh"

echo "===== 0. État acme.sh + fichiers SSL ====="
sudo $ACME --list 2>/dev/null | grep -iE "Main_Domain|$DOMAIN" | head
sudo ls -la "$SSLDIR"/ 2>/dev/null | grep -iE "dev.leezr" | head

echo "===== 1. Alias ACME global (sert le challenge en statique, tous vhosts) ====="
sudo mkdir -p "$ACME_WEBROOT/.well-known/acme-challenge"
sudo chown -R www-data:www-data "$ACME_WEBROOT" 2>/dev/null || true
sudo tee /etc/apache2/conf-available/000-acme-global.conf >/dev/null <<'EOF'
# ACME HTTP-01 global — sert les jetons Let's Encrypt en statique pour tous les sites,
# indépendamment du routage applicatif (le vhost exclut déjà /.well-known/ de sa réécriture).
Alias /.well-known/acme-challenge/ /var/www/acme-global/.well-known/acme-challenge/
<Directory /var/www/acme-global/>
    Require all granted
    AllowOverride None
    Options None
    RewriteEngine Off
    ForceType text/plain
</Directory>
EOF
sudo a2enconf 000-acme-global 2>&1 | tail -1
sudo apache2ctl configtest 2>&1 | tail -2
sudo systemctl reload apache2 && echo "apache rechargé"

echo "===== 2. Vérif service statique du challenge ====="
echo "token-test-ok" | sudo tee "$ACME_WEBROOT/.well-known/acme-challenge/selftest" >/dev/null
curl -sS -m 10 "http://$DOMAIN/.well-known/acme-challenge/selftest" ; echo " <- doit afficher 'token-test-ok'"
sudo rm -f "$ACME_WEBROOT/.well-known/acme-challenge/selftest"

echo "===== 3. Ré-émission acme.sh (webroot global, Let's Encrypt) ====="
sudo $ACME --issue -d "$DOMAIN" -w "$ACME_WEBROOT" --server letsencrypt --force --keylength 2048 2>&1 | tail -25

echo "===== 4. Installation au chemin ISPConfig + reload Apache ====="
sudo $ACME --install-cert -d "$DOMAIN" \
  --key-file       "$SSLDIR/$DOMAIN-le.key" \
  --fullchain-file "$SSLDIR/$DOMAIN-le.crt" \
  --reloadcmd      "systemctl reload apache2" 2>&1 | tail -15

echo "===== 5. Vérification du certificat servi ====="
echo | openssl s_client -servername "$DOMAIN" -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -subject -enddate 2>/dev/null
echo "RUN_MARKER: fix-v1"
