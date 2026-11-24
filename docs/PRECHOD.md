# Přechod na nový web (WEDOS → Rosti)

> **✅ PŘECHOD PROVEDEN 24. 11. 2026**
>
> **Průběh (časy v UTC):**
> - Údržba WEDOS 6:32, export DB 15 s, `prechod.sh vse` 4 min 34 s.
> - Přepnutí DNS v Cloudflare kolem 6:40, výpadek prodeje asi 8 minut.
> - Let's Encrypt certifikát od Rosti vystaven během pár minut. Cloudflare SSL je teď **Full (strict)**: Flexible s certifikátem na Rosti cyklí v přesměrování.
>
> **Ověřeno:**
> - Skutečný nákup #32554 kartou 219 Kč: platba Stripe, vstupenka, e-maily, faktura 2026-5758.
> - Refundace: Stripe `re_3UfDUP…`, opravný doklad 2026-5759, vstupenka zneplatněná.
> - Staré vstupenky: 2 557 kódů, odbavení sedí u všech, skener zná všechny formáty.
> - Účet tabletů `GSFtablety@gmail.com` přepnut ze staré role „pokladna“ (uměla mazat objednávky) na **Pokladní** (jen skener).
>
> **Plán B:**
> 1. Cloudflare zpět: A `46.28.106.223` a AAAA `2a02:2b88:1:4::11f` pro `@` a `www` (`*` zůstal na WEDOS).
> 2. `REZIM=ostre bash prechod.sh udrzba off`.
>
> **Zbývá:** viz část 3. Navíc:
> - `www` → bez www řeší nginx (`return 301` v `conf/nginx/default.conf`).
> - Ecomail podepisuje DKIM (`ecomail._domainkey`). Ověřit, zda SPF nepotřebuje include Ecomailu.
> - Admin dostal e-mail „Nová objednávka“ 2×.
> - Název platby v objednávce je text brány místo „Stripe – karta“.
> - Odbavení ze starého systému se ve skeneru nezobrazuje v historii (počty sedí).
> - FTP údaje WEDOS jsou na serveru (`import/.netrc-wedos`) kvůli plánu B a úklidu PDF. Smazat po úklidu.

Nový web běží na kopii databáze ze 18. 11. 2026. Při přechodu ji nahradí čerstvá databáze z WEDOS, takže všechno, co se na testovacím webu nastavilo mimo kód, se musí zopakovat. Proto je to tady sepsané krok za krokem.

**Kdo co dělá:**
- **Daniel** – přístupy a rozhodnutí: Rosti, Cloudflare, Google Tag Manager, skutečný nákup kartou, pokladní.
- **Claude** – všechno na serveru: databáze, pluginy, klíče, testy.

`wp` níže znamená `docker compose run --rm -T wpcli wp` ve složce `/srv/stack`.

**Skript [scripts/prechod.sh](../scripts/prechod.sh)** (na serveru `/srv/stack/prechod.sh`) udělá celou serverovou část dne D jedním příkazem: `REZIM=ostre bash prechod.sh vse <dump>`. Jednotlivé kroky jdou spustit i zvlášť (`bash prechod.sh` vypíše nápovědu). Logy jsou v `/srv/stack/.deploy/prechod/`.

**Generální zkouška 24. 11. 2026** (`REZIM=zkouska`, dump WEDOS z 18. 11.):
- Celý běh trval **8 min 24 s**:
  - záloha 9 s
  - import 2 min 31 s (18. 11. trval ručně 74 min)
  - přepis domény 3 min (v ostrém režimu odpadá)
  - nastavení 51 s
  - vstupenky 49 s (1 046 objednávek, 2 287 kódů)
  - spuštění a kontrola 68 s
