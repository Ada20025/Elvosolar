# Hardware/led_service.py

import os
import time
import threading

class LedService:
    _blinking_lock = threading.Lock()

    @classmethod
    def blink_start_led(cls, times: int = 4, interval: float = 0.08):
        def _worker():
            with cls._blinking_lock:
                led_candidates = [
                    "/sys/class/leds/ACT",
                    "/sys/class/leds/led0",
                    "/sys/class/leds/user_led",
                    "/sys/class/leds/PWR",
                    "/sys/class/leds/led1"
                ]
                active_sysfs = None
                for p in led_candidates:
                    if os.path.exists(f"{p}/brightness"):
                        active_sysfs = p
                        break

                if active_sysfs:
                    for _ in range(times):
                        try:
                            with open(f"{active_sysfs}/brightness", "w") as f:
                                f.write("1")
                            time.sleep(interval)
                            with open(f"{active_sysfs}/brightness", "w") as f:
                                f.write("0")
                            time.sleep(interval)
                        except Exception:
                            pass
                    return

                try:
                    import gpiod
                    for chip_name in ['gpiochip4', 'gpiochip0']:
                        try:
                            chip = gpiod.Chip(chip_name)
                            line = chip.get_line(26)
                            line.request(consumer="start_led", type=gpiod.LINE_REQ_DIR_OUT)
                            for _ in range(times):
                                line.set_value(1)
                                time.sleep(interval)
                                line.set_value(0)
                                time.sleep(interval)
                            line.release()
                            return
                        except Exception:
                            pass
                except ImportError:
                    pass

                try:
                    import RPi.GPIO as GPIO
                    GPIO.setwarnings(False)
                    GPIO.setmode(GPIO.BCM)
                    GPIO.setup(26, GPIO.OUT)
                    for _ in range(times):
                        GPIO.output(26, GPIO.HIGH)
                        time.sleep(interval)
                        GPIO.output(26, GPIO.LOW)
                        time.sleep(interval)
                    return
                except Exception:
                    pass

                time.sleep(interval * times * 2)

        threading.Thread(target=_worker, daemon=True).start()