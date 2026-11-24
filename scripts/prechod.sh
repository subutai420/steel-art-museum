#!/usr/bin/env bash
# Přechod na nový web: čerstvá databáze z WEDOS → nastavení nového webu na Rosti.
# Spouští se NA SERVERU (/srv/stack). Popis kroků a kdo co dělá: docs/PRECHOD.md.
#
#   REZIM=zkouska bash prechod.sh vse /srv/stack/import/db.zip   # generální zkouška na testovací doméně
#   REZIM=ostre   bash prechod.sh vse /srv/stack/import/db.zip   # den D
#   bash prechod.sh <krok> [argumenty]                           # jednotlivý krok
#
# Kroky (v tomto pořadí dělá „vse“):
#   zaloha            záloha současné databáze do .deploy/backups
#   import <soubor>   smaže všechny tabulky a nahraje dump (.zip z WPvivid, .sql nebo .sql.gz)
#   domena            zkouska: přepíše steelartmuseum.eu → testovací doména; ostre: nic
#   nastaveni         časové pásmo, pluginy, šablona, menu, pokladna, brána Stripe
#   klice             ostre: ostré klíče Stripe a údaje Fakturoid z databáze starých pluginů do .env, ostrý webhook
#   vstupenky         převod platných vstupenek starého systému
#   pojistka          ostre: odstraní pojistku testovacího webu (blokuje e-maily a HTTP)
#   spustit           restart php/cron, smazání cache, spuštění migrací obsahu
#   kontrola          souhrn stavu + HTTP kontrola stránek přímo přes nginx (funguje i před přepnutím DNS)
# Další:
#   stav              výpis nastavení (pro porovnání před/po)
#   udrzba on|off     údržba starého webu na WEDOS (.maintenance přes FTP) – jen den D
#   media             dosynchronizuje uploads z WEDOS (bez PDF vstupenek) – potřebuje /srv/stack/import/.netrc-wedos
#
# Tajné hodnoty se nikdy nevypisují (ani do logu), jen délka a začátek.

set -euo pipefail
cd /srv/stack

REZIM="${REZIM:-zkouska}"
case "$REZIM" in zkouska|ostre) ;; *) echo "REZIM musí být zkouska nebo ostre" >&2; exit 1;; esac

LIVE_HOST=steelartmuseum.eu
TEST_HOST=steelartmuseum-1028.rostiapp.cz
SITE_HOST=$([ "$REZIM" = ostre ] && echo "$LIVE_HOST" || echo "$TEST_HOST")
PREFIX=kzl9_
SAFETY=www/wp-content/mu-plugins/000-rosti-staging-safety.php
LOGDIR=.deploy/prechod
mkdir -p "$LOGDIR" .deploy/backups
LOG="$LOGDIR/$(date +%Y%m%d-%H%M%S)-$REZIM.log"

log()  { printf '%s  %s\n' "$(date '+%H:%M:%S')" "$*" | tee -a "$LOG"; }
die()  { log "CHYBA: $*"; exit 1; }
wp()   { docker compose run --rm -T wpcli wp --skip-themes "$@" </dev/null 2>>"$LOG"; }
wpp()  { docker compose run --rm -T wpcli wp --skip-themes --skip-plugins "$@" </dev/null 2>>"$LOG"; }
sql()  { docker compose exec -T db sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" "$@"' sh "$@"; }
step() { local t0=$SECONDS; log "▶ $1"; "krok_$1" "${@:2}"; log "✔ $1 ($(( SECONDS - t0 )) s)"; }

# Nastaví proměnnou v .env (nahradí existující řádek). Hodnota se nevypisuje.
set_env() {
	local key=$1 val=$2
	[ -n "$val" ] || die "prázdná hodnota pro $key"
	cp -p .env ".deploy/backups/env-$(date +%Y%m%d-%H%M%S)"
	grep -v "^${key}=" .env > .env.tmp || true
	printf "%s='%s'\n" "$key" "$val" >> .env.tmp
	chmod 600 .env.tmp && mv .env.tmp .env
	log "  .env: $key nastaveno (${val:0:8}…, délka ${#val})"
}
env_val() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -e "s/^'//" -e "s/'$//" -e 's/^"//' -e 's/"$//'; }

