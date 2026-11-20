#!/bin/bash
# ============================================================
#  ElvoControll CM5 — ručný update z GitHubu (zmaže staré, dá nové)
#  Použitie na CM5:  bash ~/Hardware/update_now.sh
#
#  ZMAŽE:  staré .py / .sh / templates / __pycache__ v ~/Hardware
#  ZACHOVÁ: database.db (dáta + nastavenia), venv, *.db.backup-*
#  STIAHNE: celý Hardware z GitHubu (main) a nainštaluje ho
# ============================================================

APP_DIR="/home/pi/Hardware"
SERVICE="elvosolar"
REPO_URL="https://github.com/Ada20025/Elvosolar/archive/refs/heads/main.zip"
TMP_ZIP="/tmp/elvosolar_main.zip"
TMP_DIR="/tmp/elvosolar_extract"

echo "=================================================="
echo "   ELVOCONTROLL CM5 — RUČNÝ UPDATE Z GITHUBU"
echo "   (staré súbory sa zmažú, DB ostane)"
echo "=================================================="

# --- 0) Čerstvý zip (starý/poškodený zip by rozbil update) ---
rm -f "$TMP_ZIP"
echo "[1/7] Sťahujem zip z GitHubu..."
wget -q --no-check-certificate -O "$TMP_ZIP" "$REPO_URL" \
    || curl -k -L -o "$TMP_ZIP" "$REPO_URL" \
    || { echo "❌ Sťahovanie zlyhalo. Skontroluj internet."; exit 1; }

unzip -tq "$TMP_ZIP" > /dev/null 2>&1 \
    || { echo "❌ Stiahnutý súbor nie je platný zip (skús o chvíľu znova)."; rm -f "$TMP_ZIP"; exit 1; }

echo "[2/7] Rozbaľujem zip..."
rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR"
unzip -o -q "$TMP_ZIP" -d "$TMP_DIR" || { echo "❌ unzip zlyhal (nainštaluj: sudo apt install unzip)"; exit 1; }

SRC_HW=$(find "$TMP_DIR" -maxdepth 2 -type d -name "Hardware" | head -1)
if [ -z "$SRC_HW" ]; then
    echo "❌ Adresár Hardware nenájdený v zipe."
    exit 1
fi
echo "    Zdroj: $SRC_HW"

# --- 1) Backup DB (NIKDY sa nemaže) ---
echo "[3/7] Backup databázy..."
if [ -f "$APP_DIR/database.db" ]; then
    cp "$APP_DIR/database.db" "$APP_DIR/database.db.backup-$(date +%Y%m%d-%H%M%S)"
    # Nechaj len posledné 3 zálohy
    ls -t "$APP_DIR"/database.db.backup-* 2>/dev/null | tail -n +4 | xargs rm -f 2>/dev/null
    echo "    ✅ database.db zazálohovaná (staré dáta ostanú)"
else
    echo "    ⚠️  database.db neexistuje (čistá inštalácia — vytvorí sa pri štarte)"
fi

# --- 2) Zastav službu (systemd, žiadne duplicitné procesy) ---
echo "[4/7] Zastavujem službu $SERVICE..."
sudo systemctl stop "$SERVICE" 2>/dev/null
sudo pkill -f "python3.*app.py" 2>/dev/null
sleep 2

# --- 3) ZÁLOHA STARÉHO KÓDU (keby nový nefungoval, dá sa vrátiť) ---
OLD_CODE_BAK="/tmp/elvo_old_code_$(date +%Y%m%d-%H%M%S).tgz"
if tar czf "$OLD_CODE_BAK" -C "$APP_DIR" --exclude="venv" --exclude="*.db*" --exclude="__pycache__" . 2>/dev/null; then
    echo "    ✅ Starý kód zálohovaný: $OLD_CODE_BAK"
else
    echo "    ⚠️  Zálohu starého kódu sa nepodarilo vytvoriť (pokračujem)"
fi

# --- 4) ZMAŽ STARÉ SÚBORY (kód, nie dáta) ---
echo "[5/7] Mažem staré súbory (kód + cache)..."
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

# --- 4) Nakopíruj nové súbory ---
echo "[6/7] Kopírujem nové súbory z GitHubu..."
cp -f "$SRC_HW"/*.py "$APP_DIR/" 2>/dev/null
cp -f "$SRC_HW"/*.sh "$APP_DIR/" 2>/dev/null
cp -f "$SRC_HW"/*.json "$APP_DIR/" 2>/dev/null
cp -f "$SRC_HW"/*.txt "$APP_DIR/" 2>/dev/null
cp -f "$SRC_HW"/*.md "$APP_DIR/" 2>/dev/null
if [ -d "$SRC_HW/templates" ]; then
    mkdir -p "$APP_DIR/templates"
    cp -f "$SRC_HW"/templates/* "$APP_DIR/templates/" 2>/dev/null
fi
chmod +x "$APP_DIR"/*.sh 2>/dev/null
echo "    ✅ Súbory aktualizované (database.db zachovaná)"

# Kontrola, či nový kód naozaj existuje — bez app.py sa služba nespúšťa
if [ ! -f "$APP_DIR/app.py" ] || [ ! -f "$APP_DIR/start.sh" ]; then
    echo "❌ Nový kód sa nenakopíroval (chýba app.py alebo start.sh)."
    echo "   Služba sa NESPÚŠŤA. Starý kód vrátiš takto:"
    echo "   cd ~/Hardware && tar xzf $OLD_CODE_BAK && sudo systemctl start $SERVICE"
    exit 1
fi

# --- 5) Reštart služby ---
echo "[7/7] Reštartujem službu $SERVICE..."
sudo systemctl restart "$SERVICE"
sleep 3
if sudo systemctl is-active "$SERVICE" > /dev/null 2>&1; then
    echo "    ✅ Aplikácia beží"
else
    echo "    ❌ Aplikácia nejde — pozri: sudo journalctl -u $SERVICE -n 30"
    echo "       (predchádzajúca verzia súborov je v $APP_DIR/*.bak)"
fi

# Uprat
rm -rf "$TMP_DIR" "$TMP_ZIP"

echo ""
echo "=================================================="
echo "   ✅ UPDATE DOKONČENÝ"
echo "   DB + nastavenia zachované."
echo "   Logy: elvo-log   |   Setup logy: elvo-log serial"
echo "=================================================="
