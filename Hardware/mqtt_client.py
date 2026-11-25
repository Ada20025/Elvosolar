# =============================================================================
# mqtt_client.py — MQTT klient pre CM5 (Ovládač)
#
# ARCHITEKTÚRA (systém pre 1 000+ zariadení):
#   Ruka    = webová appka (Websupport)      -> publikuje príkaz do stroje/{ID}/prikazy
#   Mozog   = firemný Linux server (Mosquitto) -> retained správy, okamžitý push (~50 ms)
#   Ovládač = tento modul na CM5             -> počúva, vykoná príkaz, potvrdí do stroje/{ID}/stav
#
# Kľúčové vlastnosti:
# - Beží ako vlákno v rámci app.py (žiadny nový systemd service).
# - RETAINED príkaz: po pripojení (aj po výpadku prúdu/signálu) sa stroj vráti
#   do posledného platného stavu podľa cloudu.
# - LWT (Last Will): ak CM5 stratí spojenie, broker sám vypublikuje STATUS_OFFLINE
#   -> web ihneď zobrazí sivú ikonu.
# - handle_command() je SPOLOČNÝ pre príkazy z cloudu (MQTT) aj pre lokálnu
#   skúšku káblom -> identické príkazy na oboch cestách = jednoduchá diagnostika,
#   či problém je v stroji (lokálne zlyhá) alebo v sieti (lokálne funguje).
#
# Konfigurácia: DB tabuľka system_settings (mqtt_host, mqtt_port, mqtt_username,
#   mqtt_password, mqtt_tls, mqtt_cafile) alebo env premenné MQTT_HOST, MQTT_PORT,
#   MQTT_USERNAME, MQTT_PASSWORD, MQTT_TLS, MQTT_CAFILE (env prevažuje).
# Broker má nešifrované spojenie zakázané -> TLS na 8883 je vynútené.
# CA certifikát: isrgrootx1.pem vedľa appky (sťahuje ho updater).
# Nastavuje sa raz káblom cez lokálnu stránku http://<IP-boxu>/mqtt
# =============================================================================

import os
import json
import time
import threading
import logging

logger = logging.getLogger("MQTT")

# ---------------------------------------------------------------------------
# Stav modulu — závislosti z app.py sa vstrekujú cez configure()
# (žiadna kruhová import závislosť medzi app.py a mqtt_client.py)
# ---------------------------------------------------------------------------
_log_fn = print
_apply_fn = None                      # safe_apply_system_state z app.py
_get_override_fn = (lambda: "AUTO")   # getter bg_service.manual_override
_led = None                           # LedService (voliteľné)

_serial = None
_client = None
_thread = None
_hb_thread = None
_stop_flag = threading.Event()

_state = {
    "enabled": False,
    "connected": False,
    "broker": "",
    "port": 0,
    "tls": False,
    "topic_prikazy": "",
    "topic_stav": "",
    "last_command": "",
    "last_command_source": "",
    "last_command_time": "",
    "last_state_published": "",
    "last_connect_time": "",
    "reconnects": 0,
    "last_error": "",
    "events": [],
}
_state_lock = threading.Lock()

TOPIC_ROOT = "stroje"
CLIENT_ID_PREFIX = "CM5"
KEEPALIVE_S = 30
HEARTBEAT_S = 300          # každých 5 min obnoviť retained stav

# Príkaz -> hodnota manual_override (existujúca logika app.py)
CMD_MAP = {
    "STROJ_START": "ON",
    "STROJ_STOP": "OFF",
    "STROJ_AUTO": "AUTO",
}
# Hodnota override -> text stavu pre web
STATE_MAP = {
    "ON": "STATUS_BEZI",
    "OFF": "STATUS_STOJI",
    "AUTO": "STATUS_AUTO",
}


def configure(log_fn=None, apply_state_fn=None, get_override_fn=None, led_service=None):
    """Napojenie na app.py (log_message, safe_apply_system_state, bg_service, led)."""
    global _log_fn, _apply_fn, _get_override_fn, _led
    if log_fn:
        _log_fn = log_fn
    if apply_state_fn:
        _apply_fn = apply_state_fn
    if get_override_fn:
        _get_override_fn = get_override_fn
    if led_service:
        _led = led_service


def _now():
    return time.strftime("%Y-%m-%d %H:%M:%S")


def _event(msg):
    with _state_lock:
        _state["events"].append("[%s] %s" % (_now(), msg))
        if len(_state["events"]) > 12:
            _state["events"].pop(0)
    try:
        _log_fn("[MQTT] %s" % msg)
    except Exception:
        pass


def _set(**kv):
    with _state_lock:
        for k, v in kv.items():
            _state[k] = v


