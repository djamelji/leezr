#!/usr/bin/env bash
# Lecture seule : dump du vhost panel.novamoov.com (modèle connu-bon) pour répliquer panel.leezr.com.
set +e
echo "===== /etc/apache2/sites-available/panel.novamoov.com.conf ====="
sudo cat /etc/apache2/sites-available/panel.novamoov.com.conf 2>/dev/null
echo ""
echo "===== cert actuel de l'interface ISPConfig (ispserver) ====="
sudo openssl x509 -in /usr/local/ispconfig/interface/ssl/ispserver.crt -noout -subject -enddate 2>/dev/null
echo "RUN_MARKER: panel-model-v1"
