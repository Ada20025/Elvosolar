#!/bin/bash
# ============================================================
#  ElvoSolar CM5 — START (systemd riadi reštarty, žiadne slučky)
# ============================================================

VENV_PYTHON="/home/pi/Hardware/venv/bin/python3"
APP_DIR="/home/pi/Hardware"

# Ak venv neexistuje, vytvor ho
if [ ! -f "$VENV_PYTHON" ]; then
    echo "[SETUP] Vytváram venv..."
    python3 -m venv /home/pi/Hardware/venv
    $VENV_PYTHON -m pip install --quiet fastapi uvicorn pyserial pymodbus requests gpiod
    echo "[SETUP] Závislosti nainštalované."
fi

# ============================================================
# CLEANUP: žiadna iná inštancia nesmie bežať (jedna práca = systemd)
# ============================================================
echo "[CLEANUP] Upratujem staré procesy..."
pkill -9 -f "python3.*app.py" 2>/dev/null
# Zabi cudzie start.sh (mimo systemd - napr. ručné nohup)
MY_PID=$$
for pid in $(pgrep -f "bash.*start.sh"); do
    if [ "$pid" != "$MY_PID" ] && [ "$pid" != "$PPID" ]; then
        echo "[CLEANUP] Zabíjam cudzí start.sh PID=$pid"
        kill -9 "$pid" 2>/dev/null
    fi
done
sleep 1
for PORT in 80 5020; do
    PIDS=$(fuser $PORT/tcp 2>/dev/null | tr -d ' ')
    if [ -n "$PIDS" ]; then
        echo "[CLEANUP] Port $PORT drží PID: $PIDS - ukončujem..."
        kill -9 $PIDS 2>/dev/null
        sleep 1
    fi
done
echo "[CLEANUP] ✅ Porty 80 + 5020 voľné"

# ============================================================
# NETWORK ROUTE PRE SMARTLOGGER (len host route - internet ostane fungovať)
# ============================================================
if command -v sqlite3 >/dev/null 2>&1; then
    SMARTLOGGER_IP=$(sqlite3 /home/pi/Hardware/database.db "SELECT smartlogger_ip FROM devices WHERE smartlogger_ip IS NOT NULL AND smartlogger_ip != '' LIMIT 1" 2>/dev/null)
    if [ -z "$SMARTLOGGER_IP" ]; then
        SMARTLOGGER_IP=$(sqlite3 /home/pi/Hardware/database.db "SELECT value FROM system_settings WHERE key='smartlogger_ip'" 2>/dev/null)
    fi
    if [ -n "$SMARTLOGGER_IP" ]; then
        if ! ip route show | grep -q "$SMARTLOGGER_IP"; then
            ip route add $SMARTLOGGER_IP dev eth0 2>/dev/null && echo "[NETWORK] Host route: $SMARTLOGGER_IP dev eth0"
        fi
        if ping -c 1 -W 2 $SMARTLOGGER_IP >/dev/null 2>&1; then
            echo "[NETWORK] ✅ SmartLogger $SMARTLOGGER_IP dostupný"
        else
            echo "[NETWORK] ⚠️ SmartLogger $SMARTLOGGER_IP neodpovedá (kábel zapojený?)"
        fi
    else
        echo "[NETWORK] SmartLogger IP nie je nakonfigurovaná (urob setup káblom)"
    fi
fi

# ============================================================
# UPDATER (jedna inštancia, hodinový cyklus)
# ============================================================
pgrep -f "update_service.py" >/dev/null || \
    $VENV_PYTHON $APP_DIR/update_service.py 2>/dev/null &

# ============================================================
# HLAVNY WEB SERVER - popredie (systemd ho stráži a reštartuje)
# ============================================================
echo "[APLIKÁCIA] >>> Spúšťam app.py <<<"
cd $APP_DIR
exec $VENV_PYTHON $APP_DIR/app.py 2>&1
