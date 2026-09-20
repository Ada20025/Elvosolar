# -*- coding: utf-8 -*-
"""
ElvoControll CM5 - Serial Config Service
Cita JSON config z USB UART konzoly (kedy pouzivatel posiela setup z web stranky cez WebSerial).

Ako to funguje:
1. Pouzivatel otvori setup na https://elvosolar-production.up.railway.app/setup
2. Klikne "🔌 Pripojiť cez USB UART" (Chrome na PC, kabel z PC do CM5)
3. WebSerial posle JSON config cez USB kabel do CM5 konzoly
4. Tento service ho precita a ulozi do database.db (rovnako ako /api/admin/claim)
5. CM5 sa nastavi aj ked NEMA WiFi - vsetko islo cez kabel

Spusta sa automaticky v app.py ako background thread.
"""

import os
import sys
import json
import time
import glob
import threading
import serial
from datetime import datetime

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

LOG_PREFIX = "[SERIAL-CONFIG]"

# Mozne porty kde moze byt USB konzola (PC -> CM5 kabel)
CANDIDATE_PORTS = [
    '/dev/ttyACM0',   # USB CDC (najcastejsie pre CM5 console)
    '/dev/ttyGS0',    # USB gadget serial (CM5 gadget mode)
    '/dev/ttyAMA0',   # GPIO UART (CM5)
    '/dev/ttyUSB0',   # USB-UART prevodnik
    '/dev/ttyUSB1',
    '/dev/ttyACM1',
    '/dev/serial0',   # GPIO UART fallback
]

# Znacenie zaciatku a konca JSON v raw streame
JSON_START = '{'
MIN_JSON_LEN = 30


def log(msg):
    print(f"{LOG_PREFIX} {msg}", flush=True)


def get_db_connection():
    from database import get_db_connection as _g
    return _g()


def is_already_configured():
    """Skontroluje ci je zariadenie uz nastavene (is_claimed=1)."""
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT value FROM system_settings WHERE key = 'is_claimed'")
        row = cursor.fetchone()
        conn.close()
        return row and str(row[0]) == '1'
    except Exception:
        return False


def find_console_port():
    """Najde port kde prideju data z PC (USB konzola)."""
    found = sorted(
        glob.glob('/dev/ttyACM*') +
        glob.glob('/dev/ttyUSB*') +
        glob.glob('/dev/ttyGS*') +
        ['/dev/serial0']
    )
    for p in CANDIDATE_PORTS:
        if p in found or os.path.exists(p):
            return p
    return None


