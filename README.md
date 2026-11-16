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
Rýchly štart bez deployu: vlož [`railway_setup.sql`](Software/App/db/railway_setup.sql)
do query poľa v Railway MySQL — vytvorí tabuľky aj dočasné demo zariadenia
(`demo@elvosolar.sk` / `demo123`, zariadenia `DEMO-CM5-001` a `DEMO-CM5-002`).

---

*2011–2026 ElvoControl. Všetky práva vyhradené.*
