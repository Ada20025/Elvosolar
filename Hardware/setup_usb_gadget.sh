# ============================================================
#  ELVOCONTROLL CM5 — USB GADGET SERIAL (USB-C kábel priamo z PC)
# ============================================================
# Toto umožní setup cez obyčajný USB-C kábel: CM5 sa ukáže vo
# Windows ako COM port (Web Serial v Chrome ho potom nájde).
#
# SPUŠŤ NA CM5 (SSH) — jeden blok:

# 1. Zapni USB gadget mód v boot configu (podpora pre /boot/firmware aj /boot)
#    POZOR: dr_mode=host blokuje gadget! Vzdy vymen na peripheral a nechaj len JEDEN dwc2 riadok.
BOOT_CFG="/boot/firmware/config.txt"
[ -f "$BOOT_CFG" ] || BOOT_CFG="/boot/config.txt"
sudo sed -i '/^dtoverlay=dwc2/d' "$BOOT_CFG"
echo "dtoverlay=dwc2,dr_mode=peripheral" | sudo tee -a "$BOOT_CFG"
echo "    (config: $BOOT_CFG)"

# 2. Načítaj libcomposite modul natrvalo
echo "dwc2" | sudo tee /etc/modules-load.d/dwc2.conf
echo "libcomposite" | sudo tee -a /etc/modules-load.d/dwc2.conf

# 3. Vytvor USB gadget so sériovým portom (spúšťa sa pri boote)
sudo tee /usr/local/bin/usb-serial-gadget.sh > /dev/null <<'GADGET'
#!/bin/bash
CONFIGFS=/sys/kernel/config/usb_gadget/elvosolar
if [ -d "$CONFIGFS" ]; then exit 0; fi
modprobe libcomposite
mkdir -p $CONFIGFS
cd $CONFIGFS
echo 0x1d6b > idVendor   # Linux Foundation
echo 0x0104 > idProduct  # Composite Gadget
echo 0x0100 > bcdDevice
echo 0x0200 > bcdUSB
mkdir -p strings/0x409
echo "elvocontroll"      > strings/0x409/serialnumber
echo "ElvoSolar"         > strings/0x409/manufacturer
echo "ElvoControll CM5"  > strings/0x409/product
mkdir -p configs/c.1/strings/0x409
echo "CM5 Serial"        > configs/c.1/strings/0x409/configuration
# Seriova funkcia (ACM)
mkdir -p functions/acm.gs0
ln -s functions/acm.gs0 configs/c.1/
# Aktivuj na USB kontroleri
UDC=$(ls /sys/class/udc | head -1)
echo "$UDC" > UDC
GADGET
sudo chmod +x /usr/local/bin/usb-serial-gadget.sh

# 4. systemd služba na spustenie gadgetu pri boote
sudo tee /etc/systemd/system/usb-serial-gadget.service > /dev/null <<UNIT
[Unit]
Description=ElvoSolar CM5 USB Serial Gadget
After=sys-kernel-config.mount

[Service]
Type=oneshot
ExecStart=/usr/local/bin/usb-serial-gadget.sh
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
UNIT
sudo systemctl daemon-reload
sudo systemctl enable --now usb-serial-gadget.service

# 5. Skontroluj výsledok
sleep 2
ls /dev/ttyGS0 && echo "✅ HOTOVO — CM5 je teraz COM port! Skontroluj vo Windows Device Manager: Porty (COM a LPT)" || echo "❌ ttyGS0 nevznikol — reštartuj CM5: sudo reboot"
