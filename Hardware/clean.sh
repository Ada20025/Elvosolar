#!/bin/bash
# ============================================================
#  ELVOCONTROLL CM5 — ČISTENIE (update + zmaž DB a __pycache__)
#  Použitie:  bash ~/clean.sh
#
#  POZOR: Zmaže LOKÁLNU database.db na CM5 (nastavenia aj história).
#  Cloudová DB na serveri ostáva nedotknutá!
#  Po vyčistení musíš zariadenie znova setupnúť káblom.
#
#  Vždy ťahá NAJNOVŠIE úložisko: najprv zistí posledný commit
#  na vetve main a zip stiahne pinutý naň (branch zip je
#  ~5 min cachovaný, takto nikdy nepríde stará verzia).
# ============================================================

REPO="Ada20025/Elvosolar"
BRANCH="main"

have() { command -v "$1" >/dev/null 2>&1; }

# Stiahne URL do súboru — curl, wget alebo python3 (niečo z toho na CM5 vždy je)
fetch() {
    if have curl; then
        curl -fsSL --max-time 60 -A "elvocontroll-update" -o "$2" "$1"
    elif have wget; then
        wget -q -O "$2" "$1"
    elif have python3; then
        python3 -c 'import sys, urllib.request; urllib.request.urlretrieve(sys.argv[1], sys.argv[2])' "$1" "$2"
    else
        echo "    ❌ Nenašiel som curl, wget ani python3" >&2
        return 1
    fi
}

echo "=================================================="
echo "   ELVOCONTROLL — ČISTENIE (DB + __pycache__)"
echo "=================================================="
echo ""
echo "  Toto zmaže:"
echo "    ❌ ~/Hardware/database.db       (lokálne nastavenia, história)"
echo "    ❌ všetky __pycache__ a .pyc    (staré bajtkódy)"
echo ""
echo "  Toto NEZMAŽE:"
echo "    ✅ Cloudovú DB na serveri       (zariadenia, telemetry, users)"
echo "    ✅ Samotný kód                  (ten sa naopak AKTUALIZUJE)"
echo ""
read -p "Naozaj pokračovať? (y/N): " CONFIRM
if [ "$CONFIRM" != "y" ]; then
    echo "Zrušené."
    exit 0
fi

echo ""
echo "[1/5] Sťahujem najnovší kód z GitHubu..."
echo "    Nástroje: curl=$(have curl && echo áno || echo NIE), wget=$(have wget && echo áno || echo NIE), python3=$(have python3 && echo áno || echo NIE)"

COMMIT=""
if fetch "https://api.github.com/repos/${REPO}/commits/${BRANCH}" /tmp/elvosolar_commit.json; then
    COMMIT=$(sed -n 's/^[[:space:]]*"sha": "\([0-9a-f]\{40\}\)".*/\1/p' /tmp/elvosolar_commit.json | head -1)
fi

if [ -n "$COMMIT" ]; then
    ZIP_URL="https://github.com/${REPO}/archive/${COMMIT}.zip"
    echo "    Vetva: ${BRANCH} | Commit: $(echo "$COMMIT" | cut -c1-7)"
else
    ZIP_URL="https://github.com/${REPO}/archive/refs/heads/${BRANCH}.zip"
    echo "    ⚠️  Commit sa nepodarilo zistiť — použijem vetvu ${BRANCH}"
fi

rm -f /tmp/elvosolar_main.zip
if ! fetch "$ZIP_URL" /tmp/elvosolar_main.zip; then
    echo ""
    echo "❌ Sťahovanie zlyhalo. Diagnostika:"
    echo "    systémový čas: $(date)"
    if have getent; then
        HOST=$(getent hosts github.com 2>/dev/null | head -1)
        [ -n "$HOST" ] && echo "    DNS github.com: $HOST" || echo "    DNS github.com: NEFUNGUJE (žiadny internet?)"
    fi
    if have ping; then
        if ping -c1 -W3 1.1.1.1 >/dev/null 2>&1; then
            echo "    internet (ping 1.1.1.1): OK → problém je DNS alebo TLS (zlý čas?)"
        else
            echo "    internet (ping 1.1.1.1): NEDOSTUPNÝ → CM5 nemá internet"
        fi
    fi
    echo "    Skús ručne:  curl -v -o /dev/null https://github.com"
    echo "                 sudo date -s \"$(date)\"   # ak je čas mimo (certifikát)"
    rm -f /tmp/elvosolar_main.zip /tmp/elvosolar_commit.json
    exit 1
