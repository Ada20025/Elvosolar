# MQTT systém — vzdialené ovládanie strojov (1 000+ zariadení)

```
   RUKA                     MOZOG                        OVLÁDAČ
 webová appka          firemný Linux server          Raspberry Pi CM5
 (Websupport)          Mosquitto broker              v teréne (Wi-Fi/LTE)
    |                        |                             |
    |  klik "Zapnúť #452"  |                             |
    |--publish retained----->|  stroje/CM5_452/prikazy     |
    |  (STROJ_START)         |--push do ~50 ms------------>| vykoná na meniči
    |                        |                             |
    |  číta stavy (retained) |  stroje/CM5_452/stav        |
    |<----subscribe----------|<--------publish-------------| potvrdenie
```

**Princíp:** CM5 sa sám pripojí VÝCHODNÝM smerom na firemný server (žiadne
nastavovanie routeru/firewallu v teréne). Broker drží **retained** posledný
príkaz — po výpadku prúdu/signálu sa stroj sám vráti do správneho stavu.
Správy letia len pri zmene → 1 000 zariadení = minimálna záťaž.

## Topic-y

| Topic | Smer | Retained | Hodnoty |
|---|---|---|---|
| `stroje/{serial}/prikazy` | web → CM5 | áno | `STROJ_START`, `STROJ_STOP`, `STROJ_AUTO`, `STROJ_STATUS` |
| `stroje/{serial}/stav` | CM5 → web | áno | `STATUS_BEZI`, `STATUS_STOJI`, `STATUS_AUTO`, `STATUS_OFFLINE` (LWT) |

---

## 1) MOZOG — firemný Linux server (Mosquitto)

```bash
sudo apt install mosquitto openssl
sudo cp mqtt/server/mosquitto.conf /etc/mosquitto/conf.d/elvo.conf
sudo cp mqtt/server/acl.txt /etc/mosquitto/acl.txt

# používateľ webu (Ruka) — heslo si ulož, potrebuje ho Websupport
sudo mosquitto_passwd -c /etc/mosquitto/passwd webapp

# nové zariadenie (generuje heslo samo)
sudo bash mqtt/server/add_device.sh CM5_452
```

**TLS (port 8883) — odporúčané:** doména nasmerovaná na server a:

```bash
sudo apt install certbot
sudo certbot certonly --standalone -d mqtt.tvojadomena.sk
sudo mkdir -p /etc/mosquitto/certs
sudo cp /etc/letsencrypt/live/mqtt.tvojadomena.sk/fullchain.pem /etc/mosquitto/certs/
sudo cp /etc/letsencrypt/live/mqtt.tvojadomena.sk/privkey.pem  /etc/mosquitto/certs/
sudo chown -R mosquitto:mosquitto /etc/mosquitto/certs
sudo systemctl restart mosquitto
sudo ufw allow 8883/tcp    # iba 8883! 1883 ostáva len pre localhost
```

Test na serveri:
```bash
mosquitto_sub -h localhost -u webapp -P '<heslo webapp>' -t 'stroje/+/stav' -v
mosquitto_pub -h localhost -u webapp -P '<heslo webapp>' -t 'stroje/CM5_452/prikazy' -m STROJ_START -r
```

## 2) RUKA — Websupport (táto appka)

Appka beží ako doteraz, len **mimo Railway** (`config.php` s údajmi Websupport
MySQL). MQTT prístupové údaje doplň do nového súboru
`mqtt_config.local.php` (vedľa `mqtt_lib.php`, **nekomituje sa**):

```php
<?php
return [
    'host'     => 'mqtt.tvojadomena.sk',
    'port'     => 8883,
    'tls'      => true,
    'username' => 'webapp',
    'password' => '<heslo webapp z kroku 1>',
];
```

Ovládanie: prihlásený admin/mechanik → **`/mqtt`** (tlačidlá Zapnúť/Vypnúť/AUTO,
zelená/červená/sivá ikona, auto-refresh 5 s).

Vrstvená bezpečnosť webu: prístupová brána + session, CSRF token, whitelist
serial aj príkazu, rate limit 30 príkazov/min, audit do tabuľky `mqtt_audit`
(kto, kedy, čo), TLS s verify_peer, ACL — web nemôže čítať príkazy ani písať
stavy zariadení.

