#!/usr/bin/env bash
# Diagnostic SSL/Let's Encrypt de dev.leezr.com — exécuté en root (sudo) via le CI.
# Lecture seule : aucune modification. Objectif : comprendre pourquoi le renouvellement échoue.
set +e
echo "===== WHOAMI / SUDO ====="; whoami; sudo -n true 2>/dev/null && echo "sudo: OK (passwordless)" || echo "sudo: NON"
echo ""
echo "===== CLIENT ACME utilisé ====="
command -v certbot && certbot --version 2>/dev/null
sudo ls -la /root/.acme.sh/acme.sh 2>/dev/null && echo "acme.sh présent (root)" || echo "pas d'acme.sh root"
echo ""
echo "===== certbot certificates (dev) ====="
sudo certbot certificates 2>/dev/null | grep -iE "Certificate Name|Domains|Expiry|Path:|VALID|INVALID" | head -30
echo ""
echo "===== ALIAS ACME GLOBAL Apache (conf-enabled) ====="
ls /etc/apache2/conf-enabled/ 2>/dev/null | grep -iE "acme|letsencrypt|ispconfig" || echo "AUCUN alias acme global en conf-enabled"
sudo grep -rniE "well-known/acme|acme-challenge" /etc/apache2/conf-enabled/ /etc/apache2/conf-available/ 2>/dev/null | head
echo ""
echo "===== Dir acme central ISPConfig ====="
sudo ls -la /usr/local/ispconfig/interface/acme/.well-known/acme-challenge/ 2>/dev/null | head -3 || echo "dir acme ISPConfig absent/vide"
echo ""
echo "===== vhost dev : chemin du certificat référencé ====="
sudo grep -iE "SSLCertificateFile|SSLCertificateKeyFile|SSLCertificateChainFile" /etc/apache2/sites-available/dev.leezr.com.vhost 2>/dev/null
echo ""
echo "===== logs LE récents (dernière tentative + erreur) ====="
sudo tail -n 40 /var/log/letsencrypt/letsencrypt.log 2>/dev/null | grep -iE "dev.leezr|error|challenge|fail|unauthoriz|timeout|404|detail" | tail -15 || echo "pas de log letsencrypt lisible"
echo ""
echo "===== ISPConfig: statut SSL/LE du site (si DB lisible) ====="
sudo mysql --defaults-extra-file=/usr/local/ispconfig/server/lib/mysql_clientdb.conf -N -e "SELECT domain,ssl,ssl_letsencrypt FROM dbispconfig.web_domain WHERE domain='dev.leezr.com';" 2>/dev/null || echo "(DB ISPConfig non interrogeable ainsi)"
echo "===== FIN DIAGNOSTIC ====="

echo "RUN_MARKER: diagnose-v1"
