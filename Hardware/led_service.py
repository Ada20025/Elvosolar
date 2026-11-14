# Hardware/led_service.py
# =============================================================================
#  SK6812 RGBW — adresovateľný LED pásek (Waveshare CM5 / Raspberry Pi 5)
# =============================================================================
#  Zapojenie:
#    - 3 pásky SK6812 RGBW zapojené ZA SEBOU (DO pásky 1 -> DIN pásky 2 -> ...)
#    - DIN prvého pásku = GPIO 27 (pin 13 na 40-pinovom konektore)
#    - GND páskov + GND CM5 spoločná, 5 V napájanie páskov (nie z CM5!)
#    - pri 3 páskoch doporučujeme napájanie vstreknúť aj do 2. a 3. pásku
#  Protokol: 800 kHz, 4 bajty na LED v poradí G, R, B, W (SK6812 RGBW)
#
#  Ako sa to riadi na Pi 5 / CM5:
#    Pi 5 nemá starú PWM/DMA cestu (rpi_ws281x ani pigpio tam nefungujú).
#    Používame kernel PIO overlay, ktorý vie hocijaký GPIO vrátane 27:
#
#        /boot/firmware/config.txt:
#        dtoverlay=ws2812-pio,gpio=27,num_leds=180,rgbw
#
#    Po reštarte vznikne /dev/leds0 a do neho sa zapisujú surové bajty
#    (4 na LED). Presný časovanie rieši RP1 PIO, nie Python — žiadne blikanie.
#
#  Voliteľné premené prostredia:
#    SK6812_PIN=27               dátový GPIO
#    SK6812_STRIPS=3             počet páskov za sebou
#    SK6812_LEDS_PER_STRIP=60    LED na jeden pásek
#    SK6812_LEDS=180             celkový počet LED (prebije predošlé dve)
#    SK6812_DEVICE=/dev/leds0    zariadenie kernel drivera
#    SK6812_BRIGHTNESS=100       predvolený jas v %
#    SK6812_ALLOW_BITBANG=0      1 = povolí záložné bit-bang GPIO (len núdzovo)
# =============================================================================

import os
import time
import threading
import logging

logger = logging.getLogger("LED")

# ─── KONFIGURÁCIA ────────────────────────────────────────────────────────────
DATA_PIN = int(os.environ.get("SK6812_PIN", "27"))
NUM_STRIPS = int(os.environ.get("SK6812_STRIPS", "3"))
LEDS_PER_STRIP = int(os.environ.get("SK6812_LEDS_PER_STRIP", "60"))
NUM_LEDS = int(os.environ.get("SK6812_LEDS", str(NUM_STRIPS * LEDS_PER_STRIP)))
LED_DEVICE = os.environ.get("SK6812_DEVICE", "/dev/leds0")
DEFAULT_BRIGHTNESS = int(os.environ.get("SK6812_BRIGHTNESS", "100"))
ALLOW_BITBANG = os.environ.get("SK6812_ALLOW_BITBANG", "0") == "1"

# Manuálne svetlo z appky (baterka) drží stav a nebije ho stavová animácia
MANUAL_HOLD_SECONDS = int(os.environ.get("SK6812_MANUAL_HOLD", "900"))

OVERLAY_HINT = (
    f"dtoverlay=ws2812-pio,gpio={DATA_PIN},num_leds={NUM_LEDS},rgbw"
)

# Farby sú (r, g, b, w) v rozsahu 0-255
COLOR_OFF = (0, 0, 0, 0)
COLOR_RED = (255, 0, 0, 0)
COLOR_GREEN = (0, 255, 0, 0)
COLOR_BLUE = (0, 0, 255, 0)
COLOR_WHITE = (0, 0, 0, 255)
COLOR_CYAN = (0, 255, 255, 0)
COLOR_YELLOW = (255, 255, 0, 0)
COLOR_PURPLE = (150, 0, 255, 0)


