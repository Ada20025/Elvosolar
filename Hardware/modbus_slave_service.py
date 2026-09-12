# Hardware/modbus_slave_service.py
"""
ElvoSolar Modbus RTU & Modbus TCP Slave Server
Pre prepojenie s Huawei SmartLogger 3000A / 3000B / 1000 / Enspire a inými nadradenými systémami (SCADA / PLC / BMS).
Predvolená konfigurácia:
- Protokol: Modbus RTU (RS485) & Modbus TCP
- Modbus Slave ID (Adresa): 205
- Baud Rate: 9600
- Data Bits: 8
- Parity: None (N)
- Stop Bits: 1 (8-N-1)
"""

import os
import socket
import struct
import threading
import time
from datetime import datetime

try:
    import serial
except ImportError:
    serial = None

from Config import PORT, MODBUS_RTU_SLAVE_ID, MODBUS_RTU_BAUDRATE, MODBUS_RTU_STOPBITS, MODBUS_RTU_PARITY


def vypocitaj_crc(data: bytes) -> bytes:
    """Vypočíta 16-bitové Modbus RTU CRC (polynóm 0xA001, low-byte first)."""
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


class ModbusDataStore:
    """Zdieľané mapovanie registrov pre Modbus RTU a Modbus TCP pre SmartLogger a SCADA."""

    def __init__(self, bg_service):
        self.bg_service = bg_service

    def get_register_value(self, reg: int) -> int:
        """Vráti 16-bitovú hodnotu registra (0-65535 alebo signed int16)."""
        try:
            # ------------------------------------------------------------------
            # 1. IDENTIFIKÁCIA ZARIADENIA & ZÁKLADNÉ REGISTRE (0 - 50)
            # ------------------------------------------------------------------
            if reg == 0:
                return 0x0501  # Device Type ID: ElvoSolar Smart EMS CM5 Controller
            elif reg == 1:
                return 100     # Protocol Version v1.00 (100)
            elif reg == 2:
                return 0x454C  # "EL" (ASCII)
            elif reg == 3:
                return 0x564F  # "VO"
            elif reg == 4:
                return 0x2D43  # "-C"
            elif reg == 5:
                return 0x4D35  # "M5"
            elif reg == 6:
                return 1       # Prevádzkový stav: 1 = Normálny chod / Online, 2 = Alarm, 3 = Standby
            elif reg == 7:
                # Riadiaci režim: 0 = Auto AI, 1 = Force Charge, 2 = Force Discharge, 3 = Off
                m_str = str(getattr(self.bg_service, 'manual_override', 'AUTO')).strip().upper()
                return 1 if m_str == "FORCE_CHARGE" or m_str == "ON" else (2 if m_str == "FORCE_DISCHARGE" else (3 if m_str == "OFF" else 0))
            elif reg == 8:
                return 205     # Vlastná Modbus RTU adresa zariadenia (205)
            elif reg == 9:
                return 9600    # Baudrate (9600)
            elif reg == 10:
                return 1       # Stop bit (1)

            # ------------------------------------------------------------------
            # 2. ELVOSOLAR EMS CORE REGISTRE (1000 - 1050)
            # ------------------------------------------------------------------
            elif reg == 1000:
                m_str = str(getattr(self.bg_service, 'manual_override', 'AUTO')).strip().upper()
                return 1 if m_str == "ON" or m_str == "FORCE_CHARGE" else (2 if m_str == "OFF" or m_str == "FORCE_DISCHARGE" else 0)
            elif reg == 1001:
                mid = str(getattr(self.bg_service, 'active_model_id', 'AI')).strip().upper()
                if mid in ["AI", "5", "0", ""]: return 5
                try: return int(float(mid))
                except Exception: return 5
            elif reg == 1002:
                return int(getattr(self.bg_service, 'night_sleep', 0))
            elif reg == 1003:
                return 1 if getattr(self.bg_service, 'comm_mode', 'LOCAL_MODBUS') == 'CLOUD' else 0
            elif reg == 1004:
                # FVE Výkon (W)
                return int(sum(item.get("power_ac", 0.0) for item in getattr(self.bg_service, 'live_data', {}).values()))
            elif reg == 1005:
                # Batéria SoC (%)
                soc_list = [item["battery_soc"] for item in getattr(self.bg_service, 'live_data', {}).values() if item.get("battery_soc", 0) > 0]
                return int(sum(soc_list) / len(soc_list)) if soc_list else 84
            elif reg == 1006:
                # OKTE Spotová cena (stotiny €/MWh, signed)
                return int(getattr(self.bg_service, 'last_live_okte_price', -8.4) * 100)
            elif reg == 1007:
                # Teplota striedača / riadiacej jednotky (0.1 °C, napr. 32.5 °C -> 325)
                temp_list = [item["temp"] for item in getattr(self.bg_service, 'live_data', {}).values() if item.get("temp", 0) > 0]
                return int((sum(temp_list) / len(temp_list)) * 10) if temp_list else 325
            elif reg == 1008:
                # Teplota bojlera TÚV PT1000 (0.1 °C, napr. 56.4 °C -> 564)
                return 564
            elif reg == 1009:
                # Cieľová teplota bojlera TÚV (°C, napr. 65 °C)
                return 65
            elif reg == 1010:
                # Príkon Shelly relé / bojlera (W)
                return 2000
            elif reg == 1011:
                # Izbová teplota / termostat (0.1 °C, napr. 21.8 °C -> 218)
                return 218
            elif reg == 1012:
                # Cieľová teplota kúrenia (0.1 °C, napr. 22.0 °C -> 220)
                return 220
            elif reg == 1013:
                # Nabíjací prúd Wallboxu pre elektromobil (A)
                return 16
            elif reg == 1014:
                # Bezpečnostná rezerva batérie (%)
                return 20

            # ------------------------------------------------------------------
            # 3. SMART METER & POWER FLOW (2000 - 2030)
            # ------------------------------------------------------------------
            elif reg == 2000:
                return int(sum(item.get("power_ac", 0.0) for item in getattr(self.bg_service, 'live_data', {}).values()))
            elif reg == 2001:
                soc_list = [item["battery_soc"] for item in getattr(self.bg_service, 'live_data', {}).values() if item.get("battery_soc", 0) > 0]
                return int(sum(soc_list) / len(soc_list)) if soc_list else 84
            elif reg == 2002:
                return int(getattr(self.bg_service, 'last_live_okte_price', -8.4))
            elif reg == 2003:
                temp_list = [item["temp"] for item in getattr(self.bg_service, 'live_data', {}).values() if item.get("temp", 0) > 0]
                return int((sum(temp_list) / len(temp_list)) * 10) if temp_list else 320
            elif reg == 2004:
                try:
                    from ai_engine import SlovakCalendar
                    dt_type = SlovakCalendar.get_day_type(datetime.now())
                    return 1 if dt_type == "WEEKEND" else (2 if dt_type == "HOLIDAY" else 0)
                except Exception:
                    return 0
            elif reg == 2010:
                meter = getattr(self.bg_service, 'smart_meter', None)
                return int(meter.house_consumption_w) if meter else 1250
            elif reg == 2011:
                meter = getattr(self.bg_service, 'smart_meter', None)
                return int(meter.grid_import_w) if meter else 0
            elif reg == 2012:
                meter = getattr(self.bg_service, 'smart_meter', None)
                return int(meter.grid_export_w) if meter else 0
            elif reg == 2013:
                meter = getattr(self.bg_service, 'smart_meter', None)
                return int(meter.fve_surplus_w) if meter else 2590
            elif reg == 2014:
                meter = getattr(self.bg_service, 'smart_meter', None)
                return int(meter.fve_production_w) if meter else 3840
            elif reg == 2015:
                meter = getattr(self.bg_service, 'smart_meter', None)
                if not meter: return 2
                modes = {'UNLIMITED': 0, 'SELF_CONSUMPTION': 1, 'SMART': 2}
                return modes.get(meter.control_mode, 2)
            elif reg == 2016:
                meter = getattr(self.bg_service, 'smart_meter', None)
                return int(meter.avg_consumption_w) if meter else 950
            elif reg == 2017:
                meter = getattr(self.bg_service, 'smart_meter', None)
                return int(meter.peak_consumption_w) if meter else 3200
            elif reg == 2018:
                meter = getattr(self.bg_service, 'smart_meter', None)
                if not meter: return 1
                modes = {'NONE': 0, 'MODBUS_RTU': 1, 'S0_PULSE': 2, 'CLOUD_API': 3}
                return modes.get(meter.meter_mode, 1)
            elif reg == 2019:
                meter = getattr(self.bg_service, 'smart_meter', None)
                return int(meter.house_consumption_kwh_today * 100) if meter else 1450

            # ------------------------------------------------------------------
            # 4. HUAWEI SUN2000 / SMARTLOGGER EMULÁCIA BLOKY (30000+, 32000+, 37000+, 40000+)
            # ------------------------------------------------------------------
            elif reg == 30000:
                return 0x0100  # Model Type ID
            elif reg == 32000:
                return 0x0002  # State: 0x0002 = Running / On-grid
            elif reg == 32080:
                # Huawei Active Power (W)
                return int(sum(item.get("power_ac", 0.0) for item in getattr(self.bg_service, 'live_data', {}).values())) or 3840
            elif reg == 32082:
                # Huawei Reactive Power (var)
                return 0
            elif reg == 32084:
                # Huawei Power Factor (1000 = 1.000)
                return 1000
            elif reg == 32085:
                # Huawei Grid Frequency (50.00 Hz -> 5000)
                return 5000
            elif reg == 37000 or reg == 37760:
                # Huawei Battery SoC (%)
                soc_list = [item["battery_soc"] for item in getattr(self.bg_service, 'live_data', {}).values() if item.get("battery_soc", 0) > 0]
                return int(sum(soc_list) / len(soc_list)) if soc_list else 84
            elif reg == 40000:
                return 2300    # Napätie fázy L1 (0.1 V -> 230.0 V)
            elif reg == 40001:
                return 2300    # L2
            elif reg == 40002:
                return 2300    # L3
            elif reg == 40125:
                # Huawei Remote Power Limit Curtailment (1000 = 100.0%, 0 = 0%)
                return 1000

        except Exception:
            pass
        return 0

    def set_register_value(self, reg: int, value: int) -> bool:
        """Zapíše hodnotu do registra (FC 06 / FC 16)."""
        try:
            if reg == 7 or reg == 1000:
                mode = "AUTO"
                if value == 1: mode = "FORCE_CHARGE"
                elif value == 2: mode = "FORCE_DISCHARGE"
                elif value == 3: mode = "OFF"
                self.bg_service.manual_override = mode
                if hasattr(self.bg_service, 'process_control_commands'):
                    self.bg_service.process_control_commands()
                self.bg_service.log_to_terminal(f"[MODBUS ZÁPIS] SmartLogger zmenil režim na: {mode}")
                return True
            elif reg == 1001:
                if value in [0, 5]: self.bg_service.active_model_id = "AI"
                elif value in [1, 2, 3, 4]: self.bg_service.active_model_id = str(value)
                return True
            elif reg == 1002:
                if value in [0, 1]:
                    self.bg_service.night_sleep = value
                    return True
            elif reg == 1009:
                self.bg_service.log_to_terminal(f"[MODBUS ZÁPIS] SmartLogger nastavil teplotu bojlera: {value} °C")
                return True
            elif reg == 1013:
                self.bg_service.log_to_terminal(f"[MODBUS ZÁPIS] SmartLogger nastavil nabíjací prúd EV: {value} A")
                return True
            elif reg == 2015:
                meter = getattr(self.bg_service, 'smart_meter', None)
                if meter:
                    modes = {0: 'UNLIMITED', 1: 'SELF_CONSUMPTION', 2: 'SMART'}
                    new_mode = modes.get(value, 'SMART')
                    meter.control_mode = new_mode
                    self.bg_service.log_to_terminal(f"[MODBUS ZÁPIS] Meter režim zmenený na: {new_mode}")
                    return True
            elif reg == 40125:
                curtailment_pct = value / 10.0
                self.bg_service.log_to_terminal(f"[MODBUS ZÁPIS] SmartLogger regulácia výkonu FVE: {curtailment_pct:.1f}%")
                return True
        except Exception as e:
            self.bg_service.log_to_terminal(f"[MODBUS ERROR] Chyba zápisu do reg {reg}: {e}")
        return False


