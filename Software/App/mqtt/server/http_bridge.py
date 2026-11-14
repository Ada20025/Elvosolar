#!/usr/bin/env python3
# =============================================================================
# http_bridge.py — PLÁN B: HTTP -> MQTT mostík pre web (Ruka)
#
# Používa sa IBA vtedy, ak Websupport hosting blokuje odchádzajúce TLS
# spojenie na 8883 (web sa nedokáže s brokerom spojiť priamo). Beží na
# firemnom serveri a publikuje do lokálneho Mosquitto (127.0.0.1:1883).
#
# Spustenie (systemd):
#   BRIDGE_TOKEN=$(openssl rand -hex 32) MQTT_WEB_PASS=<heslo webapp> \
#       python3 http_bridge.py
#
# Web potom volá:  POST http://<server>:8899/publish
#   hlavička X-Bridge-Token: <BRIDGE_TOKEN>
#   telo: {"serial": "CM5_452", "command": "STROJ_STOP"}
# =============================================================================
import json
import os
import re
import secrets
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

try:
    import paho.mqtt.client as mqtt
except ImportError:
    raise SystemExit("Nainštaluj paho: pip3 install paho-mqtt")

TOKEN = os.environ.get("BRIDGE_TOKEN", "")
HOST = "127.0.0.1"
PORT = int(os.environ.get("BRIDGE_PORT", "8899"))
MQTT_HOST = "127.0.0.1"
MQTT_PORT = int(os.environ.get("MQTT_PORT", "1883"))
MQTT_WEB_PASS = os.environ.get("MQTT_WEB_PASS", "")
VALID_CMD = {"STROJ_START", "STROJ_STOP", "STROJ_AUTO", "STROJ_STATUS"}
SERIAL_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$")


def publish(serial, command):
    c = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2,
                    client_id="elvobridge-" + secrets.token_hex(4), clean_session=True)
    c.username_pw_set("webapp", MQTT_WEB_PASS)
    c.connect(MQTT_HOST, MQTT_PORT, 30)
    c.loop_start()
    try:
        info = c.publish("stroje/%s/prikazy" % serial, command, qos=1, retain=True)
        info.wait_for_publish(timeout=5)
    finally:
        c.loop_stop()
        c.disconnect()


class Handler(BaseHTTPRequestHandler):
    def _json(self, code, obj):
        body = json.dumps(obj).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        if self.path != "/publish":
            return self._json(404, {"status": "error", "message": "Neznáma cesta"})
        if not TOKEN or self.headers.get("X-Bridge-Token", "") != TOKEN:
            return self._json(403, {"status": "error", "message": "Neplatný token"})
        try:
            length = int(self.headers.get("Content-Length", "0") or 0)
            data = json.loads(self.rfile.read(length) or b"{}")
            serial = str(data.get("serial", ""))
            command = str(data.get("command", "")).upper()
            if not SERIAL_RE.match(serial) or command not in VALID_CMD:
                return self._json(400, {"status": "error", "message": "Neplatný serial alebo príkaz"})
            publish(serial, command)
            self._json(200, {"status": "success", "serial": serial, "command": command})
        except Exception as e:
            self._json(502, {"status": "error", "message": str(e)[:200]})

    def log_message(self, *args):
        pass


if __name__ == "__main__":
    if not TOKEN:
        raise SystemExit("Nastav env BRIDGE_TOKEN (openssl rand -hex 32)")
    if not MQTT_WEB_PASS:
        raise SystemExit("Nastav env MQTT_WEB_PASS (heslo používateľa webapp)")
    print("HTTP->MQTT bridge počúva na %s:%d (publikuje do %s:%d)" % (HOST, PORT, MQTT_HOST, MQTT_PORT))
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()