krok_zaloha() {
	local f=".deploy/backups/db-pred-prechodem-$REZIM-$(date +%Y%m%d-%H%M).sql.gz"
	docker compose exec -T db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" --single-transaction --quick "$MARIADB_DATABASE"' </dev/null | gzip -1 > "$f"
	chmod 600 "$f"
	log "  záloha: $f ($(du -h "$f" | cut -f1))"
}

krok_import() {
	local src=${1:-}
	[ -f "$src" ] || die "dump nenalezen: $src"
	local cat_cmd
	case "$src" in
		*.zip)    cat_cmd=(unzip -p "$src" '*.sql') ;;
		*.sql.gz) cat_cmd=(gunzip -c "$src") ;;
		*.sql)    cat_cmd=(cat "$src") ;;
		*) die "neznámý formát: $src" ;;
	esac
	( set +o pipefail; "${cat_cmd[@]}" 2>/dev/null | grep -m1 -q "CREATE TABLE \`${PREFIX}options\`" ) || die "dump nevypadá jako databáze SAM (chybí tabulka ${PREFIX}options)"

	docker compose stop cron >>"$LOG" 2>&1 # žádné naplánované úlohy nad rozpracovanou databází (spustí se v kroku spustit)
	log "  mažu všechny tabulky"
	sql -N -e "SELECT CONCAT('DROP TABLE IF EXISTS \`',table_name,'\`;') FROM information_schema.tables WHERE table_schema=DATABASE()" </dev/null \
		| { echo 'SET FOREIGN_KEY_CHECKS=0;'; cat; } | sql
	# WPvivid píše 1 INSERT na řádek – bez autocommitu a se zápisem logu 1× za sekundu je import mnohonásobně rychlejší.
	docker compose exec -T db sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" -e "SET GLOBAL innodb_flush_log_at_trx_commit=2"' </dev/null
	log "  nahrávám $(basename "$src")"
	{ echo 'SET SESSION autocommit=0, unique_checks=0, foreign_key_checks=0;'; "${cat_cmd[@]}"; echo 'COMMIT;'; } | sql
	docker compose exec -T db sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" -e "SET GLOBAL innodb_flush_log_at_trx_commit=1"' </dev/null
	log "  $(sql -N -e "SELECT CONCAT(COUNT(*),' tabulek') FROM information_schema.tables WHERE table_schema=DATABASE()" </dev/null), \
$(sql -N -e "SELECT CONCAT(COUNT(*),' objednávek, poslední #',MAX(id),' z ',MAX(date_created_gmt),' UTC') FROM ${PREFIX}wc_orders WHERE type='shop_order'" </dev/null)"
	log "  web: $(sql -N -e "SELECT option_value FROM ${PREFIX}options WHERE option_name='home'" </dev/null)"
}

krok_domena() {
	if [ "$REZIM" = ostre ]; then log "  ostrý režim – doména zůstává $LIVE_HOST"; return; fi
	local o n
	for o in "https://$LIVE_HOST" "http://$LIVE_HOST" "https://www.$LIVE_HOST" "https:\\/\\/$LIVE_HOST" "http:\\/\\/$LIVE_HOST"; do
		case "$o" in *'\/'*) n="https:\\/\\/$TEST_HOST" ;; *) n="https://$TEST_HOST" ;; esac
		log "  $o → $n: $(wpp search-replace "$o" "$n" --all-tables-with-prefix --skip-columns=guid --format=count)"
	done
}

