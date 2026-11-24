#!/usr/bin/env bash
# Nasadí wp-content z gitu na server Rosti (stack /srv/stack) přes SSH.
# Vychází ze scripts/deploy.sh projektu Museum of Bricks.
#
# Použití: scripts/deploy.sh <git-ref> [--dry-run] [--allow-drift] [--note "text"]
#
# Nastavení z prostředí nebo ze souboru .env v kořeni repozitáře (necommituje se):
#   SAM_SSH_HOST, SAM_SSH_PORT, SAM_SSH_USER
#   SAM_SSH_KEY    (volitelné, výchozí ~/.ssh/sam_rosti)
#   SAM_SITE_HOST  (volitelné, výchozí steelartmuseum.eu) – doména webu pro kontrolu stránek (teď testovací)
#
# Skript odmítne nasadit, pokud se soubory na serveru liší od commitu v značce
# posledního nasazení (někdo je upravil přímo na serveru). Takové změny nejdřív
# převeďte do gitu, nebo po kontrole použijte --allow-drift.

set -euo pipefail

REMOTE_STACK=/srv/stack
REMOTE_APP=$REMOTE_STACK/www
REMOTE_BACKUPS=$REMOTE_STACK/.deploy/backups
REMOTE_MARKER=$REMOTE_STACK/.deploy/deployed-commit
PHP_IMAGE=wordpress:php8.1-fpm-alpine
SMOKE_PATHS="/ /en/ /cenik/ /kontakt/ /robots.txt /wp-login.php"

REF=""
DRY_RUN=0
ALLOW_DRIFT=0
NOTE=""
while [ $# -gt 0 ]; do
	case "$1" in
		--dry-run) DRY_RUN=1 ;;
		--allow-drift) ALLOW_DRIFT=1 ;;
		--note) shift; NOTE="${1:-}" ;;
		-h|--help) sed -n '2,16p' "$0"; exit 0 ;;
		-*) echo "Neznámý přepínač: $1" >&2; exit 64 ;;
		*) REF="$1" ;;
	esac
	shift
done
[ -n "$REF" ] || { echo "Použití: scripts/deploy.sh <git-ref> [--dry-run] [--allow-drift] [--note \"text\"]" >&2; exit 64; }

cd "$(git rev-parse --show-toplevel)"

# Hodnota z prostředí, jinak z .env (soubor se nesourcuje, hodnoty nemusí být shell-safe).
setting() {
	local name="$1" default="${2:-}" value="${!1:-}"
	if [ -z "$value" ] && [ -f .env ]; then
		value="$(grep -E "^$name=" .env | head -n 1 | cut -d= -f2- | tr -d '\r')"
	fi
	printf '%s' "${value:-$default}"
}

SSH_HOST="$(setting SAM_SSH_HOST)"
SSH_PORT="$(setting SAM_SSH_PORT 22)"
SSH_USER="$(setting SAM_SSH_USER)"
SSH_KEY="$(setting SAM_SSH_KEY "$HOME/.ssh/sam_rosti")"
SITE_HOST="$(setting SAM_SITE_HOST steelartmuseum.eu)"
[ -n "$SSH_HOST" ] && [ -n "$SSH_USER" ] || { echo "Chybí SAM_SSH_HOST nebo SAM_SSH_USER." >&2; exit 78; }

# Přihlašovací shell na Rosti je fish. Příkaz proto pošleme zakódovaný a spustíme
# v bashi; standardní vstup zůstává volný pro data (tar, seznamy souborů).
remote() {
	local b64
	b64="$(printf '%s' "$1" | base64 | tr -d '\n')"
	ssh -i "$SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=20 -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" \
		"echo $b64 | base64 -d > /tmp/.sam-deploy-cmd.\$fish_pid; bash /tmp/.sam-deploy-cmd.\$fish_pid; set s \$status; rm -f /tmp/.sam-deploy-cmd.\$fish_pid; exit \$s"
}

# Vypíše "<blob hash>TAB<cesta>" pro každý soubor, který by exportoval git archive.
manifest() {
	local ref="$1"
	git -c core.quotepath=false ls-tree -r "$ref" -- wp-content \
		| awk -F'\t' 'NR==FNR { keep[$0] = 1; next } ($2 in keep) { split($1, meta, " "); print meta[3] "\t" $2 }' \
			<(git archive "$ref" wp-content | tar tf - | grep -v '/$') -
}

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