- Nastavení po zkoušce odpovídá stavu testovacího webu před ní.
- Všech 88 kontrol testů e2e prošlo: vstupenky 18, Stripe 22, Fakturoid 26, akce 14, e-maily EN 8.
- **Odhad výpadku prodeje v den D: 15–20 minut.** Zahrnuje export databáze, skript asi 6 minut, kontrolu a přepnutí DNS.

Hotovo předem (není potřeba nic dělat):
- ✅ SMTP přes WEDOS (web@, odpovědi na info@)
- ✅ SPF v Cloudflare
- ✅ klíč Google Places
- ✅ testovací webhook Stripe
- ✅ doména pro Apple Pay a Google Pay ve Stripe

Hodnoty v `/srv/stack/.env` import databáze nepřepíše.

---

## 0. Rozhodnout předem – Daniel

- [ ] **Termín.** Až po skončení Black Friday (30. 11.). Ideálně úterý až čtvrtek, 7:00–9:00, kdy je nejmíň objednávek. Počítejte s výpadkem prodeje asi 15–20 minut (podle generální zkoušky).
- [ ] **Pokladní.** Seznam lidí (jméno a e-mail), kteří dostanou účet s rolí Pokladní.
- [ ] **Google Tag Manager.** Kdo opraví kontejner GTM-5RQRRHHQ (viz bod T9).
- [ ] **Export databáze v den D.** Jsou dvě možnosti:
  - Daniel udělá zálohu databáze ve WPvividu (Zálohy → jen databáze → stáhnout) a pošle soubor, stejně jako 18. 11.;
  - Claude na pár minut nahraje na WEDOS skript, který databázi vyexportuje sám, a hned ho smaže. Potřebuje k tomu Danielův souhlas.
- [ ] **Další odesílatelé e-mailů.** Posílá z adres @steelartmuseum.eu ještě něco jiného (Ecomail, Mailchimp, rezervační systém)? Pokud ano, přidám to do SPF.

## 1. Týden předem

### Daniel
- [ ] **Rosti.** V administraci přidat k aplikaci domény `steelartmuseum.eu` a `www.steelartmuseum.eu`. Zjistit, jak Rosti vystaví HTTPS certifikát a jestli to jde ještě před přepnutím DNS.
- [ ] **Cloudflare.** Poslat mi screenshot záznamů A/CNAME pro `steelartmuseum.eu` a `www` (kvůli případnému návratu) a nastavení SSL/TLS režimu (Flexible / Full / Full strict).
- [ ] **GTM.** Opravit kontejner (bod T9). Jde to udělat kdykoli, ale nejpozději do přechodu.
- [ ] Dát vědět pokladním, kdy se přechází. Skener bude na nové adrese.

### Claude
- [x] **Generální zkouška** s databází z 18. 11. *(24. 11. 2026, výsledky nahoře)*
- [ ] Den nebo dva před přechodem ještě jednou rychlá zkouška s čerstvým dumpem: `REZIM=zkouska bash prechod.sh vse <dump>` a testy e2e.
- [ ] Dosynchronizovat média nahraná na WEDOS od 19. 11. Na server znovu nahrát FTP údaje (`.netrc`), po přechodu je zase smažu.
- [ ] Připravit hodnoty DNS pro Rosti, které Daniel na den D přepne v Cloudflare.

## 2. Den D – v tomto pořadí

