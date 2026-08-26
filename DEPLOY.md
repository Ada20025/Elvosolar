# ElvoControl — Deployment Guide

## Architektúra

```
www.elvosolar.sk     → Statický web (Marketing, produkty, eshop)
app.elvosolar.sk     → ElvoControl PHP aplikácia (Dashboard, riadenie)
```

## 1. Alwaysdata nastavenie

### Vytvor 2 webové služby na alwaysdata:

**Služba 1 — Web (www.elvosolar.sk)**
- Typ: Static
- Document root: `/www/www.elvosolar.sk`
- Doména: `www.elvosolar.sk`

**Služba 2 — App (app.elvosolar.sk)**
- Typ: PHP
- Document root: `/www/app.elvosolar.sk`
- Doména: `app.elvosolar.sk`
- PHP verzia: 8.1+

### Databáza
- Typ: MySQL
- Vytvor DB pre app (meno: `elvocontrol`, heslo: generuj)

## 2. GitHub Secrets (Settings → Secrets)

Nastav tieto secrets v GitHub repo:

| Secret | Popis |
|--------|-------|
| `ALWAYSDATA_HOST` | `ssh-cluster-1-alwaysdata-com` (alebo tvoj SSH host) |
| `ALWAYSDATA_USER` | Tvoje alwaysdata SSH meno (číslo účtu) |
| `ALWAYSDATA_SSH_KEY` | Privátny SSH kľúč pre prístup |

### Ako získať SSH prístup:
1. V alwaysdata panel → Nastavenia → SSH kľúče
2. Pridaj verejný kľúč (`~/.ssh/id_rsa.pub`)
3. Privátny kľúč (`~/.ssh/id_rsa`) daj do GitHub Secrets

## 3. Lokálna konfigurácia

### config.php — uprav DB údaje:
```php
$DB_HOST = "mysql-elvocontrol.alwaysdata.com";
$DB_USER = "elvocontrol";
$DB_PASS = "tvoje_heslo_z_alwaysdata";
$DB_NAME = "elvocontrol";
```

### Cloud sync URL v DB (system_settings):
```
cloud_sync_url = https://app.elvosolar.sk/api/cloud/sync-telemetry
```

## 4. Deploy

Push na `main` branch → automatický deploy cez GitHub Actions.

```
git add .
git commit -m "Update"
git push origin main
```

## 5. Prvotné nastavenie

1. Otvor `app.elvosolar.sk/setup` — provisioning wizard
2. Pripoj CM5 cez Bluetooth/LAN
3. Nastav cloud sync na `app.elvosolar.sk`
4. Vytvor admin účet cez `app.elvosolar.sk/register`

## Súbory

```
Software/
├── App/                    → app.elvosolar.sk
│   ├── index.php           → Router
│   ├── config.php          → DB config
│   ├── device_db.php       → Zariadenia DB
│   ├── .htaccess           → URL rewriting
│   ├── manifest.json       → PWA manifest
│   ├── sw.js               → Service Worker
│   └── templates/          → HTML šablóny
│       ├── dashboard.html
│       ├── device_detail.html
│       ├── prihlasenie.html
│       ├── registracia.html
│       └── ...
├── Web/
│   └── www.elvosolar.sk/   → www.elvosolar.sk
│       ├── index.html
│       ├── smart-riesenia/
│       ├── eshop/
│       └── Png/

Hardware/                    → CM5 riadiaca jednotka
├── app.py                  → Hlavná služba
├── solar_service.py        → Riadiace jadro
├── smart_meter_service.py  → Smart meter
├── modbus_slave_service.py → Modbus TCP server
└── ...
```
