# Hardware/smart_meter_service.py
# Smart Meter Service - Čítanie reálnej spotreby domácnosti
# Podpora: Modbus RTU (RS485), S0 pulse input, Cloud API fallback

import time
import threading
import json
from datetime import datetime
from database import get_db_connection, db_execute


class SmartMeterService:
    """
    Číta reálnu spotrebu domácnosti z smart meradla.
    
    Podporované režimy:
    1. MODBUS_RTU - Čítanie cez RS485 zberniciu (Landis+Gyr, Kaifa, Iskraemeco, atď.)
    2. S0_PULSE - Počítanie impulzov zo S0 výstupu meradla (GPIO)
    3. CLOUD_API - Čítanie cez cloud API (Shelly EM, Tasmota, atď.)
    4. NONE - Bez meradla (odhad zo spotrebovacieho profilu)
    
    Merané hodnoty:
    - house_consumption_w: Okamžitá spotreba domácnosti [W]
    - house_consumption_kwh: Denná spotreba [kWh]
    - grid_import_w: Odber zo siete [W]
    - grid_export_w: Vývoz do siete [W]
    - fve_surplus_w: Prebytok FVE [W] (výroba - spotreba)
    """
    
    # Režimy riadenia FVE podľa spotreby
    MODE_UNLIMITED = "UNLIMITED"          # Bez obmedzenia - maximálny výkon
    MODE_SELF_CONSUMPTION = "SELF_CONSUMPTION"  # Prispôsobiť výrobu spotrebe domu
    MODE_SMART = "SMART"                  # Kombinácia: spotreba + cena + predpoveď
    
    def __init__(self, bg_service=None):
        self.bg_service = bg_service
        self.lock = threading.RLock()
        self.running = True
        
        # Aktuálne namerané hodnoty
        self.house_consumption_w = 0.0
        self.house_consumption_kwh_today = 0.0
        self.grid_import_w = 0.0
        self.grid_export_w = 0.0
        self.fve_surplus_w = 0.0
        self.fve_production_w = 0.0
        
        # Konfigurácia
        self.meter_mode = "NONE"          # MODBUS_RTU, S0_PULSE, CLOUD_API, NONE
        self.meter_slave_id = 1           # Modbus Slave ID meradla
        self.meter_baudrate = 9600
        self.meter_parity = "N"           # N, E, O
        
        # Registre pre Modbus RTU (nastaviteľné pre rôzne meradle)
        self.reg_import_wh = None         # Register pre odber [Wh/kWh]
        self.reg_export_wh = None         # Register pre vývoz [Wh/kWh]
        self.reg_import_w = None          # Register pre okamžitý odber [W]
        self.reg_export_w = None          # Register pre okamžitý vývoz [W]
        self.reg_consumption_w = None     # Register pre celkovú spotrebu [W]
        
        # S0 pulse counting
        self.s0_pin = None                # GPIO pin pre S0 vstup
        self.s0_impulses_per_kwh = 1000   # Impulzov na 1 kWh (štandard: 800-3200)
        self.s0_pulse_count = 0
        self.s0_last_count = 0
        self.s0_last_time = time.time()
        
        # Cloud API pre tretie strany (Shelly EM, Tasmota, atď.)
        self.cloud_api_url = ""
        self.cloud_api_token = ""
        
        # Riadiaci režim
        self.control_mode = self.MODE_SMART  # UNLIMITED, SELF_CONSUMPTION, SMART
        
        # Denný počítadlo
        self._last_date = ""
        self._wh_today_import = 0.0
        self._wh_today_export = 0.0
        
        # Načítanie konfigurácie z DB
        self._load_config()
        
        # Štatistiky pre učenie
        self.consumption_history = []     # Posledných 1440 vzoriek (24h @ minútovo)
        self.avg_consumption_w = 0.0
        self.peak_consumption_w = 0.0
        self.min_consumption_w = 999999.0
        
    def _load_config(self):
        """Načíta konfiguráciu smart meradla z databázy."""
        try:
            rows = db_execute("SELECT key, value FROM system_settings WHERE key LIKE 'meter_%'")
            for r in rows:
                k, v = r['key'], r['value']
                if k == 'meter_mode': self.meter_mode = v
                elif k == 'meter_slave_id': self.meter_slave_id = int(v)
                elif k == 'meter_baudrate': self.meter_baudrate = int(v)
                elif k == 'meter_parity': self.meter_parity = v
                elif k == 'meter_reg_import_wh': self.reg_import_wh = int(v) if v else None
                elif k == 'meter_reg_export_wh': self.reg_export_wh = int(v) if v else None
                elif k == 'meter_reg_import_w': self.reg_import_w = int(v) if v else None
                elif k == 'meter_reg_export_w': self.reg_export_w = int(v) if v else None
                elif k == 'meter_reg_consumption_w': self.reg_consumption_w = int(v) if v else None
                elif k == 'meter_s0_pin': self.s0_pin = int(v) if v else None
                elif k == 'meter_s0_impulses_per_kwh': self.s0_impulses_per_kwh = int(v)
                elif k == 'meter_cloud_api_url': self.cloud_api_url = v
                elif k == 'meter_cloud_api_token': self.cloud_api_token = v
                elif k == 'meter_control_mode': self.control_mode = v
        except Exception as e:
            print(f"[SMART METER] Chyba načítania konfigurácie: {e}")
    
    def save_config(self, config: dict):
        """Uloží konfiguráciu smart meradla do databázy."""
        try:
            for key, value in config.items():
                if key.startswith('meter_'):
                    db_execute(
                        "INSERT OR REPLACE INTO system_settings (key, value) VALUES (?, ?)",
                        (key, str(value))
                    )
            self._load_config()
        except Exception as e:
            print(f"[SMART METER] Chyba uloženia konfigurácie: {e}")
    
    def get_config(self) -> dict:
        """Vráti aktuálnu konfiguráciu smart meradla."""
        return {
            'meter_mode': self.meter_mode,
            'meter_slave_id': self.meter_slave_id,
            'meter_baudrate': self.meter_baudrate,
            'meter_parity': self.meter_parity,
            'meter_reg_import_wh': self.reg_import_wh,
            'meter_reg_export_wh': self.reg_export_wh,
            'meter_reg_import_w': self.reg_import_w,
            'meter_reg_export_w': self.reg_export_w,
            'meter_reg_consumption_w': self.reg_consumption_w,
            'meter_s0_pin': self.s0_pin,
            'meter_s0_impulses_per_kwh': self.s0_impulses_per_kwh,
            'meter_cloud_api_url': self.cloud_api_url,
            'meter_control_mode': self.control_mode,
        }
    
    def get_live_data(self) -> dict:
        """Vráti aktuálne dáta z smart meradla."""
        with self.lock:
            return {
                'house_consumption_w': round(self.house_consumption_w, 1),
                'house_consumption_kwh_today': round(self.house_consumption_kwh_today, 2),
                'grid_import_w': round(self.grid_import_w, 1),
                'grid_export_w': round(self.grid_export_w, 1),
                'fve_surplus_w': round(self.fve_surplus_w, 1),
                'fve_production_w': round(self.fve_production_w, 1),
                'control_mode': self.control_mode,
                'meter_mode': self.meter_mode,
                'avg_consumption_w': round(self.avg_consumption_w, 1),
                'peak_consumption_w': round(self.peak_consumption_w, 1),
                'min_consumption_w': round(self.min_consumption_w, 1) if self.min_consumption_w < 999999 else 0,
                'timestamp': int(time.time()),
            }
    
    # =========================================================================
    # MODBUS RTU ČÍTANIE
    # =========================================================================
    def _read_modbus_meter(self, solar_service) -> dict:
        """Číta dáta z smart meradla cez Modbus RTU (RS485)."""
        result = {'consumption_w': 0.0, 'import_w': 0.0, 'export_w': 0.0, 'success': False}
        
        if not solar_service or not solar_service.ser or not solar_service.ser.is_open:
            return result
        
        try:
            ser = solar_service.get_serial_port(self.meter_baudrate)
            if not ser or not ser.is_open:
                return result
            
            slave_id = self.meter_slave_id
            
            # Čítanie okamžitej spotreby [W]
            if self.reg_consumption_w is not None:
                regs = solar_service.raw_read_registers(ser, slave_id, self.reg_consumption_w, 1)
                if regs:
                    result['consumption_w'] = float(regs[0])
                    result['success'] = True
            
            # Čítanie odberu zo siete [W]
            if self.reg_import_w is not None:
                regs = solar_service.raw_read_registers(ser, slave_id, self.reg_import_w, 1)
                if regs:
                    result['import_w'] = float(regs[0])
                    result['success'] = True
            
            # Čítanie vývozu do siete [W]
            if self.reg_export_w is not None:
                regs = solar_service.raw_read_registers(ser, slave_id, self.reg_export_w, 1)
                if regs:
                    result['export_w'] = float(regs[0])
                    result['success'] = True
            
            # Čítanie Wh registr pre denný súčet
            if self.reg_import_wh is not None:
                regs = solar_service.raw_read_registers(ser, slave_id, self.reg_import_wh, 2)
                if regs:
                    wh_val = (regs[0] << 16) | regs[1]  # 32-bit register
                    with self.lock:
                        self._wh_today_import = float(wh_val)
                    result['success'] = True
            
            if self.reg_export_wh is not None:
                regs = solar_service.raw_read_registers(ser, slave_id, self.reg_export_wh, 2)
                if regs:
                    wh_val = (regs[0] << 16) | regs[1]
                    with self.lock:
                        self._wh_today_export = float(wh_val)
                    result['success'] = True
                    
        except Exception as e:
            print(f"[SMART METER] Modbus RTU chyba: {e}")
        
        return result
    
    # =========================================================================
    # CLOUD API ČÍTANIE (Shelly EM, Tasmota, atď.)
    # =========================================================================
    def _read_cloud_api(self) -> dict:
        """Číta dáta z cloud API (Shelly EM, Tasmota Energy Monitor, atď.)."""
        result = {'consumption_w': 0.0, 'import_w': 0.0, 'export_w': 0.0, 'success': False}
        
        if not self.cloud_api_url:
            return result
        
        try:
            import requests
            headers = {"Content-Type": "application/json"}
            if self.cloud_api_token:
                headers["Authorization"] = f"Bearer {self.cloud_api_token}"
            
            resp = requests.get(self.cloud_api_url, headers=headers, timeout=5)
            if resp.status_code == 200:
                data = resp.json()
                
                # Univerzálny parser - podporuje rôzne formáty
                # Shelly EM format: {"em:0":{"total_act_power":...}}
                if 'em:0' in data:
                    em = data['em:0']
                    result['consumption_w'] = abs(float(em.get('total_act_power', 0)))
                    result['import_w'] = max(0, float(em.get('total_act_power', 0)))
                    result['export_w'] = abs(min(0, float(em.get('total_act_power', 0))))
                    result['success'] = True
                
                # Tasmota format: {"StatusSNS":{"ENERGY":{"Power":...}}}
                elif 'StatusSNS' in data:
                    energy = data['StatusSNS'].get('ENERGY', {})
                    result['consumption_w'] = float(energy.get('Power', 0))
                    result['import_w'] = max(0, float(energy.get('Power', 0)))
                    result['success'] = True
                
                # Jednoduchý formát: {"consumption_w": 1234, "import_w": 500, "export_w": 100}
                elif 'consumption_w' in data:
                    result['consumption_w'] = float(data.get('consumption_w', 0))
                    result['import_w'] = float(data.get('import_w', 0))
                    result['export_w'] = float(data.get('export_w', 0))
                    result['success'] = True
                
                # Shelly Pro EM format
                elif 'em' in data:
                    em = data['em']
                    result['consumption_w'] = abs(float(em.get('total_power', 0)))
                    result['import_w'] = max(0, float(em.get('total_power', 0)))
                    result['export_w'] = abs(min(0, float(em.get('total_power', 0))))
                    result['success'] = True
                    
        except Exception as e:
            print(f"[SMART METER] Cloud API chyba: {e}")
        
        return result
    
    # =========================================================================
    # S0 PULSE ČÍTANIE (GPIO)
    # =========================================================================
    def _read_s0_pulse(self) -> dict:
        """Číta dáta zo S0 pulzu ( GPIO pin)."""
        result = {'consumption_w': 0.0, 'import_w': 0.0, 'export_w': 0.0, 'success': False}
        
        if self.s0_pin is None:
            return result
        
        try:
            # S0 pulse counting - výpočet výkonu z frekvencie impulzov
            current_time = time.time()
            dt = current_time - self.s0_last_time
            
            if dt >= 1.0:  # Výpočet každú sekundu
                pulses = self.s0_pulse_count - self.s0_last_count
                power_w = (pulses / dt) * (3600.0 / self.s0_impulses_per_kwh) * 1000.0
                
                result['consumption_w'] = power_w
                result['import_w'] = power_w  # Predpokladáme odber zo siete
                result['success'] = True
                
                self.s0_last_count = self.s0_pulse_count
                self.s0_last_time = current_time
                
                # Denný súčet
                wh_now = self.s0_pulse_count * (1000.0 / self.s0_impulses_per_kwh)
                with self.lock:
                    self._wh_today_import = wh_now
        except Exception as e:
            print(f"[SMART METER] S0 pulse chyba: {e}")
        
        return result
    
    def increment_s0_pulse(self):
        """Zavolaj pri každom S0 pulze (GPIO interrupt handler)."""
        self.s0_pulse_count += 1
    
    # =========================================================================
    # HLAVNÁ AKTUALIZÁCIA
    # =========================================================================
    def update(self, solar_service=None):
        """
        Hlavná metóda - číta dáta z meradla a aktualizuje interný stav.
        Volá sa z hlavného cyklu solar_service.
        """
        now = datetime.now()
        today_str = now.strftime('%Y-%m-%d')
        
        # Reset denného počítadla
        if today_str != self._last_date:
            self._last_date = today_str
            self._wh_today_import = 0.0
            self._wh_today_export = 0.0
            self.house_consumption_kwh_today = 0.0
            self.consumption_history = []
            self.peak_consumption_w = 0.0
            self.min_consumption_w = 999999.0
        
        # Čítanie podľa režimu
        raw = {'consumption_w': 0.0, 'import_w': 0.0, 'export_w': 0.0, 'success': False}
        
        if self.meter_mode == "MODBUS_RTU":
            raw = self._read_modbus_meter(solar_service)
        elif self.meter_mode == "CLOUD_API":
            raw = self._read_cloud_api()
        elif self.meter_mode == "S0_PULSE":
            raw = self._read_s0_pulse()
        # NONE = žiadne meradlo, zostanú nuly
        
        with self.lock:
            if raw['success']:
                self.house_consumption_w = raw['consumption_w']
                self.grid_import_w = raw['import_w']
                self.grid_export_w = raw['export_w']
            
            # Výpočet FVE prebytku
            self.fve_surplus_w = max(0, self.fve_production_w - self.house_consumption_w)
            
            # Denná spotreba v kWh
            self.house_consumption_kwh_today = self._wh_today_import / 1000.0
            
            # Štatistiky
            if self.house_consumption_w > 0:
                if self.house_consumption_w > self.peak_consumption_w:
                    self.peak_consumption_w = self.house_consumption_w
                if self.house_consumption_w < self.min_consumption_w:
                    self.min_consumption_w = self.house_consumption_w
                
                self.consumption_history.append(self.house_consumption_w)
                if len(self.consumption_history) > 1440:
                    self.consumption_history.pop(0)
                
                if self.consumption_history:
                    self.avg_consumption_w = sum(self.consumption_history) / len(self.consumption_history)
    
    def update_fve_production(self, total_power_w: float):
        """Aktualizuje celkovú FVE výrobu (volá sa z solar_service po čítaní meničov)."""
        with self.lock:
            self.fve_production_w = total_power_w
            self.fve_surplus_w = max(0, total_power_w - self.house_consumption_w)
    
    # =========================================================================
    # ROZHODOVANIE PRE RIADENIE FVE
    # =========================================================================
    def should_inverter_run(self, okte_price: float = 80.0, avg_price: float = 80.0, 
                            battery_soc: float = 50.0) -> dict:
        """
        Rozhodne, či má byť menič zapnutý na základe aktuálneho režimu.
        Vráti: {'target_on': bool, 'reason': str, 'mode': str, 'limit_w': float}
        """
        with self.lock:
            mode = self.control_mode
            consumption = self.house_consumption_w
            surplus = self.fve_surplus_w
            production = self.fve_production_w
        
        if mode == self.MODE_UNLIMITED:
            # Bez obmedzenia - menič beží stále (maximálny výkon)
            return {
                'target_on': True,
                'reason': 'UNLIMITED: Menič zapnutý bez obmedzenia.',
                'mode': 'UNLIMITED',
                'limit_w': -1  # -1 = bez limitu
            }
        
        elif mode == self.MODE_SELF_CONSUMPTION:
            # Prispôsobenie výroby spotrebe domu
            # Menič vypneme iba ak je prebytok príliš veľký a batéria je plná
            if battery_soc >= 95.0 and surplus > 500:
                return {
                    'target_on': False,
                    'reason': f'SELF_CONSUMPTION: Batéria plná ({battery_soc:.0f}%), prebytok {surplus:.0f}W - menič vypnutý.',
                    'mode': 'SELF_CONSUMPTION',
                    'limit_w': consumption
                }
            else:
                return {
                    'target_on': True,
                    'reason': f'SELF_CONSUMPTION: Spotreba {consumption:.0f}W, výroba {production:.0f}W.',
                    'mode': 'SELF_CONSUMPTION',
                    'limit_w': consumption
                }
        
        elif mode == self.MODE_SMART:
            # Inteligentné rozhodovanie: spotreba + cena + predpoveď
            is_valley = okte_price < avg_price * 0.75
            is_peak = okte_price > avg_price * 1.35
            is_negative = okte_price <= 0.0
            
            if is_negative:
                # Záporná cena - nabíjame batériu, spúšťame bojler
                return {
                    'target_on': True,
                    'reason': f'SMART: Záporná cena ({okte_price:.1f}€/MWh) - maximálne nabíjanie.',
                    'mode': 'SMART_NEGATIVE',
                    'limit_w': -1
                }
            
            elif is_valley and battery_soc < 80.0:
                # Nočná dolina - lacný odber zo siete
                return {
                    'target_on': True,
                    'reason': f'SMART: Cena dolina ({okte_price:.1f}€/MWh), batéria {battery_soc:.0f}% - nabíjanie.',
                    'mode': 'SMART_VALLEY',
                    'limit_w': -1
                }
            
            elif is_peak and battery_soc > 30.0:
                # Špička - vybíjame batériu, spotreba z FVE/batérie
                return {
                    'target_on': True,
                    'reason': f'SMART: Cena špička ({okte_price:.1f}€/MWh) - spotreba z batérie.',
                    'mode': 'SMART_PEAK',
                    'limit_w': consumption
                }
            
            else:
                # Normálny režim - self-consumption + mierne nabíjanie
                if consumption > 200 and battery_soc < 90:
                    return {
                        'target_on': True,
                        'reason': f'SMART: Spotreba {consumption:.0f}W, cena {okte_price:.1f}€/MWh.',
                        'mode': 'SMART_NORMAL',
                        'limit_w': consumption + 300  # Mierne navýšenie pre nabíjanie
                    }
                elif battery_soc >= 95 and surplus > 1000:
                    return {
                        'target_on': False,
                        'reason': f'SMART: Batéria plná ({battery_soc:.0f}%), prebytok {surplus:.0f}W.',
                        'mode': 'SMART_FULL_BATTERY',
                        'limit_w': 0
                    }
                else:
                    return {
                        'target_on': True,
                        'reason': f'SMART: Štandardný režim.',
                        'mode': 'SMART_NORMAL',
                        'limit_w': consumption + 200
                    }
        
        # Default
        return {
            'target_on': True,
            'reason': 'Default: Menič zapnutý.',
            'mode': 'DEFAULT',
            'limit_w': -1
        }


# Globálna inštancia
_meter_instance = None
_meter_lock = threading.Lock()

def get_smart_meter_service(bg_service=None) -> SmartMeterService:
    global _meter_instance
    with _meter_lock:
        if _meter_instance is None:
            _meter_instance = SmartMeterService(bg_service)
        return _meter_instance
