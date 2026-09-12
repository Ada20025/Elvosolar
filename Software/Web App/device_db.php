<?php
// device_db.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Kompletný zoznam zariadení a ich parametrov
$device_db = [
    '1' => [
        'brand_id' => 1,
        'znacka' => 'HUAWEI',
        'zapojenie' => 'KROK-ZA-KROKOM RS485 PRE HUAWEI:<br>' .
                       '1. Zoberte klasický ethernetový patch kábel a odstrihnite jeden koniec.<br>' .
                       '2. Odizolujte žily. Nájdite MODRÝ vodič (pin 4) a MODRO-BIELY vodič (pin 5).<br>' .
                       '3. Pripojte MODRÝ vodič na svorku A+ (RS485_A) na vašej krabici CM5.<br>' .
                       '4. Pripojte MODRO-BIELY vodič na svorku B- (RS485_B) na vašej krabici CM5.<br>' .
                       '5. Druhý (neodstrihnutý) koniec RJ45 zasuňte do COM portu striedača.<br>' .
                       'Pre trojfázové modely (radu M1/MB0) použite svorky 7 (A+) a 9 (B-) priamo na svorkovnici striedača.',
        'kategorie' => [
            '1' => [
                'cat_id' => 1,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'HUAWEI STRIEDAČE',
                'modely' => [
                    '1' => [
                        'meno' => 'Všetky striedače SUN2000 (Jednofázové aj Trojfázové)', 
                        'on' => 40125, 
                        'off' => 40125, 
                        'type_on' => 'E16', 
                        'type_off' => 'E16', 
                        'val_on' => 1000, 
                        'val_off' => 0, 
                        'baud' => 9600, 
                        'reg_soc' => 37760, 
                        'reg_p_ac' => 32080
                    ]
                ]
            ],
            '2' => [
                'cat_id' => 2,
                'typ' => 'logger',
                'skupina_id' => 2,
                'skip_model_selection' => false,
                'meno' => 'HUAWEI SMARTLOGGER',
                'zapojenie' => 'KROK-ZA-KROKOM RS485 PRE HUAWEI SMARTLOGGER:<br>' .
                               '1. Vyhľadajte zelené svorky COM portov (COM1-3) na spodnej strane SmartLoggera.<br>' .
                               '2. Prepojte svorku + (A) s vašou svorkou A+ (RS485_A) a svorku - (B) so svorkou B- na jednotke CM5.',
                'modely' => [
                    '1' => ['meno' => 'SmartLogger 3000A', 'on' => 40424, 'off' => 40424, 'type_on' => 'E16', 'type_off' => 'E16', 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 0, 'reg_p_ac' => 40525],
                    '2' => ['meno' => 'SmartLogger 1000', 'on' => 40424, 'off' => 40424, 'type_on' => 'E16', 'type_off' => 'E16', 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 0, 'reg_p_ac' => 40525],
                ]
            ]
        ]
    ],
    '2' => [
        'brand_id' => 2,
        'znacka' => 'FRONIUS',
        'zapojenie' => 'KROK-ZA-KROKOM RS485 PRE FRONIUS:<br>1. Otvorte spodný kryt striedača.<br>2. Nájdite svorkovnicu označenú ako "D+" (A) a "D-" (B).<br>3. Žilu zo svorky "D+" zapojte do svorky A+ (RS485_A) na krabici CM5.<br>4. Žilu zo svorky "D-" zapojte do svorky B- (RS485_B) na krabici CM5.',
        'kategorie' => [
            '1' => [
                'cat_id' => 1,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'REZIDENČNÉ JEDNOFÁZOVÉ (Séria Galvo / Primo)',
                'modely' => [
                    '1' => ['meno' => 'Galvo 1.5-1', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '2' => ['meno' => 'Galvo 2.0-1', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '3' => ['meno' => 'Galvo 2.5-1', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '4' => ['meno' => 'Galvo 3.0-1', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '5' => ['meno' => 'Primo 3.0-1', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '6' => ['meno' => 'Primo 3.5-1', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '7' => ['meno' => 'Primo 3.6-1', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '8' => ['meno' => 'Primo 4.0-1', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '9' => ['meno' => 'Primo 4.6-1', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '10' => ['meno' => 'Primo 5.0-1', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '11' => ['meno' => 'Primo 6.0-1', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '12' => ['meno' => 'Primo 8.2-1', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                ]
            ],
            '2' => [
                'cat_id' => 2,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'REZIDENČNÉ TROJFÁZOVÉ (Symo - Séria M / S)',
                'modely' => [
                    '1' => ['meno' => 'Symo 3.0-3-S', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '2' => ['meno' => 'Symo 3.0-3-M', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '3' => ['meno' => 'Symo 3.7-3-M', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '4' => ['meno' => 'Symo 4.5-3-S', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '5' => ['meno' => 'Symo 4.5-3-M', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '6' => ['meno' => 'Symo 5.0-3-M', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '7' => ['meno' => 'Symo 6.0-3-M', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '8' => ['meno' => 'Symo 7.0-3-M', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '9' => ['meno' => 'Symo 8.2-3-M', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '10' => ['meno' => 'Symo 10.0-3-M', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '11' => ['meno' => 'Symo 12.5-3-M', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '12' => ['meno' => 'Symo 15.0-3-M', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '13' => ['meno' => 'Symo 17.5-3-M', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '14' => ['meno' => 'Symo 20.0-3-M', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                ]
            ],
            '3' => [
                'cat_id' => 3,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'REZIDENČNÉ HYBRIDNÉ (Séria Symo / Primo GEN24 Plus)',
                'modely' => [
                    '1' => ['meno' => 'Primo GEN24 3.0 Plus', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '2' => ['meno' => 'Primo GEN24 3.6 Plus', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '3' => ['meno' => 'Primo GEN24 4.0 Plus', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '4' => ['meno' => 'Primo GEN24 4.6 Plus', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '5' => ['meno' => 'Primo GEN24 5.0 Plus', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '6' => ['meno' => 'Primo GEN24 6.0 Plus', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '7' => ['meno' => 'Symo GEN24 3.0 Plus', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '8' => ['meno' => 'Symo GEN24 4.0 Plus', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '9' => ['meno' => 'Symo GEN24 5.0 Plus', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '10' => ['meno' => 'Symo GEN24 6.0 Plus', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '11' => ['meno' => 'Symo GEN24 8.0 Plus', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '12' => ['meno' => 'Symo GEN24 10.0 Plus', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                ]
            ],
            '4' => [
                'cat_id' => 4,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'KOMERČNÉ A PRIEMYSELNÉ (Séria Eco / Tauro)',
                'modely' => [
                    '1' => ['meno' => 'Eco 25.0-3-S', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '2' => ['meno' => 'Eco 27.0-3-S', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '3' => ['meno' => 'Tauro 50 (Direct / Eco)', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                    '4' => ['meno' => 'Tauro 100 (Direct / Eco)', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                ]
            ]
        ]
    ],
    '3' => [
        'brand_id' => 3,
        'znacka' => 'GOODWE',
        'zapojenie' => 'KROK-ZA-KROKOM RS485 PRE GOODWE:<br>1. Lokalizujte komunikačný konektor na spodnej časti striedača.<br>2. Pripojte kábel: Žila 1 is A, Žila 2 is B.<br>3. Druhý koniec kábla z vodiča A zapojte do svorky A+ na krabici CM5.<br>4. Druhý koniec z vodiča B zapojte do svorky B- na krabici CM5.',
        'kategorie' => [
            '1' => [
                'cat_id' => 1,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'DOMÁCE JEDNOFÁZOVÉ SIEŤOVÉ & HYBRIDNÉ (Séria NS / DNS / XS / EH)',
                'modely' => [
                    '1' => ['meno' => 'GW700-XS', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '2' => ['meno' => 'GW1000-XS', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '3' => ['meno' => 'GW1500-XS', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '4' => ['meno' => 'GW2000-XS', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '5' => ['meno' => 'GW2500-XS', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '6' => ['meno' => 'GW3000-XS', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '7' => ['meno' => 'GW3000D-NS', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '8' => ['meno' => 'GW3600D-NS', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '9' => ['meno' => 'GW4200D-NS', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '10' => ['meno' => 'GW5000D-NS', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '11' => ['meno' => 'GW6000D-NS', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '12' => ['meno' => 'GW3600-EH (Hybrid)', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '13' => ['meno' => 'GW5000-EH (Hybrid)', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '14' => ['meno' => 'GW6000-EH (Hybrid)', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                ]
            ],
            '2' => [
                'cat_id' => 2,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'TROJFÁZOVÉ REZIDENČNÉ HYBRIDNÉ (Séria ET / ET Plus / ET30)',
                'modely' => [
                    '1' => ['meno' => 'GW5K-ET', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '2' => ['meno' => 'GW6.5K-ET', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '3' => ['meno' => 'GW8K-ET', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '4' => ['meno' => 'GW10K-ET', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '5' => ['meno' => 'GW15K-ET', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '6' => ['meno' => 'GW20K-ET', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '7' => ['meno' => 'GW25K-ET', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '8' => ['meno' => 'GW29.9K-ET', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '9' => ['meno' => 'GW30K-ET', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                ]
            ],
            '3' => [
                'cat_id' => 3,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'KOMERČNÉ A PRIEMYSELNÉ (Séria SDT / SMT / MT / HT)',
                'modely' => [
                    '1' => ['meno' => 'GW4K-DT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '2' => ['meno' => 'GW5K-DT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '3' => ['meno' => 'GW6K-DT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '4' => ['meno' => 'GW8K-DT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '5' => ['meno' => 'GW10K-DT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '6' => ['meno' => 'GW12K-DT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '7' => ['meno' => 'GW15K-SDT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '8' => ['meno' => 'GW17K-SDT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '9' => ['meno' => 'GW20K-SDT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '10' => ['meno' => 'GW25K-SDT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '11' => ['meno' => 'GW30K-MT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '12' => ['meno' => 'GW36K-MT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '13' => ['meno' => 'GW50K-MT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '14' => ['meno' => 'GW60K-MT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '15' => ['meno' => 'GW73K-HT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '16' => ['meno' => 'GW100K-HT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '17' => ['meno' => 'GW120K-HT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                    '18' => ['meno' => 'GW250K-HT', 'on' => 511, 'off' => 511, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 37007, 'reg_p_ac' => 35140],
                ]
            ],
            '4' => [
                'cat_id' => 4,
                'typ' => 'logger',
                'skupina_id' => 2,
                'skip_model_selection' => false,
                'meno' => 'GOODWE EZLOGGER (Riadiace jednotky)',
                'zapojenie' => 'KROK-ZA-KROKOM RS485 PRE GOODWE EZLOGGER:<br>1. Na EzLoggeri lokalizujte svorkovnicu RS485.<br>2. Zapojte svorku A na svorku A+ (RS485_A) a svorku B na svorku B- (RS485_B) na krabici CM5.',
                'modely' => [
                    '1' => ['meno' => 'EzLogger Pro', 'on' => 40016, 'off' => 40016, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 0, 'reg_p_ac' => 40010],
                    '2' => ['meno' => 'EzLogger 3000C', 'on' => 20010, 'off' => 20010, 'val_on' => 1, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 0, 'reg_p_ac' => 40010],
                ]
            ]
        ]
    ],
    '4' => [
        'brand_id' => 4,
        'znacka' => 'SOLAX',
        'zapojenie' => 'KROK-ZA-KROKOM RS485 PRE SOLAX:<br>1. Zoberte ethernetový kábel RJ45.<br>2. Odstrihnite jeden koniec a vyberte MODRÝ vodič (A+) a MODRO-BIELY vodič (B-).<br>3. MODRÝ vodič pripojte na svorku A+, MODRO-BIELY na svorku B- na krabici CM5.',
        'kategorie' => [
            '1' => [
                'cat_id' => 1,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'REZIDENČNÉ JEDNOFÁZOVÉ SIEŤOVÉ (X1-Mini / X1-Boost G4)',
                'modely' => [
                    '1' => ['meno' => 'X1-MINI-0.6K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '2' => ['meno' => 'X1-MINI-1.1K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '3' => ['meno' => 'X1-MINI-1.5K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '4' => ['meno' => 'X1-MINI-2.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '5' => ['meno' => 'X1-MINI-2.5K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '6' => ['meno' => 'X1-MINI-3.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '7' => ['meno' => 'X1-MINI-3.3K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '8' => ['meno' => 'X1-MINI-3.6K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '9' => ['meno' => 'X1-BOOST-3.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '10' => ['meno' => 'X1-BOOST-3.3K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '11' => ['meno' => 'X1-BOOST-3.6K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '12' => ['meno' => 'X1-BOOST-4.2K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '13' => ['meno' => 'X1-BOOST-5.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '14' => ['meno' => 'X1-BOOST-6.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                ]
            ],
            '2' => [
                'cat_id' => 2,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'REZIDENČNÉ TROJFÁZOVÉ SIEŤOVÉ (X3-Mic / X3-Pro G4)',
                'modely' => [
                    '1' => ['meno' => 'X3-MIC-3.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '2' => ['meno' => 'X3-MIC-4.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '3' => ['meno' => 'X3-MIC-5.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '4' => ['meno' => 'X3-MIC-6.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '5' => ['meno' => 'X3-MIC-8.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '6' => ['meno' => 'X3-MIC-10.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '7' => ['meno' => 'X3-MIC-12.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '8' => ['meno' => 'X3-MIC-15.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '9' => ['meno' => 'X3-PRO-8.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '10' => ['meno' => 'X3-PRO-10.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '11' => ['meno' => 'X3-PRO-12.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '12' => ['meno' => 'X3-PRO-15.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '13' => ['meno' => 'X3-PRO-17.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '14' => ['meno' => 'X3-PRO-20.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '15' => ['meno' => 'X3-PRO-25.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '16' => ['meno' => 'X3-PRO-30.0K-G4', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                ]
            ],
            '3' => [
                'cat_id' => 3,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'REZIDENČNÉ JEDNOFÁZOVÉ HYBRIDNÉ (X1-Hybrid G4 / X1-Fit)',
                'modely' => [
                    '1' => ['meno' => 'X1-Hybrid-3.0-D', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '2' => ['meno' => 'X1-Hybrid-3.7-D', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '3' => ['meno' => 'X1-Hybrid-5.0-D', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '4' => ['meno' => 'X1-Hybrid-6.0-D', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '5' => ['meno' => 'X1-Hybrid-7.5-D', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                ]
            ],
            '4' => [
                'cat_id' => 4,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'REZIDENČNÉ TROJFÁZOVÉ HYBRIDNÉ (X3-Hybrid G4 / X3-Fit)',
                'modely' => [
                    '1' => ['meno' => 'X3-Hybrid-5.0-D', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '2' => ['meno' => 'X3-Hybrid-6.0-D', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '3' => ['meno' => 'X3-Hybrid-8.0-D', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '4' => ['meno' => 'X3-Hybrid-10.0-D', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '5' => ['meno' => 'X3-Hybrid-12.0-D', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '6' => ['meno' => 'X3-Hybrid-15.0-D', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                ]
            ],
            '5' => [
                'cat_id' => 5,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'KOMERČNÉ A PRIEMYSELNÉ (X3-Mega G2 / X3-Forth)',
                'modely' => [
                    '1' => ['meno' => 'X3-MEGA-50K-G2', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '2' => ['meno' => 'X3-MEGA-60K-G2', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '3' => ['meno' => 'X3-FORTH-80K', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '4' => ['meno' => 'X3-FORTH-100K', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '5' => ['meno' => 'X3-FORTH-120K', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '6' => ['meno' => 'X3-FORTH-125K', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '7' => ['meno' => 'X3-FORTH-136K', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                    '8' => ['meno' => 'X3-FORTH-150K', 'on' => 121, 'off' => 121, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 28, 'reg_p_ac' => 2],
                ]
            ]
        ]
    ],
    '5' => [
        'brand_id' => 5,
        'znacka' => 'VICTRON ENERGY',
        'zapojenie' => 'KROK-ZA-KROKOM RS485 PRE VICTRON:<br>1. Zasuňte originálny USB prevodník Victron RS485 do portu CM5.<br>2. Pripojte: ORANŽOVÝ vodič na A+, ŽLTÝ na B-, ČIERNY na GND striedača.',
        'kategorie' => [
            '1' => [
                'cat_id' => 1,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'MultiPlus & MultiPlus-II (Menič/Nabíjač)',
                'modely' => [
                    '1' => ['meno' => 'MultiPlus-II 12/3000/120-50', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                    '2' => ['meno' => 'MultiPlus-II 24/3000/70-50', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                    '3' => ['meno' => 'MultiPlus-II 48/3000/35-32', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                    '4' => ['meno' => 'MultiPlus-II 48/5000/70-50', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                    '5' => ['meno' => 'MultiPlus-II 48/8000/110-100', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                    '6' => ['meno' => 'MultiPlus-II 48/10000/140-100', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                ]
            ],
            '2' => [
                'cat_id' => 2,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'Quattro & Quattro-II (Menič/Nabíjač s dvomi AC vstupmi)',
                'modely' => [
                    '1' => ['meno' => 'Quattro 24/5000/120-100/100', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                    '2' => ['meno' => 'Quattro 48/5000/70-100/100', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                    '3' => ['meno' => 'Quattro 48/8000/110-100/100', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                    '4' => ['meno' => 'Quattro 48/10000/140-100/100', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                    '5' => ['meno' => 'Quattro 48/15000/200-100/100', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                ]
            ],
            '3' => [
                'cat_id' => 3,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'EasySolar & EasySolar-II (Kombinované s MPPT regulátorom)',
                'modely' => [
                    '1' => ['meno' => 'EasySolar-II 24/3000/70-32 MPPT 250/70', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                    '2' => ['meno' => 'EasySolar-II 48/3000/35-32 MPPT 250/70', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                    '3' => ['meno' => 'EasySolar-II 48/5000/70-50 MPPT 250/100', 'on' => 33, 'off' => 33, 'val_on' => 3, 'val_off' => 4, 'baud' => 9600, 'reg_soc' => 30, 'reg_p_ac' => 12],
                ]
            ]
        ]
    ],
    '6' => [
        'brand_id' => 6,
        'znacka' => 'GROWATT',
        'zapojenie' => 'KROK-ZA-KROKOM RS485 PRE GROWATT:<br>1. Použite RJ45 konektor (SYS COM). Pin 3 is RS485_A, Pin 4 is RS485_B.<br>2. Pripojte pin 3 na svorku A+ a pin 4 na svorku B- na krabici CM5.',
        'kategorie' => [
            '1' => [
                'cat_id' => 1,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'REZIDENČNÉ JEDNOFÁZOVÉ & TROJFÁZOVÉ (MIN-XE / MOD-KTL3-X)',
                'modely' => [
                    '1' => ['meno' => 'MIN 3000TL-XE', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                    '2' => ['meno' => 'MIN 4600TL-XE', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                    '3' => ['meno' => 'MIN 5000TL-XE', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                    '4' => ['meno' => 'MIN 6000TL-XE', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                    '5' => ['meno' => 'MOD 3000TL3-X', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                    '6' => ['meno' => 'MOD 5000TL3-X', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                    '7' => ['meno' => 'MOD 6000TL3-X', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                    '8' => ['meno' => 'MOD 8000TL3-X', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                    '9' => ['meno' => 'MOD 10KTL3-X', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                    '10' => ['meno' => 'MOD 15KTL3-X', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                ]
            ],
            '2' => [
                'cat_id' => 2,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'REZIDENČNÉ HYBRIDNÉ (SPH3000-6000 / SPH TL3-BH-UP)',
                'modely' => [
                    '1' => ['meno' => 'SPH 3000 Single Phase', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                    '2' => ['meno' => 'SPH 5000 Single Phase', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                    '3' => ['meno' => 'SPH 4000TL3-BH-UP', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                    '4' => ['meno' => 'SPH 6000TL3-BH-UP', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                    '5' => ['meno' => 'SPH 10000TL3-BH-UP', 'on' => 40004, 'off' => 40004, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40101, 'reg_p_ac' => 40009],
                ]
            ]
        ]
    ],
    '7' => [
        'brand_id' => 7,
        'znacka' => 'SOFAR SOLAR',
        'zapojenie' => 'KROK-ZA-KROKOM RS485 PRE SOFAR:<br>1. Otvorte komunikačný terminál na striedači.<br>2. Použite piny 1 (RS485_A) a 2 (RS485_B).<br>3. Pripojte pin 1 (A) na svorku A+ a pin 2 (B) na svorku B- na krabici CM5.',
        'kategorie' => [
            '1' => [
                'cat_id' => 1,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'TROJFÁZOVÉ HYBRIDNÉ (Séria HYD-ES / HYD-EP / HYD-3PH)',
                'modely' => [
                    '1' => ['meno' => 'HYD 5KTL-3PH', 'on' => 4160, 'off' => 4160, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 528, 'reg_p_ac' => 1157],
                    '2' => ['meno' => 'HYD 8KTL-3PH', 'on' => 4160, 'off' => 4160, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 528, 'reg_p_ac' => 1157],
                    '3' => ['meno' => 'HYD 10KTL-3PH', 'on' => 4160, 'off' => 4160, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 528, 'reg_p_ac' => 1157],
                    '4' => ['meno' => 'HYD 15KTL-3PH', 'on' => 4160, 'off' => 4160, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 528, 'reg_p_ac' => 1157],
                    '5' => ['meno' => 'HYD 20KTL-3PH', 'on' => 4160, 'off' => 4160, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 528, 'reg_p_ac' => 1157],
                ]
            }
        ]
    ],
    '8' => [
        'brand_id' => 8,
        'znacka' => 'DEYE',
        'zapojenie' => 'KROK-ZA-KROKOM RS485 PRE DEYE:<br>1. Nájdite komunikačný konektor označený ako BMS/Meter/RS485 na bočnej/spodnej strane.<br>2. Pre komunikáciu so systémom CM5 použite dedikovaný RS485 port, piny Modrý/Modrobiely (piny 7 a 8 priamo na svorkovnici).<br>3. Prepojte svorky A a B priamo s vašou riadiacou jednotkou CM5.',
        'kategorie' => [
            '1' => [
                'cat_id' => 1,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'NÍZKONAPÄŤOVÉ TROJFÁZOVÉ HYBRIDY (Séria SUN-SG04LP3)',
                'modely' => [
                    '1' => ['meno' => 'SUN-5K-SG04LP3', 'on' => 40143, 'off' => 40143, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40009, 'reg_p_ac' => 40090],
                    '2' => ['meno' => 'SUN-6K-SG04LP3', 'on' => 40143, 'off' => 40143, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40009, 'reg_p_ac' => 40090],
                    '3' => ['meno' => 'SUN-8K-SG04LP3', 'on' => 40143, 'off' => 40143, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40009, 'reg_p_ac' => 40090],
                    '4' => ['meno' => 'SUN-10K-SG04LP3', 'on' => 40143, 'off' => 40143, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40009, 'reg_p_ac' => 40090],
                    '5' => ['meno' => 'SUN-12K-SG04LP3', 'on' => 40143, 'off' => 40143, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40009, 'reg_p_ac' => 40090],
                ]
            ],
            '2' => [
                'cat_id' => 2,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'NÍZKONAPÄŤOVÉ TROJFÁZOVÉ HYBRIDY (Séria SUN-SG04LP3 - Alt. registre)',
                'modely' => [
                    '1' => ['meno' => 'SUN-5K-SG04LP3 (Alt)', 'on' => 40143, 'off' => 40143, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40184, 'reg_p_ac' => 40175],
                    '2' => ['meno' => 'SUN-8K-SG04LP3 (Alt)', 'on' => 40143, 'off' => 40143, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40184, 'reg_p_ac' => 40175],
                    '3' => ['meno' => 'SUN-10K-SG04LP3 (Alt)', 'on' => 40143, 'off' => 40143, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40184, 'reg_p_ac' => 40175],
                    '4' => ['meno' => 'SUN-12K-SG04LP3 (Alt)', 'on' => 40143, 'off' => 40143, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40184, 'reg_p_ac' => 40175],
                ]
            ]
        ]
    ],
    '9' => [
        'brand_id' => 9,
        'znacka' => 'VIESSMANN',
        'zapojenie' => 'KROK-ZA-KROKOM RS485 PRE VIESSMANN:<br>1. Pripojte tyňovaný komunikačný kábel k portu RS485 (Modbus-R) na striedači Vitovolt.<br>2. Zapojte svorku RS485 A na A+ na krabici CM5, a svorku B na B- na krabici CM5.',
        'kategorie' => [
            '1' => [
                'cat_id' => 1,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'Séria Vitovolt 300',
                'modely' => [
                    '1' => ['meno' => 'Vitovolt 300 M300', 'on' => 40232, 'off' => 40232, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 40313, 'reg_p_ac' => 40091],
                ]
            ]
        ]
    ],
    '10' => [
        'brand_id' => 10,
        'znacka' => 'SUNGROW',
        'zapojenie' => 'KROK-ZA-KROKOM RS485 PRE SUNGROW:<br>1. Nájdite oranžovú svorkovnicu COM.<br>2. Nájdite svorky A2 (RS485_A) a B2 (RS485_B) a prepojte ich so svorkami A+ a B- na krabici CM5.',
        'kategorie' => [
            '1' => [
                'cat_id' => 1,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'TROJFÁZOVÉ REZIDENČNÉ HYBRIDY (Séria SH-RT)',
                'modely' => [
                    '1' => ['meno' => 'SH5.0RT', 'on' => 5007, 'off' => 5007, 'val_on' => 10000, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 13022, 'reg_p_ac' => 13007],
                    '2' => ['meno' => 'SH6.0RT', 'on' => 5007, 'off' => 5007, 'val_on' => 10000, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 13022, 'reg_p_ac' => 13007],
                    '3' => ['meno' => 'SH8.0RT', 'on' => 5007, 'off' => 5007, 'val_on' => 10000, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 13022, 'reg_p_ac' => 13007],
                    '4' => ['meno' => 'SH10RT', 'on' => 5007, 'off' => 5007, 'val_on' => 10000, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 13022, 'reg_p_ac' => 13007],
                ]
            ],
            '2' => [
                'cat_id' => 2,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'TROJFÁZOVÉ SIEŤOVÉ STRIEDAČE (Séria SG-RT)',
                'modely' => [
                    '1' => ['meno' => 'SG5.0RT', 'on' => 5007, 'off' => 5007, 'val_on' => 10000, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 0, 'reg_p_ac' => 13007],
                    '2' => ['meno' => 'SG10RT', 'on' => 5007, 'off' => 5007, 'val_on' => 10000, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 0, 'reg_p_ac' => 13007],
                    '3' => ['meno' => 'SG15RT', 'on' => 5007, 'off' => 5007, 'val_on' => 10000, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 0, 'reg_p_ac' => 13007],
                    '4' => ['meno' => 'SG20RT', 'on' => 5007, 'off' => 5007, 'val_on' => 10000, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 0, 'reg_p_ac' => 13007],
                ]
            ]
        ]
    ],
    '11' => [
        'brand_id' => 11,
        'znacka' => 'SOLIS',
        'zapojenie' => 'KROK-ZA-KROKOM RS485 PRE SOLIS:<br>1. Použite 4-pinový okrúhly komunikačný konektor COM. Pin 1 je RS485_A (+), pin 2 je RS485_B (-).<br>2. Pripojte pin 1 na svorku A+ a pin 2 na svorku B- na krabici CM5.',
        'kategorie' => [
            '1' => [
                'cat_id' => 1,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'TROJFÁZOVÉ REZIDENČNÉ HYBRIDY (Séria S6-EH3P)',
                'modely' => [
                    '1' => ['meno' => 'S6-EH3P5K-H', 'on' => 43110, 'off' => 43110, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 33139, 'reg_p_ac' => 33079],
                    '2' => ['meno' => 'S6-EH3P6K-H', 'on' => 43110, 'off' => 43110, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 33139, 'reg_p_ac' => 33079],
                    '3' => ['meno' => 'S6-EH3P8K-H', 'on' => 43110, 'off' => 43110, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 33139, 'reg_p_ac' => 33079],
                    '4' => ['meno' => 'S6-EH3P10K-H', 'on' => 43110, 'off' => 43110, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 33139, 'reg_p_ac' => 33079],
                ]
            ],
            '2' => [
                'cat_id' => 2,
                'typ' => 'striedac',
                'skupina_id' => 1,
                'skip_model_selection' => true,
                'meno' => 'TROJFÁZOVÉ SIEŤOVÉ STRIEDAČE (Séria S5-GR3P)',
                'modely' => [
                    '1' => ['meno' => 'S5-GR3P5K', 'on' => 43110, 'off' => 43110, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 0, 'reg_p_ac' => 33079],
                    '2' => ['meno' => 'S5-GR3P10K', 'on' => 43110, 'off' => 43110, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 0, 'reg_p_ac' => 33079],
                    '3' => ['meno' => 'S5-GR3P15K', 'on' => 43110, 'off' => 43110, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 0, 'reg_p_ac' => 33079],
                    '4' => ['meno' => 'S5-GR3P20K', 'on' => 43110, 'off' => 43110, 'val_on' => 100, 'val_off' => 0, 'baud' => 9600, 'reg_soc' => 0, 'reg_p_ac' => 33079],
                ]
            ]
        ]
    ]
];

echo json_encode($device_db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;