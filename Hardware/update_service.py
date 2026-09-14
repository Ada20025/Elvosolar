import os
import sys
import time
import hashlib
import subprocess
import urllib.request

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

# Súbory na aktualizáciu: relatívna cesta -> GitHub raw URL
UPDATE_FILES = {
    "app.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/app.py",
    "network_scan.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/network_scan.py",
    "modbus_slave_service.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/modbus_slave_service.py",
    "modbus_scan.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/modbus_scan.py",
    "models_engine.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/models_engine.py",
    "third_party_service.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/third_party_service.py",
    "ble_service.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/ble_service.py",
    "Config.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/Config.py",
    "solar_service.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/solar_service.py",
    "system_service.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/system_service.py",
    "database.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/database.py",
    "ai_engine.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/ai_engine.py",
    "led_service.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/led_service.py",
    "smart_meter_service.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/smart_meter_service.py",
    "update_service.py": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/update_service.py",
    "start.sh": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/start.sh",
    "templates/setup.html": "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/templates/setup.html",
}

CHECK_INTERVAL = 3600  # 1 hodina


def migrate_database():
    """Po update doplni nove tabulky/stlpce do existujucej DB - DATA SA NESTRAJAJU."""
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

        # telemetry: rozsirene stlpce (tabulka moze byt v starsich DB bez temp - iba ak existuje)
        if 'telemetry' in [r[0] for r in cursor.execute("SELECT name FROM sqlite_master WHERE type='table'").fetchall()]:
            for col, d in [
                ("temp", "REAL DEFAULT 25.0"),
                ("freq", "REAL DEFAULT 50.0"),
                ("status_msg", "TEXT DEFAULT 'Online'"),
            ]:
                add_col("telemetry", col, d)

        # nove tabulky ak neexistuju
        cursor.execute("""CREATE TABLE IF NOT EXISTS cm5_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            serial_number TEXT,
            config_json TEXT,
            status TEXT DEFAULT 'pending',
            result_json TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )""")
        cursor.execute("""CREATE TABLE IF NOT EXISTS system_settings_new_keys (
            key TEXT PRIMARY KEY, value TEXT
        )""")

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
    """Zmaze vsetky __pycache__ aby Python nacital cerstvy kod po update."""
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


def md5_file(filepath):
    """Vypočíta MD5 hash súboru."""
    if not os.path.exists(filepath):
        return ""
    h = hashlib.md5()
    try:
        with open(filepath, "rb") as f:
            for chunk in iter(lambda: f.read(4096), b""):
                h.update(chunk)
        return h.hexdigest()
    except Exception:
        return ""


def md5_data(data):
    """Vypočíta MD5 hash dát."""
    return hashlib.md5(data).hexdigest()


def download_file(url):
    """Stiahne súbor z URL a vráti bytes."""
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "ElvoSolar-CM5-Updater"})
        with urllib.request.urlopen(req, timeout=15) as resp:
            return resp.read()
    except Exception as e:
        print(f"[UPDATER] Chyba sťahovania {url}: {e}")
        return None


def check_and_update():
    """Skontroluje všetky súbory a aktualizuje zmenené."""
    any_updated = False

    for rel_path, url in UPDATE_FILES.items():
        local_path = os.path.join(BASE_DIR, rel_path)
        local_hash = md5_file(local_path)

        remote_data = download_file(url)
        if remote_data is None:
            continue

        remote_hash = md5_data(remote_data)

        if local_hash == remote_hash:
            print(f"[UPDATER] {rel_path} — OK (hash zhodný)")
            continue

        print(f"[UPDATER] {rel_path} — NOVÁ VERZIA! Aktualizujem...")

        try:
            # Bezpečnostná kontrola — pre .py súbory overíme syntax
            if rel_path.endswith(".py"):
                compile(remote_data.decode("utf-8"), local_path, "exec")

            # Vytvor priečinok ak neexistuje
            os.makedirs(os.path.dirname(local_path) if os.path.dirname(local_path) else BASE_DIR, exist_ok=True)

            # Backup
            backup_path = local_path + ".bak"
            if os.path.exists(local_path):
                if os.path.exists(backup_path):
                    os.remove(backup_path)
                os.rename(local_path, backup_path)

            # Zápis
            with open(local_path, "wb") as f:
                f.write(remote_data)
                f.flush()
                os.fsync(f.fileno())

            print(f"[UPDATER] ✅ {rel_path} úspešne aktualizovaný!")
            any_updated = True

        except SyntaxError as e:
            print(f"[UPDATER] ❌ CHYBA SYNTAXE v {rel_path}: {e} — súbor NEBOL prepísaný!")
            # Obnov z backupu
            backup_path = local_path + ".bak"
            if os.path.exists(backup_path):
                os.rename(backup_path, local_path)
        except Exception as e:
            print(f"[UPDATER] ❌ Chyba zápisu {rel_path}: {e}")

    return any_updated


def main():
    print("[UPDATER] ElvoSolar Auto-Update Service spustený.")
    print(f"[UPDATER] Kontrola každých {CHECK_INTERVAL // 3600} hodín.")
    print(f"[UPDATER] Zdroj: github.com/Ada20025/Elvosolar")

    while True:
        try:
            print(f"\n[UPDATER] === Kontrola aktualizácií ===")
            changed = check_and_update()

            if changed:
                print("\n[UPDATER] 🔄 Súbory boli aktualizované.")
                # 1) Migracia DB - doplni nove stlpce, EXISTUJUCE DATA OSTATU
                migrate_database()
                # 2) Zmaz __pycache__ aby sa nacital cerstvy kod
                clean_pycache()
                # 3) Restart app.py - start.sh ho hned spusti znova
                print("[UPDATER] Reštartujem app.py...")
                subprocess.run(["pkill", "-f", "python3.*app.py"], capture_output=True)
                sys.exit(0)
            else:
                print("[UPDATER] ✅ Všetko aktuálne.")

        except Exception as e:
            print(f"[UPDATER CHYBA] {e}")

        time.sleep(CHECK_INTERVAL)


if __name__ == "__main__":
    main()