def apply_config(config):
    """
    Aplikuje JSON config do database.db - rovnako ako /api/admin/claim na CM5.
    config = JSON poslany z setup stranky cez WebSerial.
    """
    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        brand_id = str(config.get('brand_id', 'huawei'))
        category_id = str(config.get('category_id', ''))
        model_id = str(config.get('model_id', ''))
        slave_id = int(config.get('slave_id', 1) or 1)
        device_name = str(config.get('device_name') or config.get('name') or 'Moje zariadenie')
        ssid = str(config.get('ssid') or '')
        password = str(config.get('password') or '')
        has_battery = 1 if config.get('has_battery', True) else 0

        # 0. Self-healing: dopln chybajuce stlpce (hocijaka stara schema)
        dev_cols = [r[1] for r in cursor.execute("PRAGMA table_info(devices)").fetchall()]
        for col, ddl in [
            ('connection_type', "VARCHAR(20) DEFAULT 'modbus_tcp'"),
            ('smartlogger_ip', "VARCHAR(50) DEFAULT ''"),
            ('smartlogger_port', 'INTEGER DEFAULT 502'),
            ('modbus_slave_id', 'INTEGER DEFAULT 205'),
            ('min_okte_price_eur', 'FLOAT DEFAULT 0'),
        ]:
            if col not in dev_cols:
                try:
                    cursor.execute(f"ALTER TABLE devices ADD COLUMN {col} {ddl}")
                except Exception:
                    pass
                dev_cols.append(col)

        # 1. Uloz/zmen hlavne zariadenie (UNIVERZALNE - hocijaka znacka/model)
        cursor.execute("SELECT id FROM devices LIMIT 1")
        row = cursor.fetchone()
        if row:
            cursor.execute(
                "UPDATE devices SET name=?, brand_id=?, category_id=?, model_id=?, sub_type=?, modbus_slave_id=? WHERE id=?",
                (device_name, brand_id, category_id, model_id, category_id, slave_id, row[0])
            )
        else:
            serial_number = f"CM5-{int(time.time())}"
            cursor.execute(
                "INSERT INTO devices (serial_number, name, brand_id, category_id, model_id, sub_type, modbus_slave_id) VALUES (?,?,?,?,?,?,?)",
                (serial_number, device_name, brand_id, category_id, model_id, category_id, slave_id)
            )

        # 1b. Komunikacia: TCP (SmartLogger cez LAN) / RTU (RS485) - podla configu
        smart_ip = str(config.get('smartlogger_ip') or config.get('smart_ip') or '').strip()
        smart_port = int(config.get('smartlogger_port') or config.get('smart_port') or 502)
        smart_unit = int(config.get('smartlogger_unit_id') or config.get('unit_id') or 0)
        conn_type_val = str(config.get('connection_type') or '').lower()
        if not conn_type_val:
            # Odvod z kadial prisli udaje: IP pritomna -> tcp inak rtu
            conn_type_val = 'modbus_tcp' if smart_ip else 'modbus_rtu'
        try:
            set_parts, set_vals = [], []
            if 'connection_type' in dev_cols:
                set_parts.append('connection_type=?'); set_vals.append(conn_type_val)
            if smart_ip and 'smartlogger_ip' in dev_cols:
                set_parts.append('smartlogger_ip=?'); set_vals.append(smart_ip)
                set_parts.append('smartlogger_port=?'); set_vals.append(smart_port)
            if smart_ip and 'modbus_slave_id' in dev_cols:
                set_parts.append('modbus_slave_id=?'); set_vals.append(smart_unit)
            if set_parts:
                set_vals.append(row[0] if row else 1)
                cursor.execute(f"UPDATE devices SET {', '.join(set_parts)} WHERE id=?", set_vals)
        except Exception as _econn:
            log(f"⚠️ connection_type skip: {_econn}")

        # 2. WiFi ak prisla
        if ssid:
            try:
                cursor.execute(
                    "INSERT OR REPLACE INTO system_settings (key, value) VALUES ('wifi_ssid', ?)",
                    (ssid,)
                )
                cursor.execute(
                    "INSERT OR REPLACE INTO system_settings (key, value) VALUES ('wifi_pass', ?)",
                    (password,)
                )
            except Exception:
                pass

        # 2b. Ostatne nastavenia - vsetko co prislo, CM5 pochopi (univerzalny config)
        extra_settings = {
            'comm_mode': config.get('comm_mode') or 'LOCAL_MODBUS',
            'device_name': device_name,
            'active_cable_cores': str(config.get('active_cable_cores') or ''),
            'meter_mode': 'NONE',
        }
        sm = config.get('smart_meter') or {}
        if isinstance(sm, dict) and sm.get('enabled'):
            extra_settings['meter_mode'] = str(sm.get('type', 'standalone')).upper()
            for k, v in sm.items():
                if k != 'enabled':
                    extra_settings[f'meter_{k}'] = str(v)
        for k, v in extra_settings.items():
            if v is None or v == '':
                continue
            try:
                cursor.execute("INSERT OR REPLACE INTO system_settings (key, value) VALUES (?, ?)", (k, str(v)))
            except Exception:
                pass

        # 3. oznac ako claimed
        cursor.execute("DELETE FROM system_settings WHERE key = 'is_claimed'")
        cursor.execute("INSERT INTO system_settings (key, value) VALUES ('is_claimed', '1')")

        conn.commit()
        conn.close()

        log(f"✅ Config aplikovany: name={device_name}, brand={brand_id}, ssid={'***' if ssid else '-'}")
        return True
    except Exception as e:
        log(f"❌ Chyba pri aplikovani configu: {e}")
        return False


