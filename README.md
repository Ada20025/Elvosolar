# ElvoControl

Inteligentný energetický manažment pre fotovoltiku a batérie.

## 🌐 Live

- **Web:** [www.elvosolar.sk](https://www.elvosolar.sk)
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

## 📱 PWA

Aplikácia je dostupná ako Progressive Web App — nainštalujte z prehliadača na plochu.

---

*2011–2026 ElvoControl. Všetky práva vyhradené.*
