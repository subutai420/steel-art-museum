# Testy celého toku na testovacím webu

Spouští se na serveru proti kopii databáze (nikdy ne na ostrém webu). E-maily zachytí před pojistkou kopie, Stripe běží v testovacím režimu. Testovací objednávky na konci smažou.

```bash
scp tests-e2e/vstupenky.php tests-e2e/stripe.php tests-e2e/fakturoid.php sam-rosti:/srv/stack/import/
ssh sam-rosti "cd /srv/stack && docker compose run --rm -T wpcli wp eval-file /import/vstupenky.php --user=<admin> --skip-themes"
ssh sam-rosti "cd /srv/stack && docker compose run --rm -T wpcli wp eval-file /import/stripe.php --user=<admin> --skip-themes"
```

- `vstupenky.php` – ruční vstupenka, PDF, e-mail, skener (nový i starý kód), propadnutí, reporty (18 kontrol)
- `stripe.php` – platba, opakovaný pokus, zamítnutá karta, webhook, refundace po položkách, refundace staré objednávky ch_ (22 kontrol)
- `fakturoid.php` – faktura se „Chci fakturu“ i bez, odkaz v e-mailu, odběratel, opakované spuštění, opravný doklad, chyba API a nový pokus (26 kontrol). Fakturoid je napodobený, nic se neposílá do skutečného účtu.