krok_nastaveni() {
	wpp option update timezone_string Europe/Prague >/dev/null
	wpp option delete wcml_currency_switcher_template_objects >/dev/null || true
	local old; old=$(wpp option get stylesheet)
	log "  dosavadní šablona: $old"
	local p
	for p in event-tickets-with-ticket-scanner-premium event-tickets-with-ticket-scanner email-template-customizer-for-woo woo-stripe-payment woo-fakturoid insert-headers-and-footers; do
		wpp plugin deactivate "$p" >/dev/null 2>&1 && log "  vypnuto: $p" || true
	done
	wpp plugin activate sam-tickets sam-stripe sam-fakturoid >/dev/null
	log "  zapnuto: sam-tickets sam-stripe sam-fakturoid"
	docker compose run --rm -T wpcli wp theme activate steel-art-museum </dev/null >>"$LOG" 2>&1
	# Umístění menu převezmeme ze staré šablony (theme_mods jsou pro každou šablonu zvlášť).
	wp eval '
		$old = get_option( "theme_mods_'"$old"'" );
		$loc = is_array( $old ) && ! empty( $old["nav_menu_locations"] ) ? $old["nav_menu_locations"] : array();
		if ( empty( $loc["primary"] ) ) { $m = wp_get_nav_menu_object( "main" ); if ( $m ) { $loc["primary"] = $m->term_id; } }
		set_theme_mod( "nav_menu_locations", $loc );
		echo "  menu: " . wp_json_encode( $loc ) . "\n";
		// Pokladna CZ + EN: klasický shortcode místo bloku WooCommerce.
		$cs = (int) get_option( "woocommerce_checkout_page_id" );
		$ids = array_unique( array_filter( array( $cs, (int) apply_filters( "wpml_object_id", $cs, "page", false, "en" ) ) ) );
		foreach ( $ids as $id ) { wp_update_post( array( "ID" => $id, "post_content" => "[woocommerce_checkout]" ) ); }
		echo "  pokladna: stránky " . implode( ", ", $ids ) . "\n";
		update_option( "woocommerce_sam_stripe_settings", array( "enabled" => "yes", "title" => "Platba kartou / Apple Pay / Google Pay", "description" => "" ) );
		echo "  brána sam_stripe zapnutá\n";
	' | tee -a "$LOG"
}

