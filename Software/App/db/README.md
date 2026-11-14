# Databáza pre Railway + notifikácie a e-maily

## 1. MySQL na Railway

1. Railway → projekt → **New → Database → MySQL**.
2. Railway MySQL dáva do premených služby (Variables) hlavne:
   `MYSQL_DATABASE`, `MYSQL_PRIVATE_URL`, `MYSQL_ROOT_PASSWORD`.
   Staršie varianty: `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQL_URL`.
   `config.php` rozumie **obom** — najprv parsuje URL (`mysql://user:heslo@host:port/db`),
   potom jednotlivé premenné. Nič netreba prepisovať v kóde.
3. Tabuľky sa **vytvoria automaticky** pri prvom requeste na aplikáciu
   (`db_bootstrap.php` prejde `schema.sql`). Ak chceš mať istotu hneď:

   **A) Railway Console** (služba s aplikáciou → Console):
   ```bash
   php db/init.php
   ```
   **B) Query v MySQL službe** (*Data* → *Query*): vlož obsah [`schema.sql`](schema.sql).

   **C) Z počítača:**
   ```bash
   mysql -h <host> -P <port> -u <user> -p <db> < schema.sql
   ```

V Railway MySQL obrazovke „You have no tables" je po prvom otvorení appky normálne,
že ešte svieti — tabuľky vzniknú pri prvom requeste (alebo cez `php db/init.php`).
Skript je idempotentný (`IF NOT EXISTS`), existujúce dáta nemazá.

## 2. Web Push notifikácie

- Netreba nič nastavovať — VAPID pár si aplikácia vygeneruje pri prvom
  použití a uloží do `push_keys`.
- Na telefóne: appka → *Nastavenia* → **Notifikácie** → povoliť a „Pripojiť
  zariadenie". Odbery sa ukladajú do `push_subs`.
- Test: tlačidlo **OTESTOVAŤ NOTIFIKÁCIU** (dashboard) alebo
  `POST /api/push/test`.
- Push chodia aj pri zatvorenej appke (service worker `sw.js`).

## 3. E-maily (Railway blokuje SMTP — použi HTTPS API)

Nastav v Railway *Variables* (Služba s aplikáciou):

| Premenná | Význam |
|---|---|
| `RESEND_API_KEY` | kľúč z [resend.com](https://resend.com) (3 000 mailov/mesiac zdarma) |
| `RESEND_FROM` | voliteľne odosielateľ, napr. `ElvoControll <no-reply@elvosolar.sk>` (doména musí byť v Resende overená) |

Alternatíva bez Resendu — relay na alwaysdata:

| Premenná | Význam |
|---|---|
| `MAIL_RELAY_URL` | `https://adamdz.alwaysdata.net/mail-relay.php` |
| `MAIL_RELAY_KEY` | rovnaký kľúč, aký je na relaying hoste |

Poradie odoslania: **Resend → relay → SMTP → `mail()`**.
Ak nie je nastavené nič, appka e-mail neodošle a pri registrácii/obnovení hesla
zobrazí kód priamo na obrazovke (aby sa človek vedel prihlásiť).

Test: appka → *Nastavenia* → **OTESTOVAŤ E-MAIL** (`POST /api/user/test-email`).

## 4. Cron — notifikácie bez otvorenej appky

Alerty, denný súhrn a záporná cena sa posielajú aj na pozadí cez cron endpoint.

1. Railway → služba s aplikáciou → *Variables* → pridaj `CRON_SECRET` (ľubovoľný dlhý reťazec).
2. Railway → *Cron Schedule* (alebo externý cron) → každých 15 minút:
   ```
   curl -X POST https://<tvoja-domena>/api/cron/notifications -H "X-Cron-Key: <CRON_SECRET>"
   ```
3. Endpoint robí tri veci:
   - prejde všetky zariadenia a vyhodnotí alerty (offline > 15 min, chyba striedača, SOC < 15 %, teplota ≥ 65 °C) — push + mail podľa predvolieb,
   - raz denne po 07:00 pošle denný súhrn (Ø a max výkon, rozsah SOC za 24 h),
   - pri zápornej cene na OKTE pošle upozornenie (raz za 15-minútový blok).
   Deduplikácia je v tabuľke `notif_log`, takže cron môže bežať aj častejšie.

## 5. Ktoré notifikácie chodia

| Typ | Kedy | Kanál |
|---|---|---|
| `new_device` | prihlásenie z nového zariadenia | podľa `user_prefs` |
| `error` | zariadenie offline > 15 min, chyba striedača, nízke SOC, vysoká teplota | podľa `user_prefs` |
| `negative_price` | záporná cena na OKTE | podľa `user_prefs` |
| `daily_report` | denný súhrn (voliteľné) | podľa `user_prefs` |

Kanál pre každý typ: `both` (push + mail), `push`, `email`, `none`.
Nastavuje sa v appke v *Nastaveniach* (`POST /api/user/notifications`).
