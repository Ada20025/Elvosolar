import hashlib
import json
import os
import sys
import time
import subprocess
import urllib.request

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

# Štádium updatu pre stavové LED (app.py ho číta a svieti zelenou podľa fázy)
UPDATE_STAGE_FILE = "/tmp/elvo_update_stage.json"

# Značka "update prebehol, ale ešte nie je potvrdené, že appka naozaj nabehla".
# Ak app.py po reštarte nenabehne, start.sh zavolá --rollback-stale a vráti
# predošlé súbory z .bak — CM5 tak nezostane mŕtvy.
PENDING_FILE = "/tmp/elvo_update_pending.json"
PENDING_MAX_AGE = 180  # sekundy — po tomto čase bez potvrdenia sa rollbackne


def set_update_stage(stage):
    """check / download / install / restart — alebo None pre zmazanie."""
    try:
        if stage is None:
            if os.path.exists(UPDATE_STAGE_FILE):
                os.remove(UPDATE_STAGE_FILE)
            return
        with open(UPDATE_STAGE_FILE, "w", encoding="utf-8") as f:
            json.dump({"stage": stage, "at": time.time()}, f)
    except Exception:
        pass

# Súbory na aktualizáciu: relatívna cesta -> GitHub raw URL
# POZOR: zdrojom je TEN ISTÝ repo, z ktorého ťahajú aj update.sh / update_now.sh /
# clean.sh / reset.sh (Ada20025/Elvosolar). Iný repo = CM5 by sťahoval starý kód.
RAW_BASE = "https://raw.githubusercontent.com/Ada20025/Elvosolar/main/Hardware/"
UPDATE_FILES = {
    "app.py": RAW_BASE + "app.py",
    "network_scan.py": RAW_BASE + "network_scan.py",
    "modbus_slave_service.py": RAW_BASE + "modbus_slave_service.py",
    "modbus_scan.py": RAW_BASE + "modbus_scan.py",
    "models_engine.py": RAW_BASE + "models_engine.py",
    "third_party_service.py": RAW_BASE + "third_party_service.py",
    "ble_service.py": RAW_BASE + "ble_service.py",
    "Config.py": RAW_BASE + "Config.py",
    "solar_service.py": RAW_BASE + "solar_service.py",
    "system_service.py": RAW_BASE + "system_service.py",
    "database.py": RAW_BASE + "database.py",
    "ai_engine.py": RAW_BASE + "ai_engine.py",
    "led_service.py": RAW_BASE + "led_service.py",
    "smart_meter_service.py": RAW_BASE + "smart_meter_service.py",
    "serial_config_service.py": RAW_BASE + "serial_config_service.py",
    "mqtt_client.py": RAW_BASE + "mqtt_client.py",
    "modbus_compat.py": RAW_BASE + "modbus_compat.py",
    "update_service.py": RAW_BASE + "update_service.py",
    "start.sh": RAW_BASE + "start.sh",
    "requirements.txt": RAW_BASE + "requirements.txt",
    "templates/setup.html": RAW_BASE + "templates/setup.html",
    "templates/mqtt.html": RAW_BASE + "templates/mqtt.html",
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


def sha256_file(filepath):
    """SHA-256 hash súboru (MD5 je na overenie integrity slabé)."""
    if not os.path.exists(filepath):
        return ""
    h = hashlib.sha256()
    try:
        with open(filepath, "rb") as f:
            for chunk in iter(lambda: f.read(65536), b""):
                h.update(chunk)
        return h.hexdigest()
    except Exception:
        return ""


def sha256_data(data):
    """SHA-256 hash dát."""
    return hashlib.sha256(data).hexdigest()


def download_file(url):
    """Stiahne súbor z URL a vráti bytes."""
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "ElvoSolar-CM5-Updater"})
        with urllib.request.urlopen(req, timeout=15) as resp:
            return resp.read()
    except Exception as e:
        print(f"[UPDATER] Chyba sťahovania {url}: {e}")
        return None


def write_pending(backups):
    """Zapíše zoznam záloh, ktoré treba vrátiť, ak appka po update nenabehne."""
    try:
        with open(PENDING_FILE, "w", encoding="utf-8") as f:
            json.dump({"at": time.time(), "backups": backups}, f)
    except Exception as e:
        print(f"[UPDATER] ⚠️ Nepodarilo sa zapísať pending marker: {e}")