def register_to_cloud(config):
    """
    CM5 sa SAM zaregistruje do cloudovej DB (Railway).
    Zavola sa po uspesnom aplikovani configu - ked CM5 dostane internet.
    Skusa to opakovane kym sa to nepodari (WiFi moze prist neskor).
    """
    import requests

    CLOUD = os.environ.get("CLOUD_SERVER_URL", "https://elvosolar-production.up.railway.app")

    # Serial z lokalnej DB - INAK by sa kazdou registraciou vytvorilo nove zariadenie v cloude
    _serial = f"CM5-{int(time.time())}"
    try:
        conn2 = get_db_connection()
        cur2 = conn2.cursor()
        cur2.execute("SELECT serial_number FROM devices WHERE serial_number IS NOT NULL LIMIT 1")
        r2 = cur2.fetchone()
        if r2 and r2[0]:
            _serial = str(r2[0])
        conn2.close()
    except Exception:
        pass

    payload = {
        'brand_id': str(config.get('brand_id', 'huawei')),
        'category_id': str(config.get('category_id', '')),
        'model_id': str(config.get('model_id', '')),
        'slave_id': int(config.get('slave_id', 1) or 1),
        'has_battery': bool(config.get('has_battery', True)),
        'name': str(config.get('device_name') or config.get('name') or 'Moje zariadenie'),
        'comm_mode': str(config.get('comm_mode') or 'LOCAL_MODBUS'),
        'serial': _serial
    }

    def _try_register():
        try:
            r = requests.post(f"{CLOUD}/api/user/claim-device", json=payload, timeout=8)
            if r.status_code == 200:
                log("✅ CM5 sa sam zaregistroval do cloudovej DB (Railway)")
                return True
            log(f"⚠️ Cloud registration: HTTP {r.status_code}")
        except Exception as e:
            log(f"⏳ Cloud nedostupny (skusam dalej): {e}")
        return False

    # Vsetko v backgrounde - setup potvrdi OKAMZITE aj ked cloud nie je dostupny.
    # CM5 funguje lokalne (Local Modbus) uplne bez cloudu - registracia je len pre dashboard.
    def _retry():
        # Prvy pokus hned (mozno uz ma internet cez LAN)
        if _try_register():
            return
        # Retry loop - kazdych 30s max 20 krat (~10 minut), bezpecne bez WiFi aj bez internetu
        for _ in range(20):
            time.sleep(30)
            if _try_register():
                return
    threading.Thread(target=_retry, daemon=True).start()


def connect_wifi_if_needed(ssid, password):
    """Pokus sa pripojit na WiFi ak prisla v configu (nmcli). Funguje aj bez WiFi - vsetko islo kablom."""
    if not ssid:
        return
    try:
        import subprocess
        # Ak uz je pripojene na tuto siet, preskoc (nenut reconnect)
        chk = subprocess.run(['nmcli', '-t', '-f', 'ACTIVE,SSID', 'dev', 'wifi'],
                             timeout=10, capture_output=True, text=True)
        for line in chk.stdout.splitlines():
            if line.startswith('ano:') or line.startswith('yes:'):
                active_ssid = line.split(':', 1)[1] if ':' in line else ''
                if active_ssid == ssid:
                    log(f"✅ WiFi {ssid} už je pripojené — preskakujem"); return
        log(f"📡 Pripajam WiFi: {ssid}")
        r = subprocess.run(
            ['nmcli', 'dev', 'wifi', 'connect', ssid, 'password', password],
            timeout=45, capture_output=True, text=True
        )
        if r.returncode == 0:
            log(f"✅ WiFi {ssid} pripojene")
            # === PERSISTENCIA: WiFi sa musí sama pripojiť aj po reštarte (update, výpadok prúdu) ===
            try:
                subprocess.run(['nmcli', 'connection', 'modify', ssid, 'connection.autoconnect', 'yes'],
                               timeout=10, capture_output=True)
                subprocess.run(['nmcli', 'connection', 'modify', ssid, 'connection.autoconnect-priority', '10'],
                               timeout=10, capture_output=True)
                log(f"💾 WiFi {ssid} uložené s autoconnect (prežije reštart)")
            except Exception:
                pass
        else:
            # RETRY: druhý pokus po krátkom čakaní (rádio sa niekdy preberá pomaly)
            log(f"⚠️ Prvý pokus neprešiel — skúšam znova o 3s...")
            time.sleep(3)
            r2 = subprocess.run(
                ['nmcli', 'dev', 'wifi', 'connect', ssid, 'password', password],
                timeout=45, capture_output=True, text=True
            )
            if r2.returncode == 0:
                log(f"✅ WiFi {ssid} pripojene (2. pokus)")
                try:
                    subprocess.run(['nmcli', 'connection', 'modify', ssid, 'connection.autoconnect', 'yes'],
                                   timeout=10, capture_output=True)
                    subprocess.run(['nmcli', 'connection', 'modify', ssid, 'connection.autoconnect-priority', '10'],
                                   timeout=10, capture_output=True)
                    log(f"💾 WiFi {ssid} uložené s autoconnect (prežije reštart)")
                except Exception:
                    pass
            else:
                log(f"⚠️ WiFi {ssid} sa nepripojilo ani na 2. pokus (pokracujem bez WiFi): {r2.stderr.strip()[:120]}")
    except Exception as e:
        log(f"⚠️ WiFi pripojenie zlyhalo (pokracujem bez WiFi): {e}")


