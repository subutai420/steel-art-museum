#!/usr/bin/env bash
# Export databáze ze starého webu na WEDOS (spouští se na serveru Rosti, /srv/stack).
#   bash export.sh            → /srv/stack/import/wedos-<datum>.sql.gz
# Nahraje dočasný PHP skript (náhodný název + tajný token) přes FTP do kořene webu, volá ho po dávkách,
# stáhne části přes FTP, spojí je a skript i pracovní složku na WEDOS hned smaže (i při chybě).
set -euo pipefail
cd /srv/stack
HERE=$(cd "$(dirname "$0")" && pwd)
[ -f import/.netrc-wedos ] || { echo "chybí import/.netrc-wedos"; exit 1; }

NAME="sam-x-$(head -c 9 /dev/urandom | od -An -tx1 | tr -d ' \n').php"
WORKDIR="sam-x-$(head -c 9 /dev/urandom | od -An -tx1 | tr -d ' \n')"
TOKEN=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
HASH=$(printf '%s' "$TOKEN" | sha256sum | cut -d' ' -f1)
TMP=$(mktemp -d /srv/stack/import/wedos-export.XXXXXX)
chmod 700 "$TMP"
sed -e "s/__TOKEN_HASH__/$HASH/" -e "s/__WORKDIR__/$WORKDIR/" "$HERE/sam-db-export.php" > "$TMP/$NAME"
REMOTE=/domains/steelartmuseum.eu
URL="https://steelartmuseum.eu/$NAME"

lftp_wedos() {
	docker run --rm -i -v "$TMP":/work -v /srv/stack/import:/import:ro alpine:3.20 sh -c '
		apk add --no-cache lftp >/dev/null 2>&1
		U=$(awk "/login/{print \$2}" /import/.netrc-wedos); P=$(awk "/password/{print \$2}" /import/.netrc-wedos)
		{ echo "set ftp:ssl-force true; set ftp:ssl-protect-data false; set net:timeout 30; set net:max-retries 3"; cat; } | lftp -u "$U","$P" 345320.w20.wedos.net'
}
cleanup() {
	printf 'rm -f %s/%s\nrm -rf %s/wp-content/uploads/%s\n' "$REMOTE" "$NAME" "$REMOTE" "$WORKDIR" | lftp_wedos >/dev/null 2>&1 || true
	echo "WEDOS: dočasný skript a pracovní složka smazány"
	rm -rf "$TMP"
}
trap cleanup EXIT

t0=$SECONDS
echo "put -O $REMOTE /work/$NAME" | lftp_wedos
rm -f "$TMP/$NAME"
for i in $(seq 1 400); do
	r=$(curl -s -m 90 "$URL?k=$TOKEN&a=step" || true)
	echo "  $r"
	case "$r" in DONE*) break ;; CONTINUE*) ;; *) echo "neočekávaná odpověď, končím"; exit 1 ;; esac
done
case "$r" in DONE*) ;; *) echo "export nedoběhl"; exit 1 ;; esac
echo "mirror $REMOTE/wp-content/uploads/$WORKDIR/ /work/parts/" | lftp_wedos >/dev/null
OUT=import/wedos-$(date +%Y%m%d-%H%M).sql.gz
cat $(ls "$TMP"/parts/part-*.sql.gz | sort) > "$OUT"
chmod 600 "$OUT"
gzip -t "$OUT"
zcat "$OUT" | tail -1 | grep -q 'SAM export hotovo' || { echo "export neúplný"; exit 1; }
echo "HOTOVO: $OUT ($(du -h "$OUT" | cut -f1)) za $(( SECONDS - t0 )) s"
