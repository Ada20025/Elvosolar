# ai_engine.py
import time
import math
import json
import sqlite3
import threading
import requests
from datetime import datetime, date, timedelta
from database import get_db_connection, init_db

# ==============================================================================
# 1. SLOVENSKÝ KALENDÁR SVIATKOV, SEZÓNY A KLASIFIKÁCIA DNÍ
# ==============================================================================
class SlovakCalendar:
    FIXED_HOLIDAYS = {
        (1, 1): "Deň vzniku Slovenskej republiky / Nový rok",
        (1, 6): "Zjavenie Pána (Traja králi)",
        (5, 1): "Sviatok práce",
        (5, 8): "Deň víťazstva nad fašizmom",
        (7, 5): "Sviatok svätého Cyrila a Metoda",
        (8, 29): "Výročie SNP",
        (9, 1): "Deň Ústavy Slovenskej republiky",
        (9, 15): "Sedembolestná Panna Mária",
        (11, 1): "Sviatok Všetkých svätých",
        (11, 17): "Deň boja za slobodu a demokraciu",
        (12, 24): "Štedrý deň",
        (12, 25): "Prvý sviatok vianočný",
        (12, 26): "Druhý sviatok vianočný"
    }

    DAY_NAMES_SK = ["Pondelok", "Utorok", "Streda", "Štvrtok", "Piatok", "Sobota", "Nedeľa"]

    @staticmethod
    def get_easter_sunday(year: int) -> date:
        a = year % 19
        b = year // 100
        c = year % 100
        d = b // 4
        e = b % 4
        f = (b + 8) // 25
        g = (b - f + 1) // 3
        h = (19 * a + b - d - g + 15) % 30
        i = c // 4
        k = c % 4
        l = (32 + 2 * e + 2 * i - h - k) % 7
        m = (a + 11 * h + 22 * l) // 451
        month = (h + l - 7 * m + 114) // 31
        day = ((h + l - 7 * m + 114) % 31) + 1
        return date(year, month, day)

    @classmethod
    def get_holiday_info(cls, check_date: date) -> tuple[bool, str]:
        key = (check_date.month, check_date.day)
        if key in cls.FIXED_HOLIDAYS:
            return True, cls.FIXED_HOLIDAYS[key]

        easter_sunday = cls.get_easter_sunday(check_date.year)
        good_friday = easter_sunday - timedelta(days=2)
        easter_monday = easter_sunday + timedelta(days=1)

        if check_date == good_friday:
            return True, "Veľký piatok"
        if check_date == easter_monday:
            return True, "Veľkonočný pondelok"

        return False, ""

    @classmethod
    def get_day_type(cls, target_dt: datetime | date) -> str:
        d = target_dt.date() if isinstance(target_dt, datetime) else target_dt
        is_hol, _ = cls.get_holiday_info(d)
        if is_hol:
            return "HOLIDAY"
        if d.weekday() in (5, 6):
            return "WEEKEND"
        return "WORKDAY"

    @classmethod
    def get_season_info(cls, target_dt: datetime | date = None) -> dict:
        if target_dt is None:
            target_dt = datetime.now()
        month = target_dt.month
        
        if month in [3, 4, 5]:
            return {
                "season": "SPRING", "label_sk": "Jar", "icon": "flower",
                "description": "Rovnováha medzi výrobou a spotrebou.",
                "strategy": "BALANCE_AND_SPRING_SURPLUS"
            }
        elif month in [6, 7, 8]:
            return {
                "season": "SUMMER", "label_sk": "Leto", "icon": "sun",
                "description": "Maximálna FVE výroba. Ochrana pred negatívnymi cenami.",
                "strategy": "MAX_SELF_AND_SURPLUS"
            }
        elif month in [9, 10, 11]:
            return {
                "season": "AUTUMN", "label_sk": "Jeseň", "icon": "leaf",
                "description": "Klesajúca výroba a začiatok vykurovacej sezóny.",
                "strategy": "HEATING_PREPARE"
            }
        else:
            return {
                "season": "WINTER", "label_sk": "Zima", "icon": "snowflake",
                "description": "Minimálna FVE výroba. Nákup v nočných cenových dolinách.",
                "strategy": "VALLEY_PRECHARGE"
            }

    @classmethod
    def get_full_day_details(cls, target_dt: datetime | date = None) -> dict:
        if target_dt is None:
            target_dt = datetime.now()
        d = target_dt.date() if isinstance(target_dt, datetime) else target_dt
        day_type = cls.get_day_type(d)
        is_hol, hol_name = cls.get_holiday_info(d)
        weekday_idx = d.weekday()
        season_info = cls.get_season_info(d)

        type_label_sk = "Pracovný deň"
        if day_type == "WEEKEND":
            type_label_sk = "Víkend"
        elif day_type == "HOLIDAY":
            type_label_sk = f"Sviatok ({hol_name})"

        return {
            "date": d.strftime("%Y-%m-%d"),
            "day_type": day_type,
            "type_label_sk": type_label_sk,
            "is_holiday": is_hol,
            "holiday_name": hol_name,
            "weekday_index": weekday_idx,
            "weekday_name": cls.DAY_NAMES_SK[weekday_idx],
            "season": season_info
        }