def execute_test_power(ip, port, unit_id, pct):
    """
    Testovací zápis výkonu priamo na SmartLogger (Modbus TCP) z CM5.
    Register 40428 (dokumentácia) -> wire adresa 40427, gain 10, signed 16-bit.
    Vracia dict s výsledkom (ok, readback, chybová správa).
    """
    try:
        from pymodbus.client import ModbusTcpClient
        client = ModbusTcpClient(host=ip, port=int(port or 502), timeout=3)
        if not client.connect():
            return {'ok': False, 'error': f'Nepodarilo sa pripojiť k {ip}:{port}'}
        try:
            wire_addr = 40428 - 1  # offset -1 pre Huawei
            raw = int(round(float(pct) * 10)) & 0xFFFF  # gain 10, signed
            w = client.write_register(address=wire_addr, value=raw, slave=int(unit_id or 0))
            if w.isError():
                return {'ok': False, 'error': f'SmartLogger odmietol zápis: {w}'}
            time.sleep(0.4)
            r = client.read_holding_registers(address=wire_addr, count=1, slave=int(unit_id or 0))
            if r.isError():
                return {'ok': True, 'readback_pct': None, 'note': 'Zápis prešiel, spätné čítanie zlyhalo'}
            v = r.registers[0]
            if v > 32767: v -= 65536
            return {'ok': True, 'readback_pct': round(v / 10.0, 1)}
        finally:
            client.close()
    except Exception as e:
        return {'ok': False, 'error': str(e)[:200]}


def handle_box_command(obj, ser):
    """
    Spracuje príkaz z PC cez USB kábel (JSON s kľúčom 'cmd').
    Podporované: test_power. Odpovedá JSON-om späť do kábla.
    Vracia True ak bol príkaz rozpoznaný a spracovaný.
    """
    cmd = str(obj.get('cmd', '')).strip().lower()
    if not cmd:
        return False

    if cmd == 'test_power':
        ip = str(obj.get('ip') or '')
        port = int(obj.get('port') or 502)
        unit = int(obj.get('unit_id') or 0)
        pct = float(obj.get('pct') or 0)
        if not (-100 <= pct <= 100):
            resp = {'cmd': 'test_power_result', 'ok': False, 'error': 'Hodnota mimo rozsahu -100 až 100 %'}
        elif not ip:
            resp = {'cmd': 'test_power_result', 'ok': False, 'error': 'Chýba IP adresa SmartLoggera'}
        else:
            log(f"🧪 TEST POWER cez kábel: {pct}% -> {ip}:{port} (unit {unit})")
            res = execute_test_power(ip, port, unit, pct)
            log(f"🧪 TEST POWER výsledok: {res}")
            resp = {'cmd': 'test_power_result'}
            resp.update(res)
        try:
            import json as _json
            ser.write((_json.dumps(resp) + '\n').encode('utf-8'))
        except Exception as e:
            log(f"⚠️ Nemožno poslať test odpoveď: {e}")
        return True

    return False