krok_klice() {
	if [ "$REZIM" != ostre ]; then log "  zkouška – zůstávají testovací klíče Stripe, Fakturoid se nenastavuje"; return; fi
	local out sk pk cid csec
	out=$(wpp eval '$s = (array) get_option( "woocommerce_stripe_api_settings" ); echo ( $s["secret_key_live"] ?? "" ), "\n", ( $s["publishable_key_live"] ?? "" ), "\n", get_option( "wc_settings_tab_fakturoid_client_id" ), "\n", get_option( "wc_settings_tab_fakturoid_client_secret" ), "\n";')
	sk=$(sed -n 1p <<<"$out"); pk=$(sed -n 2p <<<"$out"); cid=$(sed -n 3p <<<"$out"); csec=$(sed -n 4p <<<"$out")
	[[ $sk == sk_live_* || $sk == rk_live_* ]] || die "v databázi není ostrý tajný klíč Stripe"
	[[ $pk == pk_live_* ]] || die "v databázi není ostrý veřejný klíč Stripe"
	set_env SAM_STRIPE_SECRET_KEY "$sk"
	set_env SAM_STRIPE_PUBLISHABLE_KEY "$pk"

	# Ostrý webhook: starý se stejnou adresou smažeme (secret jde přečíst jen při založení).
	local url="https://$LIVE_HOST/wp-json/sam-stripe/v1/webhook" id resp whsec
	for id in $(curl -s -u "$sk:" "https://api.stripe.com/v1/webhook_endpoints?limit=100" | tr ',' '\n' | grep -B30 "\"url\": \"$url\"" | grep -oE 'we_[A-Za-z0-9]+' | sort -u); do
		curl -s -u "$sk:" -X DELETE "https://api.stripe.com/v1/webhook_endpoints/$id" >/dev/null && log "  smazán dřívější webhook $id"
	done
	resp=$(curl -s -u "$sk:" https://api.stripe.com/v1/webhook_endpoints -d url="$url" \
		-d "enabled_events[]=payment_intent.succeeded" -d "enabled_events[]=payment_intent.payment_failed" -d "enabled_events[]=charge.refunded" \
		-d api_version=2024-06-20 -d description="Nový web SAM (sam-stripe)")
	whsec=$(grep -oE 'whsec_[A-Za-z0-9]+' <<<"$resp" | head -1)
	[ -n "$whsec" ] || die "webhook se nepodařilo založit: $(grep -oE '"message": "[^"]*"' <<<"$resp")"
	log "  webhook $(grep -oE 'we_[A-Za-z0-9]+' <<<"$resp" | head -1) → $url"
	set_env SAM_STRIPE_WEBHOOK_SECRET "$whsec"

	if [ -n "$cid" ] && [ -n "$csec" ]; then
		set_env SAM_FAKTUROID_ACCOUNT roslincorp
		set_env SAM_FAKTUROID_CLIENT_ID "$cid"
		set_env SAM_FAKTUROID_CLIENT_SECRET "$csec"
		set_env SAM_FAKTUROID_NUMBER_FORMAT 235201
	else
		log "  POZOR: v databázi nejsou údaje Fakturoid z pluginu Toret – doplnit ručně"
	fi
	docker compose up -d php cron >>"$LOG" 2>&1
}

krok_vstupenky() {
	wp sam-tickets import-legacy 2>&1 | tail -5 | tee -a "$LOG"
}

krok_pojistka() {
	if [ "$REZIM" != ostre ]; then log "  zkouška – pojistka zůstává (žádné e-maily ani platby naostro)"; return; fi
	[ -f "$SAFETY" ] && mv "$SAFETY" ".deploy/backups/000-rosti-staging-safety.php.$(date +%Y%m%d-%H%M)" && log "  pojistka odstraněna (záloha v .deploy/backups)" || log "  pojistka už není"
}

krok_spustit() {
	docker compose up -d php cron >>"$LOG" 2>&1
	docker compose restart php cron >>"$LOG" 2>&1
	rm -rf www/wp-content/cache/all/* www/wp-content/cache/wpfc-minified/* 2>/dev/null || true
	wpp cache flush >/dev/null || true
	# Migrace obsahu běží na „init“ – stačí jedno načtení WordPressu.
	wp option get blogname >/dev/null
	wp rewrite flush >/dev/null 2>&1 || true
	if [ "$REZIM" = ostre ]; then
		wp sam-reviews refresh 2>&1 | tail -1 | tee -a "$LOG" || true
	fi
}

page() { # HTTP kontrola přímo přes nginx s hlavičkou Host (funguje před přepnutím DNS)
	curl -s -o /tmp/prechod-page.html -w '%{http_code} %{time_total}s' -H "Host: $SITE_HOST" -H 'X-Forwarded-Proto: https' "http://127.0.0.1$1"
}

krok_kontrola() {
	log "  šablona: $(wpp option get stylesheet), časové pásmo: $(wpp option get timezone_string)"
	log "  pluginy zapnuté (nové): $(wpp plugin list --status=active --field=name | grep -E '^sam-' | tr '\n' ' ')"
	log "  pluginy, které mají být vypnuté a nejsou: $(wpp plugin list --status=active --field=name | grep -E '^(event-tickets-with-ticket-scanner|email-template-customizer-for-woo|woo-stripe-payment|woo-fakturoid|insert-headers-and-footers)' | tr '\n' ' ')—"
	log "  migrace: $(for m in 20261119 20261119b 20261120 20261121; do printf '%s=%s ' "$m" "$(wpp option get sam_content_migration_${m}_done >/dev/null 2>&1 && echo ok || echo CHYBÍ)"; done)"
	log "  vstupenky: $(sql -N -e "SELECT CONCAT(COUNT(*),' vstupenek, ',SUM(status='valid'),' platných') FROM ${PREFIX}sam_tickets" </dev/null 2>/dev/null || echo 'tabulka chybí')"
	log "  Stripe: $(wp eval 'echo SAM_Stripe_Config::is_test_mode() ? "TESTOVACÍ" : "OSTRÝ", " režim, klíče ", SAM_Stripe_Config::keys_match() ? "sedí" : "NESEDÍ", ", webhook secret ", SAM_Stripe_Config::webhook_secret() ? "ano" : "CHYBÍ";')"
	log "  pojistka testovacího webu: $([ -f "$SAFETY" ] && echo zapnutá || echo odstraněná)"
	if [ "$REZIM" = ostre ]; then
		log "  Fakturoid: $(wp sam-fakturoid check 2>&1 | tail -1)"
		log "  SMTP: $(wp eval 'echo SAM_SMTP::configured() ? "nastaveno" : "CHYBÍ";')"
	fi
	local u r
	for u in / /en/ /cenik/ /kontakt/ /cart/ /checkout/ /en/price-list/ /sitemap_index.xml /robots.txt /wp-login.php; do
		r=$(page "$u")
		log "  $r  $u"
	done
	page / >/dev/null
	log "  úvodní stránka: $(grep -c 'b-hero' /tmp/prechod-page.html) × b-hero, odkazů na cizí doménu: $(grep -oE "https?://(www\.)?(steelartmuseum\.eu|steelartmuseum-1028\.rostiapp\.cz)" /tmp/prechod-page.html | grep -vc "$SITE_HOST" || true)"
	log "  chyby PHP za posledních 15 min: $(docker compose logs --since 15m php 2>&1 | grep -ciE 'fatal|parse error' || true)"
}

krok_stav() {
	wpp plugin list --fields=name,status --format=csv
	echo "== šablona $(wpp option get stylesheet)"
	echo "== menu"; wpp menu location list --format=csv
	for o in show_on_front page_on_front woocommerce_checkout_page_id timezone_string blog_public permalink_structure; do echo "$o=$(wpp option get $o)"; done
	echo "== sam_*"; wpp option list --search='sam_*' --field=option_name
	echo "== brány"; wp eval 'foreach ( WC()->payment_gateways()->payment_gateways() as $g ) { if ( "yes" === $g->enabled ) { echo $g->id, " "; } } echo "\n";'
	echo "== role"; wpp role list --field=role | tr '\n' ' '; echo
	echo "== cron"; wpp cron event list --fields=hook,recurrence --format=csv | grep -i sam || true
}

lftp_wedos() { # spustí příkazy lftp proti WEDOS (údaje v /srv/stack/import/.netrc-wedos)
	[ -f import/.netrc-wedos ] || die "chybí import/.netrc-wedos (FTP údaje WEDOS)"
	docker run --rm -i -v /srv/stack/import:/import:ro -v /srv/stack/www:/data alpine:3.20 sh -c '
		apk add --no-cache lftp >/dev/null 2>&1
		U=$(awk "/login/{print \$2}" /import/.netrc-wedos); P=$(awk "/password/{print \$2}" /import/.netrc-wedos)
		{ echo "set ftp:ssl-force true; set ftp:ssl-protect-data false; set net:timeout 30; set net:max-retries 3"; cat; } | lftp -u "$U","$P" 345320.w20.wedos.net'
}

krok_udrzba() {
	[ "$REZIM" = ostre ] || die "údržba starého webu jen v ostrém režimu"
	case "${1:-}" in
		on)  printf '<?php $upgrading = time();\n' > import/.maintenance
		     echo 'put -O /domains/steelartmuseum.eu/ /import/.maintenance' | lftp_wedos
		     rm -f import/.maintenance
		     log "  WEDOS: údržba zapnutá – $(curl -s -o /dev/null -w '%{http_code}' "https://$LIVE_HOST/?u=$(date +%s)") (čeká se 503)" ;;
		off) echo 'rm /domains/steelartmuseum.eu/.maintenance' | lftp_wedos
		     log "  WEDOS: údržba vypnutá" ;;
		*) die "udrzba on|off" ;;
	esac
}

krok_media() {
	echo 'mirror --only-newer --parallel=2 --exclude-glob tickets_*.pdf /domains/steelartmuseum.eu/wp-content/uploads/ /data/wp-content/uploads/' | lftp_wedos | tail -3 | tee -a "$LOG"
	chown -R 82:82 www/wp-content/uploads
}

cmd=${1:-}; shift || true
log "=== prechod.sh $cmd ($REZIM, web $SITE_HOST)"
case "$cmd" in
	vse)
		[ -n "${1:-}" ] || die "vse <soubor s dumpem>"
		T0=$SECONDS
		step zaloha; step import "$1"; step domena; step nastaveni; step klice
		step vstupenky; step pojistka; step spustit; step kontrola
		log "=== hotovo za $(( (SECONDS - T0) / 60 )) min $(( (SECONDS - T0) % 60 )) s, log: $LOG" ;;
	zaloha|import|domena|nastaveni|klice|vstupenky|pojistka|spustit|kontrola|stav|udrzba|media)
		step "$cmd" "$@" ;;
	*) sed -n '2,25p' "$0"; exit 1 ;;
esac
