# 📖 POUŽÍVATEĽSKÝ MANUÁL: ELVOSOLAR CM5 AI SMART EMS

Vitajte v oficiálnom používateľskom manuáli pre inteligentný systém riadenia fotovoltiky, batériových úložísk a spotrebičov **ElvoSolar CM5 AI Smart EMS**.

---

## 📑 OBSAH

1. [O Systéme a Architektúre](#1-o-systéme-a-architektúre)
2. [Hardvérové Zapojenie (Raspberry Pi 5 / Waveshare CM5)](#2-hardvérové-zapojenie)
3. [Prvé Spustenie & Sprievodca Inštaláciou (Setup)](#3-prvé-spustenie--sprievodca-inštaláciou)
4. [Princíp Fungovania AI EMS Jadra (100% Zadarmo & Lokálne)](#4-princíp-fungovania-ai-ems-jadra)
5. [Profil Odberateľa: Domácnosť vs Firma](#5-profil-odberateľa-domácnosť-vs-firma)
6. [Používanie Webovej Aplikácie](#6-používanie-webovej-aplikácie)
7. [Vlastné Pravidlá & Povelové Nastavenia (Custom Rules)](#7-vlastné-pravidlá--povelové-nastavenia)
8. [Zariadenia 3. Strán & Inteligentné Bojlery (`Hardware 3/`)](#8-zariadenia-3-strán--inteligentné-bojlery)
9. [Signalizácia START LED Diódy (4x Zablikanie)](#9-signalizácia-start-led-diódy)
10. [E-Shop s Príslušenstvom a Rozšíreniami](#10-e-shop-s-príslušenstvom)
11. [Riešenie Problémov (Troubleshooting)](#11-riešenie-problémov)

---

## 1. O Systéme a Architektúre

ElvoSolar CM5 AI Smart EMS je priemyselný energetický manažment systém (EMS) navrhnutý pre **lokálne riadenie bez nutnosti platených cloudových AI služieb**.

- **Mozog systému**: Raspberry Pi Compute Module 5 (CM5) s Waveshare IO doskou.
- **Komunikácia**: Priemyselná zbernica RS485 (Modbus RTU), Modbus TCP, Wi-Fi a Ethernet.
- **Spotový trh**: Oficiálne pripojenie na bezplatné rozhranie krátkodobého trhu s elektrinou **OKTE DAM**.

---

## 2. Hardvérové Zapojenie

Jednotka CM5 komunikuje so striedačom cez svorkovnicu **CH1 (Channel 1)**:
- **Svorka R/A (RS485_A+)**: Pripojte na dátový vodič A+ (alebo pin 4 RJ45 / Pin 1 svorkovnice striedača).
- **Svorka T/B (RS485_B-)**: Pripojte na dátový vodič B- (alebo pin 5 RJ45 / Pin 3 svorkovnice striedača).
- **Svorka GND**: Prepojte s tienením kábla pre ochranu pred rušením.

### Zapojenie podľa značky striedača:
| Značka | Typ pripojenia | Vodič A+ | Vodič B- | Predvolený Baudrate |
| :--- | :--- | :--- | :--- | :--- |
| **Huawei SUN2000** | COM svorkovnica (rýchlospojka) | PIN 7 (A+) | PIN 9 (B-) | 9600 (Parita: None) |
| **SolaX (G4 / X3-Hybrid)** | RJ45 konektor (RS485/Meter) | PIN 4 (Modrý) | PIN 5 (Bielo-modrý) | 9600 / 19200 |
| **Fronius (Symo / Primo / GEN24)** | Modbus svorky | D+ | D- | 9600 |
| **GoodWe (ET / EH / XS)** | Spodný komunikačný port | Svorka 1 (A) | Svorka 2 (B) | 9600 |
| **Growatt (MIN / MOD / SPH)** | RJ45 SYS COM | PIN 3 (A) | PIN 4 (B) | 9600 |
| **Victron Energy** | USB-RS485 adaptér | Oranžový (A) | Žltý (B) | 9600 |
| **Deye (SUN-SG04LP3)** | BMS/RS485 port | PIN 7 (A) | PIN 8 (B) | 9600 |

---

## 3. Prvé Spustenie & Sprievodca Inštaláciou

Po prvom zapnutí otvorte webový prehliadač a zadajte adresu vašej jednotky: `http://<IP_ADRESA_CM5>/setup.html`.

1. **Krok 1 (Rozhranie)**: Zvoľte pripojenie (Priame cez LAN/Wi-Fi alebo Bluetooth).
2. **Krok 2 (Výber Striedača & Batérie)**:
   - Zvoľte značku a modelovú radu vášho meniča.
   - Zaškrtnite **„Mám pripojené batérie“** (ak batériu nemáte, odškrtnite a systém automaticky prispôsobí grafy a skryje batériové funkcie).
   - **Profil odberateľa**: Zvoľte 🏠 **Domácnosť** (100% vlastná spotreba) alebo 🏢 **Firma** (povolený výkup na OKTE).
3. **Krok 3 (Schéma zapojenia)**: Skontrolujte priradenie vodičov.
4. **Krok 4 (Wi-Fi & Účet)**: Zadajte údaje domácej Wi-Fi siete.
5. **Krok 5 (Autodetekcia RS485)**: Systém automaticky preskenuje zbernicu a potvrdí úspešné spojenie so striedačom.

---

## 4. Princíp Fungovania AI EMS Jadra

AI optimalizátor funguje **100% lokálne na Raspberry Pi 5 a je navždy zadarmo** (bez akýchkoľvek poplatkov za API):

1. **Slovenský kalendár sviatkov**: Automaticky rozlišuje pracovné dni, víkendy a slovenské sviatky (vrátane pohyblivej Veľkej noci).
2. **Samo-učiaci sa model spotreby**: Ukladá a spresňuje krivky domácej spotreby (vstup do fázy *INITIAL_LEARNING* -> *ACTIVE_LEARNING* -> *EXPERT_ADAPTIVE*).
3. **Predikcia solárnej výroby**: Fyzikálny model osvitu pre zemepisnú šírku SR (48.7°) rekalibrovaný skutočnými meraniami zo striedača.
4. **Bezplatné sťahovanie cien z OKTE**: Každú hodinu sťahuje denný spotový diagram z `https://isot.okte.sk`.
5. **Multi-objektívna optimalizácia**:
   - **🛡️ Ochrana pred zápornými cenami**: Ak je cena <= 0.0 €/MWh, okamžite zakáže pretoky do siete a prepne striedač na nabíjanie batérie a ohrev bojlera.
   - **🌙 Nočné prednabíjanie**: V nočných cenových dolinách prednabije batériu pred drahou rannou špičkou.
   - **📈 Predaj v špičke (Arbitráž)**: Pre firmy s povoleným výkupom predáva prebytky za najvyššie ceny dňa.
   - **🏠 Priorita vlastnej spotreby**: Maximalizuje využitie energie v objekte.

---

## 5. Profil Odberateľa: Domácnosť vs Firma

- 🏠 **Rodinný dom / Domácnosť (Zero Export / Vlastná spotreba)**:
  - Vhodné pre zmluvy s nulovými pretokmi alebo virtuálnou batériou.
  - Export do distribučnej siete je striktne blokovaný.
  - Všetka prebytočná energia smeruje do batérie alebo inteligentného bojlera.
- 🏢 **Firma / Podnikateľ (Povolený výkup & Arbitráž)**:
  - Vhodné pre výrobcov a firmy s platnou zmluvou o výkupe.
  - AI aktívne realizuje cenovú arbitráž na spotovom trhu OKTE.

---

## 6. Používanie Webovej Aplikácie

Webová aplikácia (`Software/App/templates/device_detail.html`) obsahuje 4 hlavné karty:

1. **⚡ Prehľad (Dashboard)**:
   - Živý výkon FVE (W), stav batérie SoC (%), ušetrené peniaze (€), live cena OKTE (€/MWh).
   - AI Hero Box: aktuálne rozhodnutie a odôvodnenie.
   - Prepínač stavu: `AUTOMATIKA (AI EMS)`, `VYNÚTENÉ ZAPNUTIE`, `VYPNÚŤ STRIEDAČ`.
   - Interaktívny 24h SVG graf cien a výroby.
2. **🧠 AI Chytré Riadenie**:
   - Sezónne stratégie (Jar / Leto / Jeseň / Zima).
   - 24-hodinový optimalizačný plán s tlačidlom `🔄 Prepočítať plán teraz`.
   - Nastavenie predvolených prahov a pravidiel.
3. **🔌 Zariadenia 3. strán & Bojlery**:
   - Prehľad a ovládanie externých spotrebičov, bojlerov a relé.
4. **⚙️ Konfigurácia & Profil**:
   - Prepínanie profilu Domácnosť / Firma a systémové informácie.

---

## 7. Vlastné Pravidlá (Custom Rules Builder)

Môžete si vytvoriť vlastné automatizačné pravidlá priamo vo web rozhraní:
- `PRICE_BELOW`: Ak cena OKTE klesne pod X € -> Vynútené nabíjanie batérie zo siete.
- `PRICE_ABOVE`: Ak cena OKTE stúpne nad X € a batéria má dostatok energie -> Export so ziskom.
- `TIME_WINDOW`: V určenom časovom intervale -> Vykonanie zvolenej akcie.
- `SOC_BELOW`: Ochranná akcia pri poklese batérie pod zadané percento.

---

## 8. Zariadenia 3. Strán & Inteligentné Bojlery (`Hardware 3/`)

Všetky externé spotrebiče sú spravované samostatnou službou `Hardware 3/third_party_service.py`:
- **Shelly relé (Plus 1PM, Pro 4PM)**: Ovládanie cez lokálne HTTP REST API.
- **Tuya / Sonoff / Tasmota**: Ovládanie cez Wi-Fi.
- **Modbus TCP relé**: Priemyselné spínanie cievok (FC 05).

**Príklad použitia**: Bojler sa automaticky zapne, ak je na trhu záporná cena alebo ak výroba FVE presiahne 1500 W.

---

## 9. Signalizácia START LED Diódy

Na doske Raspberry Pi 5 / Waveshare CM5 je aktivovaná **START / Status LED dióda**:
- **4x rýchle zablikanie**: Znamená úspešné prijatie dát zo striedača cez RS485, prijatie riadiaceho príkazu alebo úspešný cloud heartbeat.
- Blikanie beží na pozadí a nemá žiadny vplyv na rýchlosť komunikácie.

---

## 10. E-Shop s Príslušenstvom

Priamo v aplikácii je integrovaný **ElvoSolar E-Shop** (`/eshop`), kde si môžete doobjednať:
1. **ElvoSolar CM5 AI Riadiacu Jednotku** (199 €)
2. **Smart Bojler Regulátor 3.5 kW** (89 €)
3. **Shelly Pro Smart DIN Relé** (49 €)
4. **3-fázový Modbus Elektromer s CT transformátormi** (75 €)
5. **Elvo Wallbox EV Smart Charger 11kW/22kW** (449 €)
6. **Teplotnú sondu PT1000 pre bojler** (19 €)

---

## 11. Riešenie Problémov (Troubleshooting)

- **Nekomunikuje so striedačom (Chyba zbernice)**:
  1. Skontrolujte prehodenie vodičov A+ a B- na svorkovnici CH1.
  2. Overte, či je na striedači nastavený správny Modbus Slave ID (zvyčajne ID 1).
  3. Skontrolujte správnosť baudrate (najčastejšie 9600, 8N1).
- **Zariadenie nevidí ceny z OKTE**:
  - Skontrolujte pripojenie k internetu (systém automaticky využíva lokálnu cache, ak internet dočasne vypadne).
- **Potrebujete zmeniť nastavenia**:
  - Všetky parametre môžete kedykoľvek upraviť vo webovej aplikácii v záložke *AI Chytré Riadenie* alebo *Konfigurácia*.

---
*Vytvorené tímom ElvoSolar pre inteligentnú energetiku.*

# Zariadenia 3. stran & Inteligentne Spotrebice

Tento priecinok obsahuje modularnu integraciu pre externe inteligentne spotrebice a rele:
- Bojlery a akumulacne nadrze (ohrev TUV v zapornych cenach a zo solarnych prebytkov)
- Tepelne cerpadla a klimatizacie
- Topne rebriky a elektricke kurenie
- Bazenove filtracie a cerpadla
- EV nabijacky pre elektromobily
- Inteligentne rele: Shelly (Plus/Pro/1PM), Tuya, Sonoff / Tasmota, Modbus TCP rele.
