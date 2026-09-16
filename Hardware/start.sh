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
# KROK 1.5: NETWORK ROUTE PRE SMARTLOGGER
# ===================================================================
SMARTLOGGER_IP=$(sqlite3 /home/pi/Hardware/database.db "SELECT value FROM system_settings WHERE key='smartlogger_ip'" 2>/dev/null)
if [ -n "$SMARTLOGGER_IP" ] && [ "$SMARTLOGGER_IP" != "" ]; then
    # PRIDAJ LEN HOST ROUTE (nie subnet, nie cez gateway - aby sa nezlomil internet)
    if ! ip route show | grep -q "$SMARTLOGGER_IP"; then
        sudo ip route add $SMARTLOGGER_IP dev eth0 2>/dev/null && echo "[NETWORK] Host route pridaná: $SMARTLOGGER_IP dev eth0"
    fi
    # Over spojenie
    if ping -c 1 -W 2 $SMARTLOGGER_IP >/dev/null 2>&1; then
        echo "[NETWORK] ✅ SmartLogger $SMARTLOGGER_IP dostupný"
    else
        echo "[NETWORK] ⚠️ SmartLogger $SMARTLOGGER_IP neodpovedá (kábel zapojený?)"
    fi
    # NEPRIDAVAJ default gateway - to by zlomilo internet!
else
    echo "[NETWORK] SmartLogger IP nie je nakonfigurované"
fi

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
