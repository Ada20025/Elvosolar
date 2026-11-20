#!/bin/bash
# ============================================================
#  ElvoControll CM5 — ručný update z GitHubu (zmaže staré, dá nové)
#  Použitie na CM5:  bash ~/Hardware/update_now.sh
#
#  STIAHNE:  len súbory z Hardware/ (raw odkazy, žiadny zip celého repa)
#  ZMAŽE:    staré .py / .sh / templates / __pycache__ v ~/Hardware
#  ZACHOVÁ:  database.db (dáta + nastavenia), venv, *.db.backup-*
#  Iná vetva: BRANCH=main bash ~/Hardware/update_now.sh
# ============================================================

APP_DIR="/home/pi/Hardware"
SERVICE="elvosolar"
BRANCH="${BRANCH:-main}"
REPO="Ada20025/Elvosolar"
RAW_BASE="https://raw.githubusercontent.com/${REPO}/${BRANCH}/Hardware"
DL_DIR="/tmp/elvo_dl_$$"
PARALLEL=6

# Zoznam súborov, ktoré sa sťahujú (relatívne k Hardware/)
FILES="Config.py
SMARTLOGGER_MODBUS_POINT_LIST.md
ai_engine.py
app.py
ble_service.py
clean.sh
database.py
elvo-log.sh
install_service.sh
kill_services.sh
led_service.py
modbus_compat.py
modbus_scan.py
modbus_slave_service.py
models_engine.py
mqtt_client.py
network_scan.py
requirements.txt
reset.sh
reset_db.sh
serial_config_service.py
setup_usb_gadget.sh
smart_meter_service.py
smartlogger_points.json
solar_service.py
start.sh
system_service.py
templates/mqtt.html
templates/setup.html
third_party_service.py
update.sh
update_now.sh
update_service.py"

echo "=================================================="
echo "   ELVOCONTROLL CM5 — RUČNÝ UPDATE Z GITHUBU"
echo "   (len Hardware/ súbory, DB ostane)"
echo "=================================================="

# --- 1) Stiahni LEN súbory z Hardware/ (žiadny zip celého repa) ---
echo "[1/6] Sťahujem ${BRANCH} — Hardware/ (raw súbory)..."

# Raw odkazy na vetvu sú ~5 min cachované (max-age=300). Preto zistíme
# presný commit SHA a ťaháme súbory pinuté naň — nikdy nepríde stará verzia.
COMMIT=$(curl -fsSL --max-time 10 "https://api.github.com/repos/${REPO}/commits/${BRANCH}" 2>/dev/null \
    | sed -n 's/^[[:space:]]*"sha": "\([0-9a-f]\{40\}\)".*/\1/p' | head -1)
if [ -n "$COMMIT" ]; then
    RAW_BASE="https://raw.githubusercontent.com/${REPO}/${COMMIT}/Hardware"
    echo "    Commit: $(echo "$COMMIT" | cut -c1-7)"
else
    echo "    ⚠️  Commit sa nepodarilo zistiť — použijem vetvu ${BRANCH}"
fi

rm -rf "$DL_DIR"
mkdir -p "$DL_DIR"
: > "$DL_DIR/.failed"

fetch() {
    local f="$1"
    local out="$DL_DIR/$f"
    mkdir -p "$(dirname "$out")"
    if command -v curl > /dev/null 2>&1; then
        curl -fsSL --retry 3 --connect-timeout 10 -o "$out" "$RAW_BASE/$f" 2>/dev/null \
            && [ -s "$out" ] && return 0
    fi
    if command -v wget > /dev/null 2>&1; then
        wget -q --no-check-certificate -t 3 -T 20 -O "$out" "$RAW_BASE/$f" 2>/dev/null \
            && [ -s "$out" ] && return 0
    fi
    rm -f "$out"
    echo "$f" >> "$DL_DIR/.failed"
    return 1
}

count=0
running=0
for f in $FILES; do
    fetch "$f" &
    running=$((running + 1))
    count=$((count + 1))
    if [ "$running" -ge "$PARALLEL" ]; then
        wait
        running=0
    fi
done
wait

if [ -s "$DL_DIR/.failed" ]; then
    echo "❌ Nepodarilo sa stiahnuť:"
    sed 's/^/   - /' "$DL_DIR/.failed"
    echo "   Nič sa nezmenilo — starý kód aj DB ostávajú."
    rm -rf "$DL_DIR"
    exit 1
fi

# Kontrola, že kľúčové súbory naozaj prišli a nie sú prázdne
for must in app.py start.sh led_service.py requirements.txt; do
    if [ ! -s "$DL_DIR/$must" ]; then
        echo "❌ Chýba stiahnutý súbor: $must — nič sa nezmenilo."
        rm -rf "$DL_DIR"
        exit 1
    fi
done
echo "    ✅ Stiahnutých $count súborov ($(du -sh "$DL_DIR" | cut -f1))"

