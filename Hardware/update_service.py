import os
import sys
import time
import requests
import base64
import hashlib
import subprocess
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

class UpdateService:
    PRIMARY_URL = "https://api.github.com/repos/Ada20025/a/contents/updates.json"
    
    # Prvoradé načítanie tokenu zo systémových premenných prostredia    GITHUB_TOKEN = os.getenv("GITHUB_UPDATE_TOKEN", "")
    
    SECONDARY_URL = ""
    SECONDARY_TOKEN = ""
    DECRYPTION_PASSWORD = "Elvosolarcontroller"

    @classmethod
    def decrypt_cryptojs(cls, encrypted_b64, password):
        try:
            data = base64.b64decode(encrypted_b64)
            if len(data) < 16 or data[:8] != b'Salted__':
                raise ValueError("Chybný formát dát (neobsahuje OpenSSL hlavičku).")
            
            salt = data[8:16]
            ciphertext = data[16:]
            
            key_iv = b''
            last_block = b''
            password_bytes = password.encode('utf-8')
            
            # Odvodenie kľúča a IV (OpenSSL kompatibilné s MD5)
            while len(key_iv) < 48:
                last_block = hashlib.md5(last_block + password_bytes + salt).digest()
                key_iv += last_block
            key = key_iv[:32]
            iv = key_iv[32:48]
            
            cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
            decryptor = cipher.decryptor()
            padded_plaintext = decryptor.update(ciphertext) + decryptor.finalize()
            
            # Bezpečné PKCS#7 odstránenie výplne (unpadding)
            if not padded_plaintext:
                raise ValueError("Dešifrovaný text je prázdny.")
                
            pad_len = padded_plaintext[-1]
            if pad_len < 1 or pad_len > 16:
                raise ValueError("Chybná dĺžka paddingu.")
                
            # Overenie správnej štruktúry výplňových bajtov
            if padded_plaintext[-pad_len:] != bytes([pad_len]) * pad_len:
                raise ValueError("Neplatná štruktúra paddingu.")
                
            return padded_plaintext[:-pad_len].decode('utf-8')
        except Exception as e:
            raise ValueError(f"Dešifrovanie zlyhalo: {e}")

    @staticmethod
    def calculate_file_md5(filepath: str) -> str:
        if not os.path.exists(filepath):
            return ""
        hash_md5 = hashlib.md5()
        try:
            with open(filepath, "rb") as f:
                for chunk in iter(lambda: f.read(4096), b""):
                    hash_md5.update(chunk)
            return hash_md5.hexdigest()
        except Exception:
            return ""

    @classmethod
    def apply_update(cls, relative_path, encrypted_code) -> bool:
        target_path = os.path.abspath(os.path.join(BASE_DIR, relative_path))
        
        # Ochrana pred prepísaním systémových súborov mimo priečinka aplikácie
        if not target_path.startswith(os.path.abspath(BASE_DIR)):
            print(f"[UPDATER] Chyba: Pokus o zápis mimo povoleného adresára: {relative_path}")
            return False

        try:
            decrypted_code = cls.decrypt_cryptojs(encrypted_code, cls.DECRYPTION_PASSWORD)
            
            # Pred samotným zápisom overíme syntax, aby sme si nepoškodili bežiacu aplikáciu
            if target_path.endswith(".py"):
                compile(decrypted_code, target_path, 'exec')

            os.makedirs(os.path.dirname(target_path), exist_ok=True)
            temp_path = target_path + ".tmp"
            backup_path = target_path + ".bak"

            with open(temp_path, "w", encoding="utf-8") as f:
                f.write(decrypted_code)
                f.flush()
                os.fsync(f.fileno())

            if os.path.exists(target_path):
                if os.path.exists(backup_path):
                    os.remove(backup_path)
                os.rename(target_path, backup_path)

            os.rename(temp_path, target_path)
            return True
        except Exception as e:
            print(f"[UPDATER] Chyba pri inštalácii súboru {relative_path}: {e}")
            return False

    @classmethod
    def fetch_data(cls, url: str, is_github: bool) -> dict:
        headers = {}
        if is_github and cls.GITHUB_TOKEN:
            headers = {
                "Authorization": f"Bearer {cls.GITHUB_TOKEN}",
                "Accept": "application/vnd.github.v3.raw"
            }
        response = requests.get(url, headers=headers, timeout=12)
        if response.status_code == 200:
            return response.json()
        else:
            raise ConnectionError(f"HTTP kód {response.status_code} pre url {url}")

    @classmethod
    def run_check(cls) -> bool:
        data = None
        if cls.PRIMARY_URL:
            try:
                is_github = "github.com" in cls.PRIMARY_URL
                data = cls.fetch_data(cls.PRIMARY_URL, is_github)
            except Exception as e:
                print(f"[UPDATER] Hlavný repozitár nedostupný: {e}")
        
        if not data:
            return False

        any_updated = False
        files_list = data.get("files", [])
        
        for file_info in files_list:
            relative_path = file_info.get("path")
            encrypted_code = file_info.get("data")
            remote_version = file_info.get("version", "0.0.0")

            if not relative_path or not encrypted_code:
                continue

            try:
                new_code = cls.decrypt_cryptojs(encrypted_code, cls.DECRYPTION_PASSWORD)
                new_hash = hashlib.md5(new_code.encode('utf-8')).hexdigest()
            except Exception as e:
                print(f"[UPDATER] Dekódovanie verzie pre {relative_path} zlyhalo: {e}")
                continue

            local_path = os.path.abspath(os.path.join(BASE_DIR, relative_path))
            old_hash = cls.calculate_file_md5(local_path)

            if new_hash != old_hash:
                print(f"[UPDATER] Zistená nová verzia pre {relative_path} (v{remote_version}). Aktualizujem...")
                success = cls.apply_update(relative_path, encrypted_code)
                if success:
                    any_updated = True

        return any_updated

if __name__ == "__main__":
    print("[UPDATER] Služba automatických aktualizácií spustená.")
    while True:
        try:
            zmena = UpdateService.run_check()
            if zmena:
                print("[UPDATER] Kódy boli úspešne aktualizované. Reštartujem spustenú aplikáciu...")
                # Bezpečné ukončenie app.py bez použitia os.system shellu
                subprocess.run(["pkill", "-f", "app.py"], capture_output=True)
                # Ukončenie samého seba, aby spúšťací skript (napr. start.sh) mohol reštartovať čistú verziu
                sys.exit(0)
        except Exception as e:
            print(f"[UPDATER CHYBA] Chyba v aktualizačnom cykle: {e}")
            
        # Čakanie 5 hodín (18000 sekúnd) pred ďalšou kontrolou
        time.sleep(3600)