#!/usr/bin/env python3
"""
ElvoControl Network Discovery - Najde SmartLogger / Enspire / Modbus TCP zariadenia v sieti.

Pouziva 3 metody:
  1. Modbus TCP direct probe - skusa port 502 na kazdej IP
  2. ARP scan - zisti vsetky zaradenia v ARP tabulke (funguje aj bez odpovede)
  3. mDNS / Bonjour - hladaj _modbus._tcp alebo _http._tcp sluzby

SmartLogger Enspire standardne:
  - Web port: 80 (HTTP) 
  - Modbus TCP: 502
  - UDP broadcast: 50000 (discovery)
"""

import socket
import struct
import time
import subprocess
import os
import re
import json
from concurrent.futures import ThreadPoolExecutor, as_completed


def get_local_ip():
    """Zisti vlastnu IP adresu."""
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        ip = s.getsockname()[0]
        s.close()
        return ip
    except Exception:
        return "192.168.1.100"


def get_subnet():
    """Vrati subnet prefix (napr. '192.168.1')."""
    ip = get_local_ip()
    return ip.rsplit('.', 1)[0]


def get_interfaces():
    """Zisti vsetky aktivne sietove rozhrania a ich subnety."""
    interfaces = []
    try:
        out = subprocess.check_output(['ip', '-4', 'addr', 'show'], text=True, timeout=3)
        current_iface = None
        for line in out.split('\n'):
            iface_match = re.match(r'^\d+:\s+(\S+):', line)
            if iface_match:
                current_iface = iface_match.group(1)
            ip_match = re.search(r'inet (\d+\.\d+\.\d+\.\d+)/(\d+)', line)
            if ip_match and current_iface:
                ip = ip_match.group(1)
                prefix = ip.rsplit('.', 1)[0]
                interfaces.append({
                    'name': current_iface,
                    'ip': ip,
                    'subnet': prefix,
                    'mask_bits': int(ip_match.group(2))
                })
    except Exception:
        pass
    
    if not interfaces:
        # Fallback
        ip = get_local_ip()
        interfaces.append({
            'name': 'unknown',
            'ip': ip,
            'subnet': ip.rsplit('.', 1)[0],
            'mask_bits': 24
        })
    
    return interfaces


# =============================================================================
# METODA 1: Modbus TCP Direct Probe
# =============================================================================

def probe_modbus_tcp(ip, port=502, timeout=0.3):
    """Odosle Modbus TCP poll a caka odpoved. Vrati (found, value)."""
    try:
        # FC03 Read Holding Register adresa 0
        packet = bytes([
            0x00, 0x01,  # Transaction ID
            0x00, 0x00,  # Protocol ID (Modbus)
            0x00, 0x06,  # Length
            0x01,        # Unit ID
            0x03,        # FC03: Read Holding Registers
            0x00, 0x00,  # Start Address: 0
            0x00, 0x01,  # Quantity: 1
        ])
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            s.settimeout(timeout)
            s.connect((ip, port))
            s.sendall(packet)
            resp = s.recv(256)
            if len(resp) >= 9:
                fc = resp[7]
                if fc == 0x03:
                    val = (resp[9] << 8) | resp[10] if len(resp) >= 11 else 0
                    return True, val
                elif fc == 0x83:
                    return True, -1  # Exception = zaradenie existuje
        return False, 0
    except (socket.timeout, ConnectionRefusedError, OSError):
        return False, 0
    except Exception:
        return False, 0


def probe_http_title(ip, port=80, timeout=0.5):
    """Skusi citat HTTP title - SmartLogger Enspire ma web interface."""
    try:
        req = f"GET / HTTP/1.0\r\nHost: {ip}\r\n\r\n"
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            s.settimeout(timeout)
            s.connect((ip, port))
            s.sendall(req.encode())
            resp = s.recv(2048).decode('utf-8', errors='ignore')
            
            # Hladaj title tag
            title_match = re.search(r'<title>(.*?)</title>', resp, re.IGNORECASE)
            title = title_match.group(1) if title_match else ''
            
            # SmartLogger Enspire identifikacia
            if any(x in resp.lower() for x in ['enspire', 'huawei', 'smartlogger', 'solar']):
                return True, title
            
            # Ak ma HTTP odpoved, je to zaradenie (mozno SmartLogger)
            if 'HTTP/1' in resp:
                return True, title
        return False, ''
    except Exception:
        return False, ''


