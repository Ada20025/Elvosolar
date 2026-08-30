# Hardware/system_service.py

import subprocess
import socket
import time
import os
import re
import shutil
from Config import COMMON_APNS

class SystemService:
    @staticmethod
    def get_mac_suffix() -> str:
        try:
            for interface in ['wlan0', 'eth0']:
                path = f"/sys/class/net/{interface}/address"
                if os.path.exists(path):
                    with open(path, 'r') as f:
                        mac = f.read().strip().replace(':', '')
                        if mac: return mac[-6:].upper()
            
            if os.path.exists("/sys/class/net/"):
                for interface in sorted(os.listdir("/sys/class/net/")):
                    if interface.startswith(('veth', 'docker', 'br-', 'lo')):
                        continue
                    path = f"/sys/class/net/{interface}/address"
                    if os.path.exists(path):
                        with open(path, 'r') as f:
                            mac = f.read().strip().replace(':', '')
                            if mac and len(mac) >= 6: return mac[-6:].upper()
        except Exception:
            pass
        return "UNKNOWN"

    @staticmethod
    def test_internet_connection() -> bool:
        try:
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
                s.settimeout(2.0)
                s.connect(("8.8.8.8", 53))
                return True
        except Exception:
            return False

    @staticmethod
    def scan_wifi_networks():
        if not shutil.which("nmcli"):
            return [{"ssid": "nmcli nedostupný", "signal": 100, "secure": True}]
        
        try:
            subprocess.run(["nmcli", "device", "wifi", "rescan"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=4)
        except Exception:
            pass
        
        try:
            output = subprocess.check_output(
                ["nmcli", "-f", "SSID,SIGNAL,SECURITY", "device", "wifi", "list"],
                universal_newlines=True, timeout=5
            )
            networks = []
            seen_ssids = set()
            lines = output.strip().split('\n')
            
            if len(lines) > 1:
                for line in lines[1:]:
                    parts = re.split(r'\s{2,}', line.strip())
                    if len(parts) >= 2 and parts[0] and parts[0] != '--':
                        ssid = parts[0]
                        try: signal = int(parts[1])
                        except ValueError: signal = 50
                        secure = "WPA" in parts[2] if len(parts) > 2 else True
                        
                        if ssid not in seen_ssids:
                            seen_ssids.add(ssid)
                            networks.append({"ssid": ssid, "signal": signal, "secure": secure})
            return sorted(networks, key=lambda x: x['signal'], reverse=True)
        except Exception:
            return [{"ssid": "Chyba skenovania", "signal": 0, "secure": True}]

    @staticmethod
    def connect_wifi(ssid: str, password: str) -> dict:
        if not shutil.which("nmcli"):
            return {"status": "error", "message": "nmcli nie je v systéme dostupný."}
        try:
            res = subprocess.run([
                "nmcli", "device", "wifi", "connect", ssid, "password", password
            ], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=15)
            
            if res.returncode == 0:
                return {"status": "success", "message": f"Pripojenie k Wi-Fi '{ssid}' úspešné."}
            return {"status": "error", "message": f"Pripojenie zlyhalo: {res.stderr}"}
        except Exception as e:
            return {"status": "error", "message": str(e)}

    @staticmethod
    def start_ap_mode(ssid: str = "ElvoControl-Setup", password: str = "elvo1234") -> dict:
        """Vytvori WiFi hotspot pre pocatecne nastavenie zariadenia."""
        if not shutil.which("nmcli"):
            return {"status": "error", "message": "nmcli nie je dostupny."}
        try:
            # Vytvor AP hotspot
            res = subprocess.run([
                "nmcli", "device", "wifi", "hotspot", 
                "ifname", "wlan0", 
                "ssid", ssid, 
                "password", password
            ], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=15)
            
            if res.returncode == 0:
                return {
                    "status": "success", 
                    "message": f"AP hotspot vytvoreny: {ssid}",
                    "ssid": ssid,
                    "password": password,
                    "ip": "192.168.4.1"
                }
            return {"status": "error", "message": f"AP vytvorenie zlyhalo: {res.stderr}"}
        except Exception as e:
            return {"status": "error", "message": str(e)}

    @staticmethod
    def stop_ap_mode() -> dict:
        """Zastavi AP hotspot."""
        if not shutil.which("nmcli"):
            return {"status": "error", "message": "nmcli nie je dostupny."}
        try:
            subprocess.run(["nmcli", "connection", "delete", "Hotspot"], 
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
            return {"status": "success", "message": "AP hotspot zastaveny"}
        except Exception as e:
            return {"status": "error", "message": str(e)}

    @staticmethod
    def check_wifi_configured() -> bool:
        """Skontroluje ci je WiFi nakonfigurovane."""
        if not shutil.which("nmcli"):
            return False
        try:
            res = subprocess.run([
                "nmcli", "-t", "-f", "NAME,TYPE", "connection", "show"
            ], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=5)
            # Ak je aspon jedno WiFi pripojenie okrem Hotspotu
            for line in res.stdout.strip().split('\n'):
                if '802-11-wireless' in line and 'Hotspot' not in line:
                    return True
            return False
        except:
            return False

    @staticmethod
    def configure_cellular(apn: str) -> dict:
        if not shutil.which("nmcli"):
            return {"status": "error", "message": "nmcli nedostupný."}
        try:
            subprocess.run(["nmcli", "connection", "modify", "GSM_Modem", "gsm.apn", apn], capture_output=True, timeout=6)
            res = subprocess.run(["nmcli", "connection", "up", "GSM_Modem"], capture_output=True, text=True, timeout=12)
            if res.returncode == 0:
                return {"status": "success", "message": f"Pripojené k APN: {apn}."}
            return {"status": "error", "message": res.stderr.strip()}
        except Exception as e:
            return {"status": "error", "message": str(e)}

    @staticmethod
    def auto_fallback_cellular() -> dict:
        for apn in COMMON_APNS:
            SystemService.configure_cellular(apn)
            time.sleep(5)
            if SystemService.test_internet_connection():
                return {"status": "success", "apn": apn}
        return {"status": "error", "message": "Záložné LTE pripojenie zlyhalo."}

    @staticmethod
    def setup_mdns(serial_number: str) -> dict:
        try:
            clean_sn = re.sub(r'[^a-zA-Z0-9-]', '', serial_number).lower()
            subprocess.run(["sudo", "hostnamectl", "set-hostname", clean_sn], capture_output=True, timeout=5)
            return {"status": "success", "hostname": f"{clean_sn}.local"}
        except Exception as e:
            return {"status": "error", "message": str(e)}