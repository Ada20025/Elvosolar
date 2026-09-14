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
    # chromium existuje len na obrazovke - nespúšťať hlásiť chybu keď nie je nainštalovaný
    if command -v chromium-browser >/dev/null 2>&1 || command -v chromium >/dev/null 2>&1; then
        (chromium-browser --noerrdialogs --disable-infobars --check-for-update-interval=31536000 --start-maximized http://localhost 2>/dev/null || chromium --noerrdialogs --disable-infobars --start-maximized http://localhost 2>/dev/null) &
    else
        echo "[MONITOR] Chromium nie je nainštalovaný - sprievodca otvor v prehliadači: http://$(hostname -I | awk '{print $1}')"
    fi
fi

# 3. NEKONEČNÝ BEH HLAVNÉHO WEBSERVERU (app.py)
# Pred štartom zabi všetky staré inštancie app.py (inak Errno 98 address already in use)
if ! command -v fuser >/dev/null 2>&1; then
    sudo pkill -f "python3.*app.py" 2>/dev/null
    sleep 2
fi
while true; do
    if [ -f "$APP_DIR/app.py" ]; then
        # Over či port 80 nie je obsadený starým procesom - ak áno, zobudi vlastníka
        PORT_OWNER=$(sudo fuser 80/tcp 2>/dev/null | tr -d ' ')
        if [ -n "$PORT_OWNER" ]; then
            echo "[APLIKÁCIA] Port 80 drží proces $PORT_OWNER - ukončujem ho..."
            sudo kill -9 $PORT_OWNER 2>/dev/null
            sleep 2
        fi
        echo "[APLIKÁCIA] >>> Spúšťam hlavný program (app.py) <<<"
        sudo $VENV_PYTHON $APP_DIR/app.py
        echo "[APLIKÁCIA] ⚠️ Varovanie: Program sa nečakane ukončil. Reštartujem ho o 5 sekúnd..."
    else
        echo "[APLIKÁCIA] ❌ Chyba: Súbor app.py sa nenašiel."
    fi
    sleep 5
done
