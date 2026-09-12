#!/usr/bin/env python3
"""
Modbus TCP Network Scanner - hlada SmartLogger / Enspire zariadenia v lokalnej sieti.
Skenuje subnet a testuje Modbus TCP port 502 pre kazdu IP.
"""

import socket
import struct
import time
import subprocess
import os


def get_local_subnet():
    """Zisti lokalny subnet cez `ip route` alebo `ifconfig`."""
    try:
        # Zisti IP a masku cez ip route
        out = subprocess.check_output(['ip', 'route', 'show', 'dev', 'eth0'], 
                                       text=True, timeout=3)
        for line in out.strip().split('\n'):
            if 'src' in line:
                parts = line.split()
                src_idx = parts.index('src')
                my_ip = parts[src_idx + 1]
                # Zisti masku z 'proto kernel scope link src X.X.X.X'
                return my_ip.rsplit('.', 1)[0]  # 192.168.1.x
    except Exception:
        pass

    try:
        # Fallback: zisti vlastnu IP cez socket
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        my_ip = s.getsockname()[0]
        s.close()
        return my_ip.rsplit('.', 1)[0]
    except Exception:
        pass

    return "192.168.1"  # Last resort


def get_wifi_subnet():
    """Zisti subnet pre wlan0 ak je pripojeny."""
    try:
        out = subprocess.check_output(['ip', 'route', 'show', 'dev', 'wlan0'], 
                                       text=True, timeout=3)
        for line in out.strip().split('\n'):
            if 'src' in line:
                parts = line.split()
                src_idx = parts.index('src')
                my_ip = parts[src_idx + 1]
                return my_ip.rsplit('.', 1)[0]
    except Exception:
        pass
    return None


def probe_modbus_tcp(ip, port=502, timeout=0.5):
    """Odosle Modbus TCP poll request a caka odpoved."""
    try:
        # Modbus TCP: Unit ID=1, FC03 (Read Holding Registers), Adresa 0, Count 1
        packet = bytes([
            0x00, 0x01,  # Transaction ID
            0x00, 0x00,  # Protocol ID (0 = Modbus)
            0x00, 0x06,  # Length (6 bytes after this)
            0x01,        # Unit ID
            0x03,        # Function Code: Read Holding Registers
            0x00, 0x00,  # Start Address: 0
            0x00, 0x01,  # Quantity: 1
        ])

        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            s.settimeout(timeout)
            s.connect((ip, port))
            s.sendall(packet)
            resp = s.recv(256)

            if len(resp) >= 9:
                unit_id = resp[6]
                fc = resp[7]
                byte_count = resp[8]

                if fc == 0x03 and byte_count >= 2:
                    val = (resp[9] << 8) | resp[10]
                    return True, val
                elif fc == 0x83:
                    # Exception response = zaradenie existuje ale register nie je dostupny
                    return True, -1

        return False, 0
    except (socket.timeout, ConnectionRefusedError, OSError):
        return False, 0
    except Exception:
        return False, 0


