#!/usr/bin/env bash
# DIAGNOSTIC (lecture seule) : comment l'interface ISPConfig et panel.* sont servis,
# pour donner un cert valide + auto-renouvelé à panel.leezr.com sans casser l'accès panneau.
set +e
echo "===== vhost de l'interface ISPConfig (ServerName/port/cert) ====="
sudo grep -rniE "ServerName|ServerAlias|Listen|SSLCertificateFile|SSLCertificateKeyFile|VirtualHost" \
  /etc/apache2/sites-enabled/*ispconfig* /etc/apache2/sites-available/ispconfig.vhost 2>/dev/null | head -25
echo ""
echo "===== tout vhost mentionnant panel.leezr.com ou panel.novamoov.com ====="
sudo grep -rlniE "panel.leezr.com|panel.novamoov.com" /etc/apache2/sites-enabled/ /etc/apache2/sites-available/ 2>/dev/null
sudo grep -rniE "ServerName|ServerAlias" /etc/apache2/sites-enabled/ 2>/dev/null | grep -iE "panel" | head
echo ""
echo "===== vhost par défaut (celui qui répond à panel.leezr.com actuellement) ====="
sudo apache2ctl -S 2>/dev/null | grep -iE "default server|namevhost|:443|:8080" | head -15
echo ""
echo "===== acme.sh : panel.* déjà géré ? ====="
sudo /root/.acme.sh/acme.sh --list 2>/dev/null | grep -iE "Main_Domain|panel|leezr|novamoov"
echo ""
echo "===== certbot (interface ISPConfig) ====="
sudo certbot certificates 2>/dev/null | grep -iE "Certificate Name|Domains|Expiry" | head
echo "RUN_MARKER: panel-diag-v1"