def extract_json_from_buffer(buf):
    """
    Najde prvy validny JSON objekt v bufferi.
    Vrati (json_dict, zvysok_buffera) alebo (None, buf).
    """
    start = buf.find(JSON_START)
    if start == -1:
        return None, b''

    depth = 0
    in_str = False
    esc = False
    for i in range(start, len(buf)):
        ch = buf[i:i+1]
        if in_str:
            if esc:
                esc = False
            elif ch == b'\\':
                esc = True
            elif ch == b'"':
                in_str = False
            continue
        if ch == b'"':
            in_str = True
        elif ch == b'{':
            depth += 1
        elif ch == b'}':
            depth -= 1
            if depth == 0:
                raw = buf[start:i+1]
                try:
                    obj = json.loads(raw.decode('utf-8', errors='replace'))
                    if isinstance(obj, dict) and len(raw) >= MIN_JSON_LEN:
                        return obj, buf[i+1:]
                except Exception:
                    pass
                # Zly JSON - posun sa za zaciatok a skus dalsi
                return None, buf[start+1:]
    return None, buf[start:]


def serial_reader_loop():
    """Hlavná slučka - číta z USB konzoly: JSON config aj príkazy (test_power atď.).
    Beží VŽDY - aj keď je zariadenie už nastavené (re-setup + príkazy kedykoľvek)."""
    log("Slučka spustená - čakám na config/príkazy cez USB kábel...")

    while True:
        port = find_console_port()
        if not port:
            time.sleep(10)
            continue

        ser = None
        try:
            ser = serial.Serial(port=port, baudrate=115200, timeout=1.0)
            log(f"Čítam z portu {port} (115200 8N1)...")

            buf = b''
            last_data = time.time()

            while True:
                try:
                    chunk = ser.read(256)
                except Exception:
                    break

                if chunk:
                    buf += chunk
                    last_data = time.time()
                    # Spracuj buffer ak sú v ňom kompletné JSON objekty
                    while True:
                        obj, buf = extract_json_from_buffer(buf)
                        if obj is None:
                            break
                        # === PRÍKAZY (test_power a pod.) - spracuj a odpovedz ===
                        if 'cmd' in obj:
                            handle_box_command(obj, ser)
                            continue
                        # Je to config? (má brand_id alebo device_name alebo comm_mode)
                        if any(k in obj for k in ('brand_id', 'device_name', 'comm_mode', 'model_id')):
                            log("📥 Prijatý JSON config z USB!")
                            if apply_config(obj):
                                connect_wifi_if_needed(obj.get('ssid'), obj.get('password'))
                                # CM5 sa SÁM zaregistruje do cloudu (keď dostane internet)
                                register_to_cloud(obj)
                                # Confirm späť do PC
                                try:
                                    ser.write(b'{"status":"config_applied"}\n')
                                except Exception:
                                    pass
                        else:
                            log("📥 JSON bez config kľúčov - ignorujem")
                else:
                    # Žiadne dáta - čistí starý buffer
                    if time.time() - last_data > 5 and buf:
                        buf = b''
                    time.sleep(0.2)

        except Exception as e:
            log(f"Port {port} chyba: {e}")
            time.sleep(5)
        finally:
            if ser:
                try:
                    ser.close()
                except Exception:
                    pass

        time.sleep(3)


def start_serial_config_service():
    """Spusti serial config service ako background thread."""
    t = threading.Thread(target=serial_reader_loop, daemon=True)
    t.start()
    log("✅ Serial Config Service spusteny (background)")
    return t


if __name__ == '__main__':
    print("=" * 50)
    print("  ELVOCONTROLL CM5 - SERIAL CONFIG SERVICE")
    print("  Caka na JSON config z USB kabla...")
    print("=" * 50)
    serial_reader_loop()
