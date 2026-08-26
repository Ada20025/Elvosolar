import sys
import dbus
import dbus.exceptions
import dbus.mainloop.glib
import dbus.service
import json
import threading
import time
import subprocess
from gi.repository import GLib
from system_service import SystemService

SERVICE_UUID = "12345678-1234-5678-1234-567812345678"
CHARACTERISTIC_UUID = "87654321-4321-8765-4321-876543218765"

class BLEApplication(dbus.service.Object):
    def __init__(self, bus):
        self.path = '/'
        self.services = []
        dbus.service.Object.__init__(self, bus, self.path)
        self.add_service(ConfigGATTService(bus, 0))

    def add_service(self, service):
        self.services.append(service)

    @dbus.service.method('org.freedesktop.DBus.ObjectManager', out_signature='a{oa{sa{sv}}}')
    def GetManagedObjects(self):
        objects = {}
        for service in self.services:
            objects[service.get_path()] = service.get_properties()
            for char in service.get_characteristics():
                objects[char.get_path()] = char.get_properties()
        return objects

    @dbus.service.method('org.bluez.GattManager1', in_signature='oa{sv}', out_signature='')
    def RegisterApplication(self, application, options):
        print('[BLE] Aplikácia zaregistrovaná.')

class ConfigGATTService(dbus.service.Object):
    def __init__(self, bus, index):
        self.path = f'/org/bluez/ldoc/service{index}'
        self.uuid = SERVICE_UUID
        dbus.service.Object.__init__(self, bus, self.path)
        self.characteristics = []
        self.add_characteristic(ConfigGATTCharacteristic(bus, 0, self))

    def add_characteristic(self, characteristic):
        self.characteristics.append(characteristic)

    def get_path(self):
        return dbus.ObjectPath(self.path)

    def get_characteristics(self):
        return self.characteristics

    def get_properties(self):
        return {
            'org.bluez.GattService1': {
                'UUID': dbus.String(self.uuid),
                'Primary': dbus.Boolean(True),
                'Characteristics': dbus.Array([c.get_path() for c in self.characteristics], signature='o')
            }
        }

class ConfigGATTCharacteristic(dbus.service.Object):
    def __init__(self, bus, index, service):
        self.path = f'{service.path}/char{index}'
        self.uuid = CHARACTERISTIC_UUID
        self.service = service
        self.buffer = ""
        self.last_write_time = 0
        dbus.service.Object.__init__(self, bus, self.path)

    def get_path(self):
        return dbus.ObjectPath(self.path)

    def get_properties(self):
        return {
            'org.bluez.GattCharacteristic1': {
                'Service': self.service.get_path(),
                'UUID': dbus.String(self.uuid),
                'Flags': dbus.Array(['read', 'write'], signature='s')
            }
        }

    @dbus.service.method('org.bluez.GattCharacteristic1', in_signature='a{sv}', out_signature='ay')
    def ReadValue(self, options):
        return dbus.ByteArray("CM5 GATT SERVER ACTIVE".encode('utf-8'))

    @dbus.service.method('org.bluez.GattCharacteristic1', in_signature='aya{sv}', out_signature='')
    def WriteValue(self, value, options):
        try:
            now = time.time()
            if now - self.last_write_time > 5.0:
                self.buffer = ""
            self.last_write_time = now

            # Bezpečné multi-bajtové UTF-8 dekódovanie prijatého bloku
            try:
                chunk = bytes(value).decode('utf-8', errors='ignore')
            except Exception:
                chunk = "".join([chr(b) for b in value])

            self.buffer += chunk
            
            try:
                request = json.loads(self.buffer)
                self.buffer = ""
                print(f"[BLE] Kompletné dáta prijaté. Spúšťam konfiguráciu...")
                threading.Thread(target=self.process_ble_setup_pipeline, args=(request,), daemon=True).start()
            except json.JSONDecodeError:
                pass
        except Exception as e:
            print(f"[BLE CHYBA] Zlyhal zápis dát: {e}")

    def process_ble_setup_pipeline(self, request):
        try:
            ssid = request.get("ssid")
            password = request.get("password")
            if ssid:
                print(f"[BLE WiFi] Pripájanie k: {ssid}")
                SystemService.connect_wifi(ssid, password)
            
            # Bezpečný odložený import na zamedzenie cyklických importov s hlavnou aplikáciou
            from app import process_config_payload_internal
            res = process_config_payload_internal(request)
            print(f"[BLE PIPELINE] Výsledok konfigurácie: {res}")
        except Exception as e:
            print(f"[BLE PIPELINE CHYBA] Zlyhanie: {e}")

class BLEConfigService:
    @staticmethod
    def init_bluetooth_hardware():
        try:
            suffix = SystemService.get_mac_suffix()
            name = f"CM5-{suffix}"
            print("[BLE HW] Inicializuje sa adaptér...")
            subprocess.run(["sudo", "bluetoothctl", "power", "on"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            subprocess.run(["sudo", "bluetoothctl", "system-alias", name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            subprocess.run(["sudo", "bluetoothctl", "discoverable", "on"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            subprocess.run(["sudo", "bluetoothctl", "pairable", "on"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            subprocess.run(["sudo", "bluetoothctl", "discoverable-timeout", "0"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            subprocess.run(["sudo", "bluetoothctl", "advertise", "on"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            print(f"[BLE HW] Pripravený na párovanie ako: '{name}'")
        except Exception as e:
            print(f"[BLE HW ERROR] {e}")

    @staticmethod
    def start_ble_peripheral():
        try:
            BLEConfigService.init_bluetooth_hardware()
            dbus.mainloop.glib.DBusGMainLoop(set_as_default=True)
            bus = dbus.SystemBus()
            app = BLEApplication(bus)
            adapter = bus.get_object('org.bluez', '/org/bluez/hci0')
            manager = dbus.Interface(adapter, 'org.bluez.GattManager1')
            manager.RegisterApplication(app.path, {}, reply_handler=lambda: None, error_handler=lambda e: print(e))
            mainloop = GLib.MainLoop()
            mainloop.run()
        except Exception as e:
            print(f"[BLE DBUS ERROR] {e}")

if __name__ == '__main__':
    BLEConfigService.start_ble_peripheral()