#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Konfigurácia a nasadenie Elvosolar z jedného súboru .env.

Použitie:
    python _dev/config.py check              # skontroluje, čo je vyplnené
    python _dev/config.py railway            # ukáže premenné pre Railway (bez zmeny)
    python _dev/config.py railway --apply    # nastaví premenné na Railway
    python _dev/config.py dns                # ukáže DNS zmeny v Cloudflare
    python _dev/config.py dns --apply        # vytvorí/upraví CNAME záznamy
    python _dev/config.py all --apply        # Railway + Cloudflare naraz

Bez --apply sa nič nezmení, iba sa vypíše plán.
Tajomstvá sa v výpise maskujú.
"""
import json
import os
import re
import sys
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ENV_FILE = os.path.join(ROOT, ".env")

RAILWAY_API = "https://backboard.railway.com/graphql/v2"
CF_API = "https://api.cloudflare.com/client/v4"

# Premenné, ktoré sa posielajú do Railway (poradie je len kvôli výpisu).
RAILWAY_KEYS = [
    "SITE_URL", "ELVO_DATA_DIR",
    "RESEND_API_KEY", "BREVO_API_KEY",
    "MAIL_FROM", "MAIL_FROM_NAME", "MAIL_TO", "MAIL_BACKUP", "MAIL_REPLY_TO",
    "SMTP_HOST", "SMTP_PORT", "SMTP_SECURE", "SMTP_USER", "SMTP_PASS",
    "ADMIN_USER", "ADMIN_PASSWORD_HASH",
]

SECRET_KEYS = {
    "RESEND_API_KEY", "BREVO_API_KEY", "SMTP_PASS", "CLOUDFLARE_API_TOKEN",
    "RAILWAY_TOKEN", "ADMIN_PASSWORD_HASH",
}

EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$")
HOST_RE = re.compile(r"^(?!-)[a-z0-9-]{1,63}(\.[a-z0-9-]{1,63})+$")

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


# --------------------------------------------------------------------- .env
def load_env(path):
    values = {}
    if not os.path.isfile(path):
        return values
    with open(path, "r", encoding="utf-8", errors="replace") as fh:
        for raw in fh:
            line = raw.strip()
            if not line or line[0] in "#;" or "=" not in line:
                continue
            key, value = line.split("=", 1)
            key = key.strip()
            value = value.strip()
            if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
                value = value[1:-1]
            if re.fullmatch(r"[A-Za-z0-9_]+", key or ""):
                values[key] = value
    return values


ENV = load_env(ENV_FILE)


def cfg(key, default=""):
    """Hodnota z prostredia (Railway/CI), inak z .env."""
    return os.environ.get(key) or ENV.get(key) or default


def mask(value):
    if not value:
        return "(prázdne)"
    if len(value) <= 8:
        return value[0] + "*" * max(0, len(value) - 1)
    return value[:4] + "…" + value[-4:]


def show(key):
    value = cfg(key)
    return mask(value) if key in SECRET_KEYS else (value or "(prázdne)")


# ---------------------------------------------------------------- HTTP pomoc
def http_json(url, token, payload=None, method=None, cf=False, timeout=30):
    """Zavolá JSON API a vráti odpoveď; pri chybe vyhodí RuntimeError."""
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    headers = {"Content-Type": "application/json", "Accept": "application/json",
               "User-Agent": "elvosolar-config/1.0"}
    if token:
        headers["Authorization"] = ("Bearer " if cf else "Bearer ") + token
    req = urllib.request.Request(url, data=data, headers=headers,
                                 method=method or ("POST" if data else "GET"))
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", "replace")
        raise RuntimeError("HTTP %s: %s" % (exc.code, body[:400]))
    except urllib.error.URLError as exc:
        raise RuntimeError("sieťová chyba: %s" % exc.reason)
    try:
        return json.loads(body)
    except ValueError:
        raise RuntimeError("odpoveď nie je JSON: %s" % body[:200])


def gql(query, variables):
    token = cfg("RAILWAY_TOKEN")
    if not token:
        raise RuntimeError("chýba RAILWAY_TOKEN v .env")
    data = http_json(RAILWAY_API, token, {"query": query, "variables": variables})
    if data.get("errors"):
        raise RuntimeError("; ".join(e.get("message", "?") for e in data["errors"]))
    return data.get("data") or {}


def cf_request(path, method="GET", payload=None):
    token = cfg("CLOUDFLARE_API_TOKEN")
    if not token:
        raise RuntimeError("chýba CLOUDFLARE_API_TOKEN v .env")
    data = http_json(CF_API + path, token, payload, method=method, cf=True)
    if not data.get("success"):
        errs = data.get("errors") or [{"message": "neznáma chyba"}]
        raise RuntimeError("; ".join(str(e.get("message", e)) for e in errs))
    return data.get("result")


# -------------------------------------------------------------------- check
def cmd_check(_apply=False):
    print("Konfigurácia: %s\n" % ENV_FILE)
    problems = []
    warnings = []

    def ok(label, value, hint=""):
        if value:
            print("  ✔ %-24s %s" % (label, show(label)))
        else:
            print("  ✖ %-24s chýba%s" % (label, (" — " + hint) if hint else ""))
            problems.append(label)

    for key in ("SITE_URL", "MAIL_FROM", "MAIL_TO"):
        ok(key, cfg(key))

    if cfg("RESEND_API_KEY"):
        print("  ✔ %-24s %s" % ("RESEND_API_KEY", show("RESEND_API_KEY")))
        if not cfg("RESEND_API_KEY").startswith("re_"):
            warnings.append("RESEND_API_KEY zvyčajne začína 're_'")
    elif cfg("SMTP_PASS"):
        print("  ✔ %-24s (SMTP kanál)" % "SMTP_PASS")
    else:
        print("  ✖ %-24s chýba — vyplňte RESEND_API_KEY alebo SMTP_PASS" % "E-mail kanál")
        problems.append("E-mail kanál")

    for key in ("MAIL_FROM", "MAIL_TO", "MAIL_BACKUP", "MAIL_REPLY_TO"):
        value = cfg(key)
        if value and not EMAIL_RE.match(value):
            warnings.append("%s nevyzerá ako e-mail: %s" % (key, value))

    if cfg("ADMIN_PASSWORD_HASH") and not re.match(r"^\$(2y|2a|argon2)", cfg("ADMIN_PASSWORD_HASH")):
        warnings.append("ADMIN_PASSWORD_HASH nevyzerá ako password_hash()")
    if not cfg("ADMIN_PASSWORD_HASH"):
        print("  · %-24s prázdne → použije sa _admin_config.php" % "ADMIN_PASSWORD_HASH")

    for key in ("CLOUDFLARE_API_TOKEN", "RAILWAY_TOKEN", "RAILWAY_PROJECT_ID",
                "RAILWAY_SERVICE_ID", "RAILWAY_DOMAIN"):
        ok(key, cfg(key))
    if cfg("CLOUDFLARE_ZONE_ID") and not re.fullmatch(r"[0-9a-f]{32}", cfg("CLOUDFLARE_ZONE_ID")):
        warnings.append("CLOUDFLARE_ZONE_ID má mať 32 znakov (hex)")
    if cfg("RAILWAY_DOMAIN"):
        domain = cfg("RAILWAY_DOMAIN").replace("https://", "").replace("http://", "").strip("/")
        if not HOST_RE.match(domain):
            warnings.append("RAILWAY_DOMAIN má byť hostiteľ bez https:// (napr. web-production-x.up.railway.app)")

    print()
    if warnings:
        print("Upozornenia:")
        for w in warnings:
            print("  ! %s" % w)
    if problems:
        print("\nChýba %d povinných údajov: %s" % (len(problems), ", ".join(problems)))
        return 1
    print("Všetko povinné je vyplnené.")
    return 0


# ------------------------------------------------------------------ railway
def resolve_environment_id():
    env_id = cfg("RAILWAY_ENVIRONMENT_ID")
    if env_id:
        return env_id
    data = gql(
        "query($id:String!){project(id:$id){name environments{edges{node{id name}}}}}",
        {"id": cfg("RAILWAY_PROJECT_ID")},
    )
    project = data.get("project") or {}
    edges = ((project.get("environments") or {}).get("edges") or [])
    wanted = cfg("RAILWAY_ENVIRONMENT", "production").lower()
    for edge in edges:
        node = edge.get("node") or {}
        if (node.get("name") or "").lower() == wanted:
            return node.get("id")
    if len(edges) == 1:
        return (edges[0].get("node") or {}).get("id")
    names = ", ".join((e.get("node") or {}).get("name", "?") for e in edges) or "žiadne"
    raise RuntimeError("prostredie '%s' sa nenašlo (dostupné: %s)" % (wanted, names))


def railway_variables():
    values = {}
    for key in RAILWAY_KEYS:
        value = cfg(key)
        if value:
            values[key] = value
    # Na Railway je dátový priečinok volume; ak nie je vyplnený, nastav /data.
    values.setdefault("ELVO_DATA_DIR", "/data")
    return values


def cmd_railway(apply=False):
    project_id = cfg("RAILWAY_PROJECT_ID")
    service_id = cfg("RAILWAY_SERVICE_ID")
    if not (cfg("RAILWAY_TOKEN") and project_id and service_id):
        print("Chýba RAILWAY_TOKEN / RAILWAY_PROJECT_ID / RAILWAY_SERVICE_ID v .env.")
        return 1

    variables = railway_variables()
    print("Railway premenné (%d):" % len(variables))
    for key in RAILWAY_KEYS:
        if key in variables:
            value = variables[key] if key == "ELVO_DATA_DIR" else show(key)
            print("  %-22s %s" % (key, value))

    try:
        environment_id = resolve_environment_id()
    except RuntimeError as exc:
        print("\nNepodarilo sa zistiť prostredie: %s" % exc)
        return 2

    print("\nProjekt:   %s\nProstredie: %s\nSlužba:    %s" % (project_id, environment_id, service_id))
    if not apply:
        print("\nSkúšobný režim — nič sa nezmenilo. Spustite s --apply.")
        return 0

    try:
        gql(
            "mutation($input:VariableCollectionUpsertInput!){variableCollectionUpsert(input:$input)}",
            {"input": {"projectId": project_id, "environmentId": environment_id,
                       "serviceId": service_id, "variables": variables}},
        )
    except RuntimeError as exc:
        print("\nRailway odmietol požiadavku: %s" % exc)
        return 2
    print("\nHotovo — premenné sú nastavené, Railway službu redeployne.")
    return 0


# ---------------------------------------------------------------------- dns
def zone_id():
    zone = cfg("CLOUDFLARE_ZONE_ID")
    if zone:
        return zone
    domain = cfg("CLOUDFLARE_DOMAIN", "elvosolar.sk")
    result = cf_request("/zones?name=%s" % domain)
    if not result:
        raise RuntimeError("zóna %s sa v Cloudflare nenašla (je doména pridaná?)" % domain)
    return result[0]["id"]


def cmd_dns(apply=False):
    target = cfg("RAILWAY_DOMAIN").replace("https://", "").replace("http://", "").strip("/")
    if not target:
        print("Chýba RAILWAY_DOMAIN v .env (adresa služby na Railway).")
        return 1
    domain = cfg("CLOUDFLARE_DOMAIN", "elvosolar.sk")
    proxied = cfg("CLOUDFLARE_PROXIED", "true").lower() not in ("false", "0", "no", "off")

    try:
        zone = zone_id()
    except RuntimeError as exc:
        print("Cloudflare: %s" % exc)
        return 2

    print("Zóna:      %s (%s)" % (domain, zone))
    print("Cieľ:      %s" % target)
    print("Proxy:     %s\n" % ("zapnutý (oranžový oblak)" if proxied else "vypnutý (DNS only)"))

    plan = []
    for name in ("@", "www"):
        fqdn = domain if name == "@" else "%s.%s" % (name, domain)
        try:
            records = cf_request("/zones/%s/dns_records?name=%s" % (zone, fqdn)) or []
        except RuntimeError as exc:
            print("Nepodarilo sa načítať záznamy pre %s: %s" % (fqdn, exc))
            return 2
        cname = next((r for r in records if r.get("type") == "CNAME"), None)
        others = [r for r in records if r.get("type") != "CNAME"]
        if others and not cname:
            types = ", ".join(sorted({r.get("type", "?") for r in others}))
            print("  ! %-22s existujú záznamy %s — CNAME nevytváram, upravte ich ručne" % (fqdn, types))
            continue
        if cname:
            action = "upraviť" if (cname.get("content") != target or bool(cname.get("proxied")) != proxied) else "bez zmeny"
            plan.append((fqdn, action, cname.get("id"), cname.get("content")))
        else:
            plan.append((fqdn, "vytvoriť", None, None))

    for fqdn, action, _rid, current in plan:
        extra = "" if current is None else "  (teraz: %s)" % current
        print("  %-22s %s%s" % (fqdn, action, extra))

    if not apply:
        print("\nSkúšobný režim — nič sa nezmenilo. Spustite s --apply.")
        return 0

    failures = 0
    for fqdn, action, record_id, _current in plan:
        if action == "bez zmeny":
            continue
        payload = {"type": "CNAME", "name": fqdn, "content": target,
                   "proxied": proxied, "ttl": 1}
        try:
            if record_id:
                cf_request("/zones/%s/dns_records/%s" % (zone, record_id), "PATCH", payload)
                print("  ✔ upravené  %s" % fqdn)
            else:
                cf_request("/zones/%s/dns_records" % zone, "POST", payload)
                print("  ✔ vytvorené %s" % fqdn)
        except RuntimeError as exc:
            failures += 1
            print("  ✖ %s: %s" % (fqdn, exc))

    if failures:
        print("\n%d záznamov sa nepodarilo zapísať." % failures)
        return 2
    print("\nDNS je hotové. V Railway ešte overte domény v Settings → Networking.")
    return 0


# ---------------------------------------------------------------------- CLI
def main(argv):
    args = [a for a in argv[1:] if not a.startswith("-")]
    apply = "--apply" in argv
    command = args[0] if args else "check"

    if command in ("help", "-h", "--help"):
        print(__doc__)
        return 0
    if command == "check":
        return cmd_check()
    if command == "railway":
        return cmd_railway(apply)
    if command == "dns":
        return cmd_dns(apply)
    if command == "all":
        rc = cmd_check()
        if rc:
            print("\nNajprv doplňte chýbajúce údaje.")
            return rc
        print("\n" + "=" * 52 + "\n")
        rc_r = cmd_railway(apply)
        print("\n" + "=" * 52 + "\n")
        rc_d = cmd_dns(apply)
        return rc_r or rc_d
    print("Neznámy príkaz: %s\n" % command)
    print(__doc__)
    return 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))
