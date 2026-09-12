#!/bin/bash
# ==============================================================================
# ElvoSolar CM5 — Zastavenie všetkých služieb (Kill)
# ==============================================================================

echo "=================================================="
echo "    ELVOSOLAR CM5 — KILL & STOP PROCESOV          "
echo "=================================================="

# 1. Zastavenie systemd služby
echo "Zastavujem elvosolar.service..."
sudo systemctl stop elvosolar 2>/dev/null || true

# 2. Vynútené ukončenie všetkých procesov
echo "Vypínam procesy na pozadí (app.py, modbus, update)..."
sudo pkill -9 -f "app.py" 2>/dev/null || true
sudo pkill -9 -f "modbus_slave_service.py" 2>/dev/null || true
sudo pkill -9 -f "update_service.py" 2>/dev/null || true
sudo pkill -9 -f "start.sh" 2>/dev/null || true

# 3. Uvoľnenie sieťových portov 5000, 502, 5020 ak visia
echo "Kontrolujem uvoľnenie portov..."
sudo fuser -k 5000/tcp 2>/dev/null || true
sudo fuser -k 502/tcp 2>/dev/null || true
sudo fuser -k 5020/tcp 2>/dev/null || true

echo "=================================================="
echo "🛑 Všetky ElvoSolar služby boli úspešne zastavené."
echo "=================================================="
