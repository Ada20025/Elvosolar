#!/bin/bash
# ═══════════════════════════════════════════
# ElvoControl CM5 - Update z GitHubu
# Spusti: sudo bash upgrade.sh
# ═══════════════════════════════════════════

set -e

INSTALL_DIR="/home/pi/Elvosolar"
BRANCH="main"

echo "═══════════════════════════════════════════"
echo "  ElvoControl CM5 - Aktualizácia"
echo "═══════════════════════════════════════════"

# 1. Presun do adresára projektu
cd "$INSTALL_DIR" || { echo "❌ Adresár $INSTALL_DIR nenájdený"; exit 1; }

# 2. Backup aktuálnej verzie
echo "📦 Backujiem aktuálny app.py..."
cp Hardware/app.py "Hardware/app.py.bak.$(date +%Y%m%d_%H%M%S)" 2>/dev/null || true

# 3. Stiahnutie najnovších zmien
echo "⬇️  Sťahujem z GitHubu ($BRANCH)..."
git fetch origin "$BRANCH"
git reset --hard "origin/$BRANCH"
git clean -fd Hardware/
echo "✅ Kód aktualizovaný: $(git log --oneline -1)"

# 4. Inštalácia prípadných nových závislostí
echo "📚 Kontrolujem Python závislosti..."
pip3 install -r Hardware/requirements.txt --quiet 2>/dev/null || true

# 5. Reštart služby
echo "🔄 Reštartujem ElvoControl službu..."
if systemctl is-active --quiet elvosolar 2>/dev/null; then
    sudo systemctl restart elvosolar
    echo "✅ Služba 'elvosolar' reštartovaná"
elif systemctl is-active --quiet elvosolar.service 2>/dev/null; then
    sudo systemctl restart elvosolar.service
    echo "✅ Služba 'elvosolar.service' reštartovaná"
else
    # Skus najst akukolvek ElvoControl service
    SVC=$(systemctl list-units --type=service --all 2>/dev/null | grep -i "elvo\|cm5\|solar" | awk '{print $1}' | head -1)
    if [ -n "$SVC" ]; then
        sudo systemctl restart "$SVC"
        echo "✅ Služba '$SVC' reštartovaná"
    else
        echo "⚠️  Žiadna systemd služba nenájdená. Skúšam priamo..."
        # Kill stare procesy a spusti nové
        pkill -f "python.*app.py" 2>/dev/null || true
        sleep 2
        cd Hardware
        nohup python3 app.py > /var/log/elvosolar.log 2>&1 &
        echo "✅ Spustené priamo (PID: $!)"
    fi
fi

# 6. Overenie
sleep 3
echo ""
echo "═══════════════════════════════════════════"
if curl -s http://localhost:8000/api/system/status > /dev/null 2>&1; then
    echo "  ✅ ElvoControl je online!"
    curl -s http://localhost:8000/api/system/status | python3 -m json.tool 2>/dev/null || true
else
    echo "  ⚠️  Čakám na spustenie... (môže trvať 10-15s)"
fi
echo "═══════════════════════════════════════════"
echo "  Verzia: $(git log --oneline -1)"
echo "═══════════════════════════════════════════"
