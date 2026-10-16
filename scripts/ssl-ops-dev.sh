#!/usr/bin/env bash
# Provisionne panel.leezr.com : cert acme.sh (webroot global, auto-renouvelé) + vhost proxy
# vers l'interface ISPConfig (127.0.0.1:8080), réplique du modèle vetté panel.novamoov.com.
# Non destructif : ajoute un vhost + un cert, ne touche pas l'existant.
set +e
ACME="/root/.acme.sh"; AWR="/var/www/acme-global"
CRT="/var/www/clients/client1/web2/ssl/panel.leezr.com-le.crt"
KEY="/var/www/clients/client1/web2/ssl/panel.leezr.com-le.key"
VHOST="/etc/apache2/sites-available/panel.leezr.com.conf"

echo "===== 1. Émission du cert panel.leezr.com (webroot global, servi par l'alias) ====="
# À ce stade panel.leezr.com:80 tombe sur le vhost par défaut, qui laisse passer /.well-known via l'alias global.
sudo $ACME/acme.sh --issue -d panel.leezr.com -w "$AWR" --server letsencrypt --keylength 2048 --force 2>&1 | tail -18

echo "===== 2. Installation du cert + reload Apache ====="
sudo mkdir -p "$(dirname "$CRT")"
sudo $ACME/acme.sh --install-cert -d panel.leezr.com \
  --key-file "$KEY" --fullchain-file "$CRT" \
  --reloadcmd "systemctl reload apache2" 2>&1 | tail -10

echo "===== 3. Création du vhost proxy panel.leezr.com (modèle panel.novamoov.com) ====="
sudo tee "$VHOST" >/dev/null <<EOF
<VirtualHost *:80>
    ServerName panel.leezr.com
    RewriteEngine On
    # Ne jamais rediriger le challenge ACME (laisser l'alias global le servir en HTTP)
    RewriteCond %{REQUEST_URI} !^/.well-known/acme-challenge/
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost *:443>
    ServerName panel.leezr.com

    SSLEngine on
    SSLProxyEngine On
    SSLProxyVerify none
    SSLProxyCheckPeerCN off
    SSLProxyCheckPeerName off
    ProxyPreserveHost On
    ProxyPass /.well-known/acme-challenge/ !
    ProxyPass / https://127.0.0.1:8080/
    ProxyPassReverse / https://127.0.0.1:8080/

    RequestHeader set X-Forwarded-Proto "https"
    RequestHeader set X-Real-IP "%{REMOTE_ADDR}s"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"

    ErrorLog \${APACHE_LOG_DIR}/panel.leezr.com-error.log
    CustomLog \${APACHE_LOG_DIR}/panel.leezr.com-access.log combined

    SSLCertificateFile $CRT
    SSLCertificateKeyFile $KEY
    Include /etc/letsencrypt/options-ssl-apache.conf
</VirtualHost>
EOF

echo "===== 4. Activation + test config + reload ====="
sudo a2ensite panel.leezr.com 2>&1 | tail -1
sudo apache2ctl configtest 2>&1 | tail -3
sudo systemctl reload apache2 && echo "apache rechargé"

echo "===== 5. Vérification cert panel.leezr.com + enregistrement acme.sh ====="
echo | openssl s_client -servername panel.leezr.com -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -subject -enddate 2>/dev/null
sudo $ACME/acme.sh --list 2>/dev/null | grep -iE "Main_Domain|panel.leezr"
echo "RUN_MARKER: panel-provision-v1"
