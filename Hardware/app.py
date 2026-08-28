# =============================================================================
# app.py
# Industrial IoT Core Web Server & Gateway (Waveshare CM5 / Raspberry Pi 5)
# Core EMS Server s plnou integráciou lokálneho AI a Dovolenkového režimu
# =============================================================================

from fastapi import FastAPI, HTTPException, Depends, Cookie, Response, Request
from fastapi.responses import HTMLResponse, FileResponse, RedirectResponse, StreamingResponse
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
import uvicorn
import threading
import os
import json
import requests
import socket
import time
import datetime
import serial
import traceback
import subprocess

import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

from database import init_db, get_db_connection, verify_password
from solar_service import SolarBackgroundService
from system_service import SystemService
from Config import DEVICE_DB, PORT, WEB_PORT

CLOUD_SERVER_URL = os.environ.get("CLOUD_SERVER_URL", "https://elvosolar-production.up.railway.app")

app = FastAPI(
    title="Industrial IoT Core Server (Waveshare CM5)",
    description="Aplikácia riadiaceho jadra pre inteligentné spínanie FVE striedačov, batérií a spotrebičov.",
    version="3.2.0"
)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

# --- GLOBÁLNA PAMÄŤ PRE SYSTÉMOVÉ LOGY ---
SYSTEM_LOGS = []

def log_message(msg: str):
    """Zaznamená formátovanú správu do systémových logov v RAM."""
    timestamp = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    formatted = f"[{timestamp}] {msg}"
    print(formatted)
    SYSTEM_LOGS.append(formatted)
    if len(SYSTEM_LOGS) > 50:
        SYSTEM_LOGS.pop(0)

# ============================================================================
# PYDANTIC MODELY PRE SETUP WIZARD & RIADENIE
# ============================================================================
class ClaimDeviceRequest(BaseModel):
    admin_key: str = ""
    admin_password: str = ""
    admin_username: str = "admin"
    comm_mode: str = "LOCAL_MODBUS"
    cloud_username: str = ""
    cloud_password: str = ""
    ssid: str = ""
    password: str = ""
    brand_id: str = ""
    category_id: str = ""
    model_id: str = ""
    slave_id: int = 1
    has_battery: bool = True
    active_cable_cores: int = 2

class InverterSetupRequest(BaseModel):
    brand_id: str = ""
    category_id: str = ""
    model_id: str = ""
    slave_id: int = 1
    name: str = ""
    has_battery: bool = True

class WifiConnectRequest(BaseModel):
    ssid: str
    password: str

class InverterModelRequest(BaseModel):
    model_id: str

class NightSleepRequest(BaseModel):
    night_sleep: int

class InverterPowerRequest(BaseModel):
    power_status: str

# =============================================================================
# PYDANTIC MODELY PRE VALIDÁCIU POŽIADAVIEK (REQUEST SCHEMAS)
# =============================================================================
class RenameDeviceRequest(BaseModel):
    name: str

class TerminalCommandRequest(BaseModel):
    command: str
    token: str

class AiSettingsRequest(BaseModel):
    battery_capacity_kwh: float = 10.0
    battery_min_soc: float = 15.0
    battery_max_soc: float = 95.0
    pv_installed_kwp: float = 5.0

class CustomRuleRequest(BaseModel):
    name: str
    condition_type: str
    condition_params: dict
    action_type: str
    priority: int = 10
    enabled: bool = True

class AiRulesConfigRequest(BaseModel):
    negative_price_protect: bool = True
    negative_price_threshold: float = 0.0
    negative_price_charge_grid: bool = True
    precharge_enabled: bool = True
    precharge_target_soc: float = 80.0
    precharge_price_ratio: float = 0.75
    self_consumption_priority: bool = True
    peak_export_enabled: bool = True
    peak_price_ratio: float = 1.35
    peak_export_min_soc: float = 70.0
    thermal_protection_enabled: bool = True
    max_temp_limit: float = 65.0
    battery_capacity_kwh: float = 10.0
    battery_min_soc: float = 15.0
    battery_max_soc: float = 95.0
    pv_installed_kwp: float = 5.0

class ThirdPartyDeviceRequest(BaseModel):
    name: str
    category: str = "BOILER"
    protocol: str = "SHELLY_RELAY"
    ip_address: str = ""
    channel: int = 1
    power_w: float = 2000.0
    is_enabled: bool = True
    smart_trigger: str = "NEGATIVE_AND_SURPLUS"
    trigger_params: dict = {}

class InstallationTypeRequest(BaseModel):
    installation_type: str = "HOME"
    allow_grid_export: bool = False

class HolidayModeRequest(BaseModel):
    enabled: bool
    until: str
    preheat_hours: int = 6
    target_temp: float = 22.0
    target_boiler: float = 50.0

# =============================================================================
# MIDDLEWARE & PRIPOJENIE DATABÁZY
# =============================================================================
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

try:
    init_db()
except Exception as e:
    print(f"Varovanie: Chyba pri inicializácii DB: {e}")

