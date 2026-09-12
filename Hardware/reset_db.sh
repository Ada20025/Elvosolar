#!/bin/bash
# ==============================================================================
# ElvoSolar CM5 — Reset Databázy & Čistý štart
# Zmaže starú databázu a reinicializuje čisté tabuľky
# ==============================================================================

echo "=================================================="
echo "    ELVOSOLAR CM5 — RESETOVANIE DATABÁZY          "
echo "=================================================="

# 1. Zastavenie bežiacich procesov
echo "[1/3] Zastavujem ElvoSolar službu a procesy..."
sudo systemctl stop elvosolar 2>/dev/null || true
sudo pkill -f "app.py" 2>/dev/null || true
sudo pkill -f "modbus_slave_service.py" 2>/dev/null || true

# 2. Zmazanie databáz a dočasných cache súborov
echo "[2/3] Mažem databázy a cache..."
sudo rm -f /home/pi/Hardware/database.db
sudo rm -f /home/pi/Hardware/*.db
sudo rm -f /home/pi/Hardware/cache_*.json
sudo rm -f /var/www/html/local_app.db 2>/dev/null || true
sudo rm -f /var/www/html/cache_*.json 2>/dev/null || true

# 3. Inicializácia čistej databázy
echo "[3/3] Inicializujem novú databázu..."
if [ -f "/home/pi/Hardware/venv/bin/python3" ]; then
    /home/pi/Hardware/venv/bin/python3 -c "from database import init_db; init_db(); print('Databáza vytvorená.')"
else
    python3 -c "from database import init_db; init_db(); print('Databáza vytvorená.')" 2>/dev/null || true
fi

echo "=================================================="
echo "✅ HOTOVO: Databáza bola kompletne vyčistená!"
echo "   Teraz môžete spustiť aplikáciu načisto:"
echo "   sudo systemctl start elvosolar"
echo "=================================================="
