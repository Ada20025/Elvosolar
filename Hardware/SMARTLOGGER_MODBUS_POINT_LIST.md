# 📡 Huawei SmartLogger & ElvoSolar CM5 – Modbus RTU Integračný Manuál (Adresa 205)

Tento dokument slúži pre technikov a inštalatérov na úspešné prepojenie a vyhľadanie riadiacej jednotky **ElvoSolar CM5 AI Smart EMS** v systéme **Huawei SmartLogger (3000A / 3000B / 1000 / Enspire)** cez zbernicu RS485 (Modbus RTU).

---

## ⚙️ 1. Parametre Komunikácie RS485

| Parameter | Nastavená Hodnota | Poznámka |
| :--- | :--- | :--- |
| **Protokol** | **Modbus-RTU** | Štandardná priemyselná zbernica RS485 |
| **Modbus Slave ID (Adresa)** | **205** | Logic Address v SmartLoggeri |
| **Baud Rate (Rýchlosť)** | **9600 bps** | Predvolená rýchlosť |
| **Data Bits (Dátové bity)** | **8** | Štandard |
| **Parity (Parita)** | **None (Bez parity / N)** | 8-N-1 |
| **Stop Bits (Stop bity)** | **1** | 1 stop bit |
| **Fyzické zapojenie** | **2-vodičové RS485** | Svorky **R/A (A+)** a **T/B (B-)** na CM5 $\rightarrow$ COM port na SmartLoggeri |

---

## 🛠️ 2. Postup Nastavenia v Huawei SmartLogger 3000 (Krok za Krokom)

### Krok 1: Nastavenie RS485 Portu v SmartLoggeri
1. Prihláste sa do webového rozhrania SmartLoggera (ako `Advanced User` alebo `Special User`).
2. Prejdite do ponuky: **Settings** $\rightarrow$ **Comm. Param.** $\rightarrow$ **RS485** (zvoľte COM1, COM2 alebo COM3 podľa toho, do ktorého portu ste zapojili kábel z CM5).
3. Nastavte nasledovné hodnoty:
   - **Baud rate**: `9600`
   - **Parity**: `None`
   - **Stop bit**: `1`
   - **Start address**: `1`
   - **End address**: `247`
4. Kliknite na **Submit** (Uložiť).

### Krok 2: Vyhľadanie a Pridanie Zariadenia (Device Search)
1. Prejdite do ponuky: **Maintenance** $\rightarrow$ **Device Mgmt.** $\rightarrow$ **Connect Device**.
2. Kliknite na tlačidlo **Add Device** (alebo **Auto Search** s rozsahom adries zahŕňajúcim `205`).
3. V dialógovom okne vyplňte:
   - **Device Type**: `Custom Device` (alebo `Power Sensor` / `Modbus-RTU`)
   - **Port number**: `COM1` / `COM2` / `COM3` (zvolený port)
   - **Address** (Logic address): **`205`**
4. Kliknite na **Add Devices** $\rightarrow$ SmartLogger odošle dotaz na adresu `205` a jednotka ElvoSolar CM5 okamžite odošle potvrdzujúcu odpoveď.
5. V zozname zariadení sa zobrazí **ELVOSOLAR-CM5** so stavom **Online**.

---

## 📊 3. Zoznam Registrov (Modbus RTU Point List pre SmartLogger)

### A. Identifikácia a Stav Zariadenia (Holding / Input Registers – FC 03 / FC 04)

| Register (DEC) | Register (HEX) | Názov / Popis | Typ Dát | Jednotka / Mierka | Prístup |
| :---: | :---: | :--- | :---: | :---: | :---: |
| **0** | `0x0000` | **Device Type ID** (0x0501 = ElvoSolar EMS) | uint16 | Identifikátor | R |
| **1** | `0x0001` | **Protocol Version** (100 = v1.00) | uint16 | 1 = 0.01 | R |
| **2 - 5** | `0x0002-0x0005` | **Model Name** ("ELVO-CM5") | ASCII | 8 bajtov | R |
| **6** | `0x0006` | **Prevádzkový stav** (1=Normál/Online, 2=Alarm, 3=Standby) | uint16 | Kód | R |
| **7** | `0x0007` | **Aktívny režim riadenia** (0=Auto, 1=Force Charge, 2=Discharge, 3=Off) | uint16 | Kód | R/W |
| **8** | `0x0008` | **Modbus RTU Adresa** (205) | uint16 | Adresa | R |
| **9** | `0x0009` | **Baudrate** (9600) | uint16 | bps | R |
| **10** | `0x000A` | **Stop bit** (1) | uint16 | bity | R |

---

### B. Telemetria FVE, Batérie a Cien OKTE (Holding / Input Registers – FC 03 / FC 04)

