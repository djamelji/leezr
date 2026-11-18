#!/bin/zsh
# usage: fp.sh <cnf> <db> -> tsv of table checksums (read-only)
T=$(mysql --defaults-extra-file=$1 -N -e "SET SESSION group_concat_max_len=1000000; SELECT GROUP_CONCAT(CONCAT('\`',table_name,'\`')) FROM information_schema.TABLES WHERE table_schema='$2' AND table_type='BASE TABLE'")
mysql --defaults-extra-file=$1 $2 -N -e "CHECKSUM TABLE $T" | sed "s/^$2\.//"