# --- 2) Backup DB (NIKDY sa nemaže) ---
echo "[2/6] Backup databázy..."
if [ -f "$APP_DIR/database.db" ]; then
    cp "$APP_DIR/database.db" "$APP_DIR/database.db.backup-$(date +%Y%m%d-%H%M%S)"
    # Nechaj len posledné 3 zálohy
    ls -t "$APP_DIR"/database.db.backup-* 2>/dev/null | tail -n +4 | xargs rm -f 2>/dev/null
    echo "    ✅ database.db zazálohovaná (staré dáta ostanú)"
else
    echo "    ⚠️  database.db neexistuje (čistá inštalácia — vytvorí sa pri štarte)"
fi

# --- 3) Zastav službu (systemd, žiadne duplicitné procesy) ---
echo "[3/6] Zastavujem službu $SERVICE..."
sudo systemctl stop "$SERVICE" 2>/dev/null
sudo pkill -f "python3.*app.py" 2>/dev/null
sleep 2

# --- 4) ZÁLOHA STARÉHO KÓDU (keby nový nefungoval, dá sa vrátiť) ---
OLD_CODE_BAK="/tmp/elvo_old_code_$(date +%Y%m%d-%H%M%S).tgz"
if tar czf "$OLD_CODE_BAK" -C "$APP_DIR" --exclude="venv" --exclude="*.db*" --exclude="__pycache__" . 2>/dev/null; then
    echo "    ✅ Starý kód zálohovaný: $OLD_CODE_BAK"
else
    echo "    ⚠️  Zálohu starého kódu sa nepodarilo vytvoriť (pokračujem)"
fi

# --- 5) ZMAŽ STARÉ SÚBORY (kód, nie dáta) + nakopíruj nové ---
echo "[4/6] Mažem staré súbory (kód + cache)..."
rm -f "$APP_DIR"/*.py 2>/dev/null
rm -f "$APP_DIR"/*.pyc 2>/dev/null
rm -rf "$APP_DIR"/__pycache__ 2>/dev/null
find "$APP_DIR" -type d -name "__pycache__" -exec rm -rf {} + 2>/dev/null
find "$APP_DIR" -name "*.pyc" -delete 2>/dev/null
# Šablóny idú z GitHubu nanovo (staré medzičasom zmenené súbory by inak ostali)
if [ -d "$APP_DIR/templates" ]; then
    rm -rf "$APP_DIR/templates"
fi
echo "    ✅ Starý kód a cache zmazané (database.db + venv ostávajú)"

echo "[5/6] Kopírujem nové súbory..."
cp -f "$DL_DIR"/*.py "$APP_DIR/" 2>/dev/null
cp -f "$DL_DIR"/*.sh "$APP_DIR/" 2>/dev/null
cp -f "$DL_DIR"/*.json "$APP_DIR/" 2>/dev/null
cp -f "$DL_DIR"/*.txt "$APP_DIR/" 2>/dev/null
cp -f "$DL_DIR"/*.md "$APP_DIR/" 2>/dev/null
if [ -d "$DL_DIR/templates" ]; then
    mkdir -p "$APP_DIR/templates"
    cp -f "$DL_DIR"/templates/* "$APP_DIR/templates/" 2>/dev/null
fi
chmod +x "$APP_DIR"/*.sh 2>/dev/null
echo "    ✅ Súbory aktualizované (database.db zachovaná)"

# Kontrola, či nový kód naozaj existuje — bez app.py sa služba nespúšťa
if [ ! -f "$APP_DIR/app.py" ] || [ ! -f "$APP_DIR/start.sh" ]; then
    echo "❌ Nový kód sa nenakopíroval (chýba app.py alebo start.sh)."
    echo "   Služba sa NESPÚŠŤA. Starý kód vrátiš takto:"
    echo "   cd ~/Hardware && tar xzf $OLD_CODE_BAK && sudo systemctl start $SERVICE"
    rm -rf "$DL_DIR"
    exit 1
fi

# --- 6) Reštart služby ---
echo "[6/6] Reštartujem službu $SERVICE..."
sudo systemctl restart "$SERVICE"
sleep 3
if sudo systemctl is-active "$SERVICE" > /dev/null 2>&1; then
    echo "    ✅ Aplikácia beží"
else
    echo "    ❌ Aplikácia nejde — pozri: sudo journalctl -u $SERVICE -n 30"
    echo "       Starý kód vrátiš: cd ~/Hardware && tar xzf $OLD_CODE_BAK && sudo systemctl restart $SERVICE"
fi

# Uprat
rm -rf "$DL_DIR"

echo ""
echo "=================================================="
echo "   ✅ UPDATE DOKONČENÝ"
echo "   DB + nastavenia zachované."
echo "   LED: curl -s localhost/api/led/status"
echo "   Logy: elvo-log   |   Setup logy: elvo-log serial"
echo "=================================================="