COMMIT="$(git rev-parse --verify "$REF^{commit}")"
SHORT="$(git rev-parse --short "$COMMIT")"
TAG="$(git describe --tags --exact-match "$COMMIT" 2>/dev/null || true)"
STAMP="$(date +%Y%m%d-%H%M%S)"
echo "Nasazuji $SHORT${TAG:+ ($TAG)} na $SSH_USER@$SSH_HOST:$REMOTE_APP"

manifest "$COMMIT" > "$WORK/new.txt"

PREVIOUS="$(remote "cat $REMOTE_MARKER 2>/dev/null || true" < /dev/null | sed -n 's/^commit=//p' | head -n 1 || true)"
: > "$WORK/old.txt"
if [ -n "$PREVIOUS" ] && git cat-file -e "$PREVIOUS^{commit}" 2>/dev/null; then
	manifest "$PREVIOUS" > "$WORK/old.txt"
	echo "Značka na serveru: $PREVIOUS"
elif [ "$ALLOW_DRIFT" -eq 0 ]; then
	echo "Značka na serveru ('${PREVIOUS:-žádná}') není commit tohoto repozitáře, rozdíly nejde ověřit." >&2
	echo "Stáhněte chybějící commit, nebo po ruční kontrole použijte --allow-drift." >&2
	exit 2
fi

cut -f2 "$WORK/old.txt" "$WORK/new.txt" | LC_ALL=C sort -u > "$WORK/paths.txt"
remote "cd $REMOTE_APP && while IFS= read -r p; do if [ -f \"\$p\" ]; then printf '%s\t%s\n' \"\$(git hash-object -- \"\$p\")\" \"\$p\"; else printf 'MISSING\t%s\n' \"\$p\"; fi; done" \
	< "$WORK/paths.txt" > "$WORK/server.txt"

# drift: server se liší od minulého nasazení; changed: k nahrání; removed: bylo nasazeno, v novém refu chybí.
awk -F'\t' 'NR==FNR { srv[$2] = $1; next } srv[$2] != $1 { print $2 }' "$WORK/server.txt" "$WORK/old.txt" > "$WORK/drift.txt"
awk -F'\t' 'NR==FNR { srv[$2] = $1; next } srv[$2] != $1 { print $2 }' "$WORK/server.txt" "$WORK/new.txt" > "$WORK/changed.txt"
awk -F'\t' 'FILENAME==ARGV[1] { new[$2] = 1; next } FILENAME==ARGV[2] { srv[$2] = $1; next } !($2 in new) && srv[$2] != "MISSING" { print $2 }' \
	"$WORK/new.txt" "$WORK/server.txt" "$WORK/old.txt" > "$WORK/removed.txt"

if [ -s "$WORK/drift.txt" ]; then
	echo "Soubory na serveru, které se liší od posledního nasazení ($(wc -l < "$WORK/drift.txt" | tr -d ' ')):"
	sed 's/^/  /' "$WORK/drift.txt"
	if [ "$ALLOW_DRIFT" -eq 0 ]; then
		echo "Přerušeno. Převeďte změny do gitu, nebo použijte --allow-drift." >&2
		exit 2
	fi
	echo "Pokračuji kvůli --allow-drift; tyto soubory jsou v záloze."
fi

echo "Nahrát: $(wc -l < "$WORK/changed.txt" | tr -d ' '), odstranit: $(wc -l < "$WORK/removed.txt" | tr -d ' ')"
sed 's/^/  + /' "$WORK/changed.txt"
sed 's/^/  - /' "$WORK/removed.txt"

if [ "$DRY_RUN" -eq 1 ]; then
	echo "Zkouška nanečisto, nic se nezměnilo."
	exit 0
fi