def get_serial():
    """Stabilná identita boxu — rovnaký serial ako pri registrácii do cloudu."""
    global _serial
    if _serial:
        return _serial
    try:
        from database import get_db_connection
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute("SELECT serial_number FROM devices WHERE serial_number IS NOT NULL AND serial_number != '' LIMIT 1")
        row = cur.fetchone()
        conn.close()
        if row and row[0]:
            _serial = str(row[0])
            return _serial
    except Exception:
        pass
    try:
        from serial_config_service import get_box_serial
        _serial = str(get_box_serial())
    except Exception:
        _serial = "CM5-DEFAULT"
    return _serial


def get_diagnostics():
    """Snapshot diagnostiky — používa lokálna stránka /mqtt aj /api/mqtt/diagnostics."""
    with _state_lock:
        snap = dict(_state)
        snap["events"] = list(_state["events"])
    snap["serial"] = get_serial()
    try:
        override = str(_get_override_fn() or "AUTO").strip().upper()
    except Exception:
        override = "AUTO"
    snap["state_current_override"] = override
    snap["state_text"] = STATE_MAP.get(override, "STATUS_NEZNAME")
    snap["known_commands"] = sorted(set(list(CMD_MAP.keys()) + ["STROJ_STATUS"]))
    return snap

# ---------------------------------------------------------------------------
# Konfigurácia brokera (DB system_settings, env prevažuje)
# ---------------------------------------------------------------------------
def _db_get_setting(key):
    try:
        from database import get_db_connection
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute("SELECT value FROM system_settings WHERE key = ?", (key,))
        row = cur.fetchone()
        conn.close()
        if row and row[0] is not None:
            return str(row[0])
    except Exception:
        pass
    return ""


def _db_set_setting(key, value):
    from database import get_db_connection
    conn = get_db_connection()
    cur = conn.cursor()
    cur.execute("DELETE FROM system_settings WHERE key = ?", (key,))
    cur.execute("INSERT INTO system_settings (key, value) VALUES (?, ?)", (key, str(value)))
    conn.commit()
    conn.close()


def load_config():
    """Vráti (host, port, username, password, tls)."""
    host = (os.environ.get("MQTT_HOST") or _db_get_setting("mqtt_host") or "").strip()
    try:
        port = int(os.environ.get("MQTT_PORT") or _db_get_setting("mqtt_port") or 0)
    except (TypeError, ValueError):
        port = 0
    username = (os.environ.get("MQTT_USERNAME") or _db_get_setting("mqtt_username") or "").strip()
    password = os.environ.get("MQTT_PASSWORD") or _db_get_setting("mqtt_password") or ""
    tls_raw = os.environ.get("MQTT_TLS")
    if tls_raw is None:
        tls_raw = _db_get_setting("mqtt_tls") or "1"
    tls = str(tls_raw).strip().lower() in ("1", "true", "yes", "on")
    if port <= 0:
        port = 8883 if tls else 1883
    # Nešifrované spojenie broker nepovolí. Výnimka len pre lokálny test.
    allow_plain = str(os.environ.get("MQTT_ALLOW_PLAINTEXT", "")).strip().lower() in ("1", "true", "yes")
    if not allow_plain:
        tls = True
        if port == 1883:
            port = 8883
    return host, port, username, password, tls


def resolve_cafile():
    """CA certifikát: env MQTT_CAFILE -> DB mqtt_cafile -> isrgrootx1.pem vedľa appky."""
    path = (os.environ.get("MQTT_CAFILE") or _db_get_setting("mqtt_cafile") or "").strip()
    if path and os.path.isfile(path):
        return path
    local = os.path.join(os.path.dirname(os.path.abspath(__file__)), "isrgrootx1.pem")
    if os.path.isfile(local):
        return local
    return ""


def _apply_tls(client):
    """TLS s overovaním certifikátu. Priložený ISRG Root X1 je istota,
    keď v systéme CM5 chýba alebo je store neúplný."""
    cafile = resolve_cafile()
    if cafile:
        client.tls_set(ca_certs=cafile)
    else:
        client.tls_set()


def save_config(host, port, username, password, tls):
    """Uloží broker config do DB — nastavuje sa raz káblom cez /mqtt.
    Nešifrované spojenie sa neukladá, broker ho aj tak odmietne."""
    _db_set_setting("mqtt_host", host)
    _db_set_setting("mqtt_port", 8883 if int(port or 0) in (0, 1883) else int(port))
    _db_set_setting("mqtt_username", username)
    if password:
        _db_set_setting("mqtt_password", password)
    _db_set_setting("mqtt_tls", "1")


# ---------------------------------------------------------------------------
# Publikovanie stavu (retained, QoS 1)
# ---------------------------------------------------------------------------
def get_state_text():
    try:
        override = str(_get_override_fn() or "AUTO").strip().upper()
    except Exception:
        override = "AUTO"
    return STATE_MAP.get(override, "STATUS_AUTO")


