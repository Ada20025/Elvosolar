#!/bin/bash

echo "=================================================="
echo "    CM5 ŠTARTOVACÍ MONITORING (INTELIGENTNÝ BEH)  "
echo "=================================================="

VENV_PYTHON="/home/pi/Hardware/venv/bin/python3"
APP_DIR="/home/pi/Hardware"

# Ak venv neexistuje, vytvorime ho
if [ ! -f "$VENV_PYTHON" ]; then
    echo "[SETUP] Vytváram venv..."
    python3 -m venv /home/pi/Hardware/venv
    $VENV_PYTHON -m pip install --quiet fastapi uvicorn pyserial pymodbus requests gpiod
    echo "[SETUP] Závislosti nainštalované."
fi

# Over ze fastapi je nainštalované
$VENV_PYTHON -c "import fastapi" 2>/dev/null || {
    echo "[SETUP] Inštalujem chýbajúce závislosti..."
    $VENV_PYTHON -m pip install --quiet fastapi uvicorn pyserial pymodbus requests gpiod
}

# 1. NEKONEČNÝ BEH AKTUALIZÁCIÍ (každú hodinu)
run_updater() {
    while true; do
        echo "[UPDATER] Spúšťam kontrolu aktualizácií z GitHubu..."
        $VENV_PYTHON $APP_DIR/update_service.py
        echo "[UPDATER] Služba sa vypla alebo aktualizovala súbory. Reštartujem o 3 sekundy..."
        sleep 3
    done
}

run_updater &
sleep 3

# 2. INTELIGENTNÉ AUTOMATICKÉ OTVORENIE PREHLIADAČA NA MONITORE
IS_CLAIMED="0"
if [ -f "$APP_DIR/database.db" ]; then
    IS_CLAIMED=$($VENV_PYTHON -c "import sqlite3; conn=sqlite3.connect('$APP_DIR/database.db'); cursor=conn.cursor(); cursor.execute(\"SELECT value FROM system_settings WHERE key='is_claimed'\"); row=cursor.fetchone(); print(row[0] if row else '0')" 2>/dev/null || echo "0")
fi

if [ "$IS_CLAIMED" != "1" ]; then
    echo "[MONITOR] Zariadenie zatiaľ nie je nastavené. Automaticky otváram sprievodcu na HDMI..."
    chromium-browser --noerrdialogs --disable-infobars --check-for-update-interval=31536000 --start-maximized http://localhost &
else
    echo "[MONITOR] Zariadenie je už nakonfigurované. Lokálny prehliadač neotváram."
fi

# 3. NEKONEČNÝ BEH HLAVNÉHO WEBSERVERU (app.py)
while true; do
    if [ -f "$APP_DIR/app.py" ]; then
        echo "[APLIKÁCIA] >>> Spúšťam hlavný program (app.py) <<<"
        sudo $VENV_PYTHON $APP_DIR/app.py
        echo "[APLIKÁCIA] ⚠️ Varovanie: Program sa nečakane ukončil. Reštartujem ho o 5 sekúnd..."
    else
        echo "[APLIKÁCIA] ❌ Chyba: Súbor app.py sa nenašiel."
    fi
    sleep 5
done