# ==============================================================================
# 2. SŤAHOVANIE A ANALÝZA CIEN ZO SLOVENSKÉHO OKTE (DAM SPOT MARKET)
# ==============================================================================
class OktePriceService:
    API_URL = "https://isot.okte.sk/api/v1/dam/results"

    def __init__(self):
        self.lock = threading.Lock()

    def fetch_prices_for_day(self, day_str: str) -> list[float]:
        cached = self._load_from_db(day_str)
        if cached and len(cached) >= 24:
            return cached

        url = f"{self.API_URL}?deliveryDayFrom={day_str}&deliveryDayTo={day_str}"
        headers = {
            "User-Agent": "Mozilla/5.0",
            "Accept": "application/json"
        }

        try:
            resp = requests.get(url, headers=headers, timeout=8)
            if resp.status_code == 200:
                raw = resp.json()
                results = raw.get("results", raw) if isinstance(raw, dict) else raw
                if isinstance(results, list) and len(results) > 0:
                    results.sort(key=lambda x: int(x.get("period", 0)))
                    prices = [float(item.get("price", 0.0)) for item in results]
                    self._save_to_db(day_str, prices)
                    return prices
        except Exception as e:
            print(f"[OKTE SERVICE] Varovanie: Zlyhalo sťahovanie cien z OKTE ({day_str}): {e}")

        fallback = [
            75.0, 70.0, 68.0, 65.0, 68.0, 85.0, 110.0, 135.0,
            120.0, 95.0, 80.0, 70.0, 65.0, 60.0, 75.0, 90.0,
            115.0, 145.0, 160.0, 140.0, 115.0, 95.0, 85.0, 78.0
        ]
        return fallback

    def get_schedule_for_today_and_tomorrow(self) -> dict:
        now = datetime.now()
        today_str = now.strftime("%Y-%m-%d")
        tomorrow_str = (now + timedelta(days=1)).strftime("%Y-%m-%d")

        today_prices = self.fetch_prices_for_day(today_str)
        tomorrow_prices = []
        if now.hour >= 13:
            tomorrow_prices = self.fetch_prices_for_day(tomorrow_str)

        today_hourly = self._to_hourly_prices(today_prices)
        tomorrow_hourly = self._to_hourly_prices(tomorrow_prices) if tomorrow_prices else []

        current_hour = now.hour
        current_price = today_hourly[current_hour] if current_hour < len(today_hourly) else 80.0
        stats = self.calculate_price_statistics(today_hourly)

        return {
            "today_date": today_str,
            "tomorrow_date": tomorrow_str,
            "current_price_eur_mwh": current_price,
            "current_price_eur_kwh": current_price / 1000.0,
            "today_hourly": today_hourly,
            "tomorrow_hourly": tomorrow_hourly,
            "today_raw": today_prices,
            "stats": stats
        }

    def calculate_price_statistics(self, hourly_prices: list[float]) -> dict:
        if not hourly_prices:
            return {"avg": 80.0, "min": 50.0, "max": 120.0, "negative_count": 0, "peak_hours": [], "valley_hours": []}

        avg_price = sum(hourly_prices) / len(hourly_prices)
        min_price = min(hourly_prices)
        max_price = max(hourly_prices)
        neg_count = sum(1 for p in hourly_prices if p < 0.0)

        indexed = sorted(enumerate(hourly_prices), key=lambda x: x[1])
        valley_hours = [idx for idx, _ in indexed[:6]]
        peak_hours = [idx for idx, _ in indexed[-6:]]

        return {
            "avg": round(avg_price, 2),
            "min": round(min_price, 2),
            "max": round(max_price, 2),
            "negative_count": neg_count,
            "has_negative_prices": neg_count > 0,
            "peak_hours": sorted(peak_hours),
            "valley_hours": sorted(valley_hours)
        }

    def _to_hourly_prices(self, prices: list[float]) -> list[float]:
        if len(prices) == 96:
            hourly = []
            for h in range(24):
                chunk = prices[h*4 : (h+1)*4]
                hourly.append(round(sum(chunk) / len(chunk), 2) if chunk else 0.0)
            return hourly
        elif len(prices) == 24:
            return [round(p, 2) for p in prices]
        elif len(prices) > 0:
            return [round(prices[i % len(prices)], 2) for i in range(24)]
        return [80.0] * 24

    def _load_from_db(self, day_str: str) -> list[float]:
        try:
            conn = get_db_connection()
            cursor = conn.cursor()
            cursor.execute("SELECT price_eur FROM okte_prices_cache WHERE delivery_date = ? ORDER BY period ASC", (day_str,))
            rows = cursor.fetchall()
            conn.close()
            if rows and len(rows) >= 24:
                return [float(r[0]) for r in rows]
        except Exception:
            pass
        return []

    def _save_to_db(self, day_str: str, prices: list[float]):
        try:
            conn = get_db_connection()
            cursor = conn.cursor()
            for idx, p in enumerate(prices):
                cursor.execute(
                    "INSERT OR REPLACE INTO okte_prices_cache (delivery_date, period, price_eur) VALUES (?, ?, ?)",
                    (day_str, idx + 1, float(p))
                )
            conn.commit()
            conn.close()
        except Exception:
            pass


