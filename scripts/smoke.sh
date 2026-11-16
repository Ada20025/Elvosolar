#!/usr/bin/env bash
# smoke.sh — rýchla kontrola, že nasadená appka naozaj žije.
#
#   ./scripts/smoke.sh https://domena.up.railway.app KLUC GATE_USER GATE_PASS
#
# Kontroluje: /healthcheck, bránu, prihlasovaciu stránku a že API bez sessions
# vracia 401 (nie HTML). Nič neukladá, nič nemení — len číta.
set -uo pipefail

BASE="${1:-}"
KEY="${2:-}"
GUSER="${3:-}"
GPASS="${4:-}"

if [ -z "$BASE" ]; then
    echo "Použitie: $0 https://domena KLUC GATE_USER GATE_PASS" >&2
    exit 2
fi
BASE="${BASE%/}"

fail=0
ok()   { printf '  OK   %s\n' "$1"; }
bad()  { printf '  FAIL %s\n' "$1"; fail=1; }

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$@"; }

echo "== Smoke test: $BASE =="

# 1) Healthcheck (bez kľúča, bez brány)
c=$(code "$BASE/healthcheck")
[ "$c" = "200" ] && ok "/healthcheck = 200" || bad "/healthcheck = $c (očakávam 200)"

# 2) Tajná adresa s kľúčom — nesmie vrátiť 404
if [ -n "$KEY" ]; then
    c=$(code "$BASE/?k=$KEY")
    case "$c" in
        200|302) ok "kľúč prijatý (/?k=… = $c)" ;;
        *) bad "kľúč nefunguje (/?k=… = $c) — skontroluj ACCESS_URL_KEY" ;;
    esac
else
    echo "  SKIP kľúč (nezadaný)"
fi

# 3) Brána — prihlásenie do gate
if [ -n "$GUSER" ] && [ -n "$GPASS" ]; then
    jar=$(mktemp)
    c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 -c "$jar" \
        -d "username=$GUSER&password=$GPASS" "$BASE/access")
    case "$c" in
        200|302) ok "brána prijala prihlasovacie údaje ($c)" ;;
        *) bad "brána odmietla ($c) — ACCESS_GATE_USER/PASS?" ;;
    esac
    c=$(code -b "$jar" "$BASE/login")
    [ "$c" = "200" ] && ok "/login = 200" || bad "/login = $c (očakávam 200)"
    # API bez prihlásenia musí vrátiť JSON 401, nie HTML bránu
    body=$(curl -s --max-time 15 -b "$jar" -o /dev/null -w '%{http_code}' "$BASE/api/user/me")
    [ "$body" = "401" ] && ok "/api/user/me bez session = 401" || bad "/api/user/me = $body (očakávam 401)"
    rm -f "$jar"
else
    echo "  SKIP brána (neuvedené údaje)"
fi

echo
if [ "$fail" -eq 0 ]; then
    echo "✅ Všetko prešlo."
else
    echo "❌ Niečo zlyhalo — pozri riadky FAIL vyššie."
fi
exit "$fail"