| # | Kdo | Krok | Detail |
|---|---|---|---|
| 1 | Claude | Poslední synchronizace médií z WEDOS (`prechod.sh media`) | T1 |
| 2 | Claude | **Starý web do údržby** (`REZIM=ostre prechod.sh udrzba on`). Na WEDOS se nahraje soubor `.maintenance`, od teď se nedá nakupovat. Začíná výpadek. | T1 |
| 3 | Daniel / Claude | Export databáze z WEDOS (způsob podle bodu 0) | T1 |
| 4–8 | Claude | **`REZIM=ostre bash prechod.sh vse <dump>`** (asi 6 min). Udělá:<br>• záloha a import<br>• nastavení a pluginy (vypne staré: vstupenky, e-mailovou šablonu, Stripe, Toret, WPCode)<br>• Stripe naostro: klíče ze starého pluginu do `.env` a ostrý webhook přes API<br>• Fakturoid z Toretu do `.env`<br>• převod vstupenek<br>• odstranění pojistky testovacího webu<br>• restart, cache, recenze Google | T2–T8, T10 |
| 9 | Claude | Kontrola nového webu ještě na staré DNS. Skript ji dělá sám přes nginx s hlavičkou Host. Navíc ručně: skener, zkušební e-mail, Fakturoid check. | – |
| 10 | **Daniel** | **Cloudflare: přepnout DNS** `steelartmuseum.eu` a `www` na Rosti (hodnoty pošlu). Proxy (oranžový mráček) nechat zapnutou. | – |
| 11 | Claude | Ověřit HTTPS, přesměrování www, `/en/`, sitemap a robots. Konec výpadku. | – |
| 12 | **Daniel** | **Skutečný nákup.** 1 vstupenka kartou na počítači a 1 přes Apple Pay nebo Google Pay na mobilu. Musí přijít e-mail s PDF a faktura. | – |
| 13 | Claude | Refundace obou nákupů z administrace. Ověřím vrácení ve Stripe, opravný doklad ve Fakturoidu a zneplatněnou vstupenku. | – |
| 14 | **Daniel** | Pokladní naskenuje novou vstupenku a jednu starou (z doby před přechodem) | – |
| 15 | Claude | Založit účty pokladních ze seznamu z bodu 0 | T3 |

**Plán B (něco zásadního nefunguje):**
1. Daniel v Cloudflare vrátí původní záznamy z části 1.
2. Claude smaže `.maintenance` na WEDOS a starý web jede dál jako dřív.
3. Objednávky, které mezitím vznikly na novém webu, dohledám ve Stripe a vyřešíme je ručně.

## 3. Po přechodu

### Claude (ten den až týden)
- [ ] Hlídat logy PHP, odeslané e-maily, faktury ve Fakturoidu a webhooky ve Stripe (2–3 dny).
- [ ] Smazat testovací webhook Stripe (`we_1Uf1oDLZVLEtBtpM45NMuDtJ` na rostiapp.cz) a po týdnu i starý webhook `…/wp-json/wc-stripe/v1/webhook` (ostrý i testovací).
- [ ] Smazat stránku `/novy-design/` a šablonu `page-templates/home-b.php`.
- [ ] Na WEDOS smazat staré PDF vstupenky v `uploads/tickets_*.pdf`. Jsou veřejně stažitelné.
- [ ] Smazat FTP údaje ze serveru Rosti.

### Daniel
- [ ] Google Search Console: odeslat sitemapu a po pár dnech zkontrolovat chyby.
- [ ] GTM: ověřit, že se po souhlasu měří (GA4 v reálném čase).
- [ ] Za 2–3 týdny zrušit **webhosting** na WEDOS. **E-maily (MX) ale zůstávají na WEDOS.** Před zrušením ověřit u WEDOS, že schránky nejsou vázané na webhosting.

---

## Technické detaily

### T1 – Databáze a soubory
- Záloha kopie: `/srv/stack/.deploy/backups`. Databáze WEDOS se exportuje přes FTP a skript na serveru stejně jako 18. 11. (`/srv/stack/import`).
- Média: noční skript `mirror-*.sh` v `/srv/stack/import`. Kopíruje jen rozdíly. Staré PDF vstupenek se nekopírují, kromě platných podle `keep-ids.txt`.
- `wp-config.php` na Rosti je vlastní (DB z prostředí, `DISABLE_WP_CRON`), z WEDOS se nepřenáší.
- Údržba WEDOS: soubor `.maintenance` v kořeni webu (`/domains/steelartmuseum.eu/`) s obsahem `<?php $upgrading = time();`.