def probe_modbus_tcp_registers(ip, port=502, slave_id=1, timeout=0.5):
    """Precita Huawei registre cez Modbus TCP - model, serial, power, soc."""
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            s.settimeout(timeout)
            s.connect((ip, port))
            info = {}

            # Model name: registre 30000-30015
            packet = bytes([
                0x00, 0x02, 0x00, 0x00, 0x00, 0x06,
                slave_id, 0x03,
                0x75, 0x30,  # 30000
                0x00, 0x10,  # 16 registers
            ])
            s.sendall(packet)
            resp = s.recv(512)
            if len(resp) >= 11:
                byte_count = resp[8]
                data = resp[9:9+byte_count]
                text = ""
                for i in range(0, len(data) - 1, 2):
                    hi, lo = data[i], data[i+1]
                    if hi: text += chr(hi)
                    if lo: text += chr(lo)
                text = text.strip().rstrip('\x00')
                if text:
                    info['model_name'] = text

            # Serial number: registre 32004-32011
            packet2 = bytes([
                0x00, 0x03, 0x00, 0x00, 0x00, 0x06,
                slave_id, 0x03,
                0x7D, 0x04,  # 32004
                0x00, 0x08,  # 8 registers
            ])
            s.sendall(packet2)
            resp2 = s.recv(512)
            if len(resp2) >= 11:
                byte_count = resp2[8]
                data = resp2[9:9+byte_count]
                text = ""
                for i in range(0, len(data) - 1, 2):
                    hi, lo = data[i], data[i+1]
                    if hi: text += chr(hi)
                    if lo: text += chr(lo)
                text = text.strip().rstrip('\x00')
                if text:
                    info['serial_number'] = text

            # AC Power: register 32080
            packet3 = bytes([
                0x00, 0x04, 0x00, 0x00, 0x00, 0x06,
                slave_id, 0x03,
                0x7D, 0x50,  # 32080
                0x00, 0x01,
            ])
            s.sendall(packet3)
            resp3 = s.recv(512)
            if len(resp3) >= 11:
                val = (resp3[9] << 8) | resp3[10]
                info['ac_power_w'] = val

            # SoC: register 37760
            packet4 = bytes([
                0x00, 0x05, 0x00, 0x00, 0x00, 0x06,
                slave_id, 0x03,
                0x93, 0x80,  # 37760
                0x00, 0x01,
            ])
            s.sendall(packet4)
            resp4 = s.recv(512)
            if len(resp4) >= 11:
                val = (resp4[9] << 8) | resp4[10]
                info['soc_pct'] = val

            return info
    except Exception:
        return {}


def scan_network_for_modbus(port=502, timeout=0.3, max_host=254):
    """
    Skenuje cely subnet (1-254) pre Modbus TCP zariadenia.
    Vrati zoznam: [{ip, port, unit_id, model_name, serial_number, ...}]
    """
    prefix = get_local_subnet()
    wifi_prefix = get_wifi_subnet()
    
    found = []
    
    # Skenujme eth0 subnet
    targets = [prefix]
    if wifi_prefix and wifi_prefix != prefix:
        targets.append(wifi_prefix)
    
    for subnet in targets:
        print(f"[NET] Skenujem subnet {subnet}.1-{max_host}...")
        
        for i in range(1, max_host + 1):
            ip = f"{subnet}.{i}"
            
            ok, val = probe_modbus_tcp(ip, port, timeout)
            if ok:
                print(f"[NET] ✅ Modbus TCP odpovedal: {ip}:{port} (val={val})")
                
                # Skus citat detaily
                detaily = probe_modbus_tcp_registers(ip, port, timeout=0.5)
                
                found.append({
                    'ip': ip,
                    'port': port,
                    'unit_id': 1,
                    'value': val,
                    **detaily
                })
                
            # Throttle - nesmeme bombardovat siet
            if i % 20 == 0:
                print(f"[NET] ... {i}/{max_host} ...")
                time.sleep(0.05)
    
    # Specialne: skus aj HUAWEI Enspire standardne porty (80, 8080, 502)
    print(f"[NET] Celkom najdenych: {len(found)} zariadeni")
    
    return found


if __name__ == "__main__":
    print("ELVOCONTROL NETWORK MODBUS TCP SCANNER")
    print("=" * 60)
    results = scan_network_for_modbus()
    
    if results:
        print(f"\n{'=' * 60}")
        print(f"NAJDENÉ ZARIADENIA ({len(results)}):")
        for r in results:
            model = r.get('model_name', 'Nezname')
            serial = r.get('serial_number', '---')
            print(f"  IP: {r['ip']}  Model: {model}  SN: {serial}")
    else:
        print("\nŽiadne Modbus TCP zariadenia nenájdené v sieti.")
        print("Skontrolujte:")
        print("  1. Je SmartLogger pripojený k rovnakej sieti?")
        print("  2. Je SmartLogger zapnutý?")
        print("  3. Je port 502 otvorený?")
