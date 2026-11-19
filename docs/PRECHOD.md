# Ostrý přechod – kroky v databázi

Vývoj běží na kopii databáze. Při přechodu ji nahradí čerstvá databáze z WEDOS, proto se všechny změny nastavení, které na testovacím webu vznikly mimo kód, musí zopakovat. Sem patří každá taková změna (s datem a důvodem).

Pořadí platí pro ostrou databázi po importu (`wp` = `docker compose run --rm -T wpcli wp` ve `/srv/stack`).

## Prostředí
1. `wp option update timezone_string Europe/Prague` – web neměl nastavené časové pásmo (UTC). Bez toho by platnost vstupenek končila v 1:00 a časy objednávek byly o hodinu posunuté. *(19. 11. 2026)*
2. `wp option delete wcml_currency_switcher_template_objects` – WCML si v ní pamatuje cesty na disku WEDOS a styl přepínače měn by se načítal ze starého webu. *(19. 11. 2026)*

## Vstupenky (sam-tickets)
3. `wp plugin deactivate event-tickets-with-ticket-scanner-premium event-tickets-with-ticket-scanner` – starý systém vstupenek. Tabulky v databázi zůstávají, import z nich čte. *(19. 11. 2026)*
4. `wp plugin deactivate email-template-customizer-for-woo` – přepisoval předmět i obsah e-mailu se vstupenkou. *(19. 11. 2026)*
5. `wp plugin activate sam-tickets` – vytvoří tabulky, roli Pokladní a naplánuje denní úlohu (6:00).
6. `wp sam-tickets import-legacy` – převede dosud platné vstupenky starého systému (k 19. 11. 2026: 1 046 objednávek, 2 287 kódů). Opakovatelné.
7. Ve Vstupenky → Nastavení a reporty zkontrolovat datum platnosti (výchozí 30. 6. 2027) a e-mail pro reporty (info@steelartmuseum.eu).
8. Založit účty pokladních s rolí **Pokladní** (po přihlášení rovnou skener).

## Server
9. Smazat `wp-content/mu-plugins/000-rosti-staging-safety.php` (blokuje e-maily a HTTP) a zapnout kontejner `cron`.
10. nginx: v mapě `$sam_auth` už je ostrá doména bez hesla; testovací doména zůstává za heslem.
