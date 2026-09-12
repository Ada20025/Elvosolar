#!/bin/bash
# ==============================================================================
# ElvoSolar CM5 Systemd Service Installer & Auto-Start
# Nastaví automatický štart po bootovaní Raspberry Pi CM5
# ==============================================================================

set -e

echo "=================================================="
echo "   ELVOSOLAR CM5 — AUTO-START INŠTALÁTOR         "
echo "=================================================="

SERVICE_PATH="/etc/systemd/system/elvosolar.service"
APP_DIR="/home/pi/Hardware"

if [ "$EUID" -ne 0 ]; then
  echo "❌ Spustite tento skript ako root: sudo bash install_service.sh"
  exit 1
fi

echo "[1/4] Vytváram systemd službu: $SERVICE_PATH..."

cat << 'EOF' > "$SERVICE_PATH"
[Unit]
Description=ElvoSolar CM5 Smart EMS & Modbus Core Service
After=network.target network-online.target time-sync.target
Wants=network-online.target

[Service]
Type=simple
User=root
WorkingDirectory=/home/pi/Hardware
ExecStart=/bin/bash /home/pi/Hardware/start.sh
Restart=always
RestartSec=5s
KillMode=process
StandardOutput=journal
StandardError=journal
Environment=PYTHONUNBUFFERED=1

[Install]
WantedBy=multi-user.target
EOF

echo "[2/4] Nastavujem práva..."
chmod +x /home/pi/Hardware/start.sh
chmod 644 "$SERVICE_PATH"

echo "[3/4] Načítavam systemd konfiguráciu..."
systemctl daemon-reload
systemctl enable elvosolar.service

echo "[4/4] Spúšťam službu elvosolar..."
systemctl restart elvosolar.service

echo "=================================================="
echo "✅ HOTOVO: ElvoSolar služba je aktívna a povolená na auto-štart!"
echo "   Status môžete skontrolovať príkazom:"
echo "   sudo systemctl status elvosolar"
echo "=================================================="