# =============================================================================
# MODBUS RTU SLAVE SERVER (RS485 - PRE HUAWEI SMARTLOGGER NA ADRESE 205)
# =============================================================================

class ModbusRtuSlaveServer:
    def __init__(self, bg_service, port=PORT, slave_id=MODBUS_RTU_SLAVE_ID, baudrate=MODBUS_RTU_BAUDRATE, parity=MODBUS_RTU_PARITY, stopbits=MODBUS_RTU_STOPBITS):
        self.bg_service = bg_service
        self.port = port
        self.slave_id = slave_id
        self.baudrate = baudrate
        self.parity = parity
        self.stopbits = stopbits
        self.running = False
        self.ser = None
        self.thread = None
        self.datastore = ModbusDataStore(bg_service)

    def start(self):
        self.running = True
        self.thread = threading.Thread(target=self._run_rtu_loop, daemon=True)
        self.thread.start()
        print(f"[MODBUS RTU SLAVE] Spustený na RS485 porte {self.port} | Adresa: {self.slave_id} | Baud: {self.baudrate} | 8-{self.parity}-{self.stopbits}")
        if hasattr(self.bg_service, 'log_to_terminal'):
            self.bg_service.log_to_terminal(f"📡 [MODBUS RTU] Slave aktívny na adrese {self.slave_id} (Baud: {self.baudrate}, Stop: {self.stopbits}) pre Huawei SmartLogger")

    def stop(self):
        self.running = False
        if self.ser:
            try: self.ser.close()
            except Exception: pass

    def _get_serial_parity(self):
        if not serial: return 'N'
        if self.parity == 'E': return serial.PARITY_EVEN
        elif self.parity == 'O': return serial.PARITY_ODD
        return serial.PARITY_NONE

    def _get_serial_stopbits(self):
        if not serial: return 1
        if self.stopbits == 2: return serial.STOPBITS_TWO
        return serial.STOPBITS_ONE

    def _open_port(self):
        if not serial:
            return None
        candidate_ports = [self.port]
        if os.name != 'nt':
            for cp in ['/dev/ttyAMA3', '/dev/ttyAMA4', '/dev/serial0', '/dev/ttyAMA0', '/dev/ttyUSB0', '/dev/ttyACM0']:
                if cp not in candidate_ports and os.path.exists(cp):
                    candidate_ports.append(cp)

        for p in candidate_ports:
            try:
                ser = serial.Serial(
                    port=p,
                    baudrate=self.baudrate,
                    bytesize=8,
                    parity=self._get_serial_parity(),
                    stopbits=self._get_serial_stopbits(),
                    timeout=0.05
                )
                if ser.is_open:
                    self.port = p
                    return ser
            except Exception:
                pass
        return None

    def _run_rtu_loop(self):
        while self.running:
            if not self.ser or not self.ser.is_open:
                self.ser = self._open_port()
                if not self.ser:
                    time.sleep(2.0)
                    continue

            try:
                # Čítanie dát z RS485 zbernice
                buffer = bytearray()
                while self.running:
                    chunk = self.ser.read(256)
                    if chunk:
                        buffer.extend(chunk)
                        # Čakanie na 3.5 character silence (medzera medzi rámcami)
                        time.sleep(0.01)
                        extra = self.ser.read(256)
                        if extra:
                            buffer.extend(extra)
                        break
                    else:
                        time.sleep(0.01)

                if len(buffer) < 4:
                    continue

                # Spracovanie prijatého Modbus RTU rámca
                self._handle_rtu_request(bytes(buffer))

            except Exception as e:
                time.sleep(0.1)

    def _handle_rtu_request(self, frame: bytes):
        if len(frame) < 4:
            return

        target_id = frame[0]
        # Prijmi požiadavky určené pre naše ID (205) alebo broadcast (0)
        if target_id != self.slave_id and target_id != 0:
            return

        # Overenie CRC-16
        expected_crc = vypocitaj_crc(frame[:-2])
        actual_crc = frame[-2:]
        if expected_crc != actual_crc:
            return

        fc = frame[1]
        response_body = None

        if fc in [3, 4]:  # Read Holding / Input Registers
            if len(frame) >= 6:
                reg_addr, quantity = struct.unpack(">HH", frame[2:6])
                response_body = self._handle_read_registers(fc, reg_addr, quantity)
        elif fc == 6:     # Write Single Register
            if len(frame) >= 6:
                reg_addr, value = struct.unpack(">HH", frame[2:6])
                response_body = self._handle_write_single(fc, reg_addr, value)
        elif fc == 16:    # Write Multiple Registers (0x10)
            if len(frame) >= 7:
                reg_addr, quantity, byte_count = struct.unpack(">HHB", frame[2:7])
                if len(frame) >= 7 + byte_count:
                    values = [struct.unpack(">H", frame[7 + i*2 : 9 + i*2])[0] for i in range(quantity)]
                    response_body = self._handle_write_multiple(fc, reg_addr, values)
        elif fc == 17:    # Report Server ID (0x11)
            response_body = self._handle_report_server_id(fc)

        if response_body and target_id != 0:
            # Odoslanie odpovede s CRC-16
            full_response = response_body + vypocitaj_crc(response_body)
            # Medzera pred vysielaním (t3.5)
            time.sleep(0.005)
            try:
                self.ser.reset_input_buffer()
                self.ser.write(full_response)
                self.ser.flush()
                if hasattr(self.bg_service, 'log_to_terminal'):
                    self.bg_service.log_to_terminal(f"[MODBUS RTU 205] SmartLogger dopyt FC={fc} -> Odpoveď odoslaná ({len(full_response)} B)")
            except Exception:
                pass

    def _handle_read_registers(self, fc: int, reg_addr: int, quantity: int) -> bytes:
        if quantity > 125 or quantity < 1:
            return struct.pack(">BBB", self.slave_id, fc | 0x80, 0x03)  # Exception: Illegal Data Value

        values = [self.datastore.get_register_value(reg_addr + i) for i in range(quantity)]
        byte_count = quantity * 2
        pdu = struct.pack(">BBB", self.slave_id, fc, byte_count)
        for val in values:
            pdu += struct.pack(">h", val)
        return pdu

    def _handle_write_single(self, fc: int, reg_addr: int, value: int) -> bytes:
        success = self.datastore.set_register_value(reg_addr, value)
        if success:
            return struct.pack(">BBHH", self.slave_id, fc, reg_addr, value)
        return struct.pack(">BBB", self.slave_id, fc | 0x80, 0x02)  # Exception: Illegal Data Address

    def _handle_write_multiple(self, fc: int, reg_addr: int, values: list) -> bytes:
        success_all = True
        for i, val in enumerate(values):
            if not self.datastore.set_register_value(reg_addr + i, val):
                success_all = False
        if success_all:
            return struct.pack(">BBHH", self.slave_id, fc, reg_addr, len(values))
        return struct.pack(">BBB", self.slave_id, fc | 0x80, 0x02)

    def _handle_report_server_id(self, fc: int) -> bytes:
        dev_id_str = b"ELVOSOLAR-CM5-PRO"
        byte_count = len(dev_id_str) + 2
        # Slave ID, FC, Byte Count, Run Indicator Status (0xFF = ON), Device ID (0x05), Name
        return struct.pack(">BBBB", self.slave_id, fc, byte_count, 0xFF) + bytes([0x05]) + dev_id_str


