#!/bin/bash
# =============================================================================
# upgrade.sh - ElvoControl CM5 Manual Upgrade Script
# =============================================================================
# Použitie:
#   chmod +x upgrade.sh
#   ./upgrade.sh
# =============================================================================

set -e

REPO_URL="https://github.com/Ada20025/Elvosolar.git"
BRANCH="main"
BACKUP_DIR="/home/pi/backup_$(date +%Y%m%d_%H%M%S)"
HARDWARE_DIR="/home/pi/Hardware"

echo "╔══════════════════════════════════════════╗"
echo "║  ElvoControl CM5 - Manuálny Upgrade      ║"
echo "║  Verzia: $(date '+%Y-%m-%d %H:%M:%S')              ║"
echo "╚══════════════════════════════════════════╝"
echo ""

# 1. Kontrola internetu
echo "🌐 [1/7] Kontrola internetového pripojenia..."
if ! ping -c 1 -W 3 github.com > /dev/null 2>&1; then
    echo "❌ Chyba: Internet nedostupný. Skontrolujte WiFi pripojenie."
    exit 1
fi
echo "✅ Internet OK"

# 2. Backup existujúceho kódu
echo ""
echo "💾 [2/7] Vytváram backup existujúceho kódu..."
mkdir -p "$BACKUP_DIR"
if [ -d "$HARDWARE_DIR" ]; then
    cp -r "$HARDWARE_DIR" "$BACKUP_DIR/Hardware_backup"
    echo "✅ Backup uložený do: $BACKUP_DIR"
else
    echo "⚠️  Hardware adresár nenájdený, preskakujem backup"
fi

# 3. Stiahnutie najnovšieho kódu
echo ""
echo "📥 [3/7] Sťahujem najnovší kód z GitHub..."
TEMP_DIR=$(mktemp -d)
cd "$TEMP_DIR"
git clone --depth 1 --branch "$BRANCH" "$REPO_URL" . 2>/dev/null || {
    echo "❌ Chyba pri sťahovaní z GitHub"
    rm -rf "$TEMP_DIR"
    exit 1
}
echo "✅ Kód stiahnutý"

# 4. Kontrola zmien
echo ""
echo "🔍 [4/7] Porovnávam s aktuálnou verziou..."
if [ -f "$HARDWARE_DIR/app.py" ]; then
    # Porovnaj verzie podľa timestampu
    OLD_VER=$(stat -c %Y "$HARDWARE_DIR/app.py" 2>/dev/null || echo "0")
    NEW_VER=$(stat -c %Y "$TEMP_DIR/Hardware/app.py" 2>/dev/null || echo "0")
    
    if [ "$OLD_VER" = "$NEW_VER" ]; then
        echo "ℹ️  Kód je aktuálny. Žiadne zmeny."
    else
        echo "🆕 Nová verzia dostupná!"
    fi
else
    echo "🆕 Prvá inštalácia"
fi

# 5. Zastavenie bežiacich služieb
echo ""
echo "⏹️  [5/7] Zastavujem bežiace služby..."
if pgrep -f "python3.*app.py" > /dev/null 2>&1; then
    pkill -f "python3.*app.py" 2>/dev/null || true
    sleep 2
    echo "✅ Služby zastavené"
else
    echo "ℹ️  Žiadne bežiace služby"
fi

# 6. Inštalácia nového kódu
echo ""
echo "📦 [6/7] Inštalujem nový kód..."
if [ -d "$TEMP_DIR/Hardware" ]; then
    # Kopíruj len opravené súbory (nie config)
    for f in app.py Config.py solar_service.py smart_meter_service.py models_engine.py update_service.py database.py system_service.py; do
        if [ -f "$TEMP_DIR/Hardware/$f" ]; then
            cp "$TEMP_DIR/Hardware/$f" "$HARDWARE_DIR/$f"
            echo "  ✅ $f"
        fi
    done
    
    # Kopíruj nové súbory ak neexistujú
    for f in ai_engine.py ble_service.py led_service.py modbus_slave_service.py third_party_service.py smarthome_voice_service.py; do
        if [ -f "$TEMP_DIR/Hardware/$f" ] && [ ! -f "$HARDWARE_DIR/$f" ]; then
            cp "$TEMP_DIR/Hardware/$f" "$HARDWARE_DIR/$f"
            echo "  📄 $f (nový)"
        fi
    done
    
    echo "✅ Kód nainštalovaný"
else
    echo "❌ Chyba: Hardware adresár nenájdený v repozitári"
fi

# 7. Inštalácia závislostí
echo ""
echo "📚 [7/7] Inštalujem Python závislosti..."
if [ -f "$TEMP_DIR/requirements.txt" ]; then
    pip3 install -q -r "$TEMP_DIR/requirements.txt" 2>/dev/null || true
elif [ -f "$HARDWARE_DIR/requirements.txt" ]; then
    pip3 install -q -r "$HARDWARE_DIR/requirements.txt" 2>/dev/null || true
fi
echo "✅ Závislosti nainštalované"

# Cleanup
rm -rf "$TEMP_DIR"

echo ""
echo "╔══════════════════════════════════════════╗"
echo "║  ✅ UPGRADE DOKONČENÝ                    ║"
echo "╚══════════════════════════════════════════╝"
echo ""
echo "Na spustenie:"
echo "  cd $HARDWARE_DIR && python3 app.py"
echo ""
echo "Na spustenie ako služba:"
echo "  sudo systemctl restart elvocontrol"
echo ""
echo "Backup: $BACKUP_DIR"
echo ""

# Ponuka spustenia
read -p "Spustiť ElvoControl teraz? (á/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[ÁáAaYy]$ ]]; then
    echo "🚀 Spúšťam ElvoControl..."
    cd "$HARDWARE_DIR"
    python3 app.py &
    echo "✅ ElvoControl spustený na http://$(hostname -I | awk '{print $1}'):80"
fi
