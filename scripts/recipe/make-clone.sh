#!/usr/bin/env bash
# ADR-827 — create an isolated recipe clone of a database and mark it (the only way a recipe command accepts it).
#
#   scripts/recipe/make-clone.sh --defaults-file=<mysql option file> --source=leezr --clone=leezr_audit827
#
# - the clone name must follow the convention leezr_(audit|clone|recipe)… and must not exist yet ;
# - the source is only READ (mysqldump --single-transaction) ; the dump is kept with its SHA-256 ;
# - the marker table _recipe_clone_identity is written in the CLONE only, naming the clone itself.
set -euo pipefail
for a in "$@"; do case "$a" in
  --defaults-file=*) CNF="${a#*=}";; --source=*) SRC="${a#*=}";; --clone=*) DST="${a#*=}";; --dump-dir=*) DIR="${a#*=}";;
  *) echo "unknown argument $a" >&2; exit 2;; esac; done
: "${CNF:?--defaults-file required}" "${SRC:?--source required}" "${DST:?--clone required}"
DIR="${DIR:-storage/app/recipe-clones}"
[[ "$DST" =~ ^leezr_(audit|clone|recipe)[a-z0-9_]*$ ]] || { echo "refused : $DST does not follow the clone convention" >&2; exit 2; }
case "$DST" in leezr|c1leezr|c1leezrdev) echo "refused : $DST is a protected database" >&2; exit 2;; esac
[ "$SRC" != "$DST" ] || { echo "refused : source and clone are the same" >&2; exit 2; }
EXISTS=$(mysql --defaults-extra-file="$CNF" -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DST'")
[ "$EXISTS" = "0" ] || { echo "refused : $DST already exists (never overwritten)" >&2; exit 2; }
mkdir -p "$DIR"; DUMP="$DIR/$SRC-$(date -u +%Y%m%dT%H%M%SZ).sql"
mysqldump --defaults-extra-file="$CNF" --single-transaction --set-gtid-purged=OFF --routines --triggers --hex-blob "$SRC" > "$DUMP"
shasum -a 256 "$DUMP" > "$DUMP.sha256"
mysql --defaults-extra-file="$CNF" -e "CREATE DATABASE \`$DST\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql --defaults-extra-file="$CNF" "$DST" < "$DUMP"
mysql --defaults-extra-file="$CNF" "$DST" -e "CREATE TABLE \`_recipe_clone_identity\` (database_name VARCHAR(64) NOT NULL PRIMARY KEY, source_database VARCHAR(64) NOT NULL, source_dump_sha256 CHAR(64) NOT NULL, created_at DATETIME NOT NULL); INSERT INTO \`_recipe_clone_identity\` VALUES ('$DST', '$SRC', '$(cut -d' ' -f1 "$DUMP.sha256")', UTC_TIMESTAMP());"
echo "clone $DST created from $SRC ; dump $DUMP ($(cut -d' ' -f1 "$DUMP.sha256"))"
