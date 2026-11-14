# Hardware/led_service.py
# =============================================================================
#  SK6812 RGBW — 3 stavové LED (Waveshare CM5 / Raspberry Pi 5)
# =============================================================================
#  Zapojenie:
#    - z 60-LED pásku SK6812 RGBW sú použité len 3 LED (3 ks vystrihnuté/za sebou)
#    - DIN prvej LED = GPIO 27 (pin 13 na 40-pinovom konektore)
#    - 5 V z vlastného zdroja, GND pásku a GND CM5 spoločná
#  Protokol: 800 kHz, 4 bajty na LED v poradí G, R, B, W (SK6812 RGBW)
#
#  Ako sa to riadi na Pi 5 / CM5:
#    Pi 5 nemá starú PWM/DMA cestu (rpi_ws281x ani pigpio tam nefungujú).
#    Používame kernel PIO overlay, ktorý vie hocijaký GPIO vrátane 27:
#
#        /boot/firmware/config.txt:
#        dtoverlay=ws2812-pio,gpio=27,num_leds=3,rgbw
#
#    Po reštarte vznikne /dev/leds0 a zapisujú sa doň surové bajty (4 na LED).
#    Časovanie rieši RP1 PIO, nie Python — žiadne blikanie ani záseky.
#
#  Voliteľné premenné prostredia:
#    SK6812_PIN=27            dátový GPIO
#    SK6812_LEDS=3            počet LED (3)
#    SK6812_DEVICE=/dev/leds0 zariadenie kernel drivera
#    SK6812_BRIGHTNESS=100    predvolený jas v %
#    SK6812_ALLOW_BITBANG=0   1 = povolí záložný bit-bang GPIO (len núdzovo)
# =============================================================================

import math
import os
import time
import threading
import logging

logger = logging.getLogger("LED")

# ─── KONFIGURÁCIA ────────────────────────────────────────────────────────────
DATA_PIN = int(os.environ.get("SK6812_PIN", "27"))
NUM_LEDS = int(os.environ.get("SK6812_LEDS", "3"))
LED_DEVICE = os.environ.get("SK6812_DEVICE", "/dev/leds0")
DEFAULT_BRIGHTNESS = int(os.environ.get("SK6812_BRIGHTNESS", "100"))
ALLOW_BITBANG = os.environ.get("SK6812_ALLOW_BITBANG", "0") == "1"
RENDER_FPS = 30

OVERLAY_HINT = f"dtoverlay=ws2812-pio,gpio={DATA_PIN},num_leds={NUM_LEDS},rgbw"

# (r, g, b, w) 0-255
BLACK = (0, 0, 0, 0)
BLUE = (0, 0, 255, 0)
DEEP_BLUE = (0, 0, 170, 0)
CYAN = (0, 255, 255, 0)
AZURE = (0, 130, 255, 0)
PURPLE = (150, 0, 255, 0)
GREEN = (0, 255, 0, 0)
AMBER = (255, 140, 0, 0)
MAGENTA = (255, 0, 120, 0)
RED = (255, 0, 0, 0)
RED_ORANGE = (255, 40, 0, 0)
WHITE = (0, 0, 0, 255)