**Plán B (ak Websupport blokuje odchádzajúce 8883):** na firemnom serveri
spusti `mqtt/server/http_bridge.py` (systemd), nastav `BRIDGE_TOKEN` a
`MQTT_WEB_PASS`, v `mqtt_config.local.php` nastav
`'bridge_url' => 'http://<server>:8899/publish', 'bridge_token' => '...'`
a web bude publikovať cez tento HTTP mostík (terminál servera to prepošle do
brokera). (Pozn.: bridge podpora v `mqtt_lib.php` je pripravená doplniť —
pozri sekciu Riešenie problémov.)

## 3) OVLÁDAČ — Raspberry Pi CM5

Nové súbory: `Hardware/mqtt_client.py` (vlákno v `app.py`), stránka `/mqtt`,
endpointy `/api/mqtt/*`. Knižnica `paho-mqtt` sa doinštaluje sama
(`start.sh` / `install_service.sh`).

**Nastavenie raz, káblom:** pripoj sa k boxu (LAN kábel / AP hotspot) a otvor

```
http://<IP-boxu>/mqtt
```

Vyplň adresu firemného servera, port, username (= serial, napr. `CM5_452`),
heslo od správcu brokera → **Uložiť a pripojiť**. Diagnostika hneď ukáže,
či je spojenie živé. Údaje sa ukladajú do lokálnej DB → prežijú reštart.

## 4) SKÚŠKA OVERENIA (káblom = cloud)

Prvá skúška sa robí **káblom**, druhá **bez kábla (cloud)** — na oboch sa
používajú **úplne tie isté príkazy** (`STROJ_START/STOP/AUTO`) cez ten istý
spracovateľ (`handle_command`), takže výsledok je porovnateľný:

| Výsledok | Diagnóza |
|---|---|
| Lokálne (`/mqtt` na boxe) funguje, cloud nie | problém v **sieti/brokeri** (Wi-Fi/LTE, Websupport → broker, ACL/heslá) |
| Lokálne nefunguje | problém v **stroji** (RS485 zapojenie, menič, Modbus) |
| `STATUS_OFFLINE` na webe | CM5 stratil spojenie (LWT od brokera) |

Zdroj posledného príkazu vidíš v diagnostike: **LOCAL** (kábel) vs **MQTT** (cloud).

## Riešenie problémov

- **Broker odmietol spojenie (kód 4/5)** → zlé meno/heslo alebo chýba v `passwd`.
- **Web hlási „Broker nepotvrdil (PUBACK timeout)“** → firewall medzi Websupportom
  a serverom; skús Plán B.
- **Stavy sa nezobrazujú, spojenie OK** → skontroluj ACL (webapp musí mať
  `topic read stroje/+/stav`) a že CM5 publikoval aspoň raz.
- **Po reštarte servera** → retained správy prežijú (`persistence true`).
- **Bridge v mqtt_lib.php:** funkcia `elvo_mqtt_publish_command()` je izolovaná —
  pre Plán B stačí v nej prepínať na POST na bridge URL.

## Prehľad súborov

```
Software/App/
  mqtt_lib.php                 MQTT klient + CSRF + audit + rate limit (web)
  index.php                    route /mqtt + /api/mqtt/publish + /api/mqtt/states
  templates/mqtt_dashboard.html  RUKA — ovládanie a stavy strojov
  mqtt/server/mosquitto.conf   MOZOG — konfigurácia brokera (TLS, persistence)
  mqtt/server/acl.txt          MOZOG — práva (zariadenie = len svoje topic-y)
  mqtt/server/add_device.sh    MOZOG — pridanie CM5 zariadenia
  mqtt/server/http_bridge.py   MOZOG — Plán B (HTTP -> MQTT)
Hardware/
  mqtt_client.py               OVLÁDAČ — MQTT vlákno v app.py
  app.py                       + /mqtt, /api/mqtt/* (lokálna skúška káblom)
  templates/mqtt.html          OVLÁDAČ — lokálna diagnostika + konfigurácia
```
