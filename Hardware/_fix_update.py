# -*- coding: utf-8 -*-
import io

p = 'update_service.py'
c = io.open(p, 'r', encoding='utf-8').read()

# --- 1) Add modbus_slave_service + network_scan to UPDATE_FILES ---
old_files = u"""UPDATE_FILES = {
    "app.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/app.py","""
new_files = u"""UPDATE_FILES = {
    "app.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/app.py",
    "network_scan.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/network_scan.py",
    "modbus_slave_service.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/modbus_slave_service.py",
    "modbus_scan.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/modbus_scan.py",
    "models_engine.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/models_engine.py",
    "third_party_service.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/third_party_service.py",
    "ble_service.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/ble_service.py","""
assert old_files in c, "UPDATE_FILES not found"
c = c.replace(old_files, new_files, 1)

# --- 2) Add DB migration + pycache cleanup functions ---
old_md5 = u"""def md5_file(filepath):"""
new_md5 = u"""def migrate_database():
    \"\"\"Po update doplni nove tabulky/stlpce do existujucej DB - DATA SA NESTRAJAJU.\"\"\"
    try:
        import sqlite3
        db_path = os.path.join(BASE_DIR, "database.db")
        if not os.path.exists(db_path):
            print("[UPDATER] [DB] database.db neexistuje - vytvori ho app.py pri starte")
            return
        conn = sqlite3.connect(db_path, timeout=10)
        cursor = conn.cursor()

        def table_cols(t):
            cursor.execute(f"PRAGMA table_info({t})")
            return [r[1] for r in cursor.fetchall()]

        def add_col(t, col, definition):
            if col not in table_cols(t):
                cursor.execute(f"ALTER TABLE {t} ADD COLUMN {col} {definition}")
                print(f"[UPDATER] [DB] + {t}.{col}")

        # devices: nove stlpce (SmartLogger, RTU parametre, precision)
        for col, d in [
            ("sub_type", "TEXT DEFAULT ''"),
            ("status", "TEXT DEFAULT 'offline'"),
            ("last_seen", "DATETIME"),
            ("battery_soc", "REAL DEFAULT 0"),
            ("power_ac", "REAL DEFAULT 0"),
            ("temp", "REAL DEFAULT 25.0"),
            ("min_power_pct", "REAL DEFAULT 0"),
            ("max_power_pct", "REAL DEFAULT 100"),
            ("min_okte_price_cz_eur", "REAL DEFAULT 0"),
            ("baud_rate", "INTEGER DEFAULT 9600"),
            ("parity", "TEXT DEFAULT 'none'"),
            ("stop_bits", "INTEGER DEFAULT 1"),
            ("serial_port", "TEXT DEFAULT ''"),
            ("total_saved_eur", "REAL DEFAULT 0"),
            ("total_kwh", "REAL DEFAULT 0"),
            ("manual_override", "TEXT DEFAULT 'AUTO'"),
            ("active_model_id", "TEXT DEFAULT 'AI'"),
            ("night_sleep", "INTEGER DEFAULT 0"),
        ]:
            add_col("devices", col, d)

        # telemetry: rozsirene stlpce
        for col, d in [
            ("temp", "REAL DEFAULT 25.0"),
            ("freq", "REAL DEFAULT 50.0"),
            ("status_msg", "TEXT DEFAULT 'Online'"),
        ]:
            add_col("telemetry", col, d)

        # nove tabulky ak neexistuju
        cursor.execute(\"\"\"CREATE TABLE IF NOT EXISTS cm5_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            serial_number TEXT,
            config_json TEXT,
            status TEXT DEFAULT 'pending',
            result_json TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )\"\"\")
        cursor.execute(\"\"\"CREATE TABLE IF NOT EXISTS system_settings_new_keys (
            key TEXT PRIMARY KEY, value TEXT
        )\"\"")

        # nove system_settings kluce (smartlogger a pod.) - INSERT OR IGNORE nemaze data
        for k, v in [
            ("smartlogger_ip", ""), ("smartlogger_port", "502"),
            ("smartlogger_slave_id", "205"), ("smartlogger_mode", "tcp"),
            ("cm5_registered", "0"), ("rs485_active_port", ""), ("rs485_parity", "N"),
        ]:
            cursor.execute("INSERT OR IGNORE INTO system_settings (key, value) VALUES (?, ?)", (k, v))

        conn.commit()
        conn.close()
        print("[UPDATER] [DB] Migracia hotova - existujuce data zachovane")
    except Exception as e:
        print(f"[UPDATER] [DB] Migracia zlyhala (nevadi, app.py to zopakuje): {e}")


def clean_pycache():
    \"\"\"Zmaze vsetky __pycache__ aby Python nacital cerstvy kod po update.\"\"\"
    removed = 0
    for root, dirs, files in os.walk(BASE_DIR):
        for d in dirs:
            if d == "__pycache__":
                pycache_path = os.path.join(root, d)
                try:
                    import shutil
                    shutil.rmtree(pycache_path)
                    removed += 1
                except Exception:
                    pass
    if removed:
        print(f"[UPDATER] [CACHE] Zmazanych {removed} __pycache__ adresarov")


def md5_file(filepath):"""
assert old_md5 in c, "md5_file anchor not found"
c = c.replace(old_md5, new_md5, 1)

# --- 3) Call migrate + clean_pycache before restart ---
old_restart = u"""            if changed:
                print("\\n[UPDATER] 🔄 Súbory boli aktualizované. Reštartujem app.py...")
                # Zabij app.py aby start.sh mohol reštartovať s novým kódom
                subprocess.run(["pkill", "-f", "python3.*app.py"], capture_output=True)
                sys.exit(0)"""
new_restart = u"""            if changed:
                print("\\n[UPDATER] 🔄 Súbory boli aktualizované.")
                # 1) Migracia DB - doplni nove stlpce, EXISTUJUCE DATA OSTATU
                migrate_database()
                # 2) Zmaz __pycache__ aby sa nacital cerstvy kod
                clean_pycache()
                # 3) Restart app.py - start.sh ho hned spusti znova
                print("[UPDATER] Reštartujem app.py...")
                subprocess.run(["pkill", "-f", "python3.*app.py"], capture_output=True)
                sys.exit(0)"""
assert old_restart in c, "restart block not found"
c = c.replace(old_restart, new_restart, 1)

io.open(p, 'w', encoding='utf-8', newline='').write(c)
print("OK - update_service: migrate DB + clean pycache + restart")
