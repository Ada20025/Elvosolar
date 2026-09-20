#!/bin/bash
# ============================================================
#  ELVOCONTROLL — ČISTÝ ŠTART (1 príkaz)
#  Update kódu + zmaže lokálnu DB a __pycache__
#  Použitie na CM5:  bash ~/reset.sh
#
#  Cloudová DB (Railway) ostáva NEDOTKNUTÁ!
#  Po tomto prejdi setup znova káblom.
# ============================================================

echo "=================================================="
echo "   ELVOCONTROLL — ČISTÝ ŠTART"
echo "   (nový kód + zmaže lokálnu DB + __pycache__)"
echo "=================================================="
echo ""
echo "  Zmaže:      ~/Hardware/database.db (lokálne nastavenia)"
echo "  Zmaže:      všetky __pycache__ a .pyc"
echo "  Aktualizuje: kód z GitHubu na najnovší"
echo "  NEZMAŽE:    cloudovú DB na Railway (zariadenia, users, telemetria)"
echo ""
read -p "Pokračovať? (y/N): " CONFIRM
if [ "$CONFIRM" != "y" ]; then echo "Zrušené."; exit 0; fi

echo ""
echo "[1/6] Stahujem najnovší kód..."
rm -f /tmp/elvo_reset.zip
wget -q -O /tmp/elvo_reset.zip "https://github.com/Ada20025/Elvosolar/archive/refs/heads/main.zip" \
    || { echo "❌ Sťahovanie zlyhalo (internet?)"; exit 1; }
unzip -t /tmp/elvo_reset.zip > /dev/null 2>&1 \
    || { echo "❌ Zip poškodený — skús znova"; rm -f /tmp/elvo_reset.zip; exit 1; }
echo "    ✅ Kód stiahnutý"

echo "[2/6] Zastavujem aplikáciu..."
sudo systemctl stop elvosolar 2>/dev/null
sleep 2
echo "    ✅ Zastavené"

echo "[3/6] Zálohujem starú DB (do ~/Hardware/db_old_backup)..."
mkdir -p ~/Hardware/db_old_backup
[ -f ~/Hardware/database.db ] && mv ~/Hardware/database.db ~/Hardware/db_old_backup/database_$(date +%Y%m%d_%H%M%S).db && echo "    ✅ Stará DB bezpečne odložená" || echo "    (DB neexistovala — OK)"

echo "[4/6] Mažem __pycache__ a .pyc..."
find ~/Hardware -name "__pycache__" -type d -exec rm -rf {} + 2>/dev/null
find ~/Hardware -name "*.pyc" -delete 2>/dev/null
echo "    ✅ Cache vyčistená"

echo "[5/6] Kopírujem nový kód..."
rm -rf /tmp/elvo_reset_x && mkdir -p /tmp/elvo_reset_x
unzip -q /tmp/elvo_reset.zip -d /tmp/elvo_reset_x
cp /tmp/elvo_reset_x/Elvosolar-main/Hardware/*.py ~/Hardware/ 2>/dev/null
cp /tmp/elvo_reset_x/Elvosolar-main/Hardware/*.sh ~/Hardware/ 2>/dev/null
mkdir -p ~/Hardware/templates
cp /tmp/elvo_reset_x/Elvosolar-main/Hardware/templates/* ~/Hardware/templates/ 2>/dev/null
chmod +x ~/Hardware/*.sh 2>/dev/null
echo "    ✅ Nový kód nainštalovaný (čistá DB sa vytvorí sama)"

echo "[6/6] Spúšťam aplikáciu..."
sudo systemctl start elvosolar
sleep 3
sudo systemctl is-active elvosolar > /dev/null && echo "    ✅ Aplikácia beží s ČISTOU DB" || echo "    ❌ Nebeží — pozri: sudo journalctl -u elvosolar -n 20"

rm -f /tmp/elvo_reset.zip
rm -rf /tmp/elvo_reset_x

echo ""
echo "=================================================="
echo "   ✅ ČISTÝ ŠTART DOKONČENÝ"
echo ""
echo "   Ďalší krok — setup káblom:"
echo "   1. USB-C kábel CM5 ↔ PC"
echo "   2. elvosolar-production.up.railway.app/setup"
echo "   3. 🔌 Pripojiť cez USB UART → kroky → ODOŠLI"
echo "   4. Zariadenie sa zaregistruje pod TVOJ účet"
echo "      (v finálnom kroku uvidíš meno + ID používateľa)"
echo ""
echo "   Stará DB: ~/Hardware/db_old_backup/"
echo "=================================================="
