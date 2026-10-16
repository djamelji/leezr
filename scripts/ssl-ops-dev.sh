#!/usr/bin/env bash
# DIAGNOSTIC (lecture seule) : état vhost panel.leezr.com + enregistrement DNS dans ISPConfig.
set +e
echo "===== 1. vhost panel.leezr.com toujours actif ? ====="
ls -la /etc/apache2/sites-enabled/ 2>/dev/null | grep -i "panel.leezr" || echo "PAS activé dans sites-enabled"
sudo apache2ctl -S 2>/dev/null | grep -i "panel.leezr" || echo "panel.leezr absent de apache2ctl -S"
echo ""
echo "===== 2. Zone DNS leezr.com gérée par ISPConfig (BIND) ? ====="
sudo ls -la /etc/bind/ 2>/dev/null | grep -iE "leezr" ; sudo ls -la /var/lib/bind/ 2>/dev/null | grep -iE "leezr"
ZF=$(sudo bash -c "ls /etc/bind/pri.leezr.com /var/lib/bind/pri.leezr.com 2>/dev/null" | head -1)
echo "zonefile = ${ZF:-introuvable}"
echo "--- enregistrements 'panel' ou wildcard dans la zone ---"
[ -n "$ZF" ] && sudo grep -iE "panel|^\*|wildcard" "$ZF" 2>/dev/null | head
echo ""
echo "===== 3. ISPConfig DB : zone + records leezr.com ====="
CFG="/usr/local/ispconfig/server/lib/config.inc.php"
DBH=$(sudo grep -oE "db_host.*=.*'[^']*'" $CFG 2>/dev/null | grep -oE "'[^']*'$" | tr -d "'")
DBU=$(sudo grep -oE "db_user.*=.*'[^']*'" $CFG 2>/dev/null | grep -oE "'[^']*'$" | tr -d "'")
DBP=$(sudo grep -oE "db_password.*=.*'[^']*'" $CFG 2>/dev/null | grep -oE "'[^']*'$" | tr -d "'")
DBN=$(sudo grep -oE "db_database.*=.*'[^']*'" $CFG 2>/dev/null | grep -oE "'[^']*'$" | tr -d "'")
echo "db=$DBN host=$DBH user=$DBU (pass masqué)"
sudo mysql -h"${DBH:-localhost}" -u"$DBU" -p"$DBP" -N -e "SELECT id,origin,active FROM $DBN.dns_soa WHERE origin LIKE '%leezr%';" 2>/dev/null
echo "--- records panel / wildcard ---"
sudo mysql -h"${DBH:-localhost}" -u"$DBU" -p"$DBP" -N -e "SELECT z.origin,r.name,r.type,r.data,r.active FROM $DBN.dns_rr r JOIN $DBN.dns_soa z ON r.zone=z.id WHERE z.origin LIKE '%leezr%' AND (r.name LIKE '%panel%' OR r.name='*' OR r.name='@');" 2>/dev/null | head
echo "--- web_domain ISPConfig pour panel.leezr.com ? ---"
sudo mysql -h"${DBH:-localhost}" -u"$DBU" -p"$DBP" -N -e "SELECT domain,type,active FROM $DBN.web_domain WHERE domain LIKE '%panel.leezr%' OR domain='leezr.com';" 2>/dev/null
echo "RUN_MARKER: panel-dns-diag-v1"