fi

# Over že zip je validný
unzip -t /tmp/elvosolar_main.zip > /dev/null 2>&1 \
    || { echo "❌ Zip je poškodený — skús znova"; rm -f /tmp/elvosolar_main.zip; exit 1; }
echo "    ✅ Stiahnuté a overené"

echo "[2/5] Zastavujem aplikáciu..."
sudo systemctl stop elvosolar 2>/dev/null
sleep 2

echo "[3/5] Zálohujem starú DB (pre istotu do ~/Hardware/db_old_backup)..."
mkdir -p ~/Hardware/db_old_backup
if [ -f ~/Hardware/database.db ]; then
    mv ~/Hardware/database.db ~/Hardware/db_old_backup/database_$(date +%Y%m%d_%H%M%S).db
    echo "    ✅ Stará DB presunutá do ~/Hardware/db_old_backup/"
fi

echo "[4/5] Mažem __pycache__, .pyc a staré súbory..."
find ~/Hardware -name "__pycache__" -type d -exec rm -rf {} + 2>/dev/null
find ~/Hardware -name "*.pyc" -delete 2>/dev/null
rm -rf /tmp/elvosolar_extract
mkdir -p /tmp/elvosolar_extract
unzip -q /tmp/elvosolar_main.zip -d /tmp/elvosolar_extract
# Zip pinutý na commit sa rozbaľuje ako Elvosolar-<sha> — zjednoťme cestu
if [ ! -d /tmp/elvosolar_extract/Elvosolar-main ] && [ -d /tmp/elvosolar_extract/Elvosolar-"$COMMIT" ]; then
    mv /tmp/elvosolar_extract/Elvosolar-"$COMMIT" /tmp/elvosolar_extract/Elvosolar-main
fi
[ -d /tmp/elvosolar_extract/Elvosolar-main/Hardware ] || { echo "❌ Zip nemá očakávanú štruktúru"; exit 1; }
cp -v /tmp/elvosolar_extract/Elvosolar-main/Hardware/*.py ~/Hardware/ 2>/dev/null | tail -3
cp -v /tmp/elvosolar_extract/Elvosolar-main/Hardware/*.sh ~/Hardware/ 2>/dev/null | tail -2
cp -f /tmp/elvosolar_extract/Elvosolar-main/Hardware/*.json ~/Hardware/ 2>/dev/null
cp -f /tmp/elvosolar_extract/Elvosolar-main/Hardware/*.txt ~/Hardware/ 2>/dev/null
cp -f /tmp/elvosolar_extract/Elvosolar-main/Hardware/*.md ~/Hardware/ 2>/dev/null
mkdir -p ~/Hardware/templates
cp -v /tmp/elvosolar_extract/Elvosolar-main/Hardware/templates/* ~/Hardware/templates/ 2>/dev/null
chmod +x ~/Hardware/*.sh 2>/dev/null
echo "    ✅ Vyčistené a nový kód nakopírovaný"

echo "[5/5] Spúšťam aplikáciu (čistá DB sa vytvorí automaticky)..."
sudo systemctl start elvosolar
sleep 3
sudo systemctl is-active elvosolar > /dev/null \
    && echo "    ✅ Aplikácia beží" \
    || echo "    ❌ Aplikácia nejde — pozri: sudo journalctl -u elvosolar -n 20"

# Upratuj
rm -f /tmp/elvosolar_main.zip /tmp/elvosolar_commit.json
rm -rf /tmp/elvosolar_extract

echo ""
echo "=================================================="
echo "   ✅ ČISTENIE DOKONČENÉ"
echo ""
echo "   CM5 teraz beží s ČISTOU databázou."
echo "   ➡️  Prejdi setup znova káblom:"
echo "      1. USB-C kábel: CM5 ↔ PC"
echo "      2. app.elvosolar.sk/setup"
echo "      3. 🔌 Pripojiť cez USB UART → kroky → ODOŠLI"
echo ""
echo "   Stará DB je v ~/Hardware/db_old_backup/ (ak potrebuješ niečo získať)"
echo "   Logy: elvo-log   |   Setup logy: elvo-log serial"
echo "=================================================="