# =============================================================================
#  SLOVNÍK SVETLA — každý stav má farbu, vzor a význam
# =============================================================================
#  effect: solid | breathe | blink | chase | off
#  blink:  (počet bliknutí, svieti s, pauza s, medzera medzi bliknutiami s)
STATE_TABLE = {
    "off": dict(
        color=BLACK, effect="off", label="Vypnuté",
        meaning="Pásek nesvieti.",
    ),
    "boot": dict(
        color=BLUE, effect="breathe", label="Štart",
        meaning="Modrá pomaly dýcha = systém sa spúšťa (boot).",
    ),
    "setup": dict(
        color=CYAN, effect="blink", blink=(2, 0.25, 0.25, 1.2),
        label="Setup",
        meaning="Azúrová 2× blik = zariadenie čaká na nastavenie (setup režim).",
    ),
    "ap_mode": dict(
        color=CYAN, effect="blink", blink=(1, 0.1, 0.1, 0.25),
        label="WiFi AP",
        meaning="Azúrová rýchlo bliká = WiFi hotspot pre nastavenie je aktívny.",
    ),
    "connecting": dict(
        color=DEEP_BLUE, effect="breathe", label="Pripájanie",
        meaning="Tmavomodrá dýcha = pripája sa zbernica / cloud.",
    ),
    "cloud": dict(
        color=AZURE, effect="pulse", label="Cloud",
        meaning="Modrozelený pulz = komunikácia s cloudom.",
    ),
    "scanning": dict(
        color=PURPLE, effect="chase", label="Hľadanie",
        meaning="Fialová behá po 3 LED = hľadajú sa zariadenia na zbernici.",
    ),
    "ok": dict(
        color=GREEN, effect="solid", label="OK",
        meaning="Zelená ticho svieti = všetko v poriadku, komunikácia beží.",
    ),
    "warn": dict(
        color=AMBER, effect="breathe", label="Varovanie",
        meaning="Jantárová dýcha = varovanie: vysoká teplota alebo nízke SOC.",
    ),
    "bus_error": dict(
        color=RED, effect="blink", blink=(3, 0.2, 0.2, 1.4),
        label="Chyba zbernice",
        meaning="Červená 3× blik = striedač neodpovedá (Modbus timeout / zbernica).",
    ),
    "cmd_error": dict(
        color=RED_ORANGE, effect="blink", blink=(5, 0.1, 0.1, 1.6),
        label="Príkaz zlyhal",
        meaning="Červeno-oranžová 5× rýchlo = striedač príkaz nevykonal (test/overenie zlyhalo).",
    ),
    "inverter_fault": dict(
        color=RED, effect="solid", label="Porucha striedača",
        meaning="Červená stále svieti = striedač hlási poruchu (fault).",
    ),
    "net_error": dict(
        color=MAGENTA, effect="blink", blink=(4, 0.2, 0.2, 1.2),
        label="Chyba siete",
        meaning="Purpurová 4× blik = výpadok WiFi / internetu.",
    ),
    "update_check": dict(
        color=GREEN, effect="blink", blink=(1, 0.4, 0.4, 2.0),
        label="Update: kontrola",
        meaning="Zelená 1× blik = kontroluje sa nová verzia.",
    ),
    "update_download": dict(
        color=GREEN, effect="blink", blink=(2, 0.25, 0.25, 1.2),
        label="Update: sťahovanie",
        meaning="Zelená 2× blik = sťahuje sa nová verzia.",
    ),
    "update_install": dict(
        color=GREEN, effect="blink", blink=(3, 0.15, 0.15, 1.0),
        label="Update: inštalácia",
        meaning="Zelená 3× blik = zapisujú sa nové súbory.",
    ),
    "update_restart": dict(
        color=GREEN, effect="pulse", label="Update: reštart",
        meaning="Zelená rýchlo pulzuje = reštart aplikácie po update.",
    ),
    "flashlight": dict(
        color=WHITE, effect="solid", label="Baterka",
        meaning="Čistá biela = manuálne svetlo z appky (baterka).",
    ),
    "manual": dict(
        color=WHITE, effect="solid", label="Manuálna farba",
        meaning="Manuálna farba nastavená z appky.",
    ),
}

# Staré názvy animácií -> nové stavy (spätná kompatibilita)
_LEGACY_ANIM = {
    "anim_boot": "boot",
    "anim_connecting": "connecting",
    "anim_fault": "bus_error",
    "anim_ok": "ok",
    "anim_white": "flashlight",
    "anim_scanning": "scanning",
    "anim_cloud_connecting": "cloud",
    "anim_wifi_ap": "ap_mode",
    "anim_startup": "boot",
}


def _clamp8(value):
    try:
        value = int(value)
    except (TypeError, ValueError):
        return 0
    return 0 if value < 0 else (255 if value > 255 else value)


def _scale(color, factor):
    factor = 0.0 if factor < 0 else factor
    return (_clamp8(color[0] * factor), _clamp8(color[1] * factor),
            _clamp8(color[2] * factor), _clamp8(color[3] * factor))


