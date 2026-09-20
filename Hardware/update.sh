#!/bin/bash
# ============================================================
#  ELVOCONTROLL CM5 — KOMPLETNÝ UPDATE (1 príkaz)
#  Použitie:  bash ~/update.sh
# ============================================================

echo "=================================================="
echo "   ELVOCONTROLL — UPDATE Z GITHUBU"
echo "=================================================="

# 1. Stiahni čerstvý zip (vždy nový, žiadne staré rozbité)
echo "[1/6] Sťahujem najnovší kód..."
rm -f /tmp/elvosolar_main.zip
wget -q -O /tmp/elvosolar_main.zip "https://github.com/Ada20025/Elvosolar/archive/refs/heads/main.zip" \
    || { echo "❌ Sťahovanie zlyhalo — skontroluj internet"; exit 1; }

# Over že zip je validný
unzip -t /tmp/elvosolar_main.zip > /dev/null 2>&1 \
    || { echo "❌ Zip je poškodený — skús znova"; rm -f /tmp/elvosolar_main.zip; exit 1; }
echo "    ✅ Zip stiahnutý a overený"

# 2. Rozbaľ
echo "[2/6] Rozbaľujem..."
rm -rf /tmp/elvosolar_extract
mkdir -p /tmp/elvosolar_extract
unzip -q /tmp/elvosolar_main.zip -d /tmp/elvosolar_extract
[ -d /tmp/elvosolar_extract/Elvosolar-main/Hardware ] || { echo "❌ Zip nemá očakávanú štruktúru"; exit 1; }
echo "    ✅ Rozbalené"

# 3. Backup databázy (nikdy nestrácaj dáta!)
echo "[3/6] Backup databázy..."
if [ -f ~/Hardware/database.db ]; then
    cp ~/Hardware/database.db ~/Hardware/database.db.bak.$(date +%Y%m%d_%H%M%S)
    # Nechaj len posledné 3 zálohy
    ls -t ~/Hardware/database.db.bak.* 2>/dev/null | tail -n +4 | xargs rm -f 2>/dev/null
    echo "    ✅ database.db zazálohovaná"
else
    echo "    ⚠️  Database.db nebola nájdená (prvá inštalácia?)"
fi

# 4. Zastav aplikáciu
echo "[4/6] Zastavujem aplikáciu..."
sudo systemctl stop elvosolar 2>/dev/null
sleep 2
echo "    ✅ Zastavené"

# 5. Skopíruj nové súbory (DB ostáva!)
echo "[5/6] Kopírujem nové súbory..."
cp -v /tmp/elvosolar_extract/Elvosolar-main/Hardware/*.py ~/Hardware/ 2>/dev/null
cp -v /tmp/elvosolar_extract/Elvosolar-main/Hardware/*.sh ~/Hardware/ 2>/dev/null
mkdir -p ~/Hardware/templates
cp -v /tmp/elvosolar_extract/Elvosolar-main/Hardware/templates/* ~/Hardware/templates/ 2>/dev/null
chmod +x ~/Hardware/*.sh 2>/dev/null
echo "    ✅ Súbory aktualizované (database.db zachovaná)"

# 5b. VYMAŽ __pycache__ (staré skompilované bajtkódy spôsobujú zlé chyby)
echo "[5b] Mažem __pycache__ a staré .pyc..."
find ~/Hardware -name "__pycache__" -type d -exec rm -rf {} + 2>/dev/null
find ~/Hardware -name "*.pyc" -delete 2>/dev/null
echo "    ✅ Cache vyčistená"

# 6. Spusti aplikáciu
echo "[6/6] Spúšťam aplikáciu..."
sudo systemctl start elvosolar
sleep 3
sudo systemctl is-active elvosolar > /dev/null \
    && echo "    ✅ Aplikácia beží" \
    || echo "    ❌ Aplikácia nejde — pozri: sudo journalctl -u elvosolar -n 20"

# Upratuj
rm -rf /tmp/elvosolar_main.zip /tmp/elvosolar_extract

echo ""
echo "=================================================="
echo "   ✅ UPDATE DOKONČENÝ"
echo "   Logy:  elvo-log   |   Setup logy: elvo-log serial"
echo "=================================================="