| Register (DEC) | Register (HEX) | Názov / Popis | Typ Dát | Jednotka / Mierka | Prístup |
| :---: | :---: | :--- | :---: | :---: | :---: |
| **1000** | `0x03E8` | **Riadiaci stav striedača** (0=Auto, 1=Zapnutý, 2=Vypnutý) | uint16 | Kód | R/W |
| **1001** | `0x03E9` | **Aktívny AI Model** (1-4 modely, 5=Plné AI) | uint16 | Kód | R/W |
| **1004** | `0x03EC` | **Celkový solárny výkon FVE** | int16 | **Watt (W)** | R |
| **1005** | `0x03ED` | **Stav nabitia batérie (SoC)** | uint16 | **% (0 - 100)** | R |
| **1006** | `0x03EE` | **Aktuálna spotová cena OKTE** | int16 (signed) | **0.01 €/MWh** (napr. -840 = -8.40 €) | R |
| **1007** | `0x03EF` | **Teplota jednotky / striedača** | int16 | **0.1 °C** (napr. 325 = 32.5 °C) | R |
| **1008** | `0x03F0` | **Teplota vody v bojleri (PT1000)** | int16 | **0.1 °C** (napr. 564 = 56.4 °C) | R |
| **1009** | `0x03F1` | **Cieľová teplota bojlera TÚV** | uint16 | **°C** (napr. 65 = 65 °C) | R/W |
| **1010** | `0x03F2` | **Výkon ohrevu Shelly relé / Bojlera** | uint16 | **Watt (W)** | R |
| **1011** | `0x03F3` | **Izbová teplota / Termostat** | int16 | **0.1 °C** (napr. 218 = 21.8 °C) | R |
| **1012** | `0x03F4` | **Cieľová teplota kúrenia** | int16 | **0.1 °C** (napr. 220 = 22.0 °C) | R/W |
| **1013** | `0x03F5` | **Nabíjací prúd Wallboxu pre EV** | uint16 | **Ampér (A)** (6 - 32 A) | R/W |
| **1014** | `0x03F6` | **Bezpečnostná rezerva batérie** | uint16 | **%** (napr. 20 = 20%) | R/W |

---

### C. Smart Meter & Energetické Toky (Holding / Input Registers – FC 03 / FC 04)

| Register (DEC) | Register (HEX) | Názov / Popis | Typ Dát | Jednotka / Mierka | Prístup |
| :---: | :---: | :--- | :---: | :---: | :---: |
| **2010** | `0x07DA` | **Spotreba rodinného domu** | int16 | **Watt (W)** | R |
| **2011** | `0x07DB` | **Import zo siete (Nákup)** | int16 | **Watt (W)** | R |
| **2012** | `0x07DC` | **Export do siete (Predaj)** | int16 | **Watt (W)** | R |
| **2013** | `0x07DD` | **Solárne prebytky FVE** | int16 | **Watt (W)** | R |
| **2014** | `0x07DE` | **FVE Výroba** | int16 | **Watt (W)** | R |
| **2015** | `0x07DF` | **Režim regulácie pretekania** (0=Bez limitu, 1=Vlastná spotreba/Zero Export, 2=Smart AI) | uint16 | Kód | R/W |
| **2019** | `0x07E3` | **Dnešná celková spotreba domu** | uint16 | **0.01 kWh** (napr. 1450 = 14.50 kWh) | R |

---

### D. Huawei Kompatibilné Bloky (SUN2000 emulácia)

| Register (DEC) | Register (HEX) | Názov / Popis | Typ Dát | Jednotka / Mierka | Prístup |
| :---: | :---: | :--- | :---: | :---: | :---: |
| **32000** | `0x7D00` | **Inverter Status** (0x0002 = On-grid / Running) | uint16 | Kód | R |
| **32080** | `0x7D50` | **Active Power P_AC** | int16 | **Watt (W)** | R |
| **32084** | `0x7D54` | **Power Factor (Účinník)** | int16 | 1000 = 1.000 | R |
| **32085** | `0x7D55` | **Frekvencia siete (Grid Frequency)** | uint16 | 0.01 Hz (5000 = 50.00 Hz) | R |
| **37760** | `0x9380` | **Battery SoC (Huawei Storage block)** | uint16 | **% (0 - 100)** | R |
| **40125** | `0x9CB5` | **Remote Active Power Curtailment** | uint16 | 0.1% (1000 = 100.0%, 0 = 0%) | R/W |

---

## 🔒 4. Podporované Modbus Funkčné Kódy (FC)

1. **FC 03 (0x03)** – `Read Holding Registers` (Čítanie registrov)
2. **FC 04 (0x04)** – `Read Input Registers` (Čítanie vstupných registrov)
3. **FC 06 (0x06)** – `Write Single Register` (Zápis jedného registra – napr. zmena teploty bojlera, režimu alebo prúdu)
4. **FC 16 (0x10)** – `Write Multiple Registers` (Zápis viacerých registrov naraz)
5. **FC 17 (0x11)** – `Report Server ID` (Identifikácia zariadenia: `ELVOSOLAR-CM5-PRO`)
6. **FC 43 (0x2B)** – `Read Device Identification` (Štandardná SunSpec / Modbus identifikácia)