### T2 – Prostředí
- `wp option update timezone_string Europe/Prague` – web neměl nastavené časové pásmo (UTC). Bez toho by platnost vstupenek končila v 1:00 a časy objednávek by byly o hodinu posunuté. *(19. 11. 2026)*
- `wp option delete wcml_currency_switcher_template_objects` – WCML si v ní pamatuje cesty na disku WEDOS a styl přepínače měn by se načítal ze starého webu. *(19. 11. 2026)*

### T3 – Vstupenky (sam-tickets)
- `wp plugin deactivate event-tickets-with-ticket-scanner-premium event-tickets-with-ticket-scanner` – starý systém vstupenek. Tabulky v databázi zůstávají, import z nich čte. *(19. 11. 2026)*
- `wp plugin deactivate email-template-customizer-for-woo` – přepisoval předmět i obsah e-mailu se vstupenkou. *(19. 11. 2026)*
- `wp plugin activate sam-tickets` – vytvoří tabulky a roli Pokladní a naplánuje denní úlohu (6:00).
- `wp sam-tickets import-legacy` převede dosud platné vstupenky starého systému (k 19. 11. 2026: 1 046 objednávek, 2 287 kódů). Dá se spustit opakovaně.
- Ve Vstupenky → Nastavení a reporty zkontrolovat datum platnosti (výchozí 30. 6. 2027) a e-mail pro reporty (info@steelartmuseum.eu).
- Účty pokladních s rolí **Pokladní** (po přihlášení se otevře rovnou skener).

### T4 – Platby (sam-stripe)
- Ostré klíče jsou v databázi starého pluginu: volba `woocommerce_stripe_api_settings`, položky `secret_key_live` a `publishable_key_live`. Uloží se do `/srv/stack/.env` jako `SAM_STRIPE_SECRET_KEY` a `SAM_STRIPE_PUBLISHABLE_KEY`, potom `docker compose up -d php cron`.
- Ostrý webhook se zakládá přes API ostrým klíčem (`POST /v1/webhook_endpoints`, `api_version=2024-06-20`):
  - URL: `https://steelartmuseum.eu/wp-json/sam-stripe/v1/webhook`
  - události: `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`
  - secret (`whsec_…`) se uloží rovnou do `.env` jako `SAM_STRIPE_WEBHOOK_SECRET`.
- *(23. 11. 2026: na kopii ověřeno skutečným doručením webhooku: platba, zamítnutá karta, refundace z webu i ruční refundace ve Stripe.)*
- `wp plugin deactivate woo-stripe-payment` – starý plugin Stripe. Refundace jeho objednávek (stripe_cc, stripe_googlepay…) převezme sam-stripe přes API. *(19. 11. 2026)*
- `wp plugin activate sam-stripe` a zapnout bránu (WooCommerce → Nastavení → Platby → Stripe: karta, Apple Pay, Google Pay).
- Pokladna: stránka „Pokladna“ (ID 9) má místo bloku WooCommerce obsahovat shortcode `[woocommerce_checkout]`, EN překlad stránky stejně. *(19. 11. 2026)*
- Doména `steelartmuseum.eu` je ve Stripe pro Apple Pay a Google Pay zaregistrovaná a aktivní *(23. 11. 2026)*. Ověří se nákupem v kroku 12.

### T5 – Faktury (sam-fakturoid)
- Do `.env`:
  - `SAM_FAKTUROID_ACCOUNT=roslincorp`
  - `SAM_FAKTUROID_CLIENT_ID` a `SAM_FAKTUROID_CLIENT_SECRET` z voleb Toretu `wc_settings_tab_fakturoid_client_id` a `_client_secret`
  - `SAM_FAKTUROID_NUMBER_FORMAT=235201`
  - potom `docker compose up -d php cron`.