def read_pending():
    try:
        if not os.path.exists(PENDING_FILE):
            return None
        with open(PENDING_FILE, encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return None


def confirm_update():
    """App.py zavolá po úspešnom štarte — update je potvrdený, netreba rollback."""
    if os.path.exists(PENDING_FILE):
        try:
            os.remove(PENDING_FILE)
            print("[UPDATER] ✅ Update potvrdený — nová verzia beží")
        except Exception:
            pass


def rollback_stale():
    """Ak update nebol do PENDING_MAX_AGE potvrdený, vráť .bak súbory späť."""
    pending = read_pending()
    if not pending:
        return False
    age = time.time() - float(pending.get("at") or 0)
    if age < PENDING_MAX_AGE:
        return False
    return rollback_now(pending.get("backups") or [], reason="appka po update nenabehla")


def rollback_now(backups, reason=""):
    restored = 0
    for item in backups:
        src = item.get("backup")
        dst = item.get("path")
        if not dst or not src or not os.path.exists(src):
            continue
        try:
            os.replace(src, dst)
            restored += 1
        except Exception as e:
            print(f"[UPDATER] ❌ Rollback zlyhal pre {dst}: {e}")
    try:
        if os.path.exists(PENDING_FILE):
            os.remove(PENDING_FILE)
    except Exception:
        pass
    print(f"[UPDATER] ↩️ ROLLBACK ({reason}) — vrátených {restored} súborov")
    return restored > 0


def check_and_update():
    """Skontroluje všetky súbory a aktualizuje zmenené."""
    any_updated = False
    backups = []
    set_update_stage("check")

    for rel_path, url in UPDATE_FILES.items():
        local_path = os.path.join(BASE_DIR, rel_path)
        local_hash = sha256_file(local_path)

        remote_data = download_file(url)
        if remote_data is None:
            continue

        remote_hash = sha256_data(remote_data)

        if local_hash == remote_hash:
            print(f"[UPDATER] {rel_path} — OK (hash zhodný)")
            continue

        print(f"[UPDATER] {rel_path} — NOVÁ VERZIA! Aktualizujem...")
        set_update_stage("download")

        try:
            # Bezpečnostná kontrola — pre .py súbory overíme syntax
            if rel_path.endswith(".py"):
                compile(remote_data.decode("utf-8"), local_path, "exec")
            if rel_path.endswith(".sh"):
                first = remote_data.split(b"\n", 1)[0]
                if not first.startswith(b"#!"):
                    raise ValueError("shell skript nemá shebang")

            # Vytvor priečinok ak neexistuje
            os.makedirs(os.path.dirname(local_path) if os.path.dirname(local_path) else BASE_DIR, exist_ok=True)

            # Backup
            backup_path = local_path + ".bak"
            if os.path.exists(local_path):
                if os.path.exists(backup_path):
                    os.remove(backup_path)
                os.rename(local_path, backup_path)

            # Zápis — najprv do dočasného súboru, potom atomický presun.
            # Výpadok napájania tak nikdy nenechá v app.py polovičný súbor.
            set_update_stage("install")
            tmp_path = local_path + ".new"
            with open(tmp_path, "wb") as f:
                f.write(remote_data)
                f.flush()
                os.fsync(f.fileno())
            if sha256_file(tmp_path) != remote_hash:
                raise IOError("hash po zápise nesedí")
            os.replace(tmp_path, local_path)

            if os.path.exists(backup_path):
                backups.append({"path": local_path, "backup": backup_path})

            print(f"[UPDATER] ✅ {rel_path} úspešne aktualizovaný!")
            any_updated = True

        except SyntaxError as e:
            print(f"[UPDATER] ❌ CHYBA SYNTAXE v {rel_path}: {e} — súbor NEBOL prepísaný!")
            backup_path = local_path + ".bak"
            if os.path.exists(backup_path) and not os.path.exists(local_path):
                os.rename(backup_path, local_path)
        except Exception as e:
            print(f"[UPDATER] ❌ Chyba zápisu {rel_path}: {e}")
            backup_path = local_path + ".bak"
            if os.path.exists(backup_path) and not os.path.exists(local_path):
                os.rename(backup_path, local_path)
            if os.path.exists(local_path + ".new"):
                try:
                    os.remove(local_path + ".new")
                except Exception:
                    pass

    if any_updated:
        # Zapíšeme marker PRED reštartom: keď app nenabehne do 180 s,
        # start.sh vráti .bak súbory a CM5 pobeží na starej verzii.
        write_pending(backups)
        set_update_stage("restart")
    else:
        set_update_stage(None)
    return any_updated


def main():
    print("[UPDATER] ElvoSolar Auto-Update Service spustený.")
    print(f"[UPDATER] Kontrola každých {CHECK_INTERVAL // 3600} hodín.")
    print(f"[UPDATER] Zdroj: {RAW_BASE}")

    while True:
        try:
            print(f"\n[UPDATER] === Kontrola aktualizácií ===")
            changed = check_and_update()

            if changed:
                print("\n[UPDATER] 🔄 Súbory boli aktualizované.")
                # 1) Migracia DB - doplni nove stlpce, EXISTUJUCE DATA OSTATNU
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
    arg = sys.argv[1] if len(sys.argv) > 1 else ""
    if arg == "--confirm":
        confirm_update()
        sys.exit(0)
    elif arg == "--rollback-stale":
        # Volá start.sh pred spustením app.py. Ak predošlý update nenabehol,
        # vráti staré súbory — appka sa tak nikdy nezacyklí v páde.
        rollback_stale()
        sys.exit(0)
    elif arg == "--rollback":
        pending = read_pending() or {}
        rollback_now(pending.get("backups") or [], reason="ručný rollback")
        sys.exit(0)
    main()
