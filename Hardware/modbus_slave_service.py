# Hardware/modbus_slave_service.py

import socket
import struct
import threading
import time
from datetime import datetime

class ModbusTcpSlaveServer:
    def __init__(self, bg_service, host="0.0.0.0", port=5020):
        self.bg_service = bg_service
        self.host = host
        self.port = port
        self.running = False
        self.server_socket = None
        self.thread = None

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
        
        if function_code == 3:
            reg_addr, quantity = struct.unpack(">HH", pdu[1:5])
            return self._handle_read_holding(reg_addr, quantity)
        elif function_code == 6:
            reg_addr, value = struct.unpack(">HH", pdu[1:5])
            return self._handle_write_single(reg_addr, value)
        return b""

    def _handle_read_holding(self, reg_addr, quantity) -> bytes:
        values = [self._get_register_value(reg_addr + i) for i in range(quantity)]
        pdu = struct.pack(">BB", 3, quantity * 2)
        for val in values:
            pdu += struct.pack(">h", val)
        return pdu

    def _handle_write_single(self, reg_addr, value) -> bytes:
        success = self._set_register_value(reg_addr, value)
        if success:
            return struct.pack(">BHH", 6, reg_addr, value)
        return b""

    def _get_register_value(self, reg) -> int:
        try:
            if reg == 1000:
                m_str = str(self.bg_service.manual_override).strip().upper()
                return 1 if m_str == "ON" else (2 if m_str == "OFF" else 0)
            elif reg == 1001:
                mid = str(self.bg_service.active_model_id).strip().upper()
                if mid in ["AI", "5", "0", ""]: return 5
                try: return int(float(mid))
                except Exception: return 5
            elif reg == 1002:
                return int(self.bg_service.night_sleep)
            elif reg == 1003:
                return 1 if getattr(self.bg_service, 'comm_mode', 'LOCAL_MODBUS') == 'CLOUD' else 0
            elif reg == 2000:
                return int(sum(item.get("power_ac", 0.0) for item in self.bg_service.live_data.values()))
            elif reg == 2001:
                soc_list = [item["battery_soc"] for item in self.bg_service.live_data.values() if item.get("battery_soc", 0) > 0]
                return int(sum(soc_list) / len(soc_list)) if soc_list else 0
            elif reg == 2002:
                return int(self.bg_service.last_live_okte_price)
            elif reg == 2003:
                temp_list = [item["temp"] for item in self.bg_service.live_data.values() if item.get("temp", 0) > 0]
                return int((sum(temp_list) / len(temp_list)) * 10) if temp_list else 320
            elif reg == 2004:
                try:
                    from ai_engine import SlovakCalendar
                    dt_type = SlovakCalendar.get_day_type(datetime.now())
                    return 1 if dt_type == "WEEKEND" else (2 if dt_type == "HOLIDAY" else 0)
                except Exception:
                    return 0
        except Exception:
            pass
        return 0

    def _set_register_value(self, reg, value) -> bool:
        try:
            if reg == 1000:
                self.bg_service.manual_override = "ON" if value == 1 else ("OFF" if value == 2 else "AUTO")
                self.bg_service.process_control_commands()
                return True
            elif reg == 1001:
                if value in [0, 5]: self.bg_service.active_model_id = "AI"
                elif value in [1, 2, 3, 4]: self.bg_service.active_model_id = str(value)
                return True
            elif reg == 1002:
                if value in [0, 1]:
                    self.bg_service.night_sleep = value
                    return True
        except Exception:
            pass
        return False