- `wp plugin deactivate woo-fakturoid` a `wp plugin activate sam-fakturoid`. Kontrola: `wp sam-fakturoid check`. *(19. 11. 2026)*
- Staré objednávky s fakturou z Toretu (meta `fakturoid_invoice_id`) novou fakturu nedostanou. Refundace k nim vystaví opravný doklad.
- Ověřeno na skutečném účtu 20. 11. 2026: faktura **2026-5676** (349 Kč) a opravný doklad **2026-5677** (−349 Kč). Navzájem se ruší.

### T6 – Šablona (steel-art-museum)
- `wp theme activate steel-art-museum` a `wp menu location assign main primary` (EN menu přiřadí WPML). GeneratePress a GP Premium můžou zůstat nainstalované kvůli návratu. Obsah stránek dál potřebuje plugin GenerateBlocks. *(19. 11. 2026)*
- Úvodní stránka je varianta B (`front-page.php`).
- Migrace obsahu proběhnou samy při prvním načtení webu. Kontrola:
  - `wp option get sam_content_migration_20261119_done` a `…20261119b_done` – kontakt
  - `wp option get sam_content_migration_20261120_done` – SEO titulky
  - `wp option get sam_content_migration_20261121_done` – zobecněné texty bez jmen postav
- Až potom smazat cache: `rm -rf wp-content/cache/all/*` a `wp cache flush` (Redis).

### T7 – Akce a Magenta (sam-core/promo.php)
- `wp plugin deactivate insert-headers-and-footers` vypne WPCode i se všemi snippety:
  - akce Black Friday (#32397)
  - kalkulačky Magenta CZ/EN (#21251, #26288)
  - nebezpečný hromadný přepis kupónů `?opravit=preklep2` (#26319)

  Nahrazuje je modul **WooCommerce → Akce** a kalkulačka v šabloně. Shortcody `[smart_ticket_calculator_cz_magenta]` a `_en_magenta` zůstávají na stránkách Magenta Moments.
- WooCommerce → Akce: zkontrolovat, že akce je vypnutá nebo nastavená podle aktuální kampaně. Black Friday končí 30. 11. 2026 23:59.

### T8 – Server
- Smazat `wp-content/mu-plugins/000-rosti-staging-safety.php` (blokuje e-maily, HTTP a Action Scheduler) a `docker compose restart php cron`.
- nginx: ostrá doména nedostává hlavičku `X-Robots-Tag: noindex` (mapa `$sam_robots`). Testovací doména ji má dál. Nic se nemění.

### T9 – Souhlas s cookies a GTM (sam-core/consent.php)
- Lišta a Consent Mode v2 jsou v kódu. GTM vkládá web až po souhlasu.
- **GTM-5RQRRHHQ (Daniel nebo správce GTM):** kontejner obsahuje značku, která sama udělí souhlas se vším (cookie `consent_granted`). Tu značku odstranit. Souhlas řídit událostí `sam_consent_update` a proměnnými `sam_analytics` a `sam_marketing`. Stav Consent Mode posílá web sám. Do opravy se při volbě „pouze analytika“ v GTM udělí i reklamní souhlas.

### T10 – Recenze Google (sam-core/reviews.php)
- Klíč `SAM_GOOGLE_PLACES_KEY` už je v `.env`. Po odstranění pojistky spustit `wp sam-reviews refresh`: dohledá místo a stáhne hodnocení, počet recenzí a nejnovější recenze CZ + EN. Dál se obnovuje samo jednou týdně.

### T11 – E-maily (sam-core/smtp.php) ✅
- V `.env` jsou údaje `SAM_SMTP_*`: wes1-smtp.wedos.net:587, schránka web@steelartmuseum.eu, odpovědi na info@. Zkouška: `wp sam-smtp test <adresa>` (23. 11. 2026 OK).
- SPF v Cloudflare: `v=spf1 mx a include:_spf.we.wedos.net ~all` (23. 11. 2026). DKIM podepisuje WEDOS automaticky.
