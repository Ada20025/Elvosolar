#!/bin/bash
# ============================================================
#  ELVOCONTROLL CM5 — ČISTENIE (update + zmaž DB a __pycache__)
#  Použitie:  bash ~/clean.sh
#
#  POZOR: Zmaže LOKÁLNU database.db na CM5 (nastavenia aj história).
#  Cloudová DB (Railway) ostáva nedotknutá!
#  Po vyčistení musíš zariadenie znova setupnúť káblom.
# ============================================================

echo "=================================================="
echo "   ELVOCONTROLL — ČISTENIE (DB + __pycache__)"
echo "=================================================="
echo ""
echo "  Toto zmaže:"
echo "    ❌ ~/Hardware/database.db       (lokálne nastavenia, história)"
echo "    ❌ všetky __pycache__ a .pyc    (staré bajtkódy)"
echo ""
echo "  Toto NEZMAŽE:"
echo "    ✅ Cloudovú DB na Railway       (zariadenia, telemetry, users)"
echo "    ✅ Samotný kód                  (ten sa naopak AKTUALIZUJE)"
echo ""
read -p "Naozaj pokračovať? (y/N): " CONFIRM
if [ "$CONFIRM" != "y" ]; then
    echo "Zrušené."
    exit 0
fi

echo ""
echo "[1/5] Stahujem najnovší kód z GitHubu..."
rm -f /tmp/elvosolar_main.zip
wget -q -O /tmp/elvosolar_main.zip "https://github.com/Ada20025/Elvosolar/archive/refs/heads/main.zip" \
    || { echo "❌ Sťahovanie zlyhalo"; exit 1; }
echo "    ✅ Stiahnuté"

echo "[2/5] Zastavujem aplikáciu..."
sudo systemctl stop elvosolar 2>/dev/null
sleep 2

echo "[3/5] Zálohujem starú DB (pre istotu do ~/Hardware/db_old_backup)..."
mkdir -p ~/Hardware/db_old_backup
if [ -f ~/Hardware/database.db ]; then
    mv ~/Hardware/database.db ~/Hardware/db_old_backup/database_$(date +%Y%m%d_%H%M%S).db
    echo "    ✅ Stará DB presunutá do ~/Hardware/db_old_backup/"
fi

echo "[4/5] Mažem __pycache__, .pyc a staré súbory..."
find ~/Hardware -name "__pycache__" -type d -exec rm -rf {} + 2>/dev/null
find ~/Hardware -name "*.pyc" -delete 2>/dev/null
rm -rf /tmp/elvosolar_extract
mkdir -p /tmp/elvosolar_extract
unzip -q /tmp/elvosolar_main.zip -d /tmp/elvosolar_extract
cp -v /tmp/elvosolar_extract/Elvosolar-main/Hardware/*.py ~/Hardware/ 2>/dev/null | tail -3
cp -v /tmp/elvosolar_extract/Elvosolar-main/Hardware/*.sh ~/Hardware/ 2>/dev/null | tail -2
mkdir -p ~/Hardware/templates
cp -v /tmp/elvosolar_extract/Elvosolar-main/Hardware/templates/* ~/Hardware/templates/ 2>/dev/null
chmod +x ~/Hardware/*.sh 2>/dev/null
echo "    ✅ Vyčistené a nový kód nakopírovaný"

echo "[5/5] Spúšťam aplikáciu (čistá DB sa vytvorí automaticky)..."
sudo systemctl start elvosolar
sleep 3

# Upratuj
rm -f /tmp/elvosolar_main.zip
rm -rf /tmp/elvosolar_extract

echo ""
echo "=================================================="
echo "   ✅ ČISTENIE DOKONČENÉ"
echo ""
echo "   CM5 teraz beží s ČISTOU databázou."
echo "   ➡️  Prejdi setup znova káblom:"
echo "      1. USB-C kábel: CM5 ↔ PC"
echo "      2. elvosolar-production.up.railway.app/setup"
echo "      3. 🔌 Pripojiť cez USB UART → kroky → ODOŠLI"
echo ""
echo "   Stará DB je v ~/Hardware/db_old_backup/ (ak potrebuješ niečo získať)"
echo "   Logy: elvo-log   |   Setup logy: elvo-log serial"
echo "=================================================="
