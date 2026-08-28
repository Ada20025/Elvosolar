#!/usr/bin/env python3
"""
Modbus Scanner pre ElvoControl - 2-fazovy scan
Faza 1: Rychlo prejde vsetky porty a ID - cokolvek odpovie = naslo sa
Faza 2: Na najdenom porte cita detaily (model, znacka, seriove cislo)
"""

import time
import sys

try:
    from pymodbus.client import ModbusSerialClient
    from pymodbus.exceptions import ModbusIOException
except ImportError:
    print("Pymodbus nie je nainštalovaný. Spusť: pip install pymodbus")
    sys.exit(1)

import glob as glob_mod


def find_ports():
    """Najde vsetky dostupne serial porty."""
    ports = sorted(glob_mod.glob('/dev/ttyAMA*') + 
                   glob_mod.glob('/dev/serial*') + 
                   glob_mod.glob('/dev/ttyUSB*'))
    return [p for p in ports if '/dev/' in p]


def phase1_quick_scan(ports, baudrates=[9600], parities=['N']):
    """Faza 1: Rychlo prejde vsetky porty/ID. Cokolvek odpovie = nasiel."""
    print("=" * 60)
    print("FAZA 1: Rychly scan vsetkych portov")
    print("=" * 60)
    
    found = []  # [(port, baud, parity, slave_id, response_type)]
    
    for port in ports:
        for baud in baudrates:
            for par in parities:
                client = ModbusSerialClient(
                    port=port, baudrate=baud, parity=par,
                    stopbits=1, bytesize=8, timeout=0.3
                )
                if not client.connect():
                    print(f"  X {port} baud={baud} par={par} - nedal sa otvorit")
                    continue
                
                print(f"\n  -> {port} baud={baud} parity={par}")
                
                for sid in range(1, 33):
                    try:
                        # Test 1: Holding registers adresa 0
                        result = client.read_holding_registers(address=0, count=1, slave=sid)
                        if not result.isError():
                            found.append((port, baud, par, sid, 'HOLDING_REG_0'))
                            print(f"    ✅ ID={sid} HOLDING odpovedala!")
                            continue
                        
                        # Test 2: Input registers adresa 0
                        result2 = client.read_input_registers(address=0, count=1, slave=sid)
                        if not result2.isError():
                            found.append((port, baud, par, sid, 'INPUT_REG_0'))
                            print(f"    ✅ ID={sid} INPUT odpovedala!")
                            continue
                        
                        # Test 3: Holding registers adresa 32080 (Huawei P_AC)
                        result3 = client.read_holding_registers(address=32080, count=1, slave=sid)
                        if not result3.isError():
                            found.append((port, baud, par, sid, 'HOLDING_32080'))
                            print(f"    ✅ ID={sid} HOLDING 32080 odpovedala!")
                            continue
                        
                        # Test 4: Holding registers adresa 37760 (SoC)
                        result4 = client.read_holding_registers(address=37760, count=1, slave=sid)
                        if not result4.isError():
                            found.append((port, baud, par, sid, 'HOLDING_37760'))
                            print(f"    ✅ ID={sid} HOLDING 37760 odpovedala!")
                            continue
                        
                        # Test 5: Ak pride error odpoved = zariadenie existuje
                        if hasattr(result, 'exception_code') and result.exception_code is not None:
                            found.append((port, baud, par, sid, f'ERROR_{result.exception_code}'))
                            print(f"    ⚠️ ID={sid} ERROR odpoved (exception={result.exception_code})")
                        
                    except Exception:
                        pass
                    
                    # Ticho preskocime - len bodky pre prehlad
                    if sid % 8 == 0:
                        print(f"      {sid}..", end="", flush=True)
                
                client.close()
    
    print(f"\n{'=' * 60}")
    if found:
        print(f"NAJDENE: {len(found)} zariadeni")
        for port, baud, par, sid, rtype in found:
            print(f"  Port={port} Baud={baud} Parity={par} ID={sid} Type={rtype}")
    else:
        print("Nenaslo sa nic - skontroluj zapojenie RS485")
    
    return found


