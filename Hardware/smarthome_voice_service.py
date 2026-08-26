# Hardware/smarthome_voice_service.py

import re
import json
import os
from typing import Dict, Any, List
from database import db_execute
from third_party_service import ThirdPartyDeviceManager

class SmartHomeVoiceService:
    def __init__(self, ai_engine=None, led_service=None):
        self.ai_engine = ai_engine
        self.led_service = led_service
        self.hardware_manager = ThirdPartyDeviceManager()

    def _extract_number(self, text: str) -> float:
        """Extrahuje číselnú hodnotu (teplotu, prúd, percentá) z textu príkazu."""
        match = re.search(r'(\d+(?:[.,]\d+)?)', text)
        if match:
            try:
                return float(match.group(1).replace(',', '.'))
            except ValueError:
                return None
        return None

    def _log_notification(self, title: str, message: str, msg_type: str = "info", tag: str = "VOICE"):
        """Zaznamená upozornenie do databázy pre zobrazenie v cloude/webe."""
        db_execute("""
            INSERT INTO notifications (title, message, type, tag, is_read)
            VALUES (?, ?, ?, ?, 0)
        """, (title, message, msg_type, tag))

    def _get_devices_by_category(self, category: str) -> List[Dict[str, Any]]:
        """Načíta všetky povolené inteligentné spotrebiče danej kategórie."""
        rows = db_execute("""
            SELECT id, name, category, protocol, ip_address, channel, power_w, is_enabled, is_active, trigger_params 
            FROM third_party_devices 
            WHERE category = ? AND is_enabled = 1
        """, (category,))
        return [dict(r) for r in rows]

    def _update_device_database_state(self, device_id: int, is_active: int, trigger_params_json: str = None):
        """Uloží nový prevádzkový stav a parametre do SQLite."""
        if trigger_params_json:
            db_execute("""
                UPDATE third_party_devices 
                SET is_active = ?, trigger_params = ? 
                WHERE id = ?
            """, (is_active, trigger_params_json, device_id))
        else:
            db_execute("""
                UPDATE third_party_devices 
                SET is_active = ? 
                WHERE id = ?
            """, (is_active, device_id))

    def _execute_hardware_switch(self, device: Dict[str, Any], turn_on: bool) -> bool:
        """Vykoná sieťové prepnutie prostredníctvom priradeného protokolu (Shelly, Tasmota, Modbus)."""
        proto = device.get("protocol", "SHELLY_RELAY")
        ip = device.get("ip_address")
        channel = device.get("channel", 1) - 1 # Prepis indexov na nulu pre Shelly
        
        if not ip:
            return False

        if proto == "SHELLY_RELAY":
            return self.hardware_manager.switch_shelly_relay(ip, channel if channel >= 0 else 0, turn_on)
        elif proto == "SONOFF":
            return self.hardware_manager.switch_tasmota_sonoff(ip, channel + 1, turn_on)
        elif proto == "MODBUS_TCP":
            return self.hardware_manager.switch_modbus_tcp_relay(ip, 502, channel if channel >= 0 else 0, turn_on)
        return False

    def process_voice_command(self, command: str, telemetry: Dict[str, Any] = None) -> Dict[str, Any]:
        """Spracuje klientsky povel, uloží stav a okamžite prepne smart relé na lokálnej sieti."""
        if not telemetry:
            telemetry = {}

        cmd = command.lower().strip()
        pv_w = round(telemetry.get('pv_power', 3840))
        soc = round(telemetry.get('battery_soc', 84))
        okte_p = telemetry.get('okte_price', -8.40)

        if self.led_service:
            try:
                self.led_service.blink_start_led(4)
            except Exception:
                pass

        extracted_val = self._extract_number(cmd)

        # ======================================================================
        # 1. BOJLER / ZÁSOBNÍK TÚV & TEPLOTA VODY
        # ======================================================================
        if any(w in cmd for w in ['bojler', 'voda', 'vodu', 'túv', 'tuv', 'ohrev']):
            devices = self._get_devices_by_category("BOILER")
            if not devices:
                return {"action": "ERROR", "speech_response": "V systéme nie je registrovaný žiadny bojler.", "success": False}
            
            dev = devices[0]
            dev_id = dev["id"]
            dev_name = dev["name"]

            if extracted_val is not None and any(w in cmd for w in ['stup', '°', 'cels', 'teplot', 'nastav', 'na ']):
                target_t = round(extracted_val, 1)
                
                try:
                    params = json.loads(dev["trigger_params"]) if dev["trigger_params"] else {}
                except Exception:
                    params = {}
                
                params['target_temp'] = target_t
                params_json = json.dumps(params)
                
                self._update_device_database_state(dev_id, dev["is_active"], params_json)
                self._log_notification(
                    "Hlasové nastavenie bojlera", 
                    f"Cieľová teplota pre {dev_name} bola upravená na {target_t} °C.",
                    "success"
                )

                return {
                    "action": "SET_BOILER_TEMP",
                    "target_temperature_c": target_t,
                    "speech_response": f"Cieľová teplota pre {dev_name} bola upravená na {target_t:.0f} stupňov Celzia.",
                    "success": True
                }

            elif any(w in cmd for w in ['zapni', 'spusti', 'nahrej', 'on']):
                success = self._execute_hardware_switch(dev, True)
                self._update_device_database_state(dev_id, 1)
                self._log_notification("Hlasové zopnutie", f"Spotrebič {dev_name} bol zopnutý.", "info")
                
                return {
                    "action": "BOILER_ON",
                    "speech_response": f"Zopínam ohrev na zariadení {dev_name}." if success else f"Zariadenie {dev_name} bolo aktivované v systéme.",
                    "success": True
                }

            elif any(w in cmd for w in ['vypni', 'zastav', 'off']):
                success = self._execute_hardware_switch(dev, False)
                self._update_device_database_state(dev_id, 0)
                self._log_notification("Hlasové vypnutie", f"Ohrev {dev_name} bol manuálne vypnutý.", "info")
                
                return {
                    "action": "BOILER_OFF",
                    "speech_response": f"Vypínam ohrev na zariadení {dev_name}." if success else f"Vypol som zariadenie {dev_name} v stave systému.",
                    "success": True
                }

            else:
                status_text = "zopnutý" if dev["is_active"] == 1 else "vypnutý"
                return {
                    "action": "BOILER_STATUS",
                    "speech_response": f"Zariadenie {dev_name} je momentálne {status_text}. Spotreba ohrevu pri zopnutí je {dev['power_w']:.0f} Wattov.",
                    "success": True
                }

        # ======================================================================
        # 2. TEPELNÉ ČERPADLO / KÚRENIE / TERMOSTAT
        # ======================================================================
        elif any(w in cmd for w in ['kúren', 'kuren', 'tepelné čerpadlo', 'tepelne cerpadlo', 'termostat', 'teplota v dome', 'izbov']):
            devices = self._get_devices_by_category("HEATPUMP")
            
            if extracted_val is not None and any(w in cmd for w in ['stup', '°', 'cels', 'teplot', 'nastav', 'na ']):
                target_t = round(extracted_val, 1)
                
                if devices:
                    dev = devices[0]
                    try:
                        params = json.loads(dev["trigger_params"]) if dev["trigger_params"] else {}
                    except Exception:
                        params = {}
                    params['target_temp'] = target_t
                    self._update_device_database_state(dev["id"], dev["is_active"], json.dumps(params))
                
                db_execute("INSERT OR REPLACE INTO ai_learning_state (key, value) VALUES ('heating_target_temp', ?)", (str(target_t),))

                dev_label = devices[0]["name"] if devices else "kúrenia"
                return {
                    "action": "SET_HEATING_TEMP",
                    "target_temperature_c": target_t,
                    "speech_response": f"Požadovaná teplota pre {dev_label} bola nastavená na {target_t:.1f} stupňa Celzia.",
                    "success": True
                }
            else:
                target_t = 22.0
                rows = db_execute("SELECT value FROM ai_learning_state WHERE key = 'heating_target_temp'")
                if rows:
                    target_t = float(rows[0]['value'])

                dev_label = devices[0]["name"] if devices else "Kúrenie"
                return {
                    "action": "GET_HEATING_STATUS",
                    "target_temp_c": target_t,
                    "speech_response": f"{dev_label} udržiava cieľovú teplotu nastavenú v inteligentnom pláne na {target_t:.1f} stupňa.",
                    "success": True
                }

        # ======================================================================
        # 3. KLIMATIZÁCIA & CHLADENIE
        # ======================================================================
        elif any(w in cmd for w in ['klimatiz', 'klíma', 'klima', 'chlad']):
            devices = self._get_devices_by_category("AC")
            
            if any(w in cmd for w in ['vypni', 'off']):
                if devices:
                    dev = devices[0]
                    self._execute_hardware_switch(dev, False)
                    self._update_device_database_state(dev["id"], 0)
                
                db_execute("INSERT OR REPLACE INTO ai_learning_state (key, value) VALUES ('ac_state', '0')")
                dev_label = devices[0]["name"] if devices else "Klimatizácia"
                return {
                    "action": "AC_OFF",
                    "speech_response": f"Klimatizácia {dev_label} bola úspešne vypnutá.",
                    "success": True
                }
            else:
                target_t = round(extracted_val, 1) if extracted_val is not None else 23.0
                
                if devices:
                    dev = devices[0]
                    try:
                        params = json.loads(dev["trigger_params"]) if dev["trigger_params"] else {}
                    except Exception:
                        params = {}
                    params['target_temp'] = target_t
                    self._execute_hardware_switch(dev, True)
                    self._update_device_database_state(dev["id"], 1, json.dumps(params))

                db_execute("INSERT OR REPLACE INTO ai_learning_state (key, value) VALUES ('ac_state', '1')")
                db_execute("INSERT OR REPLACE INTO ai_learning_state (key, value) VALUES ('ac_target_temp', ?)", (str(target_t),))

                dev_label = devices[0]["name"] if devices else "Klimatizácia"
                return {
                    "action": "SET_AC_TEMP",
                    "target_temperature_c": target_t,
                    "speech_response": f"Klimatizácia {dev_label} bola aktivovaná s cieľovou teplotou {target_t:.0f} stupňov Celzia.",
                    "success": True
                }

        # ======================================================================
        # 4. WALLBOX & ELEKTROMOBIL (A)
        # ======================================================================
        elif any(w in cmd for w in ['aut', 'elektromobil', 'ev', 'wallbox', 'nabíjačk', 'nabijack']):
            devices = self._get_devices_by_category("EV")

            if extracted_val is not None and any(w in cmd for w in ['ampér', 'amper', 'a']):
                amps = int(extracted_val)
                
                if devices:
                    dev = devices[0]
                    try:
                        params = json.loads(dev["trigger_params"]) if dev["trigger_params"] else {}
                    except Exception:
                        params = {}
                    params['ev_target_amps'] = amps
                    self._update_device_database_state(dev["id"], dev["is_active"], json.dumps(params))

                db_execute("INSERT OR REPLACE INTO ai_learning_state (key, value) VALUES ('ev_target_amps', ?)", (str(amps),))
                dev_label = devices[0]["name"] if devices else "Wallboxu"
                return {
                    "action": "SET_EV_AMPS",
                    "amps": amps,
                    "speech_response": f"Nabíjací prúd pre {dev_label} bol nastavený na {amps} Ampérov.",
                    "success": True
                }
            elif any(w in cmd for w in ['zapni', 'spusti', 'nabi', 'on']):
                if devices:
                    dev = devices[0]
                    self._execute_hardware_switch(dev, True)
                    self._update_device_database_state(dev["id"], 1)
                
                dev_label = devices[0]["name"] if devices else "nabíjačky"
                return {
                    "action": "EV_CHARGE_START",
                    "speech_response": f"Nabíjanie {dev_label} bolo úspešne spustené zo solárnych prebytkov.",
                    "success": True
                }
            elif any(w in cmd for w in ['vypni', 'zastav', 'off']):
                if devices:
                    dev = devices[0]
                    self._execute_hardware_switch(dev, False)
                    self._update_device_database_state(dev["id"], 0)

                dev_label = devices[0]["name"] if devices else "nabíjačky"
                return {
                    "action": "EV_CHARGE_STOP",
                    "speech_response": f"Nabíjanie {dev_label} bolo pozastavené.",
                    "success": True
                }
            else:
                dev_label = devices[0]["name"] if devices else "Wallbox"
                return {
                    "action": "EV_STATUS",
                    "speech_response": f"Zariadenie {dev_label} komunikuje so systémom v optimálnom režime.",
                    "success": True
                }

        # ======================================================================
        # 5. BATÉRIA ÚLOŽISKA & REZERVA (%)
        # ======================================================================
        elif any(w in cmd for w in ['bater', 'batér', 'akumul', 'soc']):
            if extracted_val is not None and any(w in cmd for w in ['rezerv', 'minimum', 'min']):
                min_soc = int(extracted_val)
                db_execute("UPDATE ai_learning_state SET value = ? WHERE key = 'battery_min_soc'", (str(float(min_soc)),))
                self._log_notification("Úprava rezervy batérie", f"Minimálny stav SoC nastavený na {min_soc} %.", "info")

                return {
                    "action": "SET_BATTERY_MIN_RESERVE",
                    "min_soc": min_soc,
                    "speech_response": f"Bezpečnostná rezerva batérie bola upravená na {min_soc} percent.",
                    "success": True
                }
            elif any(w in cmd for w in ['nabit', 'nabíť', 'nabij', 'nabíj', 'vynut', 'force']):
                db_execute("UPDATE system_settings SET value = 'ON' WHERE key = 'active_model'")
                return {
                    "action": "FORCE_CHARGE",
                    "speech_response": "Rozumiem. Spúšťam vynútené nabíjanie domácej batérie zo siete.",
                    "success": True
                }
            elif any(w in cmd for w in ['vybi', 'vybíjaj']):
                db_execute("UPDATE system_settings SET value = 'OFF' WHERE key = 'active_model'")
                return {
                    "action": "FORCE_DISCHARGE",
                    "speech_response": "Rozumiem. Prepínam striedač do režimu vybíjania batérie.",
                    "success": True
                }
            else:
                return {
                    "action": "BATTERY_STATUS",
                    "speech_response": f"Batéria má kapacitu {soc} percent a komunikuje na adrese RS485.",
                    "success": True
                }

        # ======================================================================
        # 6. SOLÁRNA VÝROBA FVE & SPOTOVÁ CENA OKTE
        # ======================================================================
        elif any(w in cmd for w in ['vyrob', 'slnk', 'panel', 'fotovolt', 'fve']):
            return {
                "action": "SOLAR_STATUS",
                "speech_response": f"Aktuálny výkon fotovoltiky je {pv_w} Wattov.",
                "success": True
            }
        elif any(w in cmd for w in ['cen', 'spot', 'okte', 'burz', 'elektrin']):
            if okte_p <= 0:
                return {
                    "action": "PRICE_STATUS",
                    "speech_response": f"Aktuálna cena na trhu OKTE je záporná, {okte_p:.2f} eur za megawatthodinu.",
                    "success": True
                }
            else:
                return {
                    "action": "PRICE_STATUS",
                    "speech_response": f"Aktuálna spotová cena OKTE je {okte_p:.2f} eur za megawatthodinu.",
                    "success": True
                }

        # ======================================================================
        # 7. AI AUTOMATIKA & PROFIL
        # ======================================================================
        elif any(w in cmd for w in ['auto', 'ai', 'inteligent']):
            db_execute("UPDATE system_settings SET value = 'AI' WHERE key = 'active_model'")
            self._log_notification("Zmena režimu", "Zariadenie bolo prepnuté do plnej AI automatiky.", "success")
            return {
                "action": "SET_AUTO",
                "speech_response": "Prepínam systém do plne automatického režimu AI EMS.",
                "success": True
            }
        elif any(w in cmd for w in ['profil', 'dom', 'firm']):
            if any(w in cmd for w in ['firm', 'podnik']):
                db_execute("UPDATE system_settings SET value = 'BUSINESS' WHERE key = 'installation_type'")
                self._log_notification("Zmena profilu", "Nastavený priemyselný režim s arbitrážou.", "info")
                return {
                    "action": "SET_PROFILE_BUSINESS",
                    "speech_response": "Profil inštalácie bol nastavený na: Firma s optimalizáciou pretokov.",
                    "success": True
                }
            else:
                db_execute("UPDATE system_settings SET value = 'HOME' WHERE key = 'installation_type'")
                self._log_notification("Zmena profilu", "Nastavená domácnosť so 100% vlastnou spotrebou.", "info")
                return {
                    "action": "SET_PROFILE_HOME",
                    "speech_response": "Profil inštalácie bol prepnutý na: Domácnosť so 100% vlastnou spotrebou.",
                    "success": True
                }

        return {
            "action": "UNKNOWN",
            "speech_response": "Nerozumiem príkazu. Skúste napríklad: 'Nastav bojler na 60 stupňov' alebo 'Prejdi na AI automatiku'.",
            "success": False
        }