# Steel Art Museum

Vlastní kód nového webu Steel Art Museum (WordPress + WooCommerce + WPML). Zadání, audit a plán jsou v `../ZADANI-NOVY-WEB.md`. Kroky v databázi pro ostrý přechod: `docs/PRECHOD.md`.

## Obsah repozitáře

- `wp-content/themes/steel-art-museum/` – vlastní šablona (design Žár, černá ocel)
- `wp-content/mu-plugins/sam-core.php` + `sam-core/` – jádro: pokladna, akce, i18n, formuláře
- `wp-content/plugins/sam-tickets/` – vstupenky: QR, PDF, skener, refundace, reporty (vychází z MoB)
- `wp-content/plugins/sam-stripe/` – platební brána Stripe
- `wp-content/plugins/sam-fakturoid/` – faktury a opravné doklady

WordPress core, uploads, databáze, konfigurace serveru a tajné údaje v repozitáři nejsou.

## Server

Rosti „stack“ (Docker): `/srv/stack`, web v `/srv/stack/www`, konfigurace stacku je v `../rosti-stack/`. SSH: `ssh sam-rosti` (shell fish; pro skripty `bash -s`).

## Nasazení

Nasazuje se jen z gitu, soubory na serveru se ručně neupravují.

```bash
scripts/deploy.sh <git-ref> --dry-run
scripts/deploy.sh <git-ref> --note "co se nasazuje"
```

Nastavení v `.env` (necommituje se): `SAM_SSH_HOST`, `SAM_SSH_PORT`, `SAM_SSH_USER`, volitelně `SAM_SSH_KEY`, `SAM_SITE_HOST`.

Skript:
1. ověří, že se soubory na serveru neliší od posledního nasazeného commitu (`/srv/stack/.deploy/deployed-commit`),
2. zazálohuje přepisované soubory a databázi do `/srv/stack/.deploy/backups/`,
3. nahraje export vedle webu, zkontroluje syntaxi PHP a teprve pak nakopíruje změněné soubory,
4. smaže stránkovou cache a zkontroluje hlavní stránky přímo na serveru; značku aktualizuje jen při úspěchu.