def phase2_deep_scan(port, baud, parity, slave_id):
    """Faza 2: Hlboky scan na najdenom zariadeni - citanie registrov."""
    print(f"\n{'=' * 60}")
    print(f"FAZA 2: Hlboky scan - {port} baud={baud} parity={parity} ID={slave_id}")
    print("=" * 60)
    
    client = ModbusSerialClient(
        port=port, baudrate=baud, parity=parity,
        stopbits=1, bytesize=8, timeout=0.5
    )
    if not client.connect():
        print("Nepodarilo sa pripojit")
        return {}
    
    info = {}
    
    # --- Model name (Huawei: registre 30000-30015) ---
    print("\n[Model Name] Registre 30000-30015 (Holding)...")
    try:
        result = client.read_holding_registers(address=30000, count=16, slave=slave_id)
        if not result.isError() and result.registers:
            text = ""
            for reg in result.registers:
                hi = (reg >> 8) & 0xFF
                lo = reg & 0xFF
                if hi: text += chr(hi)
                if lo: text += chr(lo)
            text = text.strip().rstrip('\x00')
            if text:
                info['model_name'] = text
                print(f"  ✅ Model: {text}")
            else:
                print(f"  - Prázdp odpoveď")
        else:
            print(f"  - Nedostupne (error)")
    except Exception as e:
        print(f"  - Chyba: {e}")
    
    # --- Serial number (Huawei: registre 30004-30011) ---
    print("\n[Serial Number] Registre 30004-30012 (Holding)...")
    try:
        result = client.read_holding_registers(address=30004, count=8, slave=slave_id)
        if not result.isError() and result.registers:
            text = ""
            for reg in result.registers:
                hi = (reg >> 8) & 0xFF
                lo = reg & 0xFF
                if hi: text += chr(hi)
                if lo: text += chr(lo)
            text = text.strip().rstrip('\x00')
            if text:
                info['serial_number'] = text
                print(f"  ✅ Serial: {text}")
    except Exception as e:
        print(f"  - Chyba: {e}")
    
    # --- AC Power (Huawei: register 32080, FC3) ---
    print("\n[AC Power] Register 32080 (Holding FC3)...")
    try:
        result = client.read_holding_registers(address=32080, count=1, slave=slave_id)
        if not result.isError() and result.registers:
            val = result.registers[0]
            info['ac_power'] = val
            print(f"  ✅ AC Power = {val} W")
        else:
            print(f"  - Nedostupne")
    except Exception as e:
        print(f"  - Chyba: {e}")
    
    # --- SoC Battery (Huawei: register 37760, FC3) ---
    print("\n[SoC Battery] Register 37760 (Holding FC3)...")
    try:
        result = client.read_holding_registers(address=37760, count=1, slave=slave_id)
        if not result.isError() and result.registers:
            val = result.registers[0]
            info['soc'] = val
            print(f"  ✅ SoC = {val}%")
        else:
            print(f"  - Nedostupne")
    except Exception as e:
        print(f"  - Chyba: {e}")
    
    # --- Daily Energy (Huawei: register 32086, FC3) ---
    print("\n[Daily Energy] Register 32086 (Holding FC3)...")
    try:
        result = client.read_holding_registers(address=32086, count=1, slave=slave_id)
        if not result.isError() and result.registers:
            val = result.registers[0] / 10  # kWh
            info['daily_energy'] = val
            print(f"  ✅ Daily Energy = {val} kWh")
    except Exception as e:
        print(f"  - Chyba: {e}")
    
    # --- Device type (Huawei: register 30000, FC4 Input) ---
    print("\n[Device Type] Register 30000 (Input FC4)...")
    try:
        result = client.read_input_registers(address=30000, count=1, slave=slave_id)
        if not result.isError() and result.registers:
            info['device_type'] = result.registers[0]
            print(f"  ✅ Device Type = {result.registers[0]}")
    except Exception as e:
        print(f"  - Chyba: {e}")
    
    client.close()
    
    print(f"\n{'=' * 60}")
    print("VYSLEDOK:")
    for k, v in info.items():
        print(f"  {k}: {v}")
    print("=" * 60)
    
    return info


def main():
    print("ELVOCONTROL MODBUS SCANNER")
    print("=" * 60)
    
    # Najdi porty
    ports = find_ports()
    if not ports:
        print("Ziadne serial porty nenajdene!")
        return
    
    print(f"Dostupne porty: {ports}")
    
    # Faza 1: Rychly scan
    found = phase1_quick_scan(ports)
    
    if not found:
        print("\nNenaslo sa nic. Skontroluj:")
        print("1. Je striedac zapnuty?")
        print("2. Je RS485 kabel pripojeny na CH1?")
        print("3. A+ ide do A, B- ide do B?")
        return
    
    # Faza 2: Hlboky scan na kazdom najdenom zariadeni
    seen = set()
    for port, baud, par, sid, rtype in found:
        key = (port, sid)
        if key in seen:
            continue
        seen.add(key)
        phase2_deep_scan(port, baud, par, sid)
    
    # Vrat prvy najdeny port pre pouzitie v app.py
    best = found[0]
    print(f"\nNAJVHODNEJSI PORT: {best[0]} baud={best[1]} parity={best[2]} ID={best[3]}")
    return best


if __name__ == "__main__":
    result = main()