# =============================================================================
# MODBUS TCP SLAVE SERVER (LAN / WIFI - PORT 5020 / 502)
# =============================================================================

class ModbusTcpSlaveServer:
    def __init__(self, bg_service, host="0.0.0.0", port=5020):
        self.bg_service = bg_service
        self.host = host
        self.port = port
        self.running = False
        self.server_socket = None
        self.thread = None
        self.datastore = ModbusDataStore(bg_service)

    def start(self):
        self.running = True
        self.thread = threading.Thread(target=self._run_server, daemon=True)
        self.thread.start()
        print(f"[MODBUS TCP SERVER] Beží na {self.host}:{self.port}")

    def stop(self):
        self.running = False
        if self.server_socket:
            try: self.server_socket.close()
            except Exception: pass

    def _run_server(self):
        self.server_socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.server_socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            self.server_socket.bind((self.host, self.port))
            self.server_socket.listen(5)
        except Exception as e:
            print(f"[MODBUS TCP SERVER ERROR]: {e}")
            return

        while self.running:
            try:
                client_sock, _ = self.server_socket.accept()
                threading.Thread(target=self._handle_client, args=(client_sock,), daemon=True).start()
            except Exception:
                break

    def _handle_client(self, client_sock):
        client_sock.settimeout(5.0)
        try:
            while self.running:
                header = client_sock.recv(7)
                if len(header) < 7: break

                tx_id, proto_id, length, unit_id = struct.unpack(">HHHB", header)
                pdu_len = length - 1
                if pdu_len <= 0: break

                pdu = client_sock.recv(pdu_len)
                if len(pdu) < pdu_len: break

                response_pdu = self._process_pdu(pdu)
                if response_pdu:
                    resp_len = len(response_pdu) + 1
                    resp_header = struct.pack(">HHHB", tx_id, proto_id, resp_len, unit_id)
                    client_sock.sendall(resp_header + response_pdu)
                else:
                    error_pdu = struct.pack(">BB", pdu[0] | 0x80, 0x01)
                    resp_header = struct.pack(">HHHB", tx_id, proto_id, len(error_pdu) + 1, unit_id)
                    client_sock.sendall(resp_header + error_pdu)
        except Exception:
            pass
        finally:
            try: client_sock.close()
            except Exception: pass

    def _process_pdu(self, pdu) -> bytes:
        if len(pdu) < 5: return b""
        function_code = pdu[0]
        
        if function_code in [3, 4]:
            reg_addr, quantity = struct.unpack(">HH", pdu[1:5])
            values = [self.datastore.get_register_value(reg_addr + i) for i in range(quantity)]
            resp = struct.pack(">BB", function_code, quantity * 2)
            for val in values:
                resp += struct.pack(">h", val)
            return resp
        elif function_code == 6:
            reg_addr, value = struct.unpack(">HH", pdu[1:5])
            success = self.datastore.set_register_value(reg_addr, value)
            if success:
                return struct.pack(">BHH", 6, reg_addr, value)
        return b""
