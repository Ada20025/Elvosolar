# database.py
import sqlite3
import os
import hashlib

DB_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "database.db")

def get_db_connection():
    conn = sqlite3.connect(DB_FILE, timeout=10)
    conn.row_factory = sqlite3.Row
    return conn

def db_execute(query: str, params: tuple = ()) -> list:
    """Univerzálny bezpečný databázový wrapper."""
    try:
        with get_db_connection() as conn:
            cursor = conn.cursor()
            cursor.execute(query, params)
            conn.commit()
            return cursor.fetchall()
    except Exception as e:
        print(f"[SQL CHYBA CORE]: {e}")
        return []

def hash_password(password: str) -> str:
    return hashlib.sha256(password.encode('utf-8')).hexdigest()

def verify_password(hashed: str, input_password: str) -> bool:
    return hashed == hash_password(input_password)

def init_db():
    conn = get_db_connection()
    try:
        cursor = conn.cursor()
        cursor.execute("PRAGMA journal_mode=WAL;")
        
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS system_settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )
        """)
        
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE,
            email TEXT,
            password TEXT,
            role TEXT
        )
        """)
        
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            serial_number TEXT UNIQUE,
            name TEXT,
            password TEXT,
            brand_id TEXT,
            category_id TEXT,
            model_id TEXT,
            slave_id INTEGER
        )
        """)
        
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS telemetry (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            power_ac REAL,
            battery_soc REAL
        )
        """)
        
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS ai_profiles (
            profile_type TEXT,
            hour INTEGER,
            avg_power_w REAL,
            min_power_w REAL,
            max_power_w REAL,
            sample_count INTEGER DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (profile_type, hour)
        )
        """)
        
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS ai_learning_state (
            key TEXT PRIMARY KEY,
            value TEXT
        )
        """)
        
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS ai_energy_plan (
            date_str TEXT,
            hour INTEGER,
            predicted_load_w REAL,
            predicted_pv_w REAL,
            okte_price REAL,
            target_mode TEXT,
            action TEXT,
            reason TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (date_str, hour)
        )
        """)
        
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS ai_decision_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            decision TEXT,
            mode TEXT,
            price REAL,
            soc REAL,
            pv_power REAL,
            house_load REAL,
            reason TEXT
        )
        """)
        
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS okte_prices_cache (
            delivery_date TEXT,
            period INTEGER,
            price_eur REAL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (delivery_date, period)
        )
        """)
        
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS ai_custom_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            enabled INTEGER DEFAULT 1,
            condition_type TEXT NOT NULL,
            condition_params TEXT NOT NULL,
            action_type TEXT NOT NULL,
            priority INTEGER DEFAULT 10,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
        """)

        cursor.execute("""
        CREATE TABLE IF NOT EXISTS third_party_devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            category TEXT NOT NULL,
            protocol TEXT DEFAULT 'HTTP',
            ip_address TEXT DEFAULT '',
            channel INTEGER DEFAULT 1,
            power_w REAL DEFAULT 2000.0,
            is_enabled INTEGER DEFAULT 1,
            is_active INTEGER DEFAULT 0,
            smart_trigger TEXT DEFAULT 'NEGATIVE_AND_SURPLUS',
            trigger_params TEXT DEFAULT '{}',
            total_kwh REAL DEFAULT 0.0,
            total_saved_eur REAL DEFAULT 0.0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
        """)

        cursor.execute("""
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            type TEXT DEFAULT 'info',
            tag TEXT DEFAULT 'EMS',
            is_read INTEGER DEFAULT 0
        )
        """)
        
        # Predvolené systémové registre
        cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES ('is_claimed', '0')")
        cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES ('admin_key', 'ELVO-CM5-KEY-2025')")
        cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES ('comm_mode', 'LOCAL_MODBUS')")
        cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES ('active_model', 'AI')")
        cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES ('night_sleep', '1')")
        cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES ('installation_type', 'HOME')")
        cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES ('allow_grid_export', '0')")
        cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES ('cloud_sync_url', 'https://adamdz.alwaysdata.net/api/cloud/sync-telemetry')")
        
        # Dovolenkový režim s pred-návratovou prípravou
        cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES ('holiday_mode_enabled', '0')")
        cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES ('holiday_mode_until', '')")
        cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES ('holiday_mode_preheat_hours', '6')")
        cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES ('holiday_mode_target_temp', '22.0')")
        cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES ('holiday_mode_target_boiler', '50.0')")
        
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('days_learned', '0')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('total_samples', '0')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('learning_stage', 'INITIAL_LEARNING')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('confidence_percent', '25.0')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('battery_capacity_kwh', '10.0')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('battery_min_soc', '15.0')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('battery_max_soc', '95.0')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('pv_installed_kwp', '5.0')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('self_consumption_priority', '1')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('negative_price_protect', '1')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('negative_price_threshold', '0.0')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('negative_price_charge_grid', '1')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('precharge_enabled', '1')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('precharge_target_soc', '80.0')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('precharge_price_ratio', '0.75')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('peak_export_enabled', '1')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('peak_price_ratio', '1.35')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('peak_export_min_soc', '70.0')")
        cursor.execute("INSERT OR IGNORE INTO ai_learning_state (key, value) VALUES ('max_temp_limit', '65.0')")
        
        cursor.execute("""
        INSERT OR IGNORE INTO third_party_devices 
        (id, name, category, protocol, ip_address, channel, power_w, is_enabled, is_active, smart_trigger, trigger_params, total_kwh, total_saved_eur) 
        VALUES (1, 'Bojler TÚV (Ohrev vody)', 'BOILER', 'SHELLY_RELAY', '192.168.1.120', 1, 2200.0, 1, 0, 'NEGATIVE_AND_SURPLUS', '{\"min_pv_surplus_w\": 1500, \"max_price\": 30.0}', 14.5, 4.20)
        """)
        
        cursor.execute("""
        INSERT OR IGNORE INTO notifications (id, title, message, type, tag, is_read)
        VALUES (1, 'Vitajte v systéme ElvoSolar AI', 'Systém inteligentného riadenia FVE bol úspešne inicializovaný.', 'success', 'SYSTEM', 0)
        """)
        
        conn.commit()
    finally:
        conn.close()