# Hardware/solar_service.py

import time
import requests
import os
import serial
import threading
import json
import queue
from datetime import datetime
from database import get_db_connection, db_execute
from Config import DEVICE_DB, PORT
from ai_engine import AiService
from led_service import LedService
from models_engine import spusti_regulacnu_logiku

class SolarBackgroundService:
    def __init__(self):
        self.running = True
        self.paused = False
        self.manual_override = "AUTO"
        self.active_model_id = "AI"
        self.night_sleep = 1
        self.active_errors = {}
        self.last_internet_check = 0
        self.lock = threading.RLock()   
        self.ser = None

        self.live_data = {}
        self.last_live_okte_price = 0.0  

        self.terminal_logs = []
        self.ai_service = AiService.get_instance()
        self.ai_logs = self.ai_service.ai_logs

        self.cloud_queue = queue.Queue(maxsize=50)
        threading.Thread(target=self._cloud_sync_worker_loop, daemon=True).start()

        try:
            from modbus_slave_service import ModbusTcpSlaveServer
            self.slave_server = ModbusTcpSlaveServer(self)
            self.slave_server.start()
        except Exception as e:
            print(f"Modbus TCP server sa nespustil: {e}")

    def log_to_terminal(self, message: str):
        try:
            timestamp = datetime.now().strftime('%H:%M:%S')
            log_line = f"[{timestamp}] {message}"
            print(log_line)
            self.terminal_logs.append(log_line)
            if len(self.terminal_logs) > 30:
                self.terminal_logs.pop(0)
        except Exception:
            pass

    def check_self_healing(self):
        try:
            now_ts = time.time()
            if now_ts - self.last_internet_check > 30:
                self.last_internet_check = now_ts
                from system_service import SystemService
                if not SystemService.test_internet_connection():
                    self.active_errors["INTERNET"] = "Výpadok lokálneho internetu."
                    SystemService.auto_fallback_cellular()
                else:
                    self.active_errors.pop("INTERNET", None)
        except Exception:
            pass

    def vypocitaj_crc(self, data: bytes) -> bytes:
        try:
            crc = 0xFFFF
            for pos in data:
                crc ^= pos
                for _ in range(8):
                    if (crc & 0x0001) != 0:
                        crc >>= 1
                        crc ^= 0xA001
                    else:
                        crc >>= 1
            return bytes([crc & 0xFF, (crc >> 8) & 0xFF])
        except Exception:
            return bytes([0, 0])

    def get_serial_port(self, baudrate=9600):
        active_port = PORT
        parity = serial.PARITY_NONE
        
        # Načítanie paritných konfigurácií z DB cez wrapper
        row_port = db_execute("SELECT value FROM system_settings WHERE key = 'rs485_active_port'")
        if row_port and row_port[0]['value']:
            active_port = row_port[0]['value']

        row_parity = db_execute("SELECT value FROM system_settings WHERE key = 'rs485_parity'")
        if row_parity and row_parity[0]['value']:
            p_val = row_parity[0]['value']
            if p_val == "E": parity = serial.PARITY_EVEN
            elif p_val == "O": parity = serial.PARITY_ODD

        if self.ser is None or not self.ser.is_open:
            candidate_ports = [active_port]
            if os.name != 'nt':
                for cp in ['/dev/ttyAMA3', '/dev/serial0', '/dev/ttyAMA0', '/dev/ttyUSB0', '/dev/ttyACM0']:
                    if cp not in candidate_ports and os.path.exists(cp):
                        candidate_ports.append(cp)

            opened = False
            for p in candidate_ports:
                try:
                    self.ser = serial.Serial(port=p, baudrate=baudrate, parity=parity, timeout=0.5)
                    if self.ser.is_open:
                        opened = True
                        break
                except Exception:
                    pass

            if not opened:
                self.log_to_terminal(f"Nepodarilo sa otvoriť RS485 port ({active_port}).")
        else:
            if self.ser.port != active_port or self.ser.baudrate != baudrate or self.ser.parity != parity:
                try:
                    self.ser.close()
                    self.ser = serial.Serial(port=active_port, baudrate=baudrate, parity=parity, timeout=0.5)
                except Exception:
                    pass
        return self.ser

    def ping_slave(self, ser, slave_id: int, register: int) -> bool:
        if not ser or not ser.is_open:
            return False
        try:
            reg_h = (register >> 8) & 0xFF
            reg_l = register & 0xFF
            
            frame = bytes([slave_id, 0x03, reg_h, reg_l, 0x00, 0x01])
            full_frame = frame + self.vypocitaj_crc(frame)
            
            ser.reset_input_buffer()
            ser.write(full_frame)
            ser.flush()
            
            res = ser.read(5)
            if len(res) < 5:
                return False
                
            if res[0] != slave_id:
                return False
                
            if res[1] in [0x83, 0x84]:
                crc_calc = self.vypocitaj_crc(res[:3])
                if res[3:5] == crc_calc:
                    return True
                    
            if res[1] == 0x03:
                remaining = ser.read(2)
                full_res = res + remaining
                if len(full_res) == 7:
                    return full_res[-2:] == self.vypocitaj_crc(full_res[:-2])
        except Exception:
            pass
        return False

    def raw_read_registers(self, ser, slave_id: int, register: int, count: int):
        if not ser or not ser.is_open:
            return None
        try:
            reg_h = (register >> 8) & 0xFF
            reg_l = register & 0xFF
            cnt_h = (count >> 8) & 0xFF
            cnt_l = count & 0xFF
            
            pdu = bytes([slave_id, 0x03, reg_h, reg_l, cnt_h, cnt_l])
            full_frame = pdu + self.vypocitaj_crc(pdu)
            
            ser.reset_input_buffer()
            ser.write(full_frame)
            ser.flush()
            
            hlavicka = ser.read(3)
            if len(hlavicka) < 3 or (hlavicka[1] & 0x80):
                return None
                
            pocet_bajtov = hlavicka[2]
            zbytok = ser.read(pocet_bajtov + 2)
            if len(zbytok) < (pocet_bajtov + 2):
                return None
                
            cela_odpoved = hlavicka + zbytok
            if cela_odpoved[-2:] == self.vypocitaj_crc(cela_odpoved[:-2]):
                return [(cela_odpoved[3 + 2*i] << 8) | cela_odpoved[4 + 2*i] for i in range(count)]
        except Exception:
            pass
        return None

    def raw_write_register(self, ser, slave_id: int, register: int, value: int, function_code: int = 6) -> bool:
        if not ser or not ser.is_open:
            return False
        try:
            reg_h = (register >> 8) & 0xFF
            reg_l = register & 0xFF
            val_h = (value >> 8) & 0xFF
            val_l = value & 0xFF
            
            if function_code == 6:
                pdu = bytes([slave_id, 0x06, reg_h, reg_l, val_h, val_l])
            else:
                pdu = bytes([slave_id, 0x10, reg_h, reg_l, 0x00, 0x01, 0x02, val_h, val_l])
                
            full_frame = pdu + self.vypocitaj_crc(pdu)
            ser.reset_input_buffer()
            ser.write(full_frame)
            ser.flush()
            
            hlavicka = ser.read(3)
            if len(hlavicka) < 3 or (hlavicka[1] & 0x80):
                return False
                
            zbytok = ser.read(5)
            if len(zbytok) < 5:
                return False
                
            cela_odpoved = hlavicka + zbytok
            return cela_odpoved[-2:] == self.vypocitaj_crc(cela_odpoved[:-2])
        except Exception as e:
            self.log_to_terminal(f"Chyba zápisu Modbus (ID {slave_id}): {e}")
        return False

    def push_to_cloud(self, payload):
        try:
            if self.cloud_queue.full():
                try:
                    self.cloud_queue.get_nowait()
                except queue.Empty:
                    pass
            self.cloud_queue.put_nowait(payload)
        except Exception:
            pass

    def _cloud_sync_worker_loop(self):
        while self.running:
            try:
                payload = self.cloud_queue.get(timeout=1.0)
            except queue.Empty:
                continue
            try:
                self._execute_cloud_post(payload)
            except Exception as e:
                self.log_to_terminal(f"Chyba synchronizácie cloudu: {e}")
            finally:
                self.cloud_queue.task_done()

    def _execute_cloud_post(self, payload):
        url_row = db_execute("SELECT value FROM system_settings WHERE key = 'cloud_sync_url'")
        if not url_row or not url_row[0]['value']:
            return

        url = url_row[0]['value']
        headers = {
            "User-Agent": "Mozilla/5.0 (Linux; Android 10)",
            "Accept": "application/json",
            "Content-Type": "application/json"
        }

        try:
            resp = requests.post(url, json=payload, headers=headers, timeout=6)
            if resp.status_code == 200:
                LedService.blink_start_led(4)
        except Exception:
            pass

    def write_command(self, slave_id: int, command: str) -> bool:
        with self.lock:
            rows = db_execute("SELECT * FROM devices WHERE slave_id = ?", (slave_id,))
            if not rows:
                self.log_to_terminal(f"Menič ID {slave_id} nebol nájdený v lokálnej DB.")
                return False

            dev_d = dict(rows[0])
            brand_id = dev_d.get('brand_id', '')
            category_id = dev_d.get('category_id', '')
            model_id = dev_d.get('model_id', '')
            
            cfg = DEVICE_DB.get(brand_id, {}).get('kategorie', {}).get(category_id, {}).get('modely', {}).get(model_id)
            if not cfg:
                return False

            cmd_upper = str(command).strip().upper()
            if cmd_upper == "ON":
                reg = cfg.get('on')
                val = cfg.get('val_on', 100)
            elif cmd_upper == "OFF":
                reg = cfg.get('off')
                val = cfg.get('val_off', 0)
            else:
                return False

            if reg is None:
                return False

            ser = self.get_serial_port(cfg.get('baud', 9600))
            if not ser or not ser.is_open:
                return False

            success = self.raw_write_register(ser, slave_id, reg, val, function_code=6)
            if not success:
                success = self.raw_write_register(ser, slave_id, reg, val, function_code=16)

            if success:
                self.log_to_terminal(f"Zápis úspešný (ID {slave_id}) -> {cmd_upper} (Register: {reg}, Hodnota: {val})")
                return True
            return False

    def process_control_commands(self, ser=None):
        with self.lock:
            devices_list = [dict(r) for r in db_execute("SELECT * FROM devices")]
            if not devices_list:
                return

            local_ser = ser if ser else self.ser
            if not local_ser or not local_ser.is_open:
                try:
                    local_ser = self.get_serial_port(9600)
                except Exception:
                    return

            if not local_ser or not local_ser.is_open:
                return

            try:
                target_on = True
                if self.manual_override == "ON":
                    target_on = True
                elif self.manual_override == "OFF":
                    target_on = False
                else: # AUTO Režim
                    total_power = sum(item.get("power_ac", 0.0) for item in self.live_data.values())
                    soc_vals = [item.get("battery_soc", 0.0) for item in self.live_data.values() if item.get("battery_soc", 0.0) > 0]
                    avg_soc = sum(soc_vals) / len(soc_vals) if soc_vals else 50.0
                    temp_vals = [item.get("temp", 30.0) for item in self.live_data.values() if item.get("temp", 0) > 0]
                    avg_temp = sum(temp_vals) / len(temp_vals) if temp_vals else 32.0

                    ai_decision = self.ai_service.evaluate_live_state(
                        battery_soc=avg_soc,
                        inverter_temp=avg_temp,
                        live_power_ac=total_power,
                        manual_override=self.manual_override
                    )
                    
                    self.last_live_okte_price = ai_decision.get("okte_current_price_eur", self.last_live_okte_price)

                    # Ak je zapnutá AUTO AI automatika, vyhodnocujeme action z ai_service
                    if self.active_model_id in ["AI", ""]:
                        target_on = (ai_decision["inverter_target"] == "ON")
                        self.log_to_terminal(f"🤖 AI [{ai_decision['mode_label']}]: {ai_decision['reason']}")
                    else:
                        # Ak je zapnutý jeden zo štyroch zjednodušených modelov, spustíme models_engine
                        log_msg = spusti_regulacnu_logiku(
                            model_id=self.active_model_id,
                            cena_aktualna=self.last_live_okte_price,
                            stats=ai_decision.get("okte_stats"),
                            soc=avg_soc,
                            core=self,
                            slave_id=devices_list[0]["slave_id"]
                        )
                        self.log_to_terminal(log_msg)
                        return

                for dev in devices_list:
                    try:
                        brand_id = dev['brand_id']
                        category_id = dev['category_id']
                        model_id = dev['model_id']
                        slave_id = dev['slave_id']
                        
                        cfg = DEVICE_DB.get(brand_id, {}).get('kategorie', {}).get(category_id, {}).get('modely', {}).get(model_id)
                        if not cfg: continue

                        reg = cfg.get('off') if not target_on else cfg.get('on')
                        val = cfg.get('val_off', 0) if not target_on else cfg.get('val_on', 100)

                        if reg is not None:
                            success = self.raw_write_register(local_ser, slave_id, reg, val, function_code=6)
                            if not success:
                                success = self.raw_write_register(local_ser, slave_id, reg, val, function_code=16)
                    except Exception:
                        pass
            except Exception as e:
                self.log_to_terminal(f"Chyba pri vykonávaní riadiacich príkazov: {e}")

    def read_and_sync_devices(self, devices_list):
        try:
            first_dev = devices_list[0]
            baud_rate = first_dev['config'].get('baud', 9600) if first_dev['config'] else 9600
            
            ser = self.get_serial_port(baud_rate)
            if not ser or not ser.is_open:
                raise Exception("Sériový port nie je otvorený.")
            
            for dev in devices_list:
                slave_id = dev['slave_id']
                cfg = dev['config']
                
                power_val = 0.0
                soc_val = 0.0
                temp_val = 0.0
                freq_val = 0.0
                status_msg = "Chyba komunikácie (Zbernica offline)"
                
                if cfg:
                    reg_p_ac = cfg.get('reg_p_ac', 32080)
                    reg_soc = cfg.get('reg_soc', 37760)
                    
                    read_p = self.raw_read_registers(ser, slave_id, reg_p_ac, 2)
                    if not read_p:
                        read_p = self.raw_read_registers(ser, slave_id, reg_p_ac, 1)
                    read_soc = self.raw_read_registers(ser, slave_id, reg_soc, 1)
                    
                    if read_p:
                        power_val = float(read_p[0])
                        temp_val = 34.2
                        freq_val = 50.01
                        status_msg = "Aktívne pripojenie"
                        LedService.blink_start_led(4)
                        
                    if read_soc:
                        soc_val = float(read_soc[0])
                        if not read_p:
                            LedService.blink_start_led(4)

                self.live_data[slave_id] = {
                    "serial_number": dev['serial_number'],
                    "slave_id": slave_id,
                    "power_ac": power_val,
                    "battery_soc": soc_val,
                    "temp": temp_val,
                    "freq": freq_val,
                    "status_msg": status_msg
                }
                
                try:
                    self.ai_service.learn_from_telemetry(power_ac=power_val, battery_soc=soc_val, temp=temp_val)
                except Exception:
                    pass

                self.push_to_cloud(self.live_data[slave_id])
                        
        except Exception as com_err:
            for dev in devices_list:
                slave_id = dev['slave_id']
                self.live_data[slave_id] = {
                    "serial_number": dev['serial_number'],
                    "slave_id": slave_id,
                    "power_ac": 0.0,
                    "battery_soc": 0.0,
                    "temp": 0.0,
                    "freq": 0.0,
                    "status_msg": f"Zbernica nedostupná: {com_err}"
                }
                self.push_to_cloud(self.live_data[slave_id])

    def start_loop(self):
        last_ctrl_time = 0
        while self.running:
            try:
                if getattr(self, 'paused', False):
                    time.sleep(1)
                    continue
                    
                self.check_self_healing()
                
                # Vyčítanie zoznamu zariadení
                rows = db_execute("SELECT * FROM devices")
                devices_list = []
                for r in rows:
                    dev_d = dict(r)
                    brand = dev_d.get('brand_id', '')
                    cat = dev_d.get('category_id', '')
                    model = dev_d.get('model_id', '')
                    dev_d['config'] = DEVICE_DB.get(brand, {}).get('kategorie', {}).get(cat, {}).get('modely', {}).get(model)
                    devices_list.append(dev_d)

                if devices_list:
                    with self.lock:
                        self.read_and_sync_devices(devices_list)

                    now_ts = time.time()
                    if now_ts - last_ctrl_time >= 15:
                        last_ctrl_time = now_ts
                        self.process_control_commands()

            except Exception as e:
                self.log_to_terminal(f"Výnimka v riadiacom jadre: {e}")
            
            time.sleep(3)