# =============================================================================
#  DRIVERY
# =============================================================================
class _KernelPioDriver:
    """Kernel ws2812-pio overlay -> /dev/leds0 (Pi 5 / CM5, ľubovoľný GPIO)."""

    name = "kernel-ws2812-pio"

    def __init__(self):
        self._path = LED_DEVICE
        self._fd = None
        if not os.path.exists(self._path):
            raise RuntimeError(f"{self._path} neexistuje (overlay nie je zapnutý)")
        self._open()

    def _open(self):
        if self._fd is not None:
            try:
                os.close(self._fd)
            except OSError:
                pass
        self._fd = os.open(self._path, os.O_WRONLY)

    def write(self, frame):
        try:
            os.write(self._fd, frame)
        except OSError:
            # Kernel driver po chybe zavrie buffer — skúsime znovu otvoriť raz
            self._open()
            os.write(self._fd, frame)

    def close(self):
        if self._fd is not None:
            try:
                os.close(self._fd)
            except OSError:
                pass
            self._fd = None


class _Ws281xDriver:
    """rpi_ws281x — funguje len na PWM kanáloch (GPIO 18 / 12 / 21 / 10)."""

    name = "rpi_ws281x"

    def __init__(self):
        if DATA_PIN not in (10, 12, 18, 21):
            raise RuntimeError(f"GPIO {DATA_PIN} nie je PWM kanál pre rpi_ws281x")
        from rpi_ws281x import PixelStrip, Color, SK6812_STRIP_RGBW  # noqa: F401
        self._Color = Color
        self._strip = PixelStrip(
            NUM_LEDS, DATA_PIN, freq_hz=800000, dma=10, invert=False,
            brightness=255, channel=0, strip_type=SK6812_STRIP_RGBW,
        )
        self._strip.begin()

    def write(self, frame):
        # frame je G,R,B,W na LED
        for i in range(NUM_LEDS):
            g, r, b, w = frame[i * 4:i * 4 + 4]
            self._strip.setPixelColor(i, self._Color(r, g, b, w))
        self._strip.show()

    def close(self):
        try:
            self.write(bytes(4 * NUM_LEDS))
        except Exception:
            pass


class _SpiDriver:
    """SPI (MOSI = GPIO 10) — záložná cesta, ak LED visia na pine 10."""

    name = "spidev"

    def __init__(self):
        if DATA_PIN != 10:
            raise RuntimeError("SPI driver vyžaduje dátový pin GPIO 10 (MOSI)")
        import spidev
        self._spi = spidev.SpiDev()
        self._spi.open(0, 0)
        self._spi.max_speed_hz = 2400000
        self._spi.mode = 0

    @staticmethod
    def _encode(frame):
        # 3 bity na jeden dátový bit pri 2.4 MHz = 800 kHz
        table = {0: (0b100, 3), 1: (0b110, 3)}
        out = bytearray()
        acc = 0
        bits = 0
        for byte in frame:
            for shift in range(7, -1, -1):
                val, n = table[(byte >> shift) & 1]
                acc = (acc << n) | val
                bits += n
                while bits >= 8:
                    bits -= 8
                    out.append((acc >> bits) & 0xFF)
        if bits:
            out.append((acc << (8 - bits)) & 0xFF)
        out.extend(b"\x00" * 40)  # reset pulse
        return bytes(out)

    def write(self, frame):
        self._spi.writebytes2(self._encode(frame))

    def close(self):
        try:
            self._spi.close()
        except Exception:
            pass