BACKUP=""
if [ -s "$WORK/changed.txt" ] || [ -s "$WORK/removed.txt" ]; then
	BACKUP="$REMOTE_BACKUPS/pre-$SHORT-$STAMP.tar.gz"
	STAGE="$REMOTE_STACK/.deploy/stage-$SHORT-$STAMP"

	# Záloha všech přepisovaných a mazaných souborů + databáze.
	awk -F'\t' 'NR==FNR { if ($1 != "MISSING") have[$2] = 1; next } ($0 in have)' "$WORK/server.txt" \
		<(cat "$WORK/changed.txt" "$WORK/removed.txt" | LC_ALL=C sort -u) > "$WORK/backup.txt"
	if [ -s "$WORK/backup.txt" ]; then
		remote "mkdir -p $REMOTE_BACKUPS && cd $REMOTE_APP && tar czf $BACKUP -T -" < "$WORK/backup.txt"
		echo "Záloha souborů: $BACKUP"
	else
		BACKUP=""
	fi
	remote "mkdir -p $REMOTE_BACKUPS && cd $REMOTE_STACK && f=$REMOTE_BACKUPS/db-$STAMP.sql.gz && docker compose exec -T db sh -c 'mariadb-dump -u root -p\"\$MARIADB_ROOT_PASSWORD\" --single-transaction --quick \"\$MARIADB_DATABASE\"' < /dev/null | gzip > \$f && chmod 600 \$f && echo \"Záloha databáze: \$f (\$(du -h \$f | cut -f1))\"" < /dev/null

	# Nahrát vedle webu, zkontrolovat syntaxi PHP a teprve pak nakopírovat na místo.
	git archive "$COMMIT" wp-content | remote "mkdir $STAGE && tar xf - -C $STAGE"
	remote "cat > $STAGE/.changed" < "$WORK/changed.txt"
	remote "cat > $STAGE/.removed" < "$WORK/removed.txt"
	if ! remote "cd $STAGE && grep '\.php\$' .changed | grep -v '/vendor/' > .lintlist || true; [ -s .lintlist ] || exit 0; docker run --rm -v $STAGE:/s:ro -w /s $PHP_IMAGE sh -c 'while IFS= read -r f; do php -l \"\$f\" > /dev/null 2> /tmp/lint || { echo \"CHYBA SYNTAXE: \$f\"; cat /tmp/lint; exit 1; }; done < .lintlist'" < /dev/null; then
		remote "rm -rf $STAGE" < /dev/null
		echo "Kontrola PHP selhala, nic nebylo nasazeno." >&2
		exit 1
	fi
	remote "cd $STAGE && tar cf - -T .changed | tar xf - -C $REMOTE_APP && cd $REMOTE_APP && while IFS= read -r f; do rm -f -- \"\$f\"; done < $STAGE/.removed; while IFS= read -r f; do d=\$(dirname \"\$f\"); while [ \"\$d\" != wp-content ] && [ \"\$d\" != . ]; do chown 82:82 \"\$d\"; d=\$(dirname \"\$d\"); done; chown 82:82 \"\$f\"; done < $STAGE/.changed; rm -rf $STAGE" < /dev/null
	echo "Soubory jsou na místě."
	# OPcache kontroluje změny souborů jednou za 60 s (opcache.revalidate_freq=60).
	echo "Čekám 65 s, než PHP načte nové soubory..."
	sleep 65
	remote "cd $REMOTE_APP/wp-content/cache 2>/dev/null && for d in all sam-page wpfc-minified; do [ -d \$d ] && mv \$d \$d.deploy-\$\$ && rm -rf \$d.deploy-\$\$; done; true" < /dev/null
	echo "Stránková cache smazána."
else
	echo "Server už odpovídá $SHORT."
fi

# Kontrola stránek přímo na serveru (přes nginx, bez Rosti proxy). Testovací doména je za heslem:
# přihlašovací údaje čte ze souboru $REMOTE_STACK/.deploy/smoke-auth (uživatel:heslo, jen root), pokud existuje.
SMOKE="$(remote "auth=''; [ -f $REMOTE_STACK/.deploy/smoke-auth ] && auth=\"-u \$(cat $REMOTE_STACK/.deploy/smoke-auth)\"; for p in $SMOKE_PATHS; do sep='?'; case \"\$p\" in *\?*) sep='&';; esac; code=\$(curl -s -o /tmp/.sam-smoke -w '%{http_code}' --max-time 60 \$auth -H 'Host: $SITE_HOST' -H 'X-Forwarded-Proto: https' \"http://127.0.0.1\$p\${sep}deploycheck=$(date +%s)\"); if [ \"\$code\" != 200 ] || grep -qi 'critical error\|kritické chybě\|Fatal error\|Parse error' /tmp/.sam-smoke; then echo \"FAIL \$code \$p\"; else echo \"ok \$code \$p\"; fi; done; rm -f /tmp/.sam-smoke" < /dev/null)"
echo "$SMOKE"
if grep -q '^FAIL' <<< "$SMOKE"; then
	echo "Značka nasazení nebyla aktualizována." >&2
	[ -n "$BACKUP" ] && echo "Návrat: ssh -p $SSH_PORT $SSH_USER@$SSH_HOST \"tar xzf $BACKUP -C $REMOTE_APP\"" >&2
	exit 1
fi

printf 'commit=%s\ntag=%s\ndatum=%s\npoznamka=%s\n' "$SHORT" "$TAG" "$(date +%F)" "${NOTE:-scripts/deploy.sh}${BACKUP:+; zaloha $BACKUP}" \
	| remote "mkdir -p $(dirname $REMOTE_MARKER) && cat > $REMOTE_MARKER"
echo "Hotovo. Značka nasazení: $SHORT."
