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
            ('has_battery', 'INTEGER DEFAULT 1'),
            ('connection_type', "VARCHAR(20) DEFAULT 'modbus_rtu'"),
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
                "UPDATE devices SET name=?, brand_id=?, category_id=?, model_id=?, slave_id=?, has_battery=? WHERE id=?",
                (device_name, brand_id, category_id, model_id, slave_id, has_battery, row[0])
            )
        else:
            serial_number = f"CM5-{int(time.time())}"
            cursor.execute(
                "INSERT INTO devices (serial_number, name, password, brand_id, category_id, model_id, slave_id, has_battery) VALUES (?,?, 'pass', ?,?,?,?,?)",
                (serial_number, device_name, brand_id, category_id, model_id, slave_id, has_battery)
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

    payload = {
        'brand_id': str(config.get('brand_id', 'huawei')),
        'category_id': str(config.get('category_id', '')),
        'model_id': str(config.get('model_id', '')),
        'slave_id': int(config.get('slave_id', 1) or 1),
        'has_battery': bool(config.get('has_battery', True)),
        'name': str(config.get('device_name') or config.get('name') or 'Moje zariadenie'),
        'comm_mode': str(config.get('comm_mode') or 'LOCAL_MODBUS'),
        'serial': f"CM5-{int(time.time())}"
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
        log(f"📡 Pripajam WiFi: {ssid}")
        subprocess.run(
            ['nmcli', 'dev', 'wifi', 'connect', ssid, 'password', password],
            timeout=30, capture_output=True
        )
        log("✅ WiFi pripojenie spustene")
    except Exception as e:
        log(f"⚠️ WiFi pripojenie zlyhalo (pokracujem bez WiFi): {e}")


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
    """Hlavna slucka - cita z USB konzoly a caka na JSON config."""
    log("Slucka spustena - cakam na config cez USB kabel...")

    while True:
        # Ak uz je zariadenie nastavene, len obcas skontroluj
        if is_already_configured():
            time.sleep(30)
            continue

        port = find_console_port()
        if not port:
            time.sleep(10)
            continue

        ser = None
        try:
            ser = serial.Serial(port=port, baudrate=115200, timeout=1.0)
            log(f"Citam z portu {port} (115200 8N1)...")

            buf = b''
            last_data = time.time()

            while True:
                if is_already_configured():
                    log("Zariadenie uz je nastavene - koncim citanie.")
                    break

                try:
                    chunk = ser.read(256)
                except Exception:
                    break

                if chunk:
                    buf += chunk
                    last_data = time.time()
                    # Spracuj buffer ak su v nom kompletné JSON objekty
                    while True:
                        obj, buf = extract_json_from_buffer(buf)
                        if obj is None:
                            break
                        # Je to config? (ma brand_id alebo device_name alebo comm_mode)
                        if any(k in obj for k in ('brand_id', 'device_name', 'comm_mode', 'model_id')):
                            log("📥 Prijaty JSON config z USB!")
                            if apply_config(obj):
                                connect_wifi_if_needed(obj.get('ssid'), obj.get('password'))
                                # CM5 sa SAM zaregistruje do cloudu (ked dostane internet)
                                register_to_cloud(obj)
                                # Confirm spat do PC
                                try:
                                    ser.write(b'{"status":"config_applied"}\n')
                                except Exception:
                                    pass
                                buf = b''
                                break
                        else:
                            log("📥 JSON bez config klucov - ignorujem")
                else:
                    # Ziadne data - cisti stary buffer
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