# Spustenie hlavnej komunikačnej služby striedača
bg_service = SolarBackgroundService()
bg_service.paused = False

def safe_apply_system_state(manual_override=None, active_model_id=None, night_sleep=None):
    """Bezpečne zapíše a zosynchronizuje nastavenia s riadiacou službou."""
    try:
        changes_detected = []

        if manual_override is not None:
            clean_override = str(manual_override).strip().upper()
            if clean_override not in ["AUTO", "ON", "OFF"]:
                clean_override = "AUTO"
            
            old_override = getattr(bg_service, 'manual_override', None)
            if str(old_override).strip().upper() != clean_override:
                changes_detected.append(f"Prevádzkový stav: '{old_override}' ---> '{clean_override}'")
                bg_service.manual_override = clean_override

        if night_sleep is not None:
            try:
                val = int(float(str(night_sleep).strip()))
                clean_sleep = 1 if val >= 1 else 0
            except (ValueError, TypeError):
                clean_sleep = 0
            
            old_sleep = getattr(bg_service, 'night_sleep', None)
            if old_sleep != clean_sleep:
                changes_detected.append(f"Nočný spánok: '{old_sleep}' ---> '{clean_sleep}'")
                bg_service.night_sleep = clean_sleep

        if changes_detected:
            log_message("Zmena parametrov striedača prostredníctvom riadiaceho signálu.")
            if hasattr(bg_service, 'process_control_commands'):
                try:
                    bg_service.process_control_commands()
                except Exception:
                    pass
        return True
    except Exception:
        return False