def read_modbus_details(ip, port=502, slave_id=1, timeout=0.5):
    """Precita Huawei registre cez Modbus TCP."""
    info = {}
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            s.settimeout(timeout)
            s.connect((ip, port))
            
            # Model name: registre 30000-30015
            pkt = bytes([0x00,0x02,0x00,0x00,0x00,0x06,slave_id,0x03, 0x75,0x30, 0x00,0x10])
            s.sendall(pkt)
            resp = s.recv(512)
            if len(resp) >= 11:
                bc = resp[8]
                data = resp[9:9+bc]
                text = ""
                for i in range(0, len(data)-1, 2):
                    if data[i]: text += chr(data[i])
                    if data[i+1]: text += chr(data[i+1])
                text = text.strip().rstrip('\x00')
                if text: info['model_name'] = text

            # Serial number: 32004
            pkt2 = bytes([0x00,0x03,0x00,0x00,0x00,0x06,slave_id,0x03, 0x7D,0x04, 0x00,0x08])
            s.sendall(pkt2)
            resp2 = s.recv(512)
            if len(resp2) >= 11:
                bc = resp2[8]
                data = resp2[9:9+bc]
                text = ""
                for i in range(0, len(data)-1, 2):
                    if data[i]: text += chr(data[i])
                    if data[i+1]: text += chr(data[i+1])
                text = text.strip().rstrip('\x00')
                if text: info['serial_number'] = text

            # AC Power: 32080
            pkt3 = bytes([0x00,0x04,0x00,0x00,0x00,0x06,slave_id,0x03, 0x7D,0x50, 0x00,0x01])
            s.sendall(pkt3)
            resp3 = s.recv(512)
            if len(resp3) >= 11:
                info['ac_power_w'] = (resp3[9] << 8) | resp3[10]

            # SoC: 37760
            pkt4 = bytes([0x00,0x05,0x00,0x00,0x00,0x06,slave_id,0x03, 0x93,0x80, 0x00,0x01])
            s.sendall(pkt4)
            resp4 = s.recv(512)
            if len(resp4) >= 11:
                info['soc_pct'] = (resp4[9] << 8) | resp4[10]
    except Exception:
        pass
    return info


# =============================================================================
# METODA 2: ARP Scan - najde vsetky zariadenia v LAN
# =============================================================================

def arp_scan(subnet):
    """ARP scan - zisti vsetky IP+MAC v sieti."""
    devices = []
    try:
        # Ping sweep najprv (aby sa ARP tabulka naplnila)
        subprocess.run(
            ['nmap', '-sn', '-n', '--min-parallelism', '32', f'{subnet}.1-254'],
            capture_output=True, timeout=30
        )
    except Exception:
        # Fallback: rychly ping
        try:
            subprocess.run(
                ['ping', '-c', '1', '-W', '1', f'{subnet}.255'],
                capture_output=True, timeout=5
            )
        except Exception:
            pass
    
    # Citanie ARP tabulky
    try:
        out = subprocess.check_output(['arp', '-a', '-n'], text=True, timeout=5)
        for line in out.split('\n'):
            if subnet in line:
                # Format: ? (192.168.1.50) at aa:bb:cc:dd:ee:ff [ether] on eth0
                match = re.search(r'\((\d+\.\d+\.\d+\.\d+)\)\s+at\s+([0-9a-fA-F:]+)', line)
                if match:
                    devices.append({
                        'ip': match.group(1),
                        'mac': match.group(2)
                    })
    except Exception:
        pass
    
    return devices


# =============================================================================
# METODA 3: UDP Broadcast Discovery
# =============================================================================

def udp_broadcast_discover(subnet, port=50000, timeout=2):
    """Odosle UDP broadcast a caka odpovede."""
    devices = []
    try:
        msg = b'ELVOCONTROL_DISCOVER_v1'
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        sock.settimeout(timeout)
        sock.sendto(msg, (f'{subnet}.255', port))
        
        start = time.time()
        while time.time() - start < timeout:
            try:
                data, addr = sock.recvfrom(1024)
                devices.append({
                    'ip': addr[0],
                    'port': addr[1],
                    'response': data.decode('utf-8', errors='ignore')[:100]
                })
            except socket.timeout:
                break
        sock.close()
    except Exception:
        pass
    return devices


# =============================================================================
# HLAVNA FUNKCIA
# =============================================================================

