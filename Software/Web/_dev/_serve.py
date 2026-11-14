#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Lokálny testovací server - imituje index.php router (PHP nie je k dispozícii)."""
import io, os, sys, unicodedata
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler

ROOT = os.path.dirname(os.path.abspath(__file__))
os.chdir(ROOT)

ROUTES = {
    '': 'index.html', 'index': 'index.html', 'domov': 'index.html',
    'o-nas': 'o-nás.html', 'o-nás': 'o-nás.html', 'on-as': 'o-nás.html',
    'sluzby': 'služby.html', 'služby': 'služby.html',
    'zelena-podnikom': 'zelená-podnikom.html', 'zelená-podnikom': 'zelená-podnikom.html',
    'projekty-samrs': 'projekty-samrs.html',
    'kontakt': 'kontakt.html', 'mapa-webu': 'mapa-webu.html',
    'fotovoltika': 'fotovoltika.html', 'solarny-ohrev': 'solárny-ohrev.html',
    'solárny-ohrev': 'solárny-ohrev.html',
    'orc-jednotky': 'orc-jednotky.html',
    'absorbecne-chladenie': 'absorpčné-chladenie.html',
    'absorpčné-chladenie': 'absorpčné-chladenie.html',
    'bateriove-uloziska': 'bateriové-úložiská.html',
    'bateriové-úložiská': 'bateriové-úložiská.html',
}
LANGS = {'sk', 'en', 'an', 'de'}


def resolve(path):
    p = path.strip('/')
    segs = p.split('/') if p else []
    if segs and segs[0].lower() in LANGS:
        segs = segs[1:]
        p = '/'.join(segs)
    key = p.lower()
    if key in ROUTES:
        return ROUTES[key]
    if p and '/' not in p:
        cand = unicodedata.normalize('NFC', p) + '.html'
        if os.path.isfile(cand):
            return cand
    return None


class Handler(SimpleHTTPRequestHandler):
    def do_GET(self):
        from urllib.parse import urlsplit, unquote
        path = unquote(urlsplit(self.path).path)
        if path.startswith('/_skip'):
            return super().do_GET()
        f = resolve(path)
        if f and os.path.isfile(f):
            ctype = 'text/html; charset=utf-8'
            with io.open(f, 'rb') as fh:
                body = fh.read()
            self.send_response(200)
            self.send_header('Content-Type', ctype)
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        if '.' not in os.path.basename(path):
            self.send_error(404, 'Not Found')
            return
        return super().do_GET()

    def log_message(self, fmt, *args):
        sys.stderr.write('%s - %s\n' % (self.address_string(), fmt % args))


if __name__ == '__main__':
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8765
    srv = ThreadingHTTPServer(('127.0.0.1', port), Handler)
    print('SERVIS na http://127.0.0.1:%d/' % port)
    srv.serve_forever()