# ==============================================================================
# 3. SAMO-UČIACI SA MODEL SPOTREBY (WORKDAY, WEEKEND, HOLIDAY)
# ==============================================================================
class ConsumptionLearner:
    DEFAULT_PROFILES = {
        "WORKDAY": [
            350.0, 320.0, 300.0, 310.0, 420.0, 650.0, 1400.0, 1850.0,
            1200.0, 650.0, 550.0, 500.0, 580.0, 520.0, 580.0, 850.0,
            1350.0, 2100.0, 2650.0, 2400.0, 1950.0, 1450.0, 850.0, 450.0
        ],
        "WEEKEND": [
            400.0, 360.0, 340.0, 330.0, 350.0, 420.0, 680.0, 1100.0,
            1650.0, 2100.0, 2300.0, 2450.0, 2200.0, 1600.0, 1400.0, 1350.0,
            1600.0, 2150.0, 2700.0, 2550.0, 2100.0, 1650.0, 950.0, 550.0
        ],
        "HOLIDAY": [
            420.0, 380.0, 350.0, 340.0, 360.0, 450.0, 720.0, 1250.0,
            1800.0, 2250.0, 2500.0, 2600.0, 2350.0, 1750.0, 1500.0, 1450.0,
            1700.0, 2250.0, 2800.0, 2650.0, 2200.0, 1750.0, 1050.0, 600.0
        ]
    }

    def __init__(self):
        self.lock = threading.Lock()
        self.profiles = {}
        self.sample_counts = {}
        self.days_learned = 0
        self.total_samples = 0
        self.learning_stage = "INITIAL_LEARNING"
        self.confidence_percent = 25.0
        self._init_or_load_profiles()

    def _init_or_load_profiles(self):
        with self.lock:
            for p_type in ["WORKDAY", "WEEKEND", "HOLIDAY"]:
                self.profiles[p_type] = list(self.DEFAULT_PROFILES[p_type])
                self.sample_counts[p_type] = [0] * 24

            try:
                conn = get_db_connection()
                cursor = conn.cursor()
                cursor.execute("SELECT key, value FROM ai_learning_state")
                for row in cursor.fetchall():
                    k, v = row['key'], row['value']
                    if k == 'days_learned': self.days_learned = int(float(v))
                    elif k == 'total_samples': self.total_samples = int(float(v))
                    elif k == 'learning_stage': self.learning_stage = v
                    elif k == 'confidence_percent': self.confidence_percent = float(v)

                cursor.execute("SELECT profile_type, hour, avg_power_w, sample_count FROM ai_profiles")
                rows = cursor.fetchall()
                for r in rows:
                    pt = r['profile_type']
                    h = r['hour']
                    if pt in self.profiles and 0 <= h < 24:
                        self.profiles[pt][h] = float(r['avg_power_w'])
                        self.sample_counts[pt][h] = int(r['sample_count'])

                if not rows:
                    for pt, vals in self.DEFAULT_PROFILES.items():
                        for h, w in enumerate(vals):
                            cursor.execute(
                                "INSERT OR REPLACE INTO ai_profiles (profile_type, hour, avg_power_w, min_power_w, max_power_w, sample_count) VALUES (?, ?, ?, ?, ?, 0)",
                                (pt, h, w, w * 0.5, w * 1.8)
                            )
                    conn.commit()

                conn.close()
            except Exception as e:
                print(f"[CONSUMPTION LEARNER] Inicializácia: {e}")

    def record_telemetry_sample(self, measured_load_w: float, current_dt: datetime = None):
        if current_dt is None:
            current_dt = datetime.now()
        if measured_load_w < 0:
            measured_load_w = 0.0

        p_type = SlovakCalendar.get_day_type(current_dt)
        hour = current_dt.hour

        with self.lock:
            self.total_samples += 1
            current_samples = self.sample_counts[p_type][hour] + 1
            self.sample_counts[p_type][hour] = current_samples

            alpha = 0.25 if self.total_samples < 500 else (0.12 if self.total_samples < 2000 else 0.05)
            old_avg = self.profiles[p_type][hour]
            new_avg = (1.0 - alpha) * old_avg + (alpha * measured_load_w)
            self.profiles[p_type][hour] = round(new_avg, 1)

            self.days_learned = max(1, self.total_samples // (24 * 12))
            if self.days_learned <= 3:
                self.learning_stage = "INITIAL_LEARNING"
                self.confidence_percent = min(50.0, 25.0 + (self.days_learned * 8.0))
            elif self.days_learned <= 14:
                self.learning_stage = "ACTIVE_LEARNING"
                self.confidence_percent = min(85.0, 50.0 + (self.days_learned * 2.5))
            else:
                self.learning_stage = "EXPERT_ADAPTIVE"
                self.confidence_percent = min(98.5, 85.0 + (self.days_learned * 0.5))

            if self.total_samples % 10 == 0:
                self._persist_learning_state(p_type, hour, new_avg, current_samples)

    def _persist_learning_state(self, p_type: str, hour: int, avg_w: float, samples: int):
        try:
            conn = get_db_connection()
            cursor = conn.cursor()
            cursor.execute(
                "INSERT OR REPLACE INTO ai_profiles (profile_type, hour, avg_power_w, sample_count, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)",
                (p_type, hour, avg_w, samples)
            )
            cursor.execute("INSERT OR REPLACE INTO ai_learning_state (key, value) VALUES ('total_samples', ?)", (str(self.total_samples),))
            cursor.execute("INSERT OR REPLACE INTO ai_learning_state (key, value) VALUES ('days_learned', ?)", (str(self.days_learned),))
            cursor.execute("INSERT OR REPLACE INTO ai_learning_state (key, value) VALUES ('learning_stage', ?)", (str(self.learning_stage),))
            cursor.execute("INSERT OR REPLACE INTO ai_learning_state (key, value) VALUES ('confidence_percent', ?)", (f"{self.confidence_percent:.1f}",))
            conn.commit()
            conn.close()
        except Exception:
            pass

    def get_predicted_load_curve(self, target_date: date) -> list[float]:
        p_type = SlovakCalendar.get_day_type(target_date)
        with self.lock:
            return list(self.profiles.get(p_type, self.DEFAULT_PROFILES[p_type]))

    def get_predicted_load_now(self, target_dt: datetime = None) -> float:
        if target_dt is None:
            target_dt = datetime.now()
        curve = self.get_predicted_load_curve(target_dt.date())
        return curve[target_dt.hour]


# ==============================================================================
# 4. PREDIKCIA SOLÁRNEHO OSVITU A VÝROBY FVE
# ==============================================================================
class SolarProductionPredictor:
    LATITUDE = 48.7

    def __init__(self, installed_kwp: float = 5.0):
        self.installed_kwp = installed_kwp
        self.recalibration_factor = 1.0
        self.last_recalibrated_hour = -1

    def calculate_theoretical_solar_curve(self, target_date: date, kwp: float = None) -> list[float]:
        if kwp is None: kwp = self.installed_kwp
        day_of_year = target_date.timetuple().tm_yday
        declination = 23.45 * math.sin(math.radians((360 / 365) * (day_of_year - 81)))
        dec_rad = math.radians(declination)
        lat_rad = math.radians(self.LATITUDE)

        cos_omega = -math.tan(lat_rad) * math.tan(dec_rad)
        cos_omega = max(-1.0, min(1.0, cos_omega))
        sunset_hour_angle = math.degrees(math.acos(cos_omega))
        day_length_hours = (2 * sunset_hour_angle) / 15.0

        solar_noon_hour = 12.5
        peak_solar_efficiency = 0.82

        hourly_pv_watts = []
        for h in range(24):
            time_diff = abs(h + 0.5 - solar_noon_hour)
            if time_diff < (day_length_hours / 2.0):
                elevation_factor = math.cos(math.radians((time_diff / (day_length_hours / 2.0)) * 90))
                elevation_factor = max(0.0, elevation_factor ** 1.3)
                estimated_power = kwp * 1000.0 * peak_solar_efficiency * elevation_factor
                hourly_pv_watts.append(round(estimated_power, 1))
            else:
                hourly_pv_watts.append(0.0)

        return hourly_pv_watts

    def recalibrate_with_actual_telemetry(self, current_hour: int, measured_pv_w: float, target_date: date):
        if current_hour < 6 or current_hour > 20: return
        theoretical_curve = self.calculate_theoretical_solar_curve(target_date)
        theo_val = theoretical_curve[current_hour]
        if theo_val > 300.0:
            ratio = max(0.1, min(1.25, measured_pv_w / theo_val))
            self.recalibration_factor = round((0.7 * self.recalibration_factor) + (0.3 * ratio), 2)
            self.last_recalibrated_hour = current_hour

    def get_adjusted_prediction(self, target_date: date) -> list[float]:
        base_curve = self.calculate_theoretical_solar_curve(target_date)
        return [round(w * self.recalibration_factor, 1) for w in base_curve]


# ==============================================================================
# 5. MULTI-OBJEKTÍVNY OPTIMALIZAČNÝ ROZHODOVACÍ STROM S PODPOROU DOVOLENKY
# ==============================================================================
class AiOptimizer:
    def __init__(self):
        self.battery_capacity_kwh = 10.0
        self.min_soc = 15.0
        self.max_soc = 95.0
        self.pv_installed_kwp = 5.0
        
        self.installation_type = "HOME" 
        self.allow_grid_export = False  

        # Dovolenkový režim s pred-návratovou prípravou
        self.holiday_mode_enabled = False
        self.holiday_mode_until = ""
        self.holiday_mode_preheat_hours = 6
        self.holiday_mode_target_temp = 22.0
        self.holiday_mode_target_boiler = 50.0

        self.negative_price_protect = True
        self.negative_price_threshold = 0.0
        self.negative_price_charge_grid = True

        self.precharge_enabled = True
        self.precharge_target_soc = 80.0
        self.precharge_price_ratio = 0.75
        
        self.self_consumption_priority = True
        
        self.peak_export_enabled = True
        self.peak_price_ratio = 1.35
        self.peak_export_min_soc = 70.0

        self.thermal_protection_enabled = True
        self.max_temp_limit = 65.0

        self.custom_rules = []
        self.third_party_devices = []
        self._load_config_and_custom_rules()

    def _load_config_and_custom_rules(self):
        try:
            init_db()
            conn = get_db_connection()
            cursor = conn.cursor()
            
            cursor.execute("""
                SELECT key, value FROM system_settings 
                WHERE key IN (
                    'installation_type', 'allow_grid_export', 
                    'holiday_mode_enabled', 'holiday_mode_until', 
                    'holiday_mode_preheat_hours', 'holiday_mode_target_temp', 
                    'holiday_mode_target_boiler'
                )
            """)
            for r in cursor.fetchall():
                k, v = r['key'], r['value']
                if k == 'installation_type': self.installation_type = v
                elif k == 'allow_grid_export': self.allow_grid_export = (v == '1')
                elif k == 'holiday_mode_enabled': self.holiday_mode_enabled = (v == '1')
                elif k == 'holiday_mode_until': self.holiday_mode_until = v if v else ""
                elif k == 'holiday_mode_preheat_hours': self.holiday_mode_preheat_hours = int(float(v))
                elif k == 'holiday_mode_target_temp': self.holiday_mode_target_temp = float(v)
                elif k == 'holiday_mode_target_boiler': self.holiday_mode_target_boiler = float(v)

            cursor.execute("SELECT key, value FROM ai_learning_state")
            for r in cursor.fetchall():
                k, v = r['key'], r['value']
                if k == 'battery_capacity_kwh': self.battery_capacity_kwh = float(v)
                elif k == 'battery_min_soc': self.min_soc = float(v)
                elif k == 'battery_max_soc': self.max_soc = float(v)
                elif k == 'pv_installed_kwp': self.pv_installed_kwp = float(v)
                elif k == 'negative_price_protect': self.negative_price_protect = (v == '1')
                elif k == 'negative_price_threshold': self.negative_price_threshold = float(v)
                elif k == 'negative_price_charge_grid': self.negative_price_charge_grid = (v == '1')
                elif k == 'precharge_enabled': self.precharge_enabled = (v == '1')
                elif k == 'precharge_target_soc': self.precharge_target_soc = float(v)
                elif k == 'precharge_price_ratio': self.precharge_price_ratio = float(v)
                elif k == 'self_consumption_priority': self.self_consumption_priority = (v == '1')
                elif k == 'peak_export_enabled': self.peak_export_enabled = (v == '1')
                elif k == 'peak_price_ratio': self.peak_price_ratio = float(v)
                elif k == 'peak_export_min_soc': self.peak_export_min_soc = float(v)
                elif k == 'max_temp_limit': self.max_temp_limit = float(v)

            cursor.execute("SELECT id, name, enabled, condition_type, condition_params, action_type, priority FROM ai_custom_rules ORDER BY priority ASC, id ASC")
            rules = []
            for row in cursor.fetchall():
                try: params = json.loads(row['condition_params'])
                except Exception: params = {}
                rules.append({
                    "id": row['id'], "name": row['name'], "enabled": bool(row['enabled']),
                    "condition_type": row['condition_type'], "condition_params": params,
                    "action_type": row['action_type'], "priority": row['priority']
                })
            self.custom_rules = rules

            cursor.execute("SELECT id, name, category, protocol, ip_address, channel, power_w, is_enabled, is_active, smart_trigger, trigger_params, total_kwh, total_saved_eur FROM third_party_devices")
            devs = []
            for d in cursor.fetchall():
                try: t_params = json.loads(d['trigger_params'])
                except Exception: t_params = {}
                devs.append({
                    "id": d['id'], "name": d['name'], "category": d['category'], "protocol": d['protocol'],
                    "ip_address": d['ip_address'], "channel": d['channel'], "power_w": float(d['power_w']),
                    "is_enabled": bool(d['is_enabled']), "is_active": bool(d['is_active']),
                    "smart_trigger": d['smart_trigger'], "trigger_params": t_params,
                    "total_kwh": float(d['total_kwh']), "total_saved_eur": float(d['total_saved_eur'])
                })
            self.third_party_devices = devs
            conn.close()
        except Exception as e:
            print(f"[AI OPTIMIZER CONFIG LOAD ERROR]: {e}")

    def get_holiday_state(self, current_dt: datetime) -> str:
        """Určí stav/fázu dovolenky: 'INACTIVE', 'STANDBY' alebo 'PREPARATION'."""
        if not self.holiday_mode_enabled or not self.holiday_mode_until:
            return 'INACTIVE'
        try:
            until_dt = datetime.strptime(self.holiday_mode_until, "%Y-%m-%d %H:%M:%S")
        except ValueError:
            try:
                until_dt = datetime.strptime(self.holiday_mode_until, "%Y-%m-%d")
                until_dt = until_dt.replace(hour=23, minute=59, second=59)
            except ValueError:
                return 'INACTIVE'

        if current_dt > until_dt:
            return 'INACTIVE'

        time_left = until_dt - current_dt
        prep_window = timedelta(hours=self.holiday_mode_preheat_hours)

        if time_left <= prep_window:
            return 'PREPARATION'
        return 'STANDBY'

    def evaluate_third_party_devices(self, okte_price: float, pv_surplus_w: float, is_valley: bool, holiday_state: str = 'INACTIVE') -> list[dict]:
        actions = []
        for dev in self.third_party_devices:
            if not dev.get("is_enabled", True):
                continue

            trigger = dev.get("smart_trigger", "NEGATIVE_AND_SURPLUS")
            params = dev.get("trigger_params", {})
            should_run = False
            reason = ""

            if holiday_state == 'STANDBY':
                min_surplus_standby = float(params.get("min_pv_surplus_w", 1200.0)) + 800.0
                if pv_surplus_w >= min_surplus_standby:
                    should_run = True
                    reason = f"Dovolenkový STANDBY: Extrémny prebytok soláru ({pv_surplus_w:.0f} W)."
                else:
                    should_run = False
                    reason = "Dovolenkový STANDBY: Zariadenie odstavené."

            elif holiday_state == 'PREPARATION':
                if dev.get("category") == "BOILER":
                    should_run = True
                    reason = f"Dovolenková PRÍPRAVA: Nahrievam vodu na príchod na cieľovú teplotu {self.holiday_mode_target_boiler:.0f}°C."
                elif dev.get("category") == "HEATPUMP":
                    should_run = True
                    reason = f"Dovolenková PRÍPRAVA: Kúrenie spustené na predhriatie domu na komfortných {self.holiday_mode_target_temp:.1f}°C."
                else:
                    if okte_price <= 15.0 or pv_surplus_w >= float(params.get("min_pv_surplus_w", 1200.0)):
                        should_run = True
                        reason = "Dovolenková PRÍPRAVA: Povolené počas nábehu."

            else:
                if trigger == "NEGATIVE_AND_SURPLUS":
                    min_surplus = float(params.get("min_pv_surplus_w", 1200.0))
                    max_price = float(params.get("max_price", 30.0))

                    if okte_price <= 0.0:
                        should_run = True
                        reason = f"Záporná cena ({okte_price:.2f} €/MWh). Zohriatie bojlera zadarmo."
                    elif pv_surplus_w >= min_surplus:
                        should_run = True
                        reason = f"Prebytok FVE výroby ({pv_surplus_w:.0f} W)."
                    elif is_valley and okte_price <= max_price:
                        should_run = True
                        reason = f"Nočná cenová dolina ({okte_price:.2f} €/MWh)."

                elif trigger == "ONLY_NEGATIVE_PRICE" and okte_price <= 0.0:
                    should_run = True
                    reason = "Spustené výhradne pri zápornej cene."

                elif trigger == "ONLY_SOLAR_SURPLUS" and pv_surplus_w >= float(params.get("min_pv_surplus_w", 1000.0)):
                    should_run = True
                    reason = "Spustené zo solárnych prebytkov."

            actions.append({
                "device_id": dev["id"], "name": dev["name"], "category": dev["category"],
                "should_run": should_run, "power_w": dev["power_w"],
                "reason": reason if should_run else ("Dovolenka STANDBY: Blokované" if holiday_state == 'STANDBY' else "V kľudovom stave.")
            })
        return actions

    def evaluate_custom_rules(
        self, current_dt: datetime, battery_soc: float, live_pv_power_w: float,
        live_house_load_w: float, okte_current_price: float, day_type: str
    ) -> dict | None:
        for rule in self.custom_rules:
            if not rule.get("enabled", True): continue
            cond_type = rule.get("condition_type", "")
            params = rule.get("condition_params", {})
            action_type = rule.get("action_type", "")
            rule_name = rule.get("name", "Vlastné pravidlo")

            matched = False
            if cond_type == "PRICE_BELOW" and okte_current_price <= float(params.get("price", 0.0)):
                matched = True
            elif cond_type == "PRICE_ABOVE" and okte_current_price >= float(params.get("price", 100.0)) and battery_soc >= float(params.get("min_soc", 20.0)):
                matched = True
            elif cond_type == "TIME_WINDOW":
                start_h = int(params.get("start_hour", 0))
                end_h = int(params.get("end_hour", 6))
                if params.get("day_type", "ALL") in ["ALL", day_type] and start_h <= current_dt.hour <= end_h:
                    matched = True
            elif cond_type == "SOC_BELOW" and battery_soc <= float(params.get("soc", 20.0)):
                matched = True

            if matched:
                if action_type == "FORCE_CHARGE":
                    return {
                        "action": "CUSTOM_FORCE_CHARGE", "inverter_target": "ON", "mode_label": f"Vlastné: {rule_name}",
                        "reason": f"Vynútené nabíjanie batérie (SoC: {battery_soc:.0f}%, Cena: {okte_current_price:.2f} EUR).",
                        "charge_rate": 100, "prevent_grid_export": True
                    }
                elif action_type == "EXPORT_GRID":
                    return {
                        "action": "CUSTOM_EXPORT_GRID", "inverter_target": "ON", "mode_label": f"Vlastné: {rule_name}",
                        "reason": f"Export energie do siete (Cena: {okte_current_price:.2f} EUR).",
                        "charge_rate": 100, "prevent_grid_export": False
                    }
                elif action_type == "TURN_OFF":
                    return {
                        "action": "CUSTOM_TURN_OFF", "inverter_target": "OFF", "mode_label": f"Vlastné: {rule_name}",
                        "reason": "Menič vypnutý.", "charge_rate": 0, "prevent_grid_export": True
                    }
                elif action_type == "SELF_CONSUMPTION":
                    return {
                        "action": "CUSTOM_SELF_CONSUMPTION", "inverter_target": "ON", "mode_label": f"Vlastné: {rule_name}",
                        "reason": "Riadenie na vlastnú spotrebu.", "charge_rate": 50, "prevent_grid_export": True
                    }
        return None

    def evaluate_action(
        self, current_dt: datetime, battery_soc: float, inverter_temp: float,
        live_pv_power_w: float, live_house_load_w: float, okte_current_price: float,
        okte_stats: dict, predicted_load_24h: list[float], predicted_pv_24h: list[float],
        manual_override: str = "AUTO"
    ) -> dict:
        hour = current_dt.hour
        avg_price = okte_stats.get("avg", 80.0)
        peak_hours = okte_stats.get("peak_hours", [18, 19, 20])
        valley_hours = okte_stats.get("valley_hours", [2, 3, 4, 5])
        day_type = SlovakCalendar.get_day_type(current_dt)

        if manual_override == "ON":
            return {"action": "MANUAL_ON", "inverter_target": "ON", "mode_label": "Manuálne zapnuté", "reason": "Užívateľ manuálne aktivoval prevádzku.", "charge_rate": 100, "prevent_grid_export": False}
        elif manual_override == "OFF":
            return {"action": "MANUAL_OFF", "inverter_target": "OFF", "mode_label": "Manuálne vypnuté", "reason": "Užívateľ manuálne vypol prevádzku striedača.", "charge_rate": 0, "prevent_grid_export": True}

        if self.thermal_protection_enabled and inverter_temp >= self.max_temp_limit:
            return {"action": "THERMAL_PROTECTION_THROTTLE", "inverter_target": "OFF", "mode_label": "Teplotná ochrana", "reason": f"Kritická teplota striedača ({inverter_temp:.1f}°C).", "charge_rate": 0, "prevent_grid_export": True}

        # Určenie fázy dovolenky
        holiday_state = self.get_holiday_state(current_dt)

        if holiday_state == 'INACTIVE':
            custom_decision = self.evaluate_custom_rules(current_dt, battery_soc, live_pv_power_w, live_house_load_w, okte_current_price, day_type)
            if custom_decision: return custom_decision

        # ======================================================================
        # DOVOLENKOVÁ STRATEGICKÁ LOGIKA
        # ======================================================================
        if holiday_state == 'STANDBY':
            real_standby_load = min(150.0, live_house_load_w) 
            if live_pv_power_w > real_standby_load:
                if battery_soc < self.max_soc:
                    return {
                        "action": "HOLIDAY_PV_CHARGE", "inverter_target": "ON", "mode_label": "Dovolenka STANDBY - Solárne nabíjanie",
                        "reason": f"Slnko pokrýva standby spotrebu domu ({real_standby_load:.0f}W). Prebytok ukladám do batérie ({battery_soc:.0f}%).",
                        "charge_rate": 100, "prevent_grid_export": True
                    }
                else:
                    return {
                        "action": "HOLIDAY_PV_STANDBY_FULL", "inverter_target": "ON", "mode_label": "Dovolenka STANDBY - Batéria plná",
                        "reason": "Dovolenkový standby. Akumulátor je plný.",
                        "charge_rate": 0, "prevent_grid_export": not self.allow_grid_export
                    }
            else:
                if battery_soc > self.min_soc:
                    return {
                        "action": "HOLIDAY_BATTERY_DISCHARGE", "inverter_target": "ON", "mode_label": "Dovolenka STANDBY - Beh z batérie",
                        "reason": f"Standby spotreba ({live_house_load_w:.0f}W) je kompletne napájaná z batérie (SoC: {battery_soc:.0f}%).",
                        "charge_rate": 0, "prevent_grid_export": True
                    }
                else:
                    return {
                        "action": "HOLIDAY_GRID_STANDBY", "inverter_target": "ON", "mode_label": "Dovolenka STANDBY - Sieť",
                        "reason": f"Batéria na min. limite ({self.min_soc:.0f}%). Chladnička beží zo siete.",
                        "charge_rate": 0, "prevent_grid_export": False
                    }

        elif holiday_state == 'PREPARATION':
            is_cheap = (hour in valley_hours) or (okte_current_price < avg_price)
            if battery_soc < 80.0:
                if live_pv_power_w > 200:
                    return {
                        "action": "HOLIDAY_PREP_SOLAR_CHARGE", "inverter_target": "ON", "mode_label": "Dovolenková PRÍPRAVA - Nabíjanie FVE",
                        "reason": f"Fáza prípravy na návrat. Solár dobíja batériu (aktuálne {battery_soc:.0f}%, cieľ 80%).",
                        "charge_rate": 100, "prevent_grid_export": True
                    }
                elif is_cheap:
                    return {
                        "action": "HOLIDAY_PREP_GRID_CHARGE", "inverter_target": "ON", "mode_label": "Dovolenková PRÍPRAVA - Dobitie zo siete",
                        "reason": f"Fáza prípravy na návrat. Dobíjam batériu v nízkej tarife ({okte_current_price:.2f} EUR) na 80%.",
                        "charge_rate": 80, "prevent_grid_export": True
                    }

            if live_pv_power_w > live_house_load_w:
                return {
                    "action": "HOLIDAY_PREP_RUN_SURPLUS", "inverter_target": "ON", "mode_label": "Dovolenková PRÍPRAVA - Solárny ohrev",
                    "reason": f"Nábeh kúrenia a bojlera z prebytkov fotovoltiky (výroba {live_pv_power_w:.0f}W).",
                    "charge_rate": 0, "prevent_grid_export": True
                }
            else:
                if battery_soc > self.min_soc:
                    return {
                        "action": "HOLIDAY_PREP_RUN_BATTERY", "inverter_target": "ON", "mode_label": "Dovolenková PRÍPRAVA - Kúrenie z batérie",
                        "reason": "Príprava komfortu pred príchodom. Ohrev vody a kúrenie beží z batérie.",
                        "charge_rate": 0, "prevent_grid_export": True
                    }
                else:
                    return {
                        "action": "HOLIDAY_PREP_RUN_GRID", "inverter_target": "ON", "mode_label": "Dovolenková PRÍPRAVA - Kúrenie zo siete",
                        "reason": "Predhrievanie domu a vody prebieha zo siete, batéria je prázdna.",
                        "charge_rate": 0, "prevent_grid_export": False
                    }

        # ======================================================================
        # ŠTANDARDNÝ REŽIM (Mimo dovolenky)
        # ======================================================================
        if self.negative_price_protect and okte_current_price <= self.negative_price_threshold:
            if self.negative_price_charge_grid and battery_soc < self.max_soc:
                return {"action": "CHARGE_NEGATIVE_PRICE", "inverter_target": "ON", "mode_label": "Záporná cena - Nabíjanie", "reason": f"Záporná cena trhu ({okte_current_price:.2f} EUR/MWh). Nabíjanie batérie zo siete.", "charge_rate": 100, "prevent_grid_export": True}
            else:
                return {"action": "ZERO_FEED_IN_NEGATIVE_PRICE", "inverter_target": "OFF", "mode_label": "Záporná cena - Zákaz pretokov", "reason": f"Záporná cena ({okte_current_price:.2f} EUR/MWh) a plná batéria.", "charge_rate": 0, "prevent_grid_export": True}

        if self.precharge_enabled:
            is_cheap_valley = (hour in valley_hours) or (okte_current_price <= (avg_price * self.precharge_price_ratio))
            if (2 <= hour <= 5) and is_cheap_valley and (battery_soc < self.precharge_target_soc):
                return {"action": "PRECHARGE_MORNING_PEAK", "inverter_target": "ON", "mode_label": "Nočné prednabíjanie", "reason": f"Lacná elektrina ({okte_current_price:.2f} EUR). Prednabíjanie batérie na cieľ {self.precharge_target_soc:.0f}%.", "charge_rate": 80, "prevent_grid_export": True}

        can_export = (self.installation_type == "BUSINESS") or self.allow_grid_export
        if self.peak_export_enabled and can_export:
            is_peak_price = (hour in peak_hours) or (okte_current_price >= (avg_price * self.peak_price_ratio))
            if is_peak_price and (battery_soc > self.peak_export_min_soc):
                return {"action": "EXPORT_PEAK_ARBITRAGE", "inverter_target": "ON", "mode_label": "Predaj v špičke (Arbitráž)", "reason": f"Cenová špička ({okte_current_price:.2f} EUR/MWh). Export prebytkov do siete so ziskom.", "charge_rate": 100, "prevent_grid_export": False}

        if live_pv_power_w > live_house_load_w:
            if battery_soc < self.max_soc:
                return {"action": "SELF_CONSUMPTION_CHARGING", "inverter_target": "ON", "mode_label": "Vlastná spotreba (Nabíjanie FVE)", "reason": f"FVE vyrába {live_pv_power_w:.0f}W, prebytky smerujú do batérie ({battery_soc:.0f}%).", "charge_rate": 100, "prevent_grid_export": not can_export}
            else:
                return {"action": "SELF_CONSUMPTION_EXPORT_SURPLUS", "inverter_target": "ON", "mode_label": "Vlastná spotreba (Prebytky)", "reason": "Batéria je plná. Prebytočná výroba smeruje do bojlera / siete.", "charge_rate": 0, "prevent_grid_export": not can_export}
        else:
            if battery_soc > self.min_soc:
                return {"action": "SELF_CONSUMPTION_BATTERY_DISCHARGE", "inverter_target": "ON", "mode_label": "Vlastná spotreba (Z batérie)", "reason": f"Spotreba domu ({live_house_load_w:.0f}W) je pokrývaná z batérie (SoC: {battery_soc:.0f}%).", "charge_rate": 0, "prevent_grid_export": True}
            else:
                return {"action": "STANDBY_GRID_MINIMAL", "inverter_target": "ON", "mode_label": "Štandardný úsporný režim", "reason": f"Batéria na minimálnom limite ({self.min_soc:.0f}%).", "charge_rate": 0, "prevent_grid_export": False}

    def generate_24h_energy_plan(
        self, target_date: date, hourly_prices: list[float], predicted_load_24h: list[float], predicted_pv_24h: list[float]
    ) -> list[dict]:
        stats = OktePriceService().calculate_price_statistics(hourly_prices)
        simulated_soc = 50.0
        plan = []

        for h in range(24):
            price = hourly_prices[h] if h < len(hourly_prices) else 80.0
            load_w = predicted_load_24h[h] if h < len(predicted_load_24h) else 500.0
            pv_w = predicted_pv_24h[h] if h < len(predicted_pv_24h) else 0.0

            dt_sim = datetime(target_date.year, target_date.month, target_date.day, h, 0)
            res = self.evaluate_action(
                current_dt=dt_sim, battery_soc=simulated_soc, inverter_temp=35.0,
                live_pv_power_w=pv_w, live_house_load_w=load_w, okte_current_price=price,
                okte_stats=stats, predicted_load_24h=predicted_load_24h, predicted_pv_24h=predicted_pv_24h
            )

            net_power = pv_w - load_w
            if "CHARGE" in res["action"]: simulated_soc = min(self.max_soc, simulated_soc + 20.0)
            elif "DISCHARGE" in res["action"]: simulated_soc = max(self.min_soc, simulated_soc - 15.0)
            elif net_power > 0: simulated_soc = min(self.max_soc, simulated_soc + (net_power / 3000.0 * 20.0))

            plan.append({
                "hour": h, "hour_label": f"{h:02d}:00", "predicted_load_w": round(load_w, 0),
                "predicted_pv_w": round(pv_w, 0), "okte_price_eur": round(price, 2),
                "action": res["action"], "mode_label": res["mode_label"], "reason": res["reason"],
                "simulated_soc": round(simulated_soc, 0)
            })
        return plan


# ==============================================================================
# 6. CENTRÁLNA AI SLUŽBA (SINGLETON AI SERVICE)
# ==============================================================================
class AiService:
    _instance = None
    _lock = threading.Lock()

    @classmethod
    def get_instance(cls):
        with cls._lock:
            if cls._instance is None:
                cls._instance = cls()
            return cls._instance

    def __init__(self):
        self.calendar = SlovakCalendar()
        self.okte = OktePriceService()
        self.learner = ConsumptionLearner()
        self.solar_predictor = SolarProductionPredictor(installed_kwp=5.0)
        self.optimizer = AiOptimizer()

        self.last_decision = {}
        self.current_plan = []
        self.ai_logs = ["🤖 [AI EMS] Jadro inteligentného riadenia FVE aktivované."]
        self.running = True

        threading.Thread(target=self._background_worker_loop, daemon=True).start()

    def add_log(self, msg: str):
        ts = datetime.now().strftime("%H:%M:%S")
        log_line = f"[{ts}] {msg}"
        print(f"[AI CORE] {log_line}")
        self.ai_logs.append(log_line)
        if len(self.ai_logs) > 50: self.ai_logs.pop(0)

    def create_notification(self, title: str, message: str, type_str: str = "info", tag: str = "EMS"):
        try:
            conn = get_db_connection()
            cursor = conn.cursor()
            cursor.execute(
                "INSERT INTO notifications (title, message, type, tag, is_read) VALUES (?, ?, ?, ?, 0)",
                (title, message, type_str, tag)
            )
            conn.commit()
            conn.close()
        except Exception:
            pass

    def learn_from_telemetry(self, power_ac: float, battery_soc: float, temp: float = 30.0):
        now = datetime.now()
        estimated_house_load = self.learner.get_predicted_load_now(now)
        if power_ac > 0:
            self.solar_predictor.recalibrate_with_actual_telemetry(now.hour, power_ac, now.date())
        self.learner.record_telemetry_sample(estimated_house_load, now)

    def evaluate_live_state(self, battery_soc: float, inverter_temp: float, live_power_ac: float, manual_override: str = "AUTO") -> dict:
        now = datetime.now()
        schedule_data = self.okte.get_schedule_for_today_and_tomorrow()
        today_hourly = schedule_data["today_hourly"]
        stats = schedule_data["stats"]
        current_price = schedule_data["current_price_eur_mwh"]

        predicted_load = self.learner.get_predicted_load_curve(now.date())
        predicted_pv = self.solar_predictor.get_adjusted_prediction(now.date())

        decision = self.optimizer.evaluate_action(
            current_dt=now, battery_soc=battery_soc, inverter_temp=inverter_temp,
            live_pv_power_w=live_power_ac, live_house_load_w=predicted_load[now.hour],
            okte_current_price=current_price, okte_stats=stats,
            predicted_load_24h=predicted_load, predicted_pv_24h=predicted_pv,
            manual_override=manual_override
        )

        holiday_state = self.optimizer.get_holiday_state(now)
        pv_surplus = max(0.0, live_power_ac - predicted_load[now.hour])
        is_valley = (now.hour in stats.get("valley_hours", []))
        third_party_eval = self.optimizer.evaluate_third_party_devices(current_price, pv_surplus, is_valley, holiday_state)

        decision["day_info"] = SlovakCalendar.get_full_day_details(now)
        decision["okte_current_price_eur"] = current_price
        decision["okte_stats"] = stats
        decision["learning_stage"] = self.learner.learning_stage
        decision["confidence_percent"] = self.learner.confidence_percent
        decision["days_learned"] = self.learner.days_learned
        decision["total_samples"] = self.learner.total_samples
        decision["installation_type"] = self.optimizer.installation_type
        decision["third_party_actions"] = third_party_eval
        decision["holiday_state"] = holiday_state
        decision["holiday_mode_until"] = self.optimizer.holiday_mode_until
        decision["holiday_mode_target_temp"] = self.optimizer.holiday_mode_target_temp
        decision["holiday_mode_target_boiler"] = self.optimizer.holiday_mode_target_boiler

        if current_price <= 0.0 and self.last_decision.get("okte_current_price_eur", 50.0) > 0.0:
            self.create_notification("Záporná cena elektriny!", f"Cena na OKTE klesla na {current_price:.2f} €/MWh. Export bol obmedzený.", "warning", "NEGATIVE_PRICE")
        elif inverter_temp >= self.optimizer.max_temp_limit:
            self.create_notification("Vysoká teplota striedača", f"Striedač dosiahol teplotu {inverter_temp:.1f}°C. Výkon bol preventívne obmedzený.", "error", "HARDWARE")

        self.last_decision = decision
        return decision

    def recalculate_plan(self):
        now = datetime.now()
        schedule_data = self.okte.get_schedule_for_today_and_tomorrow()
        today_hourly = schedule_data["today_hourly"]
        predicted_load = self.learner.get_predicted_load_curve(now.date())
        predicted_pv = self.solar_predictor.get_adjusted_prediction(now.date())

        plan = self.optimizer.generate_24h_energy_plan(
            target_date=now.date(), hourly_prices=today_hourly,
            predicted_load_24h=predicted_load, predicted_pv_24h=predicted_pv
        )
        self.current_plan = plan

        try:
            conn = get_db_connection()
            cursor = conn.cursor()
            date_str = now.strftime("%Y-%m-%d")
            for item in plan:
                cursor.execute(
                    "INSERT OR REPLACE INTO ai_energy_plan (date_str, hour, predicted_load_w, predicted_pv_w, okte_price, target_mode, action, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    (date_str, item["hour"], item["predicted_load_w"], item["predicted_pv_w"], item["okte_price_eur"], item["mode_label"], item["action"], item["reason"])
                )
            conn.commit()
            conn.close()
        except Exception:
            pass

        self.add_log(f"Plán optimalizácie prepočítaný ({len(plan)} hodín, typ dňa: {SlovakCalendar.get_day_type(now)}).")

    def _background_worker_loop(self):
        time.sleep(3)
        self.recalculate_plan()

        last_hourly_recalc = -1
        while self.running:
            try:
                now = datetime.now()
                if now.hour != last_hourly_recalc:
                    last_hourly_recalc = now.hour
                    self.recalculate_plan()
            except Exception as e:
                print(f"[AI WORKER ERROR]: {e}")
            time.sleep(60)