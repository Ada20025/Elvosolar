# ElvoControl

Inteligentný energetický manažment pre fotovoltiku a batérie.

## 🌐 Live

- **Web:** [www.elvosolar.sk](https://elvosolar.sk)
- **App:** [app.elvosolar.sk](https://app.elvosolar.sk)

## 🏗️ Štruktúra

| Zložka | Popis |
|--------|-------|
| `Software/App/` | PHP aplikácia (ElvoControl dashboard) |
| `Software/Web/` | Statický web (elvosolar.sk) |
| `Hardware/` | Python backend pre CM5 riadiacu jednotku |

## 🚀 Deploy

Push na `main` → automatický deploy cez GitHub Actions.

Pozri `DEPLOY.md` pre detaily.

## 🔧 Hardvér

Riadiaca jednotka: **Waveshare CM5** (Raspberry Pi CM5)
- Modbus TCP/RTU komunikácia so striedačmi
- Smart meter podpora (Modbus RTU, S0 pulse, Cloud API)
- Pripojenie: Ethernet / Wi-Fi / LTE
- FVE riadenie: AI EMS, Self-Consumption, Smart, Unlimited
- Stavové LED: **3× SK6812 RGBW** (vystrihnuté z pásku) na **GPIO 27**

## 📱 PWA

Aplikácia je dostupná ako Progressive Web App — nainštalujte z prehliadača na plochu.

Z LED svetla je v aplikácii **iba baterka**: okrúhle tlačidlo v **pravom dolnom rohu**
Prehľadu rozsvieti čistú bielu (kanál W) ako baterka na telefóne; po zapnutí sa nad ním
objaví posuvník jasu.
Farba a vzor 3 stavových LED sú signalizácia zariadenia — do appky sa neposielajú.
Ďalej appka obsahuje **notifikácie** (push + e-mail, nastavenie v profile).

## 🚦 LED signalizácia (3 LED SK6812 RGBW, GPIO 27)

| Farba | Vzor | Význam |
|---|---|---|
| Zelená | ticho | všetko OK, komunikácia beží |
| Modrá | pomaly dýcha | štart systému |
| Tmavomodrá | dýcha | pripájanie zbernice / cloudu |
| Azúrová | 2× blik | setup — čaká na nastavenie |
| Azúrová | rýchlo bliká | WiFi AP hotspot aktívny |
| Modrozelená | pulz | komunikácia s cloudom |
| Fialová | behá po 3 LED | hľadanie zariadení na zbernici |
| Jantárová | dýcha | varovanie: teplota ≥ 65 °C alebo SOC ≤ 10 % |
| Červená | 3× blik | striedač neodpovedá (Modbus timeout) |
| Červeno-oranžová | 5× rýchlo | príkaz nevykonaný — test/overenie zlyhalo |
| Červená | stále svieti | porucha striedača (fault) |
| Purpurová | 4× blik | výpadok WiFi / internetu |
| Zelená | 1× / 2× / 3× blik | update: kontrola / sťahovanie / inštalácia |
| Zelená | rýchly pulz | reštart po update |
| Biela | ticho | baterka z appky (čistá biela) |

Podrobné zapojenie a jednorazové povolenie overlay je v [`MANUAL.md`](MANUAL.md), sekcia 10.

## 🔔 Notifikácie a e-mail

- **Web Push** — VAPID kľúč si appka vygeneruje sama (`push_keys`), odbery sú v `push_subs`.
  Push chodia aj pri zatvorenej appke cez service worker.
- **E-mail** — na Railway je SMTP blokované, preto sa posiela cez HTTPS:
  `RESEND_API_KEY` (+ voliteľne `RESEND_FROM`), alebo relay `MAIL_RELAY_URL` + `MAIL_RELAY_KEY`.
  Bez nich appka pri registrácii zobrazí kód priamo na obrazovke.
- Kanál pre každý typ notifikácie (`both` / `push` / `email` / `none`) sa nastavuje v appke.
- Detaily a test: [`Software/App/db/README.md`](Software/App/db/README.md).

## 🗄️ Databáza pre Railway

Kompletná MySQL schéma (všetky tabuľky: používatelia, zariadenia, telemetria,
alerty, notifikácie, push, CM5 príkazy, MQTT audit, prihlásenia) je v
[`Software/App/db/schema.sql`](Software/App/db/schema.sql).
Postup importu a premenné prostredia: [`Software/App/db/README.md`](Software/App/db/README.md).
Rýchly štart bez deployu: vlož [`railway_setup.sql`](Software/App/db/railway_setup.sql) do query poľa
v Railway MySQL — vytvorí tabuľky. **Žiadny demo účet sa nezakladá** — do appky sa dostane len
tvoj admin — predvolene účet `admin@elvosolar.sk` (cez `ADMIN_EMAILS` alebo `create_admin.php` z CLI).
Testovacie dáta sú voliteľné v [`db/demo.sql`](Software/App/db/demo.sql), demo účet tam má rolu `user`.
## ✅ Kontrola po nasadení

Appka má mať vlastnú doménu (Railway → *Settings → Networking → Generate Domain*).
Po redeployi over jednu vec jedným príkazom:

```bash
./scripts/smoke.sh https://tvoja-domena.up.railway.app <KLUC> <GATE_USER> <GATE_PASS>
```

Skript kontroluje `/healthcheck`, platnosť tajného kľúča, bránu, `/login` a že API
bez session vráti JSON 401. Každý push na `main` navyše prejde CI (PHP lint,
Python compile, kontrola JS v šablónach) — viď `.github/workflows/ci.yml`.

### Cron (`POST /api/cron/notifications`, hlavička `X-Cron-Key: <CRON_SECRET>`)

Jeden endpoint robí celú údržbu, spúšťaj ho každých 10 minút:

| Krok | Čo robí |
|---|---|
| Alerty zariadení | offline > 15 min, chyba striedača, nízke SOC, vysoká teplota — push + mail |
| Stav zariadení | `devices.status` prepne na `offline`/`online` podľa `last_seen` |
| Denný súhrn | raz denne po 07:00 |
| Záporná cena | OKTE blok so zápornou cenou |
| Telemetria | denná agregácia do `telemetry_daily` + mazanie raw dát starších ako 90 dní |
| Mesačný report | 1. deň v mesiaci po 07:00 — výroba v kWh za predošlý mesiac |

### Mesačný report (A4 → PDF)

- V appke: **`/report?month=YYYY-MM`** (odkaz je aj v Profil → Mesačný report a v mesačnom maily).
- Stránka je pripravená na tlač: **Ctrl+P → Uložiť ako PDF** (tlačidlo „Uložiť ako PDF“ je priamo na nej).
- Dáta: denný agregát `telemetry_daily` + hodinové SOC z `telemetry`; cena pre odhad úspory sa berie z `REPORT_PRICE_EUR` (default `0.18`).
- Odkaz v maily používa `APP_URL`; ak nie je nastavená, zoberie sa doména z requestu.
- PDF sa generuje cez tlač prehliadača — server nemá PDF knižnicu (Dompdf by sa musel doinštalovať cez Composer).

## Bezpečnosť prístupu a Cloudflare Turnstile

Aplikácia má štyri vrstvy, každá musí prejsť:
1. **Tajná mesačne rotujúca adresa** — `/<KĽÚČ>` alebo `/?k=KĽÚČ`; bez nej appka presmeruje na `www.elvosolar.sk`.
2. **Brána** (meno/heslo) — `ACCESS_GATE_USER` / `ACCESS_GATE_PASS`, inak tabuľka `access_gate`.
3. **Prihlásenie účtu** + **2FA kód e-mailom** (ak mail neodíde, kód sa zobrazí na obrazovke).
4. **Zapamätané zariadenie** — podpísaný cookie `elvo_dev` na 10 rokov, ďalej už nič nepýta.

### Cloudflare Turnstile („nie som robot“)
Políčko sa zobrazí automaticky, len čo sú nastavené kľúče — netreba meniť kód:
1. Cloudflare dashboard → **Turnstile** → *Add site* → doména appky (napr. `*.up.railway.app`).
2. Skopíruj **Site Key** a **Secret Key**.
3. Railway → služba s aplikáciou → **Variables**:
   - `TURNSTILE_SITE_KEY` = Site Key
   - `TURNSTILE_SECRET_KEY` = Secret Key
4. Redeploy. Políčko sa objaví na bráne aj na prihlasovacom formulári; server každý token overí cez `siteverify`.

Alternatíva bez Railway: súbor `Software/App/turnstile_keys.php` vráti `['site_key' => '…', 'secret_key' => '…']`.

### Premenné, ktoré treba nastaviť (bez nich je appka zraniteľnejšia)
| Premenná | Prečo |
|---|---|
| `ACCESS_URL_KEY` | Bez nej sa mesačný kľúč počíta z predvoleného semena `ELVO-APP-2026`, teda je **predvídateľný**. Nastav vlastné dlhé tajomstvo. |
| `ACCESS_GATE_USER` / `ACCESS_GATE_PASS` | Bez nich platí `admin` / `ELVO-ACCESS-2026`. |
| `ADMIN_EMAILS` | Ktoré účty sú admini. Predvolene **len `admin@elvosolar.sk`** — iný účet (napr. súkromný gmail) sa adminom nestane. |
| `SEED_DEMO` | Demo účet `demo@elvosolar.sk` / `demo123` je **predvolene vypnutý** a existujúci demo účet appka zablokuje. `SEED_DEMO=1` ho v prázdnej DB zapne (rola `user`). |
| `APP_DEBUG` | Nechaj prázdne; `1` zobrazí chyby PHP aj s cestami a SQL. |

### Čo je v kóde vynútené
- SQL len cez pripravené dotazy (`prepare`/`execute`) — žiadne vkladanie `$_GET`/`$_POST` do SQL.
- Žiadne `eval`, `shell_exec` ani nahrávanie súborov vo webovej časti.
- Cookies `HttpOnly` + `SameSite=Lax` + `Secure` (detekcia HTTPS cez `X-Forwarded-Proto`).
- HSTS, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, `COOP`.
- `display_errors` vypnuté v produkcii; chyby idú len do logu.
- Rate limit na bráne (6 pokusov / 60 s) aj na prihlásení (3 pokusy / 30 min, blokácia zariadenia po 3 zlých 2FA kódoch).
- API vracia 401 JSON (nie HTML), admin endpointy kontrolujú rolu zo session.

### Čo ostáva na tebe
- Nastaviť `ACCESS_URL_KEY`, `ACCESS_GATE_PASS` a vypnúť demo, keď ho už nepotrebuješ.
- Zálohy MySQL (Railway → Backups) — bez nich sa nedá obnoviť stav po havárii.
- Akýkoľvek systém sa dá prelomiť; žiadne „100 %“ neexistuje. Toto je štandardná úroveň zabezpečenia pre jednu firmu, nie bankový štandard.

---

*2011–2026 ElvoControl. Všetky práva vyhradené.*
