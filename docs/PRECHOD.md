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

## Platby (sam-stripe)
9. Do `/srv/stack/.env` doplnit **ostré** klíče `SAM_STRIPE_SECRET_KEY`, `SAM_STRIPE_PUBLISHABLE_KEY` a `SAM_STRIPE_WEBHOOK_SECRET` (webhook pro ostrou doménu, události `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`) a `docker compose up -d php cron`.
10. `wp plugin deactivate woo-stripe-payment` – starý plugin Stripe. Refundace jeho objednávek (stripe_cc, stripe_googlepay…) převezme sam-stripe. *(19. 11. 2026)*
11. `wp plugin activate sam-stripe` a zapnout bránu (WooCommerce → Nastavení → Platby → Stripe – karta, Apple Pay, Google Pay).
12. Pokladna: stránka „Pokladna“ (ID 9) má místo bloku WooCommerce obsahovat shortcode `[woocommerce_checkout]` (EN překlad stránky stejně). *(19. 11. 2026, do vlastní pokladny ve fázi 4)*
13. V Stripe zaregistrovat ostrou doménu pro Apple Pay (Nastavení → Platební metody → Domény).

## Šablona (steel-art-museum)
14a. `wp theme activate steel-art-museum` a `wp menu location assign main primary` (EN menu přiřadí WPML). GeneratePress a GP Premium můžou zůstat nainstalované kvůli návratu, obsah stránek dál potřebuje plugin GenerateBlocks. *(19. 11. 2026)*
14b. Po přepnutí smazat stránkovou cache (`rm -rf wp-content/cache/all/*`).

## Server
14. Smazat `wp-content/mu-plugins/000-rosti-staging-safety.php` (blokuje e-maily a HTTP) a zapnout kontejner `cron`.
15. nginx: testovací doména je od 19. 11. 2026 bez hesla, jen s hlavičkou `X-Robots-Tag: noindex` (mapa `$sam_robots`); ostrá doména hlavičku nedostává. Nic není potřeba měnit.
