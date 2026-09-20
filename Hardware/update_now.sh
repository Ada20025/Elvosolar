#!/bin/bash
# ============================================================
#  ElvoControll CM5 — ručný update z GitHubu (zip + unzip)
#  Použitie na CM5:  bash ~/Hardware/update_now.sh
#  (alebo stiahni zip na svojom PC a prenes ho na CM5 - vid. nižšie)
# ============================================================

APP_DIR="/home/pi/Hardware"
REPO_URL="https://github.com/Ada20025/Elvosolar/archive/refs/heads/main.zip"
TMP_ZIP="/tmp/elvosolar_main.zip"
TMP_DIR="/tmp/elvosolar_extract"

echo "=================================================="
echo "   ELVOCONTROLL CM5 — RUČNÝ UPDATE Z GITHUBU"
echo "=================================================="

# --- 0) Vždy stiahni ČERSTVÝ zip (starý rozbitý/neprefixovaný zip by rozbil update) ---
rm -f "$TMP_ZIP"
echo "[1/7] Sťahujem zip z GitHubu..."
wget -q --no-check-certificate -O "$TMP_ZIP" "$REPO_URL" \
    || curl -k -L -o "$TMP_ZIP" "$REPO_URL" \
    || { echo "❌ Sťahovanie zlyhalo. Skontroluj internet."; exit 1; }

# Over že je to reálne zip (GitHub niekedy vráti HTML chybovú stránku)
unzip -tq "$TMP_ZIP" > /dev/null 2>&1 \
    || { echo "❌ Stiahnutý súbor nie je platný zip (skús o chvíľu znova)."; rm -f "$TMP_ZIP"; exit 1; }

echo "[2/7] Rozbaľujem zip..."
rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR"
unzip -o -q "$TMP_ZIP" -d "$TMP_DIR" || { echo "❌ unzip zlyhal (nainštaluj: sudo apt install unzip)"; exit 1; }

# Nájdi koreňový adresár z zipu (Elvosolar-main alebo podobné)
SRC_HW=$(find "$TMP_DIR" -maxdepth 2 -type d -name "Hardware" | head -1)
if [ -z "$SRC_HW" ]; then
    echo "❌ Adresár Hardware nenájdený v zipe."
    exit 1
fi
echo "    Zdroj: $SRC_HW"

# --- Backup starej DB a konfigurácie (NIKDY sa nemaže) ---
echo "[3/7] Backup databázy..."
if [ -f "$APP_DIR/database.db" ]; then
    cp "$APP_DIR/database.db" "$APP_DIR/database.db.backup-$(date +%Y%m%d-%H%M%S)"
    echo "    ✅ database.db zazálohovaná (staré dáta ostanú)"
fi

# --- Zastav bežiacu aplikáciu ---
echo "[4/7] Zastavujem aplikáciu..."
sudo pkill -f "python3.*app.py" 2>/dev/null
sleep 2

# --- Skopíruj nové súbory (database.db sa NEEKOPÍRUJE!) ---
echo "[5/7] Kopírujem nové súbory..."
cp -v "$SRC_HW"/*.py "$APP_DIR/" 2>/dev/null
cp -v "$SRC_HW"/start.sh "$APP_DIR/" 2>/dev/null
if [ -d "$SRC_HW/templates" ]; then
    mkdir -p "$APP_DIR/templates"
    cp -v "$SRC_HW/templates"/* "$APP_DIR/templates/" 2>/dev/null
fi
echo "    ✅ Súbory aktualizované (database.db zachovaná)"

# --- Zmaz __pycache__ ---
echo "[6/7] Mažem __pycache__..."
find "$APP_DIR" -type d -name "__pycache__" -exec rm -rf {} + 2>/dev/null
echo "    ✅ Cache vyčistená"

# --- Spusti aplikáciu ---
echo "[7/7] Spúšťam aplikáciu..."
cd "$APP_DIR"
if [ -f "$APP_DIR/venv/bin/python3" ]; then
    sudo "$APP_DIR/venv/bin/python3" "$APP_DIR/app.py" &
else
    sudo python3 "$APP_DIR/app.py" &
fi
sleep 3

echo ""
echo "=================================================="
echo "   ✅ UPDATE DOKONČENÝ"
echo "   DB + nastavenia zachované."
echo "   Ak app.py padá, pozri: sudo journalctl -u elvosolar -f"
echo "=================================================="

# Uprat
rm -rf "$TMP_DIR" "$TMP_ZIP"
