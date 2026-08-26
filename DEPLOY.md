# ElvoControl — Deployment Guide

## Architektúra

```
GitHub: Ada20025/Elvosolar  → Railway auto-deploy
Railway: elvosolar-production.up.railway.app  → PHP + MySQL
Hardware: CM5 lokálne       → Cloud sync na Railway
OTA Updates: Ada20025/a     → Šifrovaný kód pre CM5
```

## 1. Railway (PHP + MySQL)

### Nastavenie:
1. Vytvor účet na **railway.app** → Sign up with GitHub
2. "New Project" → "Deploy from GitHub repo" → `Ada20025/Elvosolar`
3. Pridaj MySQL: "+ New" → "Database" → "MySQL"
4. Premenné prostredia v Elvosolar service:
   - `MYSQLHOST` = `${{MySQL.MYSQLHOST}}`
   - `MYSQLPORT` = `${{MySQL.MYSQLPORT}}`
   - `MYSQLUSER` = `${{MySQL.MYSQLUSER}}`
   - `MYSQL_ROOT_PASSWORD` = `${{MySQL.MYSQLROOTPASSWORD}}`
   - `MYSQL_DATABASE` = `${{MySQL.MYSQLDATABASE}}`

### Prvý spustenie:
- Otvor: `https://elvosolar-production.up.railway.app/register`
- Vytvor admin účet
- Prihlás sa

### Automatický deploy:
- Push na `main` branch → Railway automaticky redeployne (~30 sek)

## 2. GitHub (Repo)

```
Ada20025/Elvosolar
├── Software/App/        → PHP app (Railway deploy)
├── Software/Web/        → Statický web (elvosolar.sk)
├── Dockerfile           → Docker build pre Railway
├── railway.json         → Railway config
├── upgrade.sh           → Manuálny upgrade pre CM5
└── README.md
```

## 3. OTA Updates (Ada20025/a)

Admin publikuje šifrovaný kód cez `admin_publisher.py`:
```bash
cd "tetovacie subory"
python3 admin_publisher.py
```

CM5 sťahuje a dešifruje cez `update_service.py`:
- Repo: `Ada20025/a/contents/updates.json`
- Šifrovanie: AES-CBC (heslo: Elvosolarcontroller)
- Token: `GITHUB_UPDATE_TOKEN` env var

## 4. Hardware (CM5)

### Manuálny upgrade:
```bash
cd /home/pi
./upgrade.sh
```

### Spustenie:
```bash
cd /home/pi/Hardware
python3 app.py
```

### Cloud sync URL:
- Default: `https://elvosolar-production.up.railway.app`
- Custom: `export CLOUD_SERVER_URL="https://tvoj-url.sk"`

## 5. Domény (voliteľné)

Ak chceš vlastnú doménu:
- `www.elvosolar.sk` → GitHub Pages (statický web)
- `app.elvosolar.sk` → CNAME na Railway

## 6. E-mail (SMTP)

Railway blokuje SMTP port 587. Možnosti:
1. **PHP mail()** — funguje automaticky na Railway
2. **Gmail SMTP** — nastav v Railway Variables: `SMTP_USER`, `SMTP_PASS`
3. **SendGrid API** — zadarmo 100 emailov/deň
