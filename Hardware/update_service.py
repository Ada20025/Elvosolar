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
                print("\n[UPDATER] 🔄 Súbory boli aktualizované. Reštartujem app.py...")
                # Zabij app.py aby start.sh mohol reštartovať s novým kódom
                subprocess.run(["pkill", "-f", "python3.*app.py"], capture_output=True)
                sys.exit(0)
            else:
                print("[UPDATER] ✅ Všetko aktuálne.")

        except Exception as e:
            print(f"[UPDATER CHYBA] {e}")

        time.sleep(CHECK_INTERVAL)


if __name__ == "__main__":
    main()