class _GpiodBitbangDriver:
    """Núdzový bit-bang z userspace. Časovanie nie je garantované."""

    name = "gpiod-bitbang"

    def __init__(self):
        import gpiod
        chip = None
        for path in ("/dev/gpiochip4", "/dev/gpiochip0", "gpiochip4", "gpiochip0"):
            try:
                chip = gpiod.Chip(path)
                break
            except Exception:
                continue
        if chip is None:
            raise RuntimeError("gpiod chip nenájdený")
        self._chip = chip
        settings = gpiod.LineSettings(direction=gpiod.line.Direction.OUTPUT,
                                      output_value=gpiod.line.Value.INACTIVE)
        self._request = chip.request_lines(consumer="sk6812", config={DATA_PIN: settings})
        self._gpiod = gpiod

    def _bit(self, one):
        high_ns = 600 if one else 300
        low_ns = 900
        r = self._request
        gpiod = self._gpiod
        r.set_value(DATA_PIN, gpiod.line.Value.ACTIVE)
        t0 = time.perf_counter_ns()
        while time.perf_counter_ns() - t0 < high_ns:
            pass
        r.set_value(DATA_PIN, gpiod.line.Value.INACTIVE)
        t0 = time.perf_counter_ns()
        while time.perf_counter_ns() - t0 < low_ns:
            pass

    def write(self, frame):
        for byte in frame:
            for shift in range(7, -1, -1):
                self._bit((byte >> shift) & 1)
        time.sleep(0.0003)

    def close(self):
        try:
            self._request.release()
            self._chip.close()
        except Exception:
            pass


