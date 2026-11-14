#!/bin/bash
# =============================================================================
# add_device.sh — pridá CM5 zariadenie do Mosquitto brokera
# Použitie (na firemnom serveri):  sudo ./add_device.sh CM5_452
# Skript vygeneruje silné heslo a vypíše údaje, ktoré zadáš na boxe káblom
# cez http://<IP-boxu>/mqtt
# =============================================================================
set -e

SERIAL="$1"
if [ -z "$SERIAL" ]; then
  echo "Použitie: sudo ./add_device.sh CM5_452"
  exit 1
fi
if ! echo "$SERIAL" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$'; then
  echo "❌ Neplatný serial (povolené: písmená, čísla, . _ -)"
  exit 1
fi

PASS=$(openssl rand -hex 16)
touch /etc/mosquitto/passwd
mosquitto_passwd -b /etc/mosquitto/passwd "$SERIAL" "$PASS"
chown root:mosquitto /etc/mosquitto/passwd
chmod 640 /etc/mosquitto/passwd
systemctl restart mosquitto

echo ""
echo "=============================================="
echo " ✅ Zariadenie $SERIAL pridané do brokera"
echo "----------------------------------------------"
echo " username: $SERIAL"
echo " password: $PASS"
echo "----------------------------------------------"
echo " Zadaj tieto údaje na boxe káblom:"
echo "   http://<IP-boxu>/mqtt  -> Konfigurácia brokera"
echo " (username = serial, heslo vyššie)"
echo "=============================================="