# =============================================================================
# REPREZENTATÍVNE REST API PRE DOVOLENKOVÝ REŽIM
# =============================================================================
@app.get("/api/system/holiday-mode")
def get_holiday_mode_status():
    try:
        opt = bg_service.ai_service.optimizer
        now = datetime.datetime.now()
        holiday_state = opt.get_holiday_state(now)
        return {
            "status": "success",
            "enabled": opt.holiday_mode_enabled,
            "until": opt.holiday_mode_until,
            "preheat_hours": opt.holiday_mode_preheat_hours,
            "target_temp": opt.holiday_mode_target_temp,
            "target_boiler": opt.holiday_mode_target_boiler,
            "holiday_state": holiday_state,
            "is_active": (holiday_state != 'INACTIVE')
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/api/system/holiday-mode")
def configure_holiday_mode(data: HolidayModeRequest):
    try:
        until_str = data.until.strip()
        if data.enabled and until_str:
            try:
                datetime.datetime.strptime(until_str, "%Y-%m-%d %H:%M:%S")
            except ValueError:
                try:
                    datetime.datetime.strptime(until_str, "%Y-%m-%d")
                except ValueError:
                    raise HTTPException(status_code=400, detail="Neplatný formát príchodu.")

        opt = bg_service.ai_service.optimizer
        opt.holiday_mode_enabled = data.enabled
        opt.holiday_mode_until = until_str
        opt.holiday_mode_preheat_hours = data.preheat_hours
        opt.holiday_mode_target_temp = data.target_temp
        opt.holiday_mode_target_boiler = data.target_boiler

        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("INSERT OR REPLACE INTO system_settings (key, value) VALUES ('holiday_mode_enabled', ?)", ('1' if data.enabled else '0',))
        cursor.execute("INSERT OR REPLACE INTO system_settings (key, value) VALUES ('holiday_mode_until', ?)", (until_str,))
        cursor.execute("INSERT OR REPLACE INTO system_settings (key, value) VALUES ('holiday_mode_preheat_hours', ?)", (str(data.preheat_hours),))
        cursor.execute("INSERT OR REPLACE INTO system_settings (key, value) VALUES ('holiday_mode_target_temp', ?)", (str(data.target_temp),))
        cursor.execute("INSERT OR REPLACE INTO system_settings (key, value) VALUES ('holiday_mode_target_boiler', ?)", (str(data.target_boiler),))
        conn.commit()
        conn.close()

        bg_service.ai_service.recalculate_plan()
        
        status_label = "AKTIVOVANÝ" if data.enabled else "DEAKTIVOVANÝ"
        if data.enabled:
            notif_msg = f"Dovolenka nastavená do {until_str}."
        else:
            notif_msg = "Dovolenkový režim ukončený."

        bg_service.ai_service.create_notification("Dovolenkový režim", notif_msg, "success" if data.enabled else "info", "SYSTEM")
        log_message(f"Dovolenkový režim bol {status_label.lower()}.")
        
        return {"status": "success", "message": "Dovolenkový režim bol úspešne nastavený."}
    except Exception as e:
        return {"status": "error", "message": str(e)}

# =============================================================================
# AUTODETEKCIA A SKENOVANIE STRIEDAČOV (MODBUS SCANNER)
# =============================================================================
@app.get("/api/system/discover")
def api_system_discover(brand: str = "", category: str = "", model: str = ""):
    """Vyhľadá pripojené Modbus stanice na kanáli CH1 (/dev/ttyAMA4).
    Skenuje vsetky slave ID 1-32 s FC3 aj FC4, parity NONE aj EVEN.
    Query params: brand, category, model (optional - fallback na DB alebo defaults)."""
    conn = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        
        # 1. Berieme brand z query parametrov (frontend posle z kroku 1)
        brand_id = brand
        cat_id = category
        model_id = model
        
        # 2. Ak neni v query, skusame z DB
        if not brand_id:
            cursor.execute("SELECT brand_id, category_id, model_id FROM devices LIMIT 1")
            row = cursor.fetchone()
            if row:
                brand_id, cat_id, model_id = row
        
        # 3. Config z DEVICE_DB
        cfg = None
        if brand_id and cat_id and model_id:
            cfg = DEVICE_DB.get(brand_id, {}).get('kategorie', {}).get(cat_id, {}).get('modely', {}).get(model_id)
        
        # 4. Defaults pre Huawei ak nic nevieme
        if not brand_id:
            brand_id = "5"
        
        test_reg = cfg.get('reg_p_ac', 32080) if cfg else 32080
        reg_soc = cfg.get('reg_soc', 37760) if cfg else 37760
        baud_rate = cfg.get('baud', 9600) if cfg else 9600
        conn.close()
        conn = None
        
        discovered_slaves = []
        bg_service.paused = True
        time.sleep(0.1)
        
        brand_config = DEVICE_DB.get(brand_id, {})
        target_port = brand_config.get('port', '/dev/ttyAMA4')
        
        if not os.path.exists(target_port):
            fallback_ports = ['/dev/ttyAMA4', '/dev/serial0', '/dev/ttyAMA0', '/dev/ttyUSB0', '/dev/ttyACM0']
            for fp in fallback_ports:
                if os.path.exists(fp):
                    target_port = fp
                    bg_service.log_to_terminal(f"Autodetekcia: Pouzivam fallback port: {fp}")
                    break
        
        winning_parity = "N"
        parities_to_test = [serial.PARITY_NONE, serial.PARITY_EVEN]
        
        if not os.path.exists(target_port):
            bg_service.paused = False
            return {"status": "error", "message": f"Port {target_port} nie je dostupny.", "discovered_count": 0, "slaves": []}
        
        bg_service.log_to_terminal(f"Autodetekcia: Skenujem port {target_port} (baud={baud_rate})...")
        
        with bg_service.lock:
            if hasattr(bg_service, 'ser') and bg_service.ser and bg_service.ser.is_open:
                try: bg_service.ser.close()
                except Exception: pass
            
            for parity in parities_to_test:
                try:
                    ser = serial.Serial(port=target_port, baudrate=baud_rate, parity=parity, timeout=0.3)
                    parity_label = "EVEN" if parity == serial.PARITY_EVEN else "NONE"
                    bg_service.log_to_terminal(f"Autodetekcia: Testujem parity={parity_label}...")
                    
                    parity_found = []
                    for slave_id in range(1, 33):
                        # Skusame FC3 (Holding Registers) aj FC4 (Input Registers)
                        found = bg_service.ping_slave_fc(ser, slave_id, test_reg, 3)
                        if not found:
                            found = bg_service.ping_slave_fc(ser, slave_id, test_reg, 4)
                        if not found and reg_soc:
                            found = bg_service.ping_slave_fc(ser, slave_id, reg_soc, 3)
                            if not found:
                                found = bg_service.ping_slave_fc(ser, slave_id, reg_soc, 4)
                        if found:
                            parity_found.append(slave_id)
                            discovered_slaves.append(slave_id)
                            bg_service.log_to_terminal(f"Autodetekcia: Najdeny slave ID {slave_id} (parity={parity_label})")
                    
                    ser.close()
                    if parity_found:
                        winning_parity = "E" if parity == serial.PARITY_EVEN else "N"
                        break
                except Exception as e:
                    bg_service.log_to_terminal(f"Autodetekcia: Chyba: {e}")
            
        bg_service.paused = False
        
        if not discovered_slaves:
            bg_service.log_to_terminal("Autodetekcia: Zbernica neodpoveda.")
            return {"status": "error", "message": "Zbernica neodpovedá. Skontrolujte zapojenie A/B a baud rate.", "discovered_count": 0, "slaves": []}
        
        bg_service.log_to_terminal(f"Autodetekcia: Najdene {len(discovered_slaves)} zariadeni: {discovered_slaves}")
        
        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("DELETE FROM devices")
        cursor.execute("DELETE FROM system_settings WHERE key = 'rs485_active_port'")
        cursor.execute("INSERT INTO system_settings (key, value) VALUES ('rs485_active_port', ?)", (target_port,))
        cursor.execute("DELETE FROM system_settings WHERE key = 'rs485_parity'")
        cursor.execute("INSERT INTO system_settings (key, value) VALUES ('rs485_parity', ?)", (winning_parity,))
        
        mac_suffix = SystemService.get_mac_suffix()
        for sid in discovered_slaves:
            local_sn = f"SN-CM5-{mac_suffix}-{sid}"
            name = f"Striedač {DEVICE_DB[brand_id]['znacka']} (ID {sid})"
            cursor.execute(
                "INSERT INTO devices (serial_number, name, password, brand_id, category_id, model_id, slave_id) VALUES (?, ?, 'pass', ?, ?, ?, ?)",
                (local_sn, name, brand_id, cat_id, model_id, sid)
            )
        
        cursor.execute("DELETE FROM system_settings WHERE key = 'is_claimed'")
        cursor.execute("INSERT INTO system_settings (key, value) VALUES ('is_claimed', '1')")
        conn.commit()
        conn.close()
        
        return {
            "status": "success", 
            "discovered_count": len(discovered_slaves), 
            "slaves": discovered_slaves,
            "port": target_port,
            "parity": "EVEN" if winning_parity == "E" else "NONE"
        }
    except Exception as e:
        bg_service.paused = False
        return {"status": "error", "message": f"Chyba vyhľadávania: {str(e)}", "discovered_count": 0, "slaves": []}


@app.get("/api/system/is-claimed")
def api_is_claimed():
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT value FROM system_settings WHERE key = 'is_claimed'")
    row = cursor.fetchone()
    conn.close()
    return {"is_claimed": row and row[0] == '1'}

@app.get("/api/devices/list")
def get_devices_db_list():
    return DEVICE_DB

@app.get("/api/system/wifi/scan")
def api_scan_wifi():
    return SystemService.scan_wifi_networks()

@app.post("/api/system/wifi/connect")
def api_connect_to_wifi(data: WifiConnectRequest):
    return SystemService.connect_wifi(data.ssid.strip(), data.password)

@app.get("/api/system/status")
def api_system_status():
    try:
        internet = SystemService.test_internet_connection()
        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT COUNT(*) FROM devices")
        has_devices = cursor.fetchone()[0] > 0
        conn.close()
        return {
            "status": "success",
            "is_claimed": api_is_claimed()["is_claimed"],
            "internet": internet,
            "modbus": "CONNECTED" if len(bg_service.live_data) > 0 or has_devices else "DISCONNECTED"
        }
    except Exception:
        return {"status": "success", "internet": False, "modbus": "DISCONNECTED"}

@app.post("/api/admin/claim")
def claim_device(data: ClaimDeviceRequest):
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT value FROM system_settings WHERE key = 'admin_key'")
    row = cursor.fetchone()
    saved_key = row[0] if row else ""
    
    if saved_key and data.admin_key != saved_key:
        conn.close()
        raise HTTPException(status_code=401, detail="Nesprávny párovací kľúč.")

    from database import hash_password
    secured_password = hash_password(data.admin_password)
    
    cursor.execute("DELETE FROM users WHERE role = 'admin'")
    cursor.execute("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'admin')", (data.admin_username, data.admin_username, secured_password))
    cursor.execute("DELETE FROM system_settings WHERE key = 'comm_mode'")
    cursor.execute("INSERT INTO system_settings (key, value) VALUES ('comm_mode', ?)", (data.comm_mode,))
    conn.commit()
    conn.close()
    return {"status": "success"}

@app.post("/api/user/claim-device")
def save_claimed_device(data: InverterSetupRequest):
    conn = get_db_connection()
    cursor = conn.cursor()
    mac_suffix = SystemService.get_mac_suffix()
    local_sn = f"SN-CM5-{mac_suffix}"
    custom_name = data.name.strip() if data.name else f"Striedač ID {data.slave_id}"
    
    cursor.execute(
        "INSERT OR REPLACE INTO devices (serial_number, name, password, brand_id, category_id, model_id, slave_id) VALUES (?, ?, 'pass', ?, ?, ?, ?)",
        (local_sn, custom_name, data.brand_id, data.category_id, data.model_id, data.slave_id)
    )
    cursor.execute("DELETE FROM system_settings WHERE key = 'is_claimed'")
    cursor.execute("INSERT INTO system_settings (key, value) VALUES ('is_claimed', '1')")
    conn.commit()
    conn.close()
    return {"status": "success"}

# =============================================================================
# HLAVNÉ MONITOROVACIE REST API (DASHBOARD & TELEMETRY)
# =============================================================================
@app.get("/api/dashboard/{slave_id}")
def get_dashboard_data(slave_id: int):
    try:
        total_live_power = sum(item["power_ac"] for item in bg_service.live_data.values())
        soc_list = [item["battery_soc"] for item in bg_service.live_data.values() if item["battery_soc"] > 0]
        avg_soc = sum(soc_list) / len(soc_list) if soc_list else 0.0
        temp_list = [item["temp"] for item in bg_service.live_data.values() if item.get("temp", 0) > 0]
        avg_temp = sum(temp_list) / len(temp_list) if temp_list else 30.0
        
        devices_payload = []
        for sid, dat in bg_service.live_data.items():
            devices_payload.append({
                "name": f"Striedač RS485 (ID {sid})",
                "slave_id": sid,
                "live": {
                    "power_ac": dat["power_ac"],
                    "battery_soc": dat["battery_soc"],
                    "inverter_temp": dat["temp"],
                    "grid_freq": dat["freq"],
                    "pv_volt": 380.0 if dat["power_ac"] > 0 else 0.0,
                    "pv_curr": 4.5 if dat["power_ac"] > 0 else 0.0,
                    "status_msg": dat["status_msg"]
                }
            })

        ai_sched = bg_service.ai_service.okte.get_schedule_for_today_and_tomorrow()
        today_okte_sched = ai_sched.get("today_hourly", [bg_service.last_live_okte_price] * 24)
        tomorrow_okte_sched = ai_sched.get("tomorrow_hourly", [])
        live_price = ai_sched.get("current_price_eur_mwh", bg_service.last_live_okte_price)
        
        now = datetime.datetime.now()
        day_details = bg_service.ai_service.calendar.get_full_day_details(now)
        opt = bg_service.ai_service.optimizer
        holiday_state = opt.get_holiday_state(now)
        
        return {
            "static_ip": "DHCP",
            "total_saved_eur": total_live_power * 0.000005,
            "total_kwh": total_live_power * 0.0001,
            "has_battery": len(soc_list) > 0,
            "avg_live_soc": avg_soc,
            "night_sleep": bg_service.night_sleep,
            "total_live_power": total_live_power,
            "live_okte_price": live_price,
            "active_model": bg_service.active_model_id,
            "devices": devices_payload,
            "okte_schedule_today": today_okte_sched,
            "okte_schedule_tomorrow": tomorrow_okte_sched,
            "holiday_mode": {
                "is_active": (holiday_state != 'INACTIVE'),
                "holiday_state": holiday_state,
                "enabled": opt.holiday_mode_enabled,
                "until": opt.holiday_mode_until,
                "preheat_hours": opt.holiday_mode_preheat_hours,
                "target_temp": opt.holiday_mode_target_temp,
                "target_boiler": opt.holiday_mode_target_boiler
            },
            "ai_info": {
                "day_type": day_details["day_type"],
                "type_label_sk": day_details["type_label_sk"],
                "is_holiday": day_details["is_holiday"],
                "learning_stage": bg_service.ai_service.learner.learning_stage,
                "confidence_percent": bg_service.ai_service.learner.confidence_percent,
                "days_learned": bg_service.ai_service.learner.days_learned,
                "last_decision": bg_service.ai_service.last_decision
            }
        }
    except Exception as e:
        return {"status": "error", "message": f"Chyba pri generovaní telemetrie: {str(e)}"}

# =============================================================================
# INTEGRÁCIA MANUÁLNYCH PREPÍNAČOV ZARIADENÍ (REŽIM, SPÁNOK)
# =============================================================================
@app.post("/api/system/inverter-power")
def set_manual_power(data: InverterPowerRequest):
    try:
        success = safe_apply_system_state(manual_override=data.power_status)
        if success:
            return {"status": "success", "message": f"Režim meniča zmenený na {bg_service.manual_override}."}
        raise HTTPException(status_code=500, detail="Zlyhalo uloženie režimu.")
    except Exception as e:
        return {"status": "error", "message": str(e)}

@app.post("/api/system/model")
def change_active_model(data: InverterModelRequest):
    try:
        bg_service.active_model_id = str(data.model_id).strip().upper()
        bg_service.process_control_commands()
        return {"status": "success", "message": f"Aktívny model zmenený na {bg_service.active_model_id}."}
    except Exception as e:
        return {"status": "error", "message": str(e)}

@app.post("/api/system/night-sleep")
def toggle_night_sleep(data: NightSleepRequest):
    try:
        success = safe_apply_system_state(night_sleep=data.night_sleep)
        if success:
            return {"status": "success", "message": f"Nočný spánok nastavený na {bg_service.night_sleep}."}
        raise HTTPException(status_code=500, detail="Zápis zlyhal.")
    except Exception as e:
        return {"status": "error", "message": str(e)}

# =============================================================================
# REST API PRE INTEGRÁCIU SPOTREBIČOV 3. STRÁN & VLASTNÝCH PRAVIDIEL
# =============================================================================
@app.get("/api/devices/third-party")
def get_third_party_devices_api():
    opt = bg_service.ai_service.optimizer
    opt._load_config_and_custom_rules()
    return {"status": "success", "devices": opt.third_party_devices}

@app.post("/api/devices/third-party")
def save_third_party_device_api(data: ThirdPartyDeviceRequest):
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        params_str = json.dumps(data.trigger_params)
        cursor.execute("""
            INSERT INTO third_party_devices 
            (name, category, protocol, ip_address, channel, power_w, is_enabled, smart_trigger, trigger_params)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, (data.name.strip(), data.category, data.protocol, data.ip_address.strip(), data.channel, data.power_w, 1 if data.is_enabled else 0, data.smart_trigger, params_str))
        conn.commit()
        dev_id = cursor.lastrowid
        conn.close()

        bg_service.ai_service.optimizer._load_config_and_custom_rules()
        bg_service.ai_service.create_notification(f"Pridaný spotrebič {data.name}", f"Zariadenie '{data.name}' je pripojené.", "success", "DEVICE_3RD")
        return {"status": "success", "device_id": dev_id}
    except Exception as e:
        return {"status": "error", "message": str(e)}

@app.delete("/api/devices/third-party/{dev_id}")
def delete_third_party_device_api(dev_id: int):
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("DELETE FROM third_party_devices WHERE id = ?", (dev_id,))
        conn.commit()
        conn.close()
        bg_service.ai_service.optimizer._load_config_and_custom_rules()
        return {"status": "success"}
    except Exception as e:
        return {"status": "error", "message": str(e)}

@app.post("/api/devices/third-party/{dev_id}/toggle")
def toggle_third_party_device_api(dev_id: int):
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT is_enabled FROM third_party_devices WHERE id = ?", (dev_id,))
        row = cursor.fetchone()
        if not row: return {"status": "error", "message": "Zariadenie neexistuje."}
        
        new_state = 0 if row['is_enabled'] == 1 else 1
        cursor.execute("UPDATE third_party_devices SET is_enabled = ? WHERE id = ?", (new_state, dev_id))
        conn.commit()
        conn.close()
        bg_service.ai_service.optimizer._load_config_and_custom_rules()
        return {"status": "success", "is_enabled": bool(new_state)}
    except Exception as e:
        return {"status": "error", "message": str(e)}

# =============================================================================
# REST API PRE NOTIFIKÁCIE & HISTÓRIU CHÝB
# =============================================================================
@app.get("/api/notifications")
def get_notifications_api():
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT id, timestamp, title, message, type, tag, is_read FROM notifications ORDER BY id DESC LIMIT 30")
        rows = cursor.fetchall()
        conn.close()
        notifs = [{"id": r['id'], "timestamp": r['timestamp'], "title": r['title'], "message": r['message'], "type": r['type'], "tag": r['tag'], "is_read": bool(r['is_read'])} for r in rows]
        return {"status": "success", "notifications": notifs}
    except Exception as e:
        return {"status": "error", "message": str(e)}

@app.post("/api/notifications/{notif_id}/read")
def mark_notification_read_api(notif_id: int):
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("UPDATE notifications SET is_read = 1 WHERE id = ?", (notif_id,))
        conn.commit()
        conn.close()
        return {"status": "success"}
    except Exception as e:
        return {"status": "error", "message": str(e)}

@app.post("/api/notifications/clear")
def clear_notifications_api():
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("DELETE FROM notifications")
        conn.commit()
        conn.close()
        return {"status": "success"}
    except Exception as e:
        return {"status": "error", "message": str(e)}

# =============================================================================
# SECURE SHELL EXECUTION (MODBUS DIAGNOSTIKA & TERMINÁL)
# =============================================================================
@app.post("/api/system/terminal-execute")
def execute_terminal_command(data: TerminalCommandRequest):
    if data.token != "cm5_master_secure_9921":
        return {"status": "error", "message": "Prístup zamietnutý."}
        
    cmd = data.command.strip()
    log_message(f"[TERMINAL COMMAND]: {cmd}")
    
    if cmd == "restart_services":
        bg_service.paused = True
        time.sleep(0.5)
        if bg_service.ser and bg_service.ser.is_open:
            try: bg_service.ser.close()
            except Exception: pass
        bg_service.ser = None
        bg_service.paused = False
        return {"status": "success", "message": "Rozhrania reštartované."}
        
    elif cmd == "reboot_pi":
        threading.Thread(target=lambda: (time.sleep(1), os.system("sudo reboot"))).start()
        return {"status": "success", "message": "Reštartujem CM5..."}
        
    elif cmd == "clear_logs":
        SYSTEM_LOGS.clear()
        bg_service.terminal_logs.clear()
        return {"status": "success", "message": "Logy vymazané."}
        
    else:
        try:
            result = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=8)
            output = result.stdout if result.stdout else result.stderr
            return {"status": "success", "message": output.strip()}
        except Exception as e:
            return {"status": "error", "message": f"Chyba shellu: {str(e)}"}

# =============================================================================
# OBSLUHA VSTUPNÝCH STRÁNOK (PORTAL ENDPOINTS)
# =============================================================================
@app.get("/", response_class=HTMLResponse)
def serve_portal():
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT value FROM system_settings WHERE key = 'is_claimed'")
    row = cursor.fetchone()
    claimed = row and row[0] == '1'
    conn.close()

    if not claimed:
        setup_path = os.path.join(BASE_DIR, "templates", "setup.html")
        if os.path.exists(setup_path):
            with open(setup_path, "r", encoding="utf-8") as f:
                response = HTMLResponse(content=f.read())
                response.headers["Cache-Control"] = "no-store, no-cache, must-revalidate, max-age=0"
                return response
        return "<h3>Chyba: setup.html chýba v šablónach.</h3>"
    else:
        return RedirectResponse(url=CLOUD_SERVER_URL + "/login", status_code=303)

@app.get("/setup", response_class=HTMLResponse)
def serve_setup():
    setup_path = os.path.join(BASE_DIR, "templates", "setup.html")
    if os.path.exists(setup_path):
        with open(setup_path, "r", encoding="utf-8") as f:
            response = HTMLResponse(content=f.read())
            response.headers["Cache-Control"] = "no-store, no-cache, must-revalidate, max-age=0"
            return response
    return "<h3>Chyba: setup.html chýba v šablónach.</h3>"

@app.get("/api/system/logs")
def get_system_logs_api():
    combined = []
    for log in SYSTEM_LOGS: combined.append(log)
    for log in bg_service.terminal_logs: combined.append(log)
    if len(combined) > 45: combined = combined[-45:]
    return {"logs": combined}

@app.get("/api/system/reset")
def api_reset_system():
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("DELETE FROM system_settings WHERE key = 'is_claimed'")
    cursor.execute("INSERT INTO system_settings (key, value) VALUES ('is_claimed', '0')")
    cursor.execute("DELETE FROM devices")
    cursor.execute("DELETE FROM telemetry")
    cursor.execute("DELETE FROM users WHERE role = 'admin'")
    conn.commit()
    conn.close()
    
    response = RedirectResponse(url="/", status_code=303)
    response.headers["Cache-Control"] = "no-store, no-cache, must-revalidate, max-age=0"
    return response


# =============================================================================
# CLOUD SYNC BACKGROUND THREAD
# =============================================================================
import socket as _socket

def _get_local_ip():
    try:
        s = _socket.socket(_socket.AF_INET, _socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        ip = s.getsockname()[0]
        s.close()
        return ip
    except:
        return "unknown"

def _get_serial_number():
    return "CM5-DEFAULT"

def cloud_sync_loop():
    serial_num = _get_serial_number()
    while True:
        time.sleep(5)
        try:
            local_ip = _get_local_ip()
            try:
                requests.post(CLOUD_SERVER_URL + "/api/report-ip",
                    json={"ip": local_ip, "serial": serial_num}, timeout=5, verify=False)
            except: pass

            resp = requests.post(CLOUD_SERVER_URL + "/api/cm5/poll",
                json={"serial": serial_num}, timeout=30, verify=False)
            if resp.status_code != 200:
                continue
            data = resp.json()
            if data.get("status") != "success":
                continue

            config = data.get("config", {})
            command_id = data.get("command_id", 0)
            action = config.get("action", "")
            log_message(f"[CLOUD SYNC] Prikaz: {action} (cmd_id={command_id})")
            result = {"status": "error", "message": "Neznama akcia"}

            if action == "claim":
                conn = get_db_connection()
                cursor = conn.cursor()
                from database import hash_password
                sec_pass = hash_password(config.get("admin_password", ""))
                cursor.execute("DELETE FROM users WHERE role = 'admin'")
                cursor.execute("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'admin')",
                    (config.get("admin_username", "admin"), config.get("admin_username", "admin"), sec_pass))
                cursor.execute("DELETE FROM system_settings WHERE key = 'comm_mode'")
                cursor.execute("INSERT INTO system_settings (key, value) VALUES ('comm_mode', ?)",
                    (config.get("comm_mode", "LOCAL_MODBUS"),))
                cursor.execute("DELETE FROM system_settings WHERE key = 'is_claimed'")
                cursor.execute("INSERT INTO system_settings (key, value) VALUES ('is_claimed', '1')")
                conn.commit()
                conn.close()
                result = {"status": "success", "message": "Claim aplikovany"}

            elif action == "claim_device":
                conn = get_db_connection()
                cursor = conn.cursor()
                mac_suffix = SystemService.get_mac_suffix()
                local_sn = f"SN-CM5-{mac_suffix}"
                brand_id = config.get("brand_id", "")
                cat_id = config.get("category_id", "")
                model_id = config.get("model_id", "")
                slave_id = config.get("slave_id", 1)
                name = config.get("name", "") or f"Striedac ID {slave_id}"
                cursor.execute(
                    "INSERT OR REPLACE INTO devices (serial_number, name, password, brand_id, category_id, model_id, slave_id) VALUES (?, ?, 'pass', ?, ?, ?, ?)",
                    (local_sn, name, brand_id, cat_id, model_id, slave_id))
                cursor.execute("DELETE FROM system_settings WHERE key = 'is_claimed'")
                cursor.execute("INSERT INTO system_settings (key, value) VALUES ('is_claimed', '1')")
                conn.commit()
                conn.close()
                result = {"status": "success", "message": f"Zariadenie ulozene (slave_id={slave_id})"}

            elif action == "discover":
                brand_id = config.get("brand_id", "5")
                cat_id = config.get("category_id", "1")
                model_id = config.get("model_id", "1")
                cfg = DEVICE_DB.get(brand_id, {}).get('kategorie', {}).get(cat_id, {}).get('modely', {}).get(model_id)
                test_reg = cfg.get('reg_p_ac', 32080) if cfg else 32080
                reg_soc = cfg.get('reg_soc', 37760) if cfg else 37760
                baud_rate = cfg.get('baud', 9600) if cfg else 9600
                discovered_slaves = []
                bg_service.paused = True
                time.sleep(0.1)
                brand_config = DEVICE_DB.get(brand_id, {})
                target_port = brand_config.get('port', '/dev/ttyAMA4')
                if not os.path.exists(target_port):
                    for fp in ['/dev/ttyAMA4', '/dev/serial0', '/dev/ttyAMA0', '/dev/ttyUSB0']:
                        if os.path.exists(fp):
                            target_port = fp
                            break
                if os.path.exists(target_port):
                    with bg_service.lock:
                        if bg_service.ser and bg_service.ser.is_open:
                            try: bg_service.ser.close()
                            except: pass
                        for parity in [serial.PARITY_NONE, serial.PARITY_EVEN]:
                            par_name = 'EVEN' if parity == serial.PARITY_EVEN else 'NONE'
                            log_message(f'[DISCOVER] Testujem port={target_port} baud={baud_rate} parity={par_name}')
                            try:
                                ser = serial.Serial(port=target_port, baudrate=baud_rate, parity=parity, timeout=0.3)
                                for sid in range(1, 33):
                                    found = bg_service.ping_slave_fc(ser, sid, test_reg, 3)
                                    if not found:
                                        found = bg_service.ping_slave_fc(ser, sid, test_reg, 4)
                                    if not found and reg_soc:
                                        found = bg_service.ping_slave_fc(ser, sid, reg_soc, 3)
                                        if not found:
                                            found = bg_service.ping_slave_fc(ser, sid, reg_soc, 4)
                                    if found:
                                        discovered_slaves.append(sid)
                                        log_message(f'[DISCOVER] ✅ NAYDENE slave ID={sid} parity={par_name}')
                                ser.close()
                                log_message(f'[DISCOVER] Scan dokonceny parity={par_name} nasiel={discovered_slaves}')
                                if discovered_slaves:
                                    break
                            except Exception as e:
                                log_message(f'[DISCOVER] CHYBA parity={par_name}: {e}')
                bg_service.paused = False
                winning_parity = "N"
                if discovered_slaves:
                    conn = get_db_connection()
                    cursor = conn.cursor()
                    cursor.execute("DELETE FROM system_settings WHERE key = 'rs485_active_port'")
                    cursor.execute("INSERT INTO system_settings (key, value) VALUES ('rs485_active_port', ?)", (target_port,))
                    cursor.execute("DELETE FROM system_settings WHERE key = 'rs485_parity'")
                    cursor.execute("INSERT INTO system_settings (key, value) VALUES ('rs485_parity', ?)", (winning_parity,))
                    mac_suffix = SystemService.get_mac_suffix()
                    for sid in discovered_slaves:
                        local_sn = f"SN-CM5-{mac_suffix}-{sid}"
                        name = f"Striedac {DEVICE_DB.get(brand_id, {}).get('znacka', 'Unknown')} (ID {sid})"
                        cursor.execute(
                            "INSERT OR REPLACE INTO devices (serial_number, name, password, brand_id, category_id, model_id, slave_id) VALUES (?, ?, 'pass', ?, ?, ?, ?)",
                            (local_sn, name, brand_id, cat_id, model_id, sid))
                    cursor.execute("DELETE FROM system_settings WHERE key = 'is_claimed'")
                    cursor.execute("INSERT INTO system_settings (key, value) VALUES ('is_claimed', '1')")
                    conn.commit()
                    conn.close()
                result = {
                    "status": "success" if discovered_slaves else "error",
                    "slaves": discovered_slaves,
                    "discovered_count": len(discovered_slaves),
                    "port": target_port,
                    "message": f"Najdene: {discovered_slaves}" if discovered_slaves else "Zbernica neodpoveda"
                }

            elif action == "wifi_connect":
                ssid = config.get("ssid", "")
                password = config.get("password", "")
                if ssid and ssid != "ACTIVE_CURRENT_WIFI":
                    result = SystemService.connect_wifi(ssid, password)
                else:
                    result = {"status": "success", "message": "WiFi preskocene"}

            try:
                requests.post(CLOUD_SERVER_URL + "/api/cm5/result",
                    json={"serial": serial_num, "command_id": command_id, "result": result},
                    timeout=5, verify=False)
                log_message(f"[CLOUD SYNC] Vysledok odoslany: {result.get('status')}")
            except Exception as e:
                log_message(f"[CLOUD SYNC] Chyba pri odosielani vysledku: {e}")

        except requests.exceptions.ConnectionError:
            pass
        except Exception as e:
            log_message(f"[CLOUD SYNC] Chyba: {e}")

cloud_thread = threading.Thread(target=cloud_sync_loop, daemon=True)
cloud_thread.start()
log_message("[CLOUD SYNC] Background thread spusteny")


if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=WEB_PORT)