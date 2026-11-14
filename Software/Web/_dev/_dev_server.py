#!/usr/bin/env python3
"""Lokálny dev server — imituje index.php routing (clean URLs).

Súbory s príponou servuje priamo; bez prípony mapuje cez $ROUTES z index.php
(/sluzby -> služby.html), podporuje jazykové prefixy /sk /en /an /de.
"""
import mimetypes
import os
import re
import sys
import json
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import unquote, urlsplit

ROOT = os.path.dirname(os.path.abspath(__file__))
os.chdir(ROOT)

# ---- Trasy a presmerovania prevzaté z index.php ----
routes = {}
redirects = {}
try:
    router_file = "web-router.php"
    for candidate in ("web-router.php", "front.php", "router.php", "index.php"):
        if os.path.isfile(candidate):
            router_file = candidate
            break
    src = open(router_file, encoding="utf-8").read()
    block = re.search(r"\$ROUTES\s*=\s*\[(.*?)\];", src, re.S).group(1)
    pair_re = r"'((?:[^'\\\\]|\\\\.)*)'\s*=>\s*'((?:[^'\\\\]|\\\\.)*)'"
    for k, v in re.findall(pair_re, block):
        routes[k.lower()] = v
    rblock = re.search(r"\$REDIRECTS\s*=\s*\[(.*?)\];", src, re.S)
    if rblock:
        for k, v in re.findall(pair_re, rblock.group(1)):
            redirects[k.lower()] = v
except Exception as exc:  # noqa: BLE001
    print("Nepodarilo sa nacitat trasy z router.php:", exc, file=sys.stderr)

langs = {"sk", "en", "an", "de"}
head_re = re.compile(r"(<head[^>]*>)", re.I)

MAIL_COUNTER_FILE = os.path.join(os.path.dirname(__file__) or ".", ".php_mail_counter")


def _read_mail_counter():
    try:
        with open(MAIL_COUNTER_FILE, encoding="utf-8") as fh:
            return int(fh.read().strip() or "0")
    except Exception:
        return 0


def _increment_mail_counter():
    n = _read_mail_counter() + 1
    with open(MAIL_COUNTER_FILE, "w", encoding="utf-8") as fh:
        fh.write(str(n))
    return n


def _fake_send_php_body(payload_bytes):
    # Simuluje spracovanie send.php — vracia JSON ako keby mail() fungoval.
    try:
        data = json.loads(payload_bytes.decode("utf-8", errors="replace"))
    except Exception:
        return json.dumps({"success": False, "message": "Neplatný JSON požiadavky."}).encode("utf-8")

    action = (data.get("action") or "").strip()
    website = (data.get("website") or "").strip()

    # honeypot — bot detekcia, fake "uspech"
    if website:
        return json.dumps({"success": True}).encode("utf-8")

    # rate-limit simulácia (záloha — na dev serveri neblokuje)
    # (v reálnom send.php sa tato logika vykonáva v PHP; tu iba nechávame mail behať)

    # akcia: contact / cart / newsletter
    if action in ("contact", "cart", "newsletter"):
        # "odoslanie maila" — inkrementujeme počítadlo, ale neposielame skutočný mail
        mail_no = _increment_mail_counter()
        subject = ""
        try:
            name = (data.get("name") or "").strip()
            subject = "Nový kontaktný dopyt: " + name if name else "Oct 7 2026 18:05"
        except Exception:
            pass

        body = {
            "success": True,
            "message": "E-mail bol úspešne odoslaný (simulácia: mail() nebol vykonaný na tomto serveri).",
            "meta": {
                "action": action,
                "mail_counter": mail_no,
                "subject": subject,
                "simulovano": True,
                "note": "Reálny PHP mail() sa spustí až na hostingu (InfinityFree). Na tomto lokálnom serveri mail() neexistuje, preto len simulujeme úspešnú odpoveď.",
            },
        }
        return json.dumps(body, ensure_ascii=False).encode("utf-8")

    return json.dumps({"success": False, "message": "Neznáma požiadavka."}).encode("utf-8")


class Handler(SimpleHTTPRequestHandler):
    def _html_response(self, status, file_path, extra_head=""):
        with open(file_path, "rb") as fh:
            html = fh.read().decode("utf-8", errors="replace")
        if extra_head and "<base" not in html:
            m = head_re.search(html)
            if m:
                html = html[: m.end()] + extra_head + html[m.end() :]
            else:
                html = extra_head + html
        data = html.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/html; charset=UTF-8")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(data)

    def do_GET(self):
        path = unquote(urlsplit(self.path).path)
        stripped = path.strip("/")

        # existujúce súbory / priečinky nechaj na štandardné spracovanie
        if stripped and (os.path.isfile(stripped) or os.path.isdir(stripped)):
            return super().do_GET()

        segments = [s for s in stripped.split("/") if s]
        lang = None
        if segments and segments[0].lower() in langs:
            lang = segments.pop(0).lower()
            if lang == "an":
                lang = "en"
            stripped = "/".join(segments)

        # presmerovania starých adries (napr. /sluzby → /riesenia-na-kluc)
        redirect_to = redirects.get(stripped.lower())
        if redirect_to:
            prefix = ("/" + lang) if lang else ""
            self.send_response(301)
            self.send_header("Location", "%s/%s" % (prefix, redirect_to))
            self.send_header("Content-Length", "0")
            self.end_headers()
            return

        file = routes.get(stripped.lower())
        if file is None and stripped and "/" not in stripped:
            cand = stripped + ".html"
            if cand != ".." and ".." not in cand and os.path.isfile(cand):
                file = cand

        if file and os.path.isfile(file):
            extra = ""
            if lang:
                extra += (
                    "<script>try{localStorage.setItem('elvo_lang','%s')}catch(e){}</script>"
                    % lang
                )
            # index.php vkladá <base href="/"> pre každú prefixovanú URL
            # (inak by relatívne cesty css/js smerovali na /sk/assets/…) —
            # dev server musí robiť to isté, aby sa správal ako produkcia.
            if lang or "/" in stripped:
                extra = '<base href="/">' + extra
            return self._html_response(200, file, extra)

        if os.path.isfile("404.html"):
            self._html_response(404, "404.html", '<base href="/">')
        else:
            self.send_error(404, "Not Found")

    def do_POST(self):
        # Podpory POST pre PHP súbory (send.php) — vracia fake "mail sent" JSON
        path = unquote(urlsplit(self.path).path).strip("/")
        if path.endswith(".php"):
            try:
                length = int(self.headers.get("Content-Length") or "0")
            except Exception:
                length = 0
            body_bytes = self.rfile.read(length) if length else b"{}"
            resp_bytes = _fake_send_php_body(body_bytes)
            self.send_response(200)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(resp_bytes)))
            self.end_headers()
            self.wfile.write(resp_bytes)
            return
        # pre ostatné POST požiadavky — 405
        self.send_error(405, "Method Not Allowed")

    do_HEAD = do_GET

    def log_message(self, fmt, *args):
        sys.stderr.write("%s - %s\n" % (self.address_string(), fmt % args))


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8734
    print("Elvo dev server: http://localhost:%d  (trás: %d)" % (port, len(routes)))
    ThreadingHTTPServer(("127.0.0.1", port), Handler).serve_forever()