def scan_network(port=502, timeout=0.3, max_workers=48):
    """
    Komplexne skenovanie siete. Vrati zoznam najdenych zariadeni.
    """
    ifaces = get_interfaces()
    all_found = []
    
    iface_info = [f'{i["name"]}={i["ip"]}' for i in ifaces]
    print(f"[NET] Moje rozhrania: {iface_info}")
    
    for iface in ifaces:
        subnet = iface['subnet']
        print(f"\n[NET] === Skenovanie subnetu {subnet}.1-254 ({iface['name']}) ===")
        
        # --- METODA 1: Modbus TCP parallel probe ---
        print(f"[NET] Metoda 1: Modbus TCP probe (port {port})...")
        modbus_found = []
        
        with ThreadPoolExecutor(max_workers=max_workers) as executor:
            futures = {}
            for i in range(1, 255):
                ip = f"{subnet}.{i}"
                f = executor.submit(probe_modbus_tcp, ip, port, timeout)
                futures[f] = ip
            
            for f in as_completed(futures):
                ip = futures[f]
                try:
                    ok, val = f.result()
                    if ok:
                        print(f"  ✅ {ip}:{port} odpovedal (val={val})")
                        modbus_found.append({'ip': ip, 'port': port, 'value': val, 'method': 'modbus_tcp'})
                except Exception:
                    pass
        
        # --- METODA 2: ARP scan ---
        print(f"[NET] Metoda 2: ARP scan...")
        arp_devices = arp_scan(subnet)
        print(f"  Nájdených {len(arp_devices)} zariadení v ARP tabulke")
        
        # --- METODA 3: HTTP probe na ARP zariadeniach ---
        print(f"[NET] Metoda 3: HTTP probe na ARP zariadeniach (port 80)...")
        http_found = []
        
        if arp_devices:
            # Skusaj HTTP len na zariadeniach co NIE su v modbus_found
            modbus_ips = {d['ip'] for d in modbus_found}
            arp_only = [d for d in arp_devices if d['ip'] not in modbus_ips]
            
            with ThreadPoolExecutor(max_workers=20) as executor:
                futures = {}
                for dev in arp_only[:50]:  # Max 50
                    f = executor.submit(probe_http_title, dev['ip'], 80, 0.5)
                    futures[f] = dev
                
                for f in as_completed(futures):
                    dev = futures[f]
                    try:
                        ok, title = f.result()
                        if ok:
                            is_smartlogger = any(x in title.lower() for x in ['enspire', 'huawei', 'smartlogger'])
                            print(f"  ✅ {dev['ip']}:80 HTTP '{title}' {'<-- SMARTLOGGER!' if is_smartlogger else ''}")
                            http_found.append({
                                'ip': dev['ip'], 
                                'port': 80, 
                                'http_title': title,
                                'mac': dev.get('mac', ''),
                                'is_smartlogger': is_smartlogger,
                                'method': 'http'
                            })
                    except Exception:
                        pass
        
        # --- METODA 4: UDP Broadcast ---
        print(f"[NET] Metoda 4: UDP broadcast...")
        udp_found = udp_broadcast_discover(subnet)
        if udp_found:
            print(f"  Nájdených {len(udp_found)} zariadení cez UDP")
        
        # --- ZLUC vysledky ---
        all_ip_results = {}
        
        for d in modbus_found:
            all_ip_results[d['ip']] = d
        
        for d in http_found:
            if d['ip'] not in all_ip_results:
                all_ip_results[d['ip']] = d
            elif d.get('is_smartlogger'):
                all_ip_results[d['ip']]['is_smartlogger'] = True
                all_ip_results[d['ip']]['http_title'] = d.get('http_title', '')
        
        for d in arp_devices:
            if d['ip'] not in all_ip_results:
                all_ip_results[d['ip']] = {**d, 'method': 'arp_only'}
        
        # --- Doplnt detaily pre Modbus zariadenia ---
        for ip, dev in all_ip_results.items():
            if dev.get('method') == 'modbus_tcp':
                print(f"[NET] Čítam detaily z {ip}...")
                details = read_modbus_details(ip, port)
                dev.update(details)
        
        all_found.extend(all_ip_results.values())
    
    # Zhrnutie
    print(f"\n{'='*60}")
    print(f"CELKOM NAJDENÝCH: {len(all_found)} zariadení v sieti")
    
    # SmartLogger prioritne
    smartloggers = [d for d in all_found if d.get('is_smartlogger') or 'enspire' in str(d.get('http_title', '')).lower()]
    modbus_devices = [d for d in all_found if d.get('method') == 'modbus_tcp']
    
    if smartloggers:
        print(f"\n🎯 SMARTLOGGER / ENSPIRE ({len(smartloggers)}):")
        for d in smartloggers:
            print(f"  {d['ip']} - {d.get('http_title', 'SmartLogger')} (MAC: {d.get('mac', '?')})")
    
    if modbus_devices:
        print(f"\n📡 MODBUS TCP ZARIADENIA ({len(modbus_devices)}):")
        for d in modbus_devices:
            model = d.get('model_name', 'Neznáme')
            sn = d.get('serial_number', '---')
            print(f"  {d['ip']}:{d['port']} - Model: {model} SN: {sn}")
    
    if not all_found:
        print("\n⚠️ Žiadne zariadenia nenájdené.")
        print("Skontrolujte:")
        print("  1. Je SmartLogger zapnutý a pripojený k sieti?")
        print("  2. Je CM5 na rovnakej sieti (eth0 alebo wlan0)?")
        print("  3. Firewall neblokuje port 502?")
    
    return all_found


if __name__ == "__main__":
    print("="*60)
    print("ELVOCONTROL NETWORK DISCOVERY")
    print(f"Cas: {time.strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Moja IP: {get_local_ip()}")
    print(f"Subnet: {get_subnet()}")
    print("="*60)
    
    results = scan_network()
    
    # Uloz vysledky do JSON pre API
    output_file = '/tmp/network_scan_results.json'
    with open(output_file, 'w') as f:
        json.dump(results, f, indent=2, default=str)
    print(f"\nVýsledky uložené do {output_file}")