def _clamp8(value):
    try:
        value = int(value)
    except (TypeError, ValueError):
        return 0
    return 0 if value < 0 else (255 if value > 255 else value)


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
        from rpi_ws281x import PixelStrip, SK6812_STRIP_RGBW  # noqa: F401
        self._strip = PixelStrip(
            NUM_LEDS, DATA_PIN, freq_hz=800000, dma=10, invert=False,
            brightness=255, channel=0, strip_type=SK6812_STRIP_RGBW,
        )
        self._strip.begin()
        self._color = None
        from rpi_ws281x import Color
        self._Color = Color

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
    """SPI (MOSI = GPIO 10) — záložná cesta, ak pásek visí na pine 10."""

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
        low_ns = 900 if one else 900
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
        self._anim_thread = None
        self._num_leds = NUM_LEDS
        self._pixels = bytearray(4 * NUM_LEDS)   # G,R,B,W na LED
        self._brightness = max(0, min(100, DEFAULT_BRIGHTNESS))
        self._mode = "off"
        self._color = COLOR_OFF
        self._manual_until = 0.0
        self._driver = None
        self._driver_error = ""
        self._init_driver()
        self._clear()

    # ── inicializácia drivera ────────────────────────────────────────────────
    def _init_driver(self):
        candidates = [_KernelPioDriver, _Ws281xDriver, _SpiDriver]
        if ALLOW_BITBANG:
            candidates.append(_GpiodBitbangDriver)

        errors = []
        for cls in candidates:
            try:
                self._driver = cls()
                logger.info("[LED] Driver: %s | %d LED (%d pásky x %d) | GPIO %d",
                            cls.name, self._num_leds, NUM_STRIPS, LEDS_PER_STRIP, DATA_PIN)
                return
            except ImportError as e:
                errors.append(f"{cls.name}: chýba knižnica ({e})")
            except Exception as e:
                errors.append(f"{cls.name}: {e}")

        self._driver = None
        self._driver_error = " | ".join(errors)
        logger.warning("[LED] Žiadny driver nedostupný — pásek sa neovláda. %s",
                       self._driver_error)
        if not os.path.exists(LED_DEVICE):
            logger.warning("[LED] Zapni kernel overlay v /boot/firmware/config.txt:")
            logger.warning("[LED]     %s", OVERLAY_HINT)
            logger.warning("[LED] potom reštart CM5 (LED pásek musí byť 5 V + GND spoločná).")

    # ── interné ──────────────────────────────────────────────────────────────
    def _scaled_frame(self):
        with self._lock:
            raw = bytes(self._pixels)
        br = self._brightness
        if br >= 100:
            return raw
        return bytes((v * br) // 100 for v in raw)

    def _push(self):
        if self._driver is None:
            return False
        try:
            self._driver.write(self._scaled_frame())
            return True
        except Exception as e:
            logger.warning("[LED] Zápis do pásku zlyhal: %s", e)
            return False

    def _fill_buffer(self, r, g, b, w):
        g, r, b, w = (_clamp8(g), _clamp8(r), _clamp8(b), _clamp8(w))
        with self._lock:
            for i in range(self._num_leds):
                self._pixels[i * 4:i * 4 + 4] = bytes((g, r, b, w))

    def _clear(self):
        self._fill_buffer(0, 0, 0, 0)
        self._push()

    def _auto_allowed(self):
        return time.time() >= self._manual_until

    # ── základné ovládanie ───────────────────────────────────────────────────
    def set_brightness(self, percent):
        self._brightness = max(0, min(100, int(percent)))
        self._push()
        return self._brightness

    def set_pixel(self, index, r=0, g=0, b=0, w=0):
        if not 0 <= index < self._num_leds:
            return
        with self._lock:
            self._pixels[index * 4:index * 4 + 4] = bytes(
                (_clamp8(g), _clamp8(r), _clamp8(b), _clamp8(w)))
        self._push()

    def set_color(self, r=0, g=0, b=0, w=0):
        """Nastaví všetky LED na jednu farbu (r, g, b, w)."""
        self._color = (_clamp8(r), _clamp8(g), _clamp8(b), _clamp8(w))
        self._mode = "off" if not any(self._color) else "color"
        self._fill_buffer(*self._color)
        self._push()

    def set_color_smooth(self, target, duration=0.3, steps=20):
        start = self._color
        for i in range(1, steps + 1):
            t = i / steps
            self.set_color(*[int(start[j] + (target[j] - start[j]) * t) for j in range(4)])
            time.sleep(duration / steps)

    def off(self):
        """Vypne pásek a vráti ovládanie stavovým animáciám."""
        self.stop_animation()
        self._manual_until = 0.0
        self._mode = "off"
        self._color = COLOR_OFF
        self._clear()

    # ── manuálne svetlo z appky ─────────────────────────────────────────────
    def flashlight(self, on=True, brightness=None, hold_seconds=None):
        """Čistá biela ako baterka na telefóne — svieti iba biela LED (W)."""
        if not on:
            self.off()
            return self.status()
        self.stop_animation()
        if brightness is not None:
            self._brightness = max(0, min(100, int(brightness)))
        hold = MANUAL_HOLD_SECONDS if hold_seconds is None else int(hold_seconds)
        self._manual_until = time.time() + max(0, hold)
        self._mode = "flashlight"
        self._color = COLOR_WHITE
        self._fill_buffer(0, 0, 0, 255)
        self._push()
        logger.info("[LED] Baterka: čistá biela, jas %d%%, držím %d s", self._brightness, hold)
        return self.status()

    def set_manual(self, mode="white", brightness=None, r=0, g=0, b=0, w=0,
                   hold_seconds=None):
        """Manuálna farba z appky. 'white' = čistá biela (W kanál)."""
        self.stop_animation()
        if brightness is not None:
            self._brightness = max(0, min(100, int(brightness)))
        hold = MANUAL_HOLD_SECONDS if hold_seconds is None else int(hold_seconds)
        self._manual_until = time.time() + max(0, hold)
        mode = (mode or "color").lower()
        if mode == "white":
            self._mode = "white"
            self._color = COLOR_WHITE
            self._fill_buffer(0, 0, 0, 255)
        else:
            self._mode = "color"
            self._color = (_clamp8(r), _clamp8(g), _clamp8(b), _clamp8(w))
            self._fill_buffer(*self._color)
        self._push()
        return self.status()

    def status(self):
        with self._lock:
            color = self._color
        remaining = max(0, int(self._manual_until - time.time()))
        return {
            "mode": self._mode,
            "brightness": self._brightness,
            "r": color[0], "g": color[1], "b": color[2], "w": color[3],
            "color": list(color),
            "pin": DATA_PIN,
            "strips": NUM_STRIPS,
            "leds_per_strip": LEDS_PER_STRIP,
            "num_leds": self._num_leds,
            "driver": self._driver.name if self._driver else None,
            "device": LED_DEVICE,
            "manual_hold_s": remaining,
            "overlay_hint": OVERLAY_HINT,
        }

    # ── animácie (vypnuté, kým beží manuálne svetlo) ─────────────────────────
    def stop_animation(self):
        self._stop_event.set()
        if self._anim_thread and self._anim_thread.is_alive():
            self._anim_thread.join(timeout=2)
        self._stop_event.clear()

    def _run_animation(self, func):
        self.stop_animation()
        self._stop_event.clear()
        self._anim_thread = threading.Thread(target=func, daemon=True)
        self._anim_thread.start()

    def anim_boot(self):
        logger.info("[LED] anim_boot - červená")
        if not self._auto_allowed():
            return
        self.stop_animation()
        self.set_color(r=255)

    def anim_connecting(self):
        if not self._auto_allowed():
            return

        def _chase():
            while not self._stop_event.is_set():
                for _ in range(3):
                    self.set_color(b=255)
                    if self._stop_event.wait(0.15):
                        return
                    self.set_color(b=40)
                    if self._stop_event.wait(0.15):
                        return
                self.set_color(b=20)
                if self._stop_event.wait(0.3):
                    return

        self._run_animation(_chase)

    def anim_fault(self):
        if not self._auto_allowed():
            return

        def _fault():
            while not self._stop_event.is_set():
                for brightness in range(255, 30, -5):
                    if self._stop_event.wait(0.01):
                        return
                    self.set_color(r=brightness)
                for brightness in range(30, 256, 5):
                    if self._stop_event.wait(0.01):
                        return
                    self.set_color(r=brightness)

        self._run_animation(_fault)

    def anim_ok(self):
        if not self._auto_allowed():
            return
        self.stop_animation()
        self.set_color(g=255)

    def anim_white(self):
        if not self._auto_allowed():
            return
        self.stop_animation()
        self.set_color(w=255)

    def anim_scanning(self):
        if not self._auto_allowed():
            return

        def _scan():
            while not self._stop_event.is_set():
                for pos in range(4):
                    if self._stop_event.wait(0.1):
                        return
                    self.set_color(r=100 + pos * 40, b=255)
                for pos in range(3, -1, -1):
                    if self._stop_event.wait(0.1):
                        return
                    self.set_color(r=100 + pos * 40, b=255)

        self._run_animation(_scan)

    def anim_cloud_connecting(self):
        if not self._auto_allowed():
            return

        def _cloud():
            while not self._stop_event.is_set():
                for brightness in range(255, 20, -8):
                    if self._stop_event.wait(0.01):
                        return
                    self.set_color(g=brightness, b=brightness)
                for brightness in range(20, 256, 8):
                    if self._stop_event.wait(0.01):
                        return
                    self.set_color(g=brightness, b=brightness)

        self._run_animation(_cloud)

    def anim_wifi_ap(self):
        if not self._auto_allowed():
            return

        def _ap():
            while not self._stop_event.is_set():
                for brightness in range(255, 20, -12):
                    if self._stop_event.wait(0.008):
                        return
                    self.set_color(g=brightness, b=brightness)
                for brightness in range(20, 256, 12):
                    if self._stop_event.wait(0.008):
                        return
                    self.set_color(g=brightness, b=brightness)

        self._run_animation(_ap)

    def anim_startup(self):
        if not self._auto_allowed():
            return

        def _startup():
            for color in (COLOR_BLUE, COLOR_CYAN, COLOR_GREEN,
                          COLOR_YELLOW, COLOR_RED, COLOR_WHITE, COLOR_OFF):
                if self._stop_event.wait(0.3):
                    return
                self.set_color_smooth(color, duration=0.3)
            if self._stop_event.wait(0.2):
                return
            self.set_color(0, 0, 0, 0)

        self._run_animation(_startup)

    # ── upratovanie ─────────────────────────────────────────────────────────
    def cleanup(self):
        self.stop_animation()
        try:
            self._manual_until = 0.0
            self._clear()
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
