# Hardware/led_service.py
# RGB LED Strip for Pi5 / CM5
# 27=12V, 26=Red, 22=Green, 18=Blue, 17=White

import time
import threading
import logging

logger = logging.getLogger("LED")

PIN_12V = 27
PIN_RED = 26
PIN_GRN = 22
PIN_BLU = 18
PIN_WHT = 17

COLOR_OFF    = (0, 0, 0, 0)
COLOR_RED    = (255, 0, 0, 0)
COLOR_GREEN  = (0, 255, 0, 0)
COLOR_BLUE   = (0, 0, 255, 0)
COLOR_WHITE  = (0, 0, 0, 255)
COLOR_CYAN   = (0, 255, 255, 0)
COLOR_YELLOW = (255, 255, 0, 0)
COLOR_PURPLE = (150, 0, 255, 0)


class LedService:
    _instance = None
    _lock = threading.Lock()

    def __new__(cls):
        with cls._lock:
            if cls._instance is None:
                cls._instance = super().__new__(cls)
                cls._instance._initialized = False
            return cls._instance

    def __init__(self):
        if self._initialized:
            return
        self._initialized = True
        self._chip = None
        self._lines = {}
        self._current_color = COLOR_OFF
        self._animation_thread = None
        self._stop_event = threading.Event()
        self._gpio_ok = False
        self._use_sysfs = False
        self._init_gpio()

    def _init_gpio(self):
        try:
            import gpiod
            chip_paths = [
                "/dev/gpiochip4", "/dev/gpiochip3", "/dev/gpiochip0",
                "gpiochip4", "gpiochip3", "gpiochip0",
            ]
            for chip_path in chip_paths:
                try:
                    self._chip = gpiod.Chip(chip_path)
                    logger.info("gpiod chip otvoreny: %s", chip_path)
                    break
                except Exception:
                    continue

            if self._chip is not None:
                for name, pin in [("12V", PIN_12V), ("RED", PIN_RED),
                                  ("GRN", PIN_GRN), ("BLU", PIN_BLU), ("WHT", PIN_WHT)]:
                    try:
                        line = self._chip.get_line(pin)
                        line.request(consumer="led_" + name, type=gpiod.LINE_REQ_DIR_OUT)
                        self._lines[name] = line
                        line.set_value(0)
                        self._gpio_ok = True
                        logger.info("GPIO %d (%s) OK", pin, name)
                    except Exception as e:
                        logger.warning("GPIO %d (%s) chyba: %s", pin, name, e)
                return
        except ImportError:
            pass

        logger.info("Skusam sysfs fallback pre GPIO")
        self._use_sysfs = True
        import os
        import subprocess
        for name, pin in [("12V", PIN_12V), ("RED", PIN_RED),
                          ("GRN", PIN_GRN), ("BLU", PIN_BLU), ("WHT", PIN_WHT)]:
            try:
                if not os.path.exists(f"/sys/class/gpio/gpio{pin}"):
                    subprocess.run(["sudo", "bash", "-c",
                                   f"echo {pin} > /sys/class/gpio/export"],
                                   timeout=2, capture_output=True)
                    subprocess.run(["sudo", "bash", "-c",
                                   f"echo out > /sys/class/gpio/gpio{pin}/direction"],
                                   timeout=2, capture_output=True)
                self._lines[name] = pin
                self._write_sysfs(pin, 0)
                self._gpio_ok = True
                logger.info("SYSFS GPIO %d (%s) OK", pin, name)
            except Exception as e:
                logger.warning("SYSFS GPIO %d (%s) chyba: %s", pin, name, e)

        if not self._gpio_ok:
            logger.warning("Ziadne GPIO nedisponuje - LED nefunkcne")

    def _write_sysfs(self, pin, value):
        try:
            import subprocess
            subprocess.run(["sudo", "bash", "-c",
                           f"echo {value} > /sys/class/gpio/gpio{pin}/value"],
                           timeout=1, capture_output=True)
        except Exception:
            pass

    def _write_pin(self, name, value):
        pin = self._lines.get(name)
        if pin is None:
            return
        try:
            if self._use_sysfs:
                self._write_sysfs(pin, value)
            else:
                pin.set_value(value)
        except Exception:
            pass

    def set_color(self, r=0, g=0, b=0, w=0):
        if not self._gpio_ok:
            return
        self._current_color = (r, g, b, w)
        self._write_pin("12V", 1 if (r + g + b + w) > 0 else 0)
        self._write_pin("RED", 1 if r > 0 else 0)
        self._write_pin("GRN", 1 if g > 0 else 0)
        self._write_pin("BLU", 1 if b > 0 else 0)
        self._write_pin("WHT", 1 if w > 0 else 0)

    def set_color_smooth(self, target, duration=0.3, steps=20):
        start = self._current_color
        for i in range(1, steps + 1):
            t = i / steps
            r = int(start[0] + (target[0] - start[0]) * t)
            g = int(start[1] + (target[1] - start[1]) * t)
            b = int(start[2] + (target[2] - start[2]) * t)
            w = int(start[3] + (target[3] - start[3]) * t)
            self.set_color(r, g, b, w)
            time.sleep(duration / steps)

    def off(self):
        self.stop_animation()
        self.set_color(0, 0, 0, 0)

    def stop_animation(self):
        self._stop_event.set()
        if self._animation_thread and self._animation_thread.is_alive():
            self._animation_thread.join(timeout=2)
        self._stop_event.clear()

    def _run_animation(self, func):
        self.stop_animation()
        self._stop_event.clear()
        self._animation_thread = threading.Thread(target=func, daemon=True)
        self._animation_thread.start()

    def anim_boot(self):
        logger.info("LED: Boot (red)")
        self.stop_animation()
        self.set_color(r=255)

    def anim_connecting(self):
        def _chase():
            logger.info("LED: Connecting (blue chase)")
            while not self._stop_event.is_set():
                for step in range(3):
                    self.set_color(b=255)
                    if self._stop_event.wait(0.15): return
                    self.set_color(b=40)
                    if self._stop_event.wait(0.15): return
                self.set_color(b=20)
                if self._stop_event.wait(0.3): return
        self._run_animation(_chase)

    def anim_fault(self):
        def _fault():
            logger.info("LED: Fault (red pulse)")
            while not self._stop_event.is_set():
                for brightness in range(255, 30, -5):
                    if self._stop_event.wait(0.01): return
                    self.set_color(r=brightness)
                for brightness in range(30, 256, 5):
                    if self._stop_event.wait(0.01): return
                    self.set_color(r=brightness)
        self._run_animation(_fault)

    def anim_ok(self):
        logger.info("LED: OK (green solid)")
        self.stop_animation()
        self.set_color(g=255)

    def anim_white(self):
        logger.info("LED: White solid")
        self.stop_animation()
        self.set_color(w=255)

    def anim_scanning(self):
        def _scan():
            logger.info("LED: Scanning (purple chase)")
            while not self._stop_event.is_set():
                for pos in range(4):
                    if self._stop_event.wait(0.1): return
                    self.set_color(r=100 + pos * 40, b=255)
                for pos in range(3, -1, -1):
                    if self._stop_event.wait(0.1): return
                    self.set_color(r=100 + pos * 40, b=255)
        self._run_animation(_scan)

    def anim_cloud_connecting(self):
        def _cloud():
            logger.info("LED: Cloud connecting (cyan pulse)")
            while not self._stop_event.is_set():
                for brightness in range(255, 20, -8):
                    if self._stop_event.wait(0.01): return
                    self.set_color(g=brightness, b=brightness)
                for brightness in range(20, 256, 8):
                    if self._stop_event.wait(0.01): return
                    self.set_color(g=brightness, b=brightness)
        self._run_animation(_cloud)

    def anim_startup(self):
        def _startup():
            logger.info("LED: Startup sequence")
            colors = [COLOR_BLUE, COLOR_CYAN, COLOR_GREEN,
                      COLOR_YELLOW, COLOR_RED, COLOR_WHITE, COLOR_OFF]
            for color in colors:
                if self._stop_event.wait(0.3): return
                self.set_color_smooth(color, duration=0.3)
            if self._stop_event.wait(0.2): return
            self.set_color(0, 0, 0, 0)
        self._run_animation(_startup)

    def cleanup(self):
        self.stop_animation()
        self.off()
        for name, pin in self._lines.items():
            try:
                if self._use_sysfs:
                    import subprocess
                    subprocess.run(["sudo", "bash", "-c",
                                   f"echo {pin} > /sys/class/gpio/unexport"],
                                   timeout=1, capture_output=True)
                else:
                    pin.release()
            except Exception:
                pass
        if self._chip:
            try:
                self._chip.close()
            except Exception:
                pass
        logger.info("LED: Cleanup hotovy")

    def __del__(self):
        try:
            self.cleanup()
        except Exception:
            pass
