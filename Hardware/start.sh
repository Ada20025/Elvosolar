#!/bin/bash

echo "=================================================="
echo "    CM5 ELVOSOLAR - START                         "
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

# ===================================================================
# KROK 1: ZABI VSETKY STARE PROCESY + UVOLNI PORTY
# ===================================================================
echo "[CLEANUP] Zabíjam staré app.py procesy..."
sudo pkill -9 -f "python3.*app.py" 2>/dev/null
sleep 1

# Zabij aj vsetky start.sh okrem seba (aby sa nerecursili)
MY_PID=$$
for pid in $(pgrep -f "bash.*start.sh"); do
    if [ "$pid" != "$MY_PID" ]; then
        echo "[CLEANUP] Zabíjam starý start.sh PID=$pid"
        sudo kill -9 $pid 2>/dev/null
    fi
done
sleep 1

# Uvolni porty 80 a 5020
for PORT in 80 5020; do
    PIDS=$(sudo fuser $PORT/tcp 2>/dev/null | tr -d ' ')
    if [ -n "$PIDS" ]; then
        echo "[CLEANUP] Port $PORT drží PID: $PIDS - ukončujem..."
        sudo kill -9 $PIDS 2>/dev/null
        sleep 1
    fi
done

# Over ze porty su volne
for PORT in 80 5020; do
    if sudo fuser $PORT/tcp >/dev/null 2>&1; then
        echo "[CLEANUP] ⚠️ Port $PORT stále obsadený!"
    else
        echo "[CLEANUP] ✅ Port $PORT voľný"
    fi
done

# ===================================================================
# KROK 2: UPDATER (background, kazdu hodinu)
# ===================================================================
run_updater() {
    while true; do
        echo "[UPDATER] Kontrola aktualizácií..."
        $VENV_PYTHON $APP_DIR/update_service.py 2>/dev/null
        echo "[UPDATER] Hotovo, ďalšia kontrola o hodinu."
        sleep 3600
    done
}

run_updater &
sleep 3

# ===================================================================
# KROK 3: HLAVNY WEB SERVER (app.py) - nekonečny restart
# ===================================================================
while true; do
    if [ -f "$APP_DIR/app.py" ]; then
        echo "[APLIKÁCIA] >>> Spúšťam app.py <<<"
        sudo $VENV_PYTHON $APP_DIR/app.py 2>&1
        EXIT_CODE=$?
        echo "[APLIKÁCIA] app.py skončil (exit=$EXIT_CODE). Reštartujem o 5s..."
    else
        echo "[APLIKÁCIA] ❌ app.py sa nenašiel!"
    fi
    sleep 5
done
