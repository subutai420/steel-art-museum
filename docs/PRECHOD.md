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

## Faktury (sam-fakturoid)
13a. Do `/srv/stack/.env` doplnit `SAM_FAKTUROID_ACCOUNT=roslincorp`, `SAM_FAKTUROID_CLIENT_ID`, `SAM_FAKTUROID_CLIENT_SECRET` (API v3 – stejné údaje, jaké má v nastavení plugin Toret, nebo nové v Nastavení → Uživatelský účet → API) a `SAM_FAKTUROID_NUMBER_FORMAT=235201` (řada, kterou používal Toret). `docker compose up -d php cron`.
13b. `wp plugin deactivate woo-fakturoid` a `wp plugin activate sam-fakturoid`. Kontrola přihlášení: `wp sam-fakturoid check`. *(19. 11. 2026)*
13c. Staré objednávky s fakturou z Toretu (meta `fakturoid_invoice_id`) novou fakturu nedostanou; refundace k nim vystaví opravný doklad.
13d. Ověřeno na skutečném účtu 20. 11. 2026 s údaji z pluginu Toret (volby `wc_settings_tab_fakturoid_client_id` / `_client_secret`): testovací faktura **2026-5676** (349 Kč, Koncový zákazník, objednávka 32442 na kopii) a opravný doklad **2026-5677** (−349 Kč). Oba jsou v účtu roslincorp jako test nového webu, navzájem se ruší.

## Šablona (steel-art-museum)
14a. `wp theme activate steel-art-museum` a `wp menu location assign main primary` (EN menu přiřadí WPML). GeneratePress a GP Premium můžou zůstat nainstalované kvůli návratu, obsah stránek dál potřebuje plugin GenerateBlocks. *(19. 11. 2026)*
14b. Po přepnutí smazat stránkovou cache (`rm -rf wp-content/cache/all/*`).
14d. Úvodní stránka je varianta B (`front-page.php`). Srovnávací stránku `/novy-design/` (ID 32441 na kopii) po spuštění smazat, šablona `page-templates/home-b.php` může jít pryč s ní.
14e. SEO titulky a popisy (úvodní CZ/EN, ceník, kontakt) doplní sama migrace `sam-core/content-migration-20261120.php` (vyplněné hodnoty nepřepisuje). Kontrola: `wp option get sam_content_migration_20261120_done`.
14c. Stránka Kontakt se upraví sama při prvním načtení webu (`sam-core/content-migration-20261119.php`: vlastní formulář místo Gutena Forms, pryč trustindex a mapa). Kontrola: `wp option get sam_content_migration_20261119_done`. Až potom smazat cache (14b).

## Akce a Magenta (sam-core/promo.php)
16. `wp plugin deactivate insert-headers-and-footers` – vypne WPCode i se všemi snippety: akce Black Friday (#32397), kalkulačky Magenta CZ/EN (#21251, #26288) a nebezpečný hromadný přepis kupónů `?opravit=preklep2` (#26319). Nahrazuje je modul **WooCommerce → Akce** a kalkulačka v šabloně (shortcody `[smart_ticket_calculator_cz_magenta]` / `_en_magenta` zůstávají na stránkách Magenta Moments). Dokud je snippet akce aktivní, modul se sám vypne.
17. WooCommerce → Akce: zkontrolovat nastavení. Při prvním spuštění se převezme Black Friday „každá druhá zdarma“ do 30. 11. 2026 23:59 (pak se sama vypne). Kódy MAGENTA-xxxxxx zůstávají jako kupóny WooCommerce, nové se generují tamtéž.

## E-maily (sam-core/smtp.php)
18. Do `/srv/stack/.env` doplnit `SAM_SMTP_HOST`, `SAM_SMTP_PORT` (465), `SAM_SMTP_USER`, `SAM_SMTP_PASS` (schránka info@steelartmuseum.eu na WEDOS), případně `SAM_SMTP_FROM`. `docker compose up -d php cron wpcli`. Zkouška: `wp sam-smtp test <adresa>`.
19. DNS (Cloudflare): doplnit SPF záznam pro odesílání přes WEDOS (podle návodu WEDOS), jinak hrozí, že vstupenky skončí ve spamu.

## Souhlas s cookies (sam-core/consent.php)
20. Lišta a Consent Mode v2 jsou v kódu, nic se nezapíná. Google Tag Manager vkládá modul až po souhlasu (vkládání pluginem DataLayer for eCommerce se vypíná).
21. **GTM-5RQRRHHQ:** kontejner obsahuje značku, která sama udělí souhlas se vším (cookie `consent_granted`). Upravit v GTM: značku odstranit a souhlas řídit událostí `sam_consent_update` / proměnnými `sam_analytics`, `sam_marketing` (Consent Mode stav posílá web sám). Do té doby web GTM bez souhlasu vůbec nenačte, takže je to bezpečné, jen s „pouze analytikou“ se v GTM udělí i reklamní souhlas.

## Server
14. Smazat `wp-content/mu-plugins/000-rosti-staging-safety.php` (blokuje e-maily a HTTP) a zapnout kontejner `cron`.
15. nginx: testovací doména je od 19. 11. 2026 bez hesla, jen s hlavičkou `X-Robots-Tag: noindex` (mapa `$sam_robots`); ostrá doména hlavičku nedostává. Nic není potřeba měnit.
