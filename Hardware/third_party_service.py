# Hardware/third_party_service.py

import time
import requests
import socket

class ThirdPartyDeviceManager:
    @staticmethod
    def switch_shelly_relay(ip_address: str, channel: int = 0, turn_on: bool = True) -> bool:
        """Ovláda Shelly Gen 2 (Plus/Pro RPC) alebo Gen 1 (klasické HTTP API)."""
        # 1. Pokus cez moderné RPC rozhranie (Shelly Gen 2)
        try:
            url_gen2 = f"http://{ip_address}/rpc/Switch.Set?id={channel}&on={'true' if turn_on else 'false'}"
            r = requests.get(url_gen2, timeout=2.0)
            if r.status_code == 200:
                return True
        except Exception:
            pass

        # 2. Záložný pokus cez klasické HTTP API (Shelly Gen 1)
        try:
            url_gen1 = f"http://{ip_address}/relay/{channel}?turn={'on' if turn_on else 'off'}"
            r = requests.get(url_gen1, timeout=2.0)
            return r.status_code == 200
        except Exception:
            return False

    @staticmethod
    def switch_tasmota_sonoff(ip_address: str, channel: int = 1, turn_on: bool = True) -> bool:
        """Ovláda inteligentné relé Tasmota / Sonoff cez HTTP príkaz."""
        try:
            cmd = "ON" if turn_on else "OFF"
            url = f"http://{ip_address}/cm?cmnd=Power{channel}%20{cmd}"
            r = requests.get(url, timeout=2.0)
            return r.status_code == 200
        except Exception:
            return False

    @staticmethod
    def switch_modbus_tcp_relay(ip_address: str, port: int = 502, coil_index: int = 0, turn_on: bool = True) -> bool:
        """Nízkoúrovňové prepnutie Modbus TCP cievky priemyselného ethernetového relé."""
        try:
            val = 0xFF00 if turn_on else 0x0000
            packet = bytes([
                0x00, 0x01, # Transaction ID
                0x00, 0x00, # Protocol ID
                0x00, 0x06, # Length
                0x01,       # Unit ID
                0x05,       # Function Code 5: Write Single Coil
                (coil_index >> 8) & 0xFF, coil_index & 0xFF,
                (val >> 8) & 0xFF, val & 0xFF
            ])
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
                s.settimeout(2.0)
                s.connect((ip_address, port))
                s.sendall(packet)
                resp = s.recv(12)
                return len(resp) >= 12 and resp[7] == 0x05
        except Exception:
            return False