# =============================================================================
#  SLUŽBA
# =============================================================================
class LedService:
    _instance = None
    _instance_lock = threading.Lock()

    def __new__(cls):
        with cls._instance_lock:
            if cls._instance is None:
                cls._instance = super().__new__(cls)
                cls._instance._initialized = False
            return cls._instance

    def __init__(self):
        if getattr(self, "_initialized", False):
            return
        self._initialized = True
        self._lock = threading.RLock()
        self._stop_event = threading.Event()
        self._num_leds = NUM_LEDS
        self._brightness = max(0, min(100, DEFAULT_BRIGHTNESS))
        self._state = "off"
        self._state_since = time.monotonic()
        self._state_note = ""
        self._manual_until = 0.0        # 0 = manuál nikdy nevyprší (baterka)
        self._manual_state = None
        self._error_until = 0.0         # dočasná chybová signalizácia
        self._error_state = None
        self._last_auto_state = "boot"
        self._manual_color = WHITE
        self._driver = None
        self._driver_error = ""
        self._render_thread = None
        self._init_driver()
        self.set_state("boot")
        self._start_renderer()

    # ── inicializácia ────────────────────────────────────────────────────────
    def _init_driver(self):
        candidates = [_KernelPioDriver, _Ws281xDriver, _SpiDriver]
        if ALLOW_BITBANG:
            candidates.append(_GpiodBitbangDriver)

        errors = []
        for cls in candidates:
            try:
                self._driver = cls()
                logger.info("[LED] Driver: %s | %d LED | GPIO %d",
                            cls.name, self._num_leds, DATA_PIN)
                return
            except ImportError as e:
                errors.append(f"{cls.name}: chýba knižnica ({e})")
            except Exception as e:
                errors.append(f"{cls.name}: {e}")

        self._driver = None
        self._driver_error = " | ".join(errors)
        logger.warning("[LED] Žiadny driver nedostupný — LED sa neovládajú. %s",
                       self._driver_error)
        if not os.path.exists(LED_DEVICE):
            logger.warning("[LED] Zapni kernel overlay v /boot/firmware/config.txt:")
            logger.warning("[LED]     %s", OVERLAY_HINT)
            logger.warning("[LED] potom reštart CM5 (5 V + spoločná GND).")

    def _start_renderer(self):
        self._render_thread = threading.Thread(target=self._render_loop, daemon=True)
        self._render_thread.start()

    # ── render slučka ────────────────────────────────────────────────────────
    def _render_loop(self):
        period = 1.0 / RENDER_FPS
        while not self._stop_event.is_set():
            try:
                self._push(self._frame_for(self._state, self._elapsed()))
            except Exception:
                pass
            time.sleep(period)

    def _elapsed(self):
        return time.monotonic() - self._state_since

    def _frame_for(self, state, elapsed):
        spec = STATE_TABLE.get(state, STATE_TABLE["off"])
        color = spec.get("color", BLACK)
        effect = spec.get("effect", "off")
        n = self._num_leds

        if state == "manual":
            # manuálna farba z appky (nie z tabuľky stavov)
            color = getattr(self, "_manual_color", WHITE)
            effect = "solid"

        if effect == "off":
            pixels = [BLACK] * n
        elif effect == "solid":
            pixels = [color] * n
        elif effect == "breathe":
            f = 0.15 + 0.85 * (0.5 + 0.5 * math.sin(2 * math.pi * elapsed / 3.0))
            pixels = [_scale(color, f)] * n
        elif effect == "pulse":
            f = 0.15 + 0.85 * (0.5 + 0.5 * math.sin(2 * math.pi * elapsed / 1.2))
            pixels = [_scale(color, f)] * n
        elif effect == "blink":
            count, on_s, off_s, pause_s = spec.get("blink", (2, 0.2, 0.2, 1.0))
            unit = on_s + off_s
            cycle = count * unit + pause_s
            t = elapsed % cycle
            lit = False
            if t < count * unit:
                idx = int(t / unit)
                lit = (t - idx * unit) < on_s
            pixels = [color if lit else BLACK] * n
        elif effect == "chase":
            idx = int(elapsed / 0.25) % n
            pixels = [BLACK] * n
            pixels[idx] = color
        else:
            pixels = [BLACK] * n

        br = self._brightness / 100.0
        return [_scale(px, br) for px in pixels]

    def _push(self, pixels):
        if self._driver is None:
            return False
        frame = bytearray()
        for r, g, b, w in pixels:
            frame += bytes((_clamp8(g), _clamp8(r), _clamp8(b), _clamp8(w)))
        try:
            self._driver.write(bytes(frame))
            return True
        except Exception as e:
            logger.warning("[LED] Zápis do LED zlyhal: %s", e)
            return False

    # ── stavy ────────────────────────────────────────────────────────────────
    def is_manual(self):
        """True ak svieti manuálne svetlo z appky (baterka / farba)."""
        return self._manual_state is not None

    def set_state(self, code, note="", force=False):
        """Nastaví stav svietenia podľa slovníka. Manuálne svetlo má prednosť."""
        if code not in STATE_TABLE:
            code = "ok"
        with self._lock:
            if self._manual_state is not None and not force:
                return self._state
            if time.time() < self._error_until and not force:
                return self._state
            if code == self._state and not note:
                return self._state
            self._state = code
            self._state_note = note
            self._state_since = time.monotonic()
            if code not in ("manual", "flashlight", "off"):
                self._last_auto_state = code
            logger.info("[LED] Stav: %s (%s)%s", code,
                        STATE_TABLE[code]["label"], f" — {note}" if note else "")
        return self._state

    def signal_error(self, code="cmd_error", hold_seconds=20):
        """Dočasná chyba (napr. príkaz nevykonaný) — bliká a potom sa vráti stav."""
        if code not in STATE_TABLE:
            code = "cmd_error"
        with self._lock:
            self._error_state = code
            self._error_until = time.time() + max(1, int(hold_seconds))
            self._manual_state = None
            self._manual_until = 0.0
            self._state = code
            self._state_since = time.monotonic()
            logger.info("[LED] Chyba: %s (%s)", code, STATE_TABLE[code]["meaning"])
        threading.Timer(max(1, int(hold_seconds)), self._clear_error).start()
        return self._state

    def _clear_error(self):
        with self._lock:
            if self._error_state and time.time() >= self._error_until:
                self._error_state = None
                fallback = self._last_auto_state
                if fallback in ("manual", "flashlight", "off"):
                    fallback = "ok"
                self._state = fallback
                self._state_since = time.monotonic()

    def flash_ok(self):
        """Krátke zelené potvrdenie (3× rýchlo) a návrat k stavu OK."""
        with self._lock:
            if self._manual_state is not None:
                return
            self._error_state = None
            self._state = "ok"
            self._state_since = time.monotonic()
            self._last_auto_state = "ok"

    # ── manuálne svetlo z appky ─────────────────────────────────────────────
    def flashlight(self, on=True, brightness=None, hold_seconds=0):
        """Čistá biela ako baterka na telefóne — svieti iba biela LED (W)."""
        if not on:
            self.off()
            return self.status()
        self.stop_animation()
        with self._lock:
            if brightness is not None:
                self._brightness = max(0, min(100, int(brightness)))
            self._manual_state = "flashlight"
            self._manual_until = 0.0 if not hold_seconds else time.time() + int(hold_seconds)
            self._state = "flashlight"
            self._state_since = time.monotonic()
        logger.info("[LED] Baterka: čistá biela, jas %d%%", self._brightness)
        return self.status()

    def set_manual(self, mode="color", brightness=None, r=0, g=0, b=0, w=0,
                   hold_seconds=0):
        self.stop_animation()
        with self._lock:
            if brightness is not None:
                self._brightness = max(0, min(100, int(brightness)))
            self._manual_state = "white" if mode == "white" else "color"
            self._manual_until = 0.0 if not hold_seconds else time.time() + int(hold_seconds)
            self._state = "manual"
            self._state_since = time.monotonic()
            self._manual_color = ((0, 0, 0, 255) if self._manual_state == "white"
                                  else (_clamp8(r), _clamp8(g), _clamp8(b), _clamp8(w)))
        return self.status()

    def set_color(self, r=0, g=0, b=0, w=0):
        """Manuálna farba (r, g, b, w), drží kým sa nezavolá off()."""
        return self.set_manual("color", r=r, g=g, b=b, w=w)

    def set_brightness(self, percent):
        self._brightness = max(0, min(100, int(percent)))
        return self._brightness

    def off(self):
        """Vypne LED a vráti ovládanie stavovému režimu."""
        with self._lock:
            self._manual_state = None
            self._manual_until = 0.0
            self._error_state = None
            self._error_until = 0.0
            self._state = "off"
            self._state_since = time.monotonic()
            self._last_auto_state = "off"
        self._push([BLACK] * self._num_leds)
        logger.info("[LED] Vypnuté")
        return self.status()

    def status(self):
        spec = STATE_TABLE.get(self._state, STATE_TABLE["off"])
        return {
            "state": self._state,
            "label": spec["label"],
            "meaning": spec["meaning"],
            "note": self._state_note,
            "brightness": self._brightness,
            "num_leds": self._num_leds,
            "pin": DATA_PIN,
            "driver": self._driver.name if self._driver else None,
            "device": LED_DEVICE,
            "manual": self._manual_state is not None,
            "manual_color": list(getattr(self, "_manual_color", (0, 0, 0, 0))),
            "overlay_hint": OVERLAY_HINT,
        }

    @staticmethod
    def legend():
        """Slovník svietenia pre appku / diagnostiku."""
        return [
            {"state": code, "label": spec["label"], "color": list(spec["color"]),
             "effect": spec["effect"], "meaning": spec["meaning"]}
            for code, spec in STATE_TABLE.items() if code != "off"
        ]

    # ── spätná kompatibilita so starými anim_* volaniami ────────────────────
    def stop_animation(self):
        return None

    def anim_boot(self):
        return self.set_state("boot")

    def anim_connecting(self):
        return self.set_state("connecting")

    def anim_fault(self):
        return self.set_state("bus_error")

    def anim_ok(self):
        return self.set_state("ok")

    def anim_white(self):
        return self.set_state("flashlight")

    def anim_scanning(self):
        return self.set_state("scanning")

    def anim_cloud_connecting(self):
        return self.set_state("cloud")

    def anim_wifi_ap(self):
        return self.set_state("ap_mode")

    def anim_startup(self):
        return self.set_state("boot")

    # ── upratovanie ─────────────────────────────────────────────────────────
    def cleanup(self):
        self._stop_event.set()
        if self._render_thread and self._render_thread.is_alive():
            self._render_thread.join(timeout=2)
        try:
            self._push([BLACK] * self._num_leds)
        except Exception:
            pass
        if self._driver is not None:
            try:
                self._driver.close()
            except Exception:
                pass
            self._driver = None
        logger.info("[LED] Cleanup")

    def __del__(self):
        try:
            self.cleanup()
        except Exception:
            pass
