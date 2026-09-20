#!/bin/bash
# ============================================================
#  ELVO-LOG — čisté logy CM5 bez GPIO/sudo spamu
#  Použitie:  bash ~/elvo-log          (živé sledovanie)
#             bash ~/elvo-log 50       (posledných 50 riadkov)
#             bash ~/elvo-log serial   (len Serial Config / JSON / WiFi)
# ============================================================
FILTER='sudo\[|pam_unix|gpio[0-9]'

case "$1" in
    serial)
        echo "=== SERIAL-CONFIG (JSON, WiFi, testy) — živé (Ctrl+C = koniec) ==="
        sudo journalctl -u elvosolar -f --no-pager | grep --line-buffered "SERIAL-CONFIG"
        ;;
    ""|[0-9]*)
        N="${1:-40}"
        echo "=== ELVOSOLAR — posledných $N riadkov (čisté) ==="
        sudo journalctl -u elvosolar -n "$N" --no-pager | grep -vE "$FILTER"
        echo ""
        echo "Tip: 'bash ~/elvo-log serial' = len Serial Config | 'bash ~/elvo-log' = živé"
        ;;
    *)
        echo "Použitie:"
        echo "  bash ~/elvo-log         → živé sledovanie (čisté)"
        echo "  bash ~/elvo-log 100     → posledných 100 riadkov (čisté)"
        echo "  bash ~/elvo-log serial  → len Serial Config (JSON/WiFi/testy)"
        ;;
esac