def _publish_one_shot(topic, payload):
    """Krátke samostatné spojenie (publish-and-disconnect) — fallback keď hlavné
    spojenie práve nie je aktívne. Používa prihlasovacie údaje tohto zariadenia."""
    import paho.mqtt.client as mqtt
    host, port, username, password, tls = load_config()
    if not host:
        raise RuntimeError("broker nie je nakonfigurovaný")
    c = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2,
                    client_id="%s-WEBCHK-%s" % (CLIENT_ID_PREFIX, os.getpid()),
                    clean_session=True)
    if username:
        c.username_pw_set(username, password)
    if tls:
        _apply_tls(c)
    c.connect(host, port, keepalive=KEEPALIVE_S)
    c.loop_start()
    try:
        info = c.publish(topic, payload, qos=1, retain=True)
        info.wait_for_publish(timeout=5)
    finally:
        c.loop_stop()
        c.disconnect()


def _publish_state(state_text, mqtt_client=None):
    topic = "%s/%s/stav" % (TOPIC_ROOT, get_serial())
    try:
        if mqtt_client is not None and mqtt_client.is_connected():
            info = mqtt_client.publish(topic, state_text, qos=1, retain=True)
            try:
                info.wait_for_publish(timeout=5)
            except Exception:
                pass
        else:
            _publish_one_shot(topic, state_text)
        _set(last_state_published=state_text)
        return True
    except Exception as e:
        _set(last_error="publish stav: %s" % e)
        return False


# ---------------------------------------------------------------------------
# SPOLOČNÝ SPRACOVATEĽ PRÍKAZOV (cloud MQTT aj lokálna skúška káblom)
# ---------------------------------------------------------------------------
def handle_command(payload, source="MQTT"):
    """Prijme príkaz (čistý text alebo JSON {"command": ...}).
    Podporované: STROJ_START, STROJ_STOP, STROJ_AUTO, STROJ_STATUS.
    Vykoná zmenu cez safe_apply_system_state (tá istá cesta ako lokálne API
    aj cloud poll) a potvrdí nový stav do topic .../stav (retained)."""
    raw = str(payload or "").strip()
    cmd = ""
    if raw.startswith("{"):
        try:
            cmd = str(json.loads(raw).get("command", "")).strip().upper()
        except Exception:
            cmd = ""
    if not cmd:
        cmd = raw.upper()

    _set(last_command=cmd, last_command_source=source, last_command_time=_now())

    if cmd == "STROJ_STATUS":
        st = get_state_text()
        _publish_state(st)
        return {"status": "success", "command": cmd, "state": st,
                "message": "Aktuálny stav: %s" % st}

    mapped = CMD_MAP.get(cmd)
    if not mapped:
        _event("Neznámy príkaz: %r (zdroj %s)" % (cmd, source))
        return {"status": "error", "message": "Neznámy príkaz: %s" % cmd}

    if _apply_fn is None:
        return {"status": "error", "message": "Aplikačná vrstva nie je pripojená"}

    try:
        ok = bool(_apply_fn(manual_override=mapped))
    except Exception as e:
        _set(last_error="apply: %s" % e)
        return {"status": "error", "message": "Zápis na menič zlyhal: %s" % e}

    if not ok:
        return {"status": "error", "message": "Zápis na menič zlyhal"}

    st = STATE_MAP.get(mapped, "STATUS_NEZNAME")
    if _led is not None:
        try:
            _led.anim_cloud_connecting()
        except Exception:
            pass
    _publish_state(st)
    _event("Príkaz %s vykonaný (zdroj: %s) -> %s" % (cmd, source, st))
    return {"status": "success", "command": cmd, "applied": mapped, "state": st,
            "message": "Stroj: %s (%s)" % (mapped, st)}


def handle_local_command(command):
    """Vstup pre LOKÁLNU skúšku káblom — úplne tie isté príkazy ako z cloudu.
    Diagnostika: lokálne funguje + cloud nie -> problém v sieti/brokeri;
    lokálne zlyhá -> problém v stroji."""
    return handle_command(command, source="LOCAL")

# ---------------------------------------------------------------------------
# Paho MQTT callbacks (v2 API; fallback pre staré paho 1.x)
# ---------------------------------------------------------------------------
def _on_connect(client, userdata, flags, reason_code, properties=None):
    is_failure = getattr(reason_code, "is_failure", lambda: False)
    try:
        failed = is_failure() if callable(is_failure) else bool(is_failure)
    except Exception:
        failed = False
    if failed:
        _set(last_error="broker odmietol spojenie: %s" % reason_code)
        _event("Broker odmietol spojenie: %s (skontroluj heslo/ACL)" % reason_code)
        return
    first = not _state.get("last_connect_time")
    with _state_lock:
        _state["connected"] = True
        _state["last_connect_time"] = _now()
        if not first:
            _state["reconnects"] = _state.get("reconnects", 0) + 1
    _event("Pripojené k brokeru (reason=%s)" % reason_code)
    try:
        client.subscribe("%s/%s/prikazy" % (TOPIC_ROOT, get_serial()), qos=1)
    except Exception as e:
        _event("subscribe zlyhal: %s" % e)
    # Hneď po pripojení potvrď aktuálny stav (web vie, že zariadenie žije)
    _publish_state(get_state_text(), mqtt_client=client)


