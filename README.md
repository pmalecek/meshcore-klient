# MeshCore klient

Webový klient pro [MeshCore](https://meshcore.co.uk/), který má dva režimy:

- **Home Assistant:** běží nad integrací [meshcore-ha](https://github.com/meshcore-dev/meshcore-ha).
  Companion je připojený k Home Assistantu a klient k němu přistupuje z prohlížeče odkudkoli.
- **Bluetooth (`/ble`):** připojí companion přímo z prohlížeče přes Web Bluetooth (Chrome / Edge).
  Funguje i bez přihlášení a lze ho nainstalovat jako aplikaci (PWA).

Používá PHP 8.3, MariaDB / MySQL, jQuery a Bootstrap 5. Všechny knihovny jsou přibalené lokálně, nic se nenačítá z CDN.
Rozhraní je v češtině.

## Co umí

- **Zprávy a kanály:**
  - DM, kanály a room servery.
  - Nepřečtené zprávy, oblíbené, vyhledávání, zmínky `@[jméno]`, vizitky kontaktů.
  - Skrývání duplicitních zpráv.
- **Doručení DM:** 3 pokusy známou cestou, pak reset cesty a 3 pokusy floodem. Potvrzení přes ACK.
- **Limit provozu v meshcore-ha:**
  - Zprávy do kanálů čekají ve frontě a odešlou se samy.
  - Ukazatel zbývajícího kreditu.
  - Při malém kreditu se zkrátí opakování DM.
- **Heard:**
  - Přes [CoreScope analyzer](https://analyzer.meshcore.cz) klient zjistí, kolik observerů zachytilo odeslanou zprávu v kanálu.
  - Zobrazí region (scope) zprávy.
- **Trasy:**
  - Zachycené cesty paketu s dohledáním repeaterů a minimapa trasy.
  - Mapa sítě.
  - Tlačítko **Odpovědět trasou**: pošle `@[jméno]: Rep1←Rep2←…`.
- **Kontakty:**
  - Přidání z vizitky, klíče nebo slyšených uzlů, úklid starých kontaktů.
  - Stav repeaterů a vzdálená správa (přihlášení jako host / admin, příkazy).
- **Zařízení:**
  - MQTT brokery, companion a sledované uzly z HA s grafy senzorů.
  - Verze firmwaru companionu.
- **Kanály:** přidání (hashtagový, Public, soukromý s klíčem i nový), odebrání a sdílení klíče.
  U každého kanálu jde nastavit region (flood scope), do kterého se zprávy odesílají.
- **Vzhled:**
  - Světlý, tmavý, podle systému nebo podle slunce.
  - Pozadí chatů (barva, vzor, vlastní obrázek).
- **Přihlášení:** jménem a kódem TOTP (Google Authenticator, Aegis, 2FAS…), volitelně i heslem.
- **Uchování zpráv:** nastavitelné globálně i pro jednotlivé konverzace.

## Jak to funguje

```
MeshCore companion ──BLE/USB── Home Assistant (meshcore-ha)
                                    │  WebSocket (události)     ▲ REST / WS (odeslání)
                                    ▼                           │
                             bin/worker.php ──► MariaDB ◄── public/ (web)

MeshCore companion ──Web Bluetooth── prohlížeč (/ble) ──(volitelně)──► server (synchronizace, heard)
```

- **Worker** (`bin/worker.php`) drží trvalé WebSocket spojení s HA.
  - Ukládá zprávy, potvrzení doručení a stav zařízení do DB.
  - Obsluhuje frontu odesílání a opakování DM.
  - Po výpadku HA nebo DB se sám znovu připojí.
- **Web** (`public/`) čte z DB a nové zprávy dostává přes long-polling. Token k HA zůstává jen na serveru.
- **Timer** (`bin/heard.php`, každých 5 s) kontroluje heard přes analyzer, stahuje regiony a uklízí staré zprávy.
- **BLE klient** (`/ble`) má data v IndexedDB prohlížeče. Přihlášený uživatel si je může synchronizovat na server.

## Požadavky

- **Server:** PHP 8.3 (CLI i web) s rozšířeními `pdo_mysql`, `curl`, `mbstring`, `openssl`, `fileinfo`.
- **Databáze:** MariaDB 10.6+ / MySQL 8.
- **Web server:** Apache 2.4 (`mod_headers`, `mod_expires`) nebo jiný. DocumentRoot musí mířit na `public/`.
- **systemd** pro worker a timer.
- **HTTPS:** nutné pro notifikace v prohlížeči, Web Bluetooth a PWA.
- **Home Assistant** s integrací meshcore-ha a long-lived tokenem administrátora. Pro samotný režim `/ble` není potřeba.

## Instalace

```bash
# 1) soubory
git clone https://github.com/pmalecek/meshcore-klient.git /var/www/meshcore-klient
cd /var/www/meshcore-klient

# 2) databáze a uživatel
mariadb -e "CREATE DATABASE meshcore CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
            CREATE USER 'meshcore'@'localhost' IDENTIFIED BY 'ZMEN_HESLO';
            GRANT ALL ON meshcore.* TO 'meshcore'@'localhost';"
mariadb meshcore < sql/schema.sql

# 3) konfigurace: databáze, adresa HA a long-lived token
#    (HA → Profil → Zabezpečení → Dlouhodobé přístupové tokeny)
cp config/config.local.php.example config/config.local.php
nano config/config.local.php

# 4) práva
mkdir -p data/backgrounds
chown -R www-data:www-data /var/www/meshcore-klient
chmod 640 config/config.local.php

# 5) první spuštění workeru ručně (Ctrl+C ukončí)
sudo -u www-data php bin/worker.php -v

# 6) worker a timer jako služby (cestu v unitách uprav, pokud je jiná než /var/www/meshcore-klient)
cp deploy/meshcore-worker.service deploy/meshcore-heard.service deploy/meshcore-heard.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now meshcore-worker meshcore-heard.timer
journalctl -u meshcore-worker -f

# 7) uživatel + TOTP (QR kód v terminálu: apt install qrencode)
php bin/user.php add admin
php bin/user.php passwd admin      # volitelně i heslo

# 8) web server – viz deploy/apache-vhost.conf.example
a2enmod headers expires
```

Pak otevři `https://tvuj-server/`. Bluetooth klient je na `https://tvuj-server/ble/`.

Na starší MariaDB (bez `utf8mb4_uca1400_ai_ci`) nahraď v `sql/schema.sql` a `sql/migrations/*.sql`
collation za `utf8mb4_unicode_ci`.

## Konfigurace

Výchozí hodnoty jsou v `config/config.php` (neupravuj ho). Vlastní hodnoty patří do `config/config.local.php`,
který se do gitu nedostane. Nejdůležitější volby:

| Klíč | Význam |
|---|---|
| `ha.url`, `ha.token` | adresa Home Assistantu a long-lived token |
| `ha.entry_id` | máš-li v HA víc companionů, omezí klienta na jeden |
| `analyzer.url` | CoreScope analyzer pro heard (výchozí `https://analyzer.meshcore.cz`) |
| `analyzer.node_name` | jméno companionu, jak ho vidí analyzer (přesnější párování) |
| `analyzer.own_observers` | vlastní observery, které se do heard nepočítají |
| `ble.public_heard` | povolit kontrolu heard i v `/ble` bez přihlášení (s limity na IP a zařízení) |
| `app.timezone` | časová zóna |
| `app.home` | `[lat, lon]` companionu pro mapu, když ji HA nevystavuje |
| `app.dedup_window` | okno pro skrývání duplicitních zpráv (s) |

## Správa uživatelů

```bash
php bin/user.php add <jmeno>       # vytvoří uživatele a vypíše TOTP
php bin/user.php reset <jmeno>     # nový TOTP klíč
php bin/user.php passwd <jmeno>    # nastaví heslo
php bin/user.php nopasswd <jmeno>  # zruší heslo (jen kód)
php bin/user.php delete <jmeno>
php bin/user.php list
```

Každý kód TOTP jde použít jen jednou. Po 5 neúspěšných pokusech z jedné IP následuje 15minutová blokace. Session platí 14 dní.

## Aktualizace

```bash
git pull
# nové migrace v sql/migrations/ spusť jen jednou, v pořadí čísel, např.:
mariadb meshcore < sql/migrations/013_channel_scope.sql
systemctl restart meshcore-worker
```

Při nové instalaci se migrace nespouští, `sql/schema.sql` už obsahuje všechno.

## Poznámky k Home Assistantu

- **Administrátor:** token musí patřit administrátorovi HA. Přidávání kanálů, vzdálená správa a opakování DM
  používají službu `meshcore.execute_command`.
- **Bez úprav HA:** klient v HA nic nepřidává ani nemění. Stav zařízení drží přes `render_template`,
  události přes WebSocket.
- **Limit provozu:** meshcore-ha v režimu **Governed** omezuje vlastní zprávy. Lane „messages“ má výchozí zásobu 10
  a doplňuje se 60 za hodinu. Klient na to umí čekat. Hodnoty lze změnit jen úpravou
  `GOVERNED_LANES` v `custom_components/meshcore/traffic.py` a aktualizace integrace je přepíše.
- **Historie:** začne se plnit od spuštění workeru, starší zprávy z HA se nepřenášejí.

## Struktura

```
bin/        worker.php (služba), heard.php (timer), user.php (uživatelé), dedup.php
config/     config.php (výchozí), config.local.php.example (vzor vlastní konfigurace)
deploy/     systemd unity, příklad Apache vhostu
public/     web (DocumentRoot), ble/ (Bluetooth klient, PWA), assets/ (JS, CSS, knihovny)
src/        PHP třídy
sql/        schema.sql (nová instalace), migrations/ (aktualizace)
```

## Licence

Kód klienta je pod licencí MIT (viz [LICENSE](LICENSE)). Přibalené knihovny mají vlastní licence:

- [Bootstrap](https://getbootstrap.com/) a [Bootstrap Icons](https://icons.getbootstrap.com/): MIT.
- [jQuery](https://jquery.com/): MIT.
- [Leaflet](https://leafletjs.com/): BSD-2-Clause.
- [meshcore.js](https://github.com/liamcottle/meshcore.js): MIT.

Mapové podklady pocházejí z © přispěvatelů [OpenStreetMap](https://www.openstreetmap.org/copyright).
