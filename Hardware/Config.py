# Hardware/Config.py
import os


PORT = '/dev/ttyAMA3'  # Predvolený hardvérový port pre integrovanú zbernicu CM5 (svorky CH1)
TZ_SK = "Europe/Bratislava"
OKTE_URL = "https://isot.okte.sk/api/v1/dam/results"
WEB_PORT = 80

IP_REPORT_URL = os.environ.get("CLOUD_SERVER_URL", "https://elvosolar-production.up.railway.app") + "/api/report-ip"
COMMON_APNS = ["internet", "o2internet", "to.naklik", "o2.sk"]

DEVICE_DB = {
    '1': {
        'brand_id': 1,
        'znacka': 'HUAWEI',
        'zapojenie': 'KROK-ZA-KROKOM RS485 PRE WAVESHARE CM5 (CH1):\n'
                     '1. Nájdite na zelenej svorkovnici CM5 sekciu CH1 (Channel 1).\n'
                     '2. Pripojte BIELO-MODRÝ vodič na svorku R/A (RS485_A+) na vašej krabici CM5.\n'
                     '3. Pripojte MODRÝ vodič na svorku T/B (RS485_B-) na vašej krabici CM5.\n'
                     '4. Druhý koniec RJ45 zasuňte do COM portu striedača.\n'
                     'Pre trojfázové modely použite svorky 7 (A+) a 9 (B-) priamo na striedači.',
        'kategorie': {
            '1': {
                'cat_id': 1,
                'typ': 'striedac',
                'skupina_id': 1,
                'skip_model_selection': True,
                'meno': 'HUAWEI STRIEDAČE (SUN2000)',
                'modely': {
                    '1': {
                        'meno': 'Všetky modely SUN2000 (Jednofázové aj Trojfázové)', 
                        'on': 40125, 
                        'off': 40125, 
                        'type_on': 'E16', 
                        'type_off': 'E16', 
                        'val_on': 1000, 
                        'val_off': 0, 
                        'baud': 9600, 
                        'reg_soc': 37760, 
                        'reg_p_ac': 32080
                    },
                }
            }
        }
    },
    '2': {
        'brand_id': 2,
        'znacka': 'FRONIUS',
        'zapojenie': 'KROK-ZA-KROKOM RS485 PRE WAVESHARE CM5 (CH1):\n'
                     '1. Pripojte svorku D+ striedača Fronius na svorku R/A (RS485_A+) na krabici CM5.\n'
                     '2. Pripojte svorku D- striedača Fronius na svorku T/B (RS485_B-) na krabici CM5.\n'
                     '3. Pre stabilnú komunikáciu prepojte tienenie (alebo GND) so svorkou GND.',
        'kategorie': {
            '1': {
                'cat_id': 1,
                'typ': 'striedac',
                'skupina_id': 1,
                'skip_model_selection': True,
                'meno': 'REZIDENČNÉ JEDNOFÁZOVÉ (Séria Galvo / Primo)',
                'modely': {
                    '1': {'meno': 'Galvo / Primo Modely', 'on': 40232, 'off': 40232, 'val_on': 100, 'val_off': 0, 'baud': 9600, 'reg_soc': 40313, 'reg_p_ac': 40091},
                }
            }
        }
    },
    '3': {
        'brand_id': 3,
        'znacka': 'GOODWE',
        'zapojenie': 'KROK-ZA-KROKOM RS485 PRE GOODWE:\n'
                     '1. Lokalizujte komunikačný konektor na spodnej časti striedača.\n'
                     '2. Pripojte vodič A na svorku A+ na krabici CM5.\n'
                     '3. Pripojte vodič B na svorku B- na krabici CM5.',
        'kategorie': {
            '1': {
                'cat_id': 1,
                'typ': 'striedac',
                'skupina_id': 1,
                'skip_model_selection': True,
                'meno': 'Jednofázové aj Trojfázové Hybridy',
                'modely': {
                    '1': {'meno': 'Všetky modely (ET/EH/XS/DNS)', 'on': 511, 'off': 511, 'val_on': 100, 'val_off': 0, 'baud': 9600, 'reg_soc': 37007, 'reg_p_ac': 35140},
                }
            }
        }
    },
    '4': {
        'brand_id': 4,
        'znacka': 'SOLAX',
        'zapojenie': 'KROK-ZA-KROKOM RS485 PRE SOLAX:\n'
                     '1. RJ45 pin 4 (MODRÝ, predstavuje A+) pripojte na svorku A+ na CM5.\n'
                     '2. RJ45 pin 5 (MODRO-BIELY, predstavuje B-) pripojte na svorku B- na CM5.\n'
                     '3. Druhý RJ45 koniec zasuňte do portu COM/RS485 na Solax striedači.',
        'kategorie': {
            '1': {
                'cat_id': 1,
                'typ': 'striedac',
                'skupina_id': 1,
                'skip_model_selection': True,
                'meno': 'Jednofázové aj Trojfázové Hybridy / Sieťové',
                'modely': {
                    '1': {'meno': 'Všetky modely G4 / Fit / Forth', 'on': 121, 'off': 121, 'val_on': 100, 'val_off': 0, 'baud': 9600, 'reg_soc': 28, 'reg_p_ac': 2},
                }
            }
        }
    },
    '5': {
        'brand_id': 5,
        'znacka': 'VICTRON ENERGY',
        'port': '/dev/ttyUSB0',  # VICTRON pouziva USB-RS485 adapter
        'zapojenie': 'KROK-ZA-KROKOM RS485 PRE VICTRON:\n'
                     '1. Zasuňte USB prevodník Victron RS485 do ľubovoľného USB portu CM5.\n'
                     '2. ORANŽOVÝ vodič zapojte do svorky A+ striedača.\n'
                     '3. ŽLTÝ vodič zapojte do svorky B- striedača.\n'
                     '4. ČIERNY vodič zapojte do svorky GND striedača.',
        'kategorie': {
            '1': {
                'cat_id': 1,
                'typ': 'striedac',
                'skupina_id': 1,
                'skip_model_selection': True,
                'meno': 'MultiPlus & Quattro (Menič/Nabíjač)',
                'modely': {
                    '1': {'meno': 'MultiPlus-II / Quattro / EasySolar', 'on': 33, 'off': 33, 'val_on': 3, 'val_off': 4, 'baud': 9600, 'reg_soc': 30, 'reg_p_ac': 12},
                }
            }
        }
    },
    '6': {
        'brand_id': 6,
        'znacka': 'GROWATT',
        'zapojenie': 'KROK-ZA-KROKOM RS485 PRE GROWATT:\n'
                     '1. SYS COM RJ45: pin 3 (A) na svorku A+ na krabici CM5.\n'
                     '2. SYS COM RJ45: pin 4 (B) na svorku B- na krabici CM5.',
        'kategorie': {
            '1': {
                'cat_id': 1,
                'typ': 'striedac',
                'skupina_id': 1,
                'skip_model_selection': True,
                'meno': 'Séria MIN-XE, MOD, SPH',
                'modely': {
                    '1': {'meno': 'Všetky Growatt modely', 'on': 40004, 'off': 40004, 'val_on': 100, 'val_off': 0, 'baud': 9600, 'reg_soc': 40101, 'reg_p_ac': 40009},
                }
            }
        }
    },
    '7': {
        'brand_id': 7,
        'znacka': 'SOFAR SOLAR',
        'zapojenie': 'KROK-ZA-KROKOM RS485 PRE SOFAR:\n'
                     '1. Pripojte pin 1 (A) na svorku A+ na krabici CM5.\n'
                     '2. Pripojte pin 2 (B) na svorku B- na krabici CM5.',
        'kategorie': {
            '1': {
                'cat_id': 1,
                'typ': 'striedac',
                'skupina_id': 1,
                'skip_model_selection': True,
                'meno': 'Trojfázové hybridy séria HYD',
                'modely': {
                    '1': {'meno': 'Všetky Sofar HYD modely', 'on': 4160, 'off': 4160, 'val_on': 100, 'val_off': 0, 'baud': 9600, 'reg_soc': 528, 'reg_p_ac': 1157},
                }
            }
        }
    },
    '8': {
        'brand_id': 8,
        'znacka': 'DEYE',
        'zapojenie': 'KROK-ZA-KROKOM RS485 PRE DEYE:\n'
                     '1. Použite dedikovaný RS485 port (piny 7 a 8 na svorkovnici).\n'
                     '2. Prepojte svorky A a B priamo s jednotkou CM5.',
        'kategorie': {
            '1': {
                'cat_id': 1,
                'typ': 'striedac',
                'skupina_id': 1,
                'skip_model_selection': True,
                'meno': 'Nízkonapäťové trojfázové hybridy',
                'modely': {
                    '1': {'meno': 'SUN-SG04LP3 Séria', 'on': 40143, 'off': 40143, 'val_on': 100, 'val_off': 0, 'baud': 9600, 'reg_soc': 40009, 'reg_p_ac': 40090},
                    '2': {'meno': 'SUN-SG04LP3 Séria (Alt. registre)', 'on': 40143, 'off': 40143, 'val_on': 100, 'val_off': 0, 'baud': 9600, 'reg_soc': 40184, 'reg_p_ac': 40175},
                }
            }
        }
    },
    '9': {
        'brand_id': 9,
        'znacka': 'VIESSMANN',
        'zapojenie': 'KROK-ZA-KROKOM RS485 PRE VIESSMANN:\n'
                     '1. Zapojte svorku RS485 A na A+ na krabici CM5.\n'
                     '2. Zapojte svorku RS485 B na B- na krabici CM5.',
        'kategorie': {
            '1': {
                'cat_id': 1,
                'typ': 'striedac',
                'skupina_id': 1,
                'skip_model_selection': True,
                'meno': 'Séria Vitovolt 300',
                'modely': {
                    '1': {'meno': 'Vitovolt 300 M300', 'on': 40232, 'off': 40232, 'val_on': 100, 'val_off': 0, 'baud': 9600, 'reg_soc': 40313, 'reg_p_ac': 40091},
                }
            }
        }
    },
    '10': {
        'brand_id': 10,
        'znacka': 'SUNGROW',
        'zapojenie': 'KROK-ZA-KROKOM RS485 PRE SUNGROW:\n'
                     '1. Pripojte svorku A2 na svorku A+ na krabici CM5.\n'
                     '2. Pripojte svorku B2 na svorku B- na krabici CM5.',
        'kategorie': {
            '1': {
                'cat_id': 1,
                'typ': 'striedac',
                'skupina_id': 1,
                'skip_model_selection': True,
                'meno': 'SH-RT / SG-RT Série',
                'modely': {
                    '1': {'meno': 'Všetky Sungrow modely', 'on': 5007, 'off': 5007, 'val_on': 10000, 'val_off': 0, 'baud': 9600, 'reg_soc': 13022, 'reg_p_ac': 13007},
                }
            }
        }
    },
    '11': {
        'brand_id': 11,
        'znacka': 'SOLIS',
        'zapojenie': 'KROK-ZA-KROKOM RS485 PRE SOLIS:\n'
                     '1. 4-pin COM konektor: Pin 1 (A+) na svorku A+ na krabici CM5.\n'
                     '2. 4-pin COM konektor: Pin 2 (B-) na svorku B- na krabici CM5.',
        'kategorie': {
            '1': {
                'cat_id': 1,
                'typ': 'striedac',
                'skupina_id': 1,
                'skip_model_selection': True,
                'meno': 'Séria S6-EH3P / S5-GR3P',
                'modely': {
                    '1': {'meno': 'Všetky Solis modely', 'on': 43110, 'off': 43110, 'val_on': 100, 'val_off': 0, 'baud': 9600, 'reg_soc': 33139, 'reg_p_ac': 33079},
                }
            }
        }
    }
}