def _on_disconnect(client, userdata, *args):
    _set(connected=False)
    reason = str(args[-1]) if args else "?"
    if reason not in ("Normal disconnection", "0", "None"):
        _event("Odpojené od brokera: %s (auto-reconnect beží)" % reason)


def _on_message(client, userdata, msg):
    try:
        payload = msg.payload.decode("utf-8", "replace")
    except Exception:
        payload = ""
    _event("Príkaz z cloudu: %r" % payload)
    handle_command(payload, source="MQTT")


def _run_client():
    global _client
    try:
        import paho.mqtt.client as mqtt
    except Exception as e:
        _set(enabled=False,
             last_error="paho-mqtt nie je nainštalované (spusti install_service.sh alebo pip install paho-mqtt)")
        _event("knižnica paho-mqtt chýba: %s" % e)
        return

    host, port, username, password, tls = load_config()
    if not host:
        _set(enabled=False)
        _event("Broker nie je nakonfigurovaný — nastav ho káblom cez http://<IP-boxu>/mqtt")
        return

    serial = get_serial()
    topic_prikazy = "%s/%s/prikazy" % (TOPIC_ROOT, serial)
    topic_stav = "%s/%s/stav" % (TOPIC_ROOT, serial)
    _set(enabled=True, broker=host, port=port, tls=tls,
         topic_prikazy=topic_prikazy, topic_stav=topic_stav, last_error="")

    client_id = "%s-%s" % (CLIENT_ID_PREFIX, serial)
    paho_v2 = hasattr(mqtt, "CallbackAPIVersion")
    try:
        if paho_v2:
            client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2,
                                 client_id=client_id, clean_session=False)
        else:
            client = mqtt.Client(client_id=client_id, clean_session=False)
    except Exception as e:
        _set(last_error="init paho: %s" % e)
        return

    if username:
        client.username_pw_set(username, password)
    if tls:
        _apply_tls(client)
    # LWT: ak CM5 stratí spojenie, broker vypublikuje STATUS_OFFLINE (retained)
    client.will_set(topic_stav, "STATUS_OFFLINE", qos=1, retain=True)
    client.on_message = _on_message
    if paho_v2:
        client.on_connect = _on_connect
        client.on_disconnect = _on_disconnect
    else:
        def _on_connect_v1(c, u, flags, rc):
            _on_connect(c, u, flags, rc)
        def _on_disconnect_v1(c, u, rc):
            _on_disconnect(c, u, rc)
        client.on_connect = _on_connect_v1
        client.on_disconnect = _on_disconnect_v1
    client.reconnect_delay_set(min_delay=1, max_delay=60)
    _client = client

    _event("Spojujem s brokerom %s:%s (TLS=%s, CA=%s, client_id=%s)"
           % (host, port, tls, resolve_cafile() or "systémový", client_id))
    try:
        client.loop_forever(retry_first_connection=True)  # auto-reconnect navždy
    except Exception as e:
        _set(last_error="loop: %s" % e)
    _set(connected=False)


def _heartbeat_loop():
    """Pravidelne obnovuje retained stav — web má vždy čerstvý stav aj po reštarte brokera."""
    while True:
        if _stop_flag.wait(HEARTBEAT_S):
            return
        try:
            if _state.get("connected"):
                _publish_state(get_state_text())
        except Exception:
            pass


def start():
    """Spustí MQTT klienta (idempotentné — volá sa raz z app.py pri štarte)."""
    global _thread, _hb_thread
    if _hb_thread is None or not _hb_thread.is_alive():
        _hb_thread = threading.Thread(target=_heartbeat_loop, name="mqtt-heartbeat", daemon=True)
        _hb_thread.start()
    if _thread is not None and _thread.is_alive():
        return
    _stop_flag.clear()
    _thread = threading.Thread(target=_run_client, name="mqtt-client", daemon=True)
    _thread.start()


def stop():
    _stop_flag.set()


def restart():
    """Čistý reštart klienta po zmene konfigurácie (POST /api/mqtt/config)."""
    global _thread
    _set(connected=False)
    c = _client
    if c is not None:
        try:
            c.disconnect()  # ukončí loop_forever v bežiacom vlákne
        except Exception:
            pass
    t = _thread
    if t is not None:
        try:
            t.join(timeout=5)
        except Exception:
            pass
    _thread = None
    _client = None
    start()
