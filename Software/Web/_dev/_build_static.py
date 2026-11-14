#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Statický build webu Elvosolar.

1) Do HTML vloží slovenské texty priamo do elementov s data-translate
   (text je viditeľný okamžite, nečaká na fetch JSON súborov).
   Zdrojom je preklad/SK/spolocne.json + preklad/SK/<stranka>.json.
2) Nahradí Tailwind CDN (https://cdn.tailwindcss.com) lokálnym minifikovaným
   CSS súborom assets/tailwind.css — žiadne varovanie v konzole a rýchlejší
   prvý render (CDN verzia kompiluje CSS až v prehliadači).
3) Zvýši verzie ?v= pri CSS/JS, aby prehliadače nezobrazili starú cache.

Spustenie:  python _build_static.py
"""
import html
import json
import os
import re
import shutil
import subprocess
import sys

try:  # konzola Windows (cp1250) nevie vypísať diakritiku v názvoch súborov
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
except Exception:
    pass

ROOT = os.path.dirname(os.path.abspath(__file__))
SK_DIR = os.path.join(ROOT, 'preklad', 'SK')
TW_BUILD = os.path.abspath(os.path.join(ROOT, '..', '_twbuild'))
CSS_OUT = os.path.join(ROOT, 'assets', 'tailwind.css')
ASSET_VERSION = '12'

PAGES = [
    'index.html', 'o-nás.html', 'fotovoltika.html',
    'solárny-ohrev.html', 'orc-jednotky.html', 'absorpčné-chladenie.html',
    'bateriové-úložiská.html', 'zelená-podnikom.html', 'projekty-samrs.html',
    'kontakt.html', 'servis.html', 'riešenia-na-kľúč.html',
    'mapa-webu.html', '404.html',
]

# <tag ... data-translate="key" ...>obsah</tag>
TEXT_RE = re.compile(
    r'(<([a-zA-Z][\w:-]*)\b[^>]*\sdata-translate="([^"]+)"[^>]*>)(.*?)(</\2>)',
    re.S,
)
PLACEHOLDER_RE = re.compile(
    r'<((?:input|textarea)\b[^>]*\sdata-translate-placeholder="([^"]+)"[^>]*)>',
    re.S,
)
TAILWIND_CSS_LINK = '<link rel="stylesheet" href="assets/tailwind.css?v=%s">' % ASSET_VERSION


def get_value(data, key):
    """Vráti reťazec pre bodkovú cestu (hero.title) alebo None."""
    node = data
    for part in key.split('.'):
        if not isinstance(node, dict) or part not in node:
            return None
        node = node[part]
    return node if isinstance(node, str) else None


def deep_merge(target, src):
    for key, value in src.items():
        if isinstance(value, dict):
            target[key] = deep_merge(target.get(key, {}), value)
        else:
            target[key] = value
    return target


def load_page_dict(page_id):
    data = {}
    for name in ('spolocne.json', page_id + '.json'):
        path = os.path.join(SK_DIR, name)
        if os.path.isfile(path):
            with open(path, encoding='utf-8') as fh:
                deep_merge(data, json.load(fh))
    return data


def page_id_from(doc):
    match = re.search(r'<html\b[^>]*\bdata-page="([^"]+)"', doc)
    return match.group(1) if match else None


def bake_texts(doc, data):
    filled = 0

    def text_repl(match):
        nonlocal filled
        open_tag, tag, key, inner, close_tag = match.groups()
        if inner.strip():
            # existujúci obsah nechávame (je to čitateľná záloha)
            return match.group(0)
        value = get_value(data, key)
        if value is None:
            return match.group(0)
        filled += 1
        return open_tag + html.escape(value, quote=False) + close_tag

    doc = TEXT_RE.sub(text_repl, doc)

    # <title data-translate="title"> — nastav vždy správny titulok
    def title_repl(match):
        nonlocal filled
        open_tag, tag, key, inner, close_tag = match.groups()
        if tag.lower() != 'title':
            return match.group(0)
        value = get_value(data, key)
        if value is None:
            return match.group(0)
        filled += 1
        return open_tag + html.escape(value, quote=False) + close_tag

    doc = TEXT_RE.sub(title_repl, doc)
    return doc, filled


def bake_placeholders(doc, data):
    filled = 0

    def repl(match):
        nonlocal filled
        attrs, key = match.group(1), match.group(2)
        value = get_value(data, key)
        if value is None:
            return match.group(0)
        escaped = html.escape(value, quote=True)
        filled += 1
        if re.search(r'\bplaceholder="[^"]*"', attrs):
            attrs = re.sub(r'\bplaceholder="[^"]*"', 'placeholder="%s"' % escaped, attrs, count=1)
        else:
            attrs = attrs + ' placeholder="%s"' % escaped
        return '<' + attrs + '>'

    return PLACEHOLDER_RE.sub(repl, doc), filled


def replace_tailwind_cdn(doc):
    """Odstráni CDN Tailwind + jeho config a vloží lokálne CSS."""
    if 'cdn.tailwindcss.com' not in doc:
        return doc, False
    # preconnect
    doc = re.sub(
        r'[ \t]*<link rel="preconnect" href="https://cdn\.tailwindcss\.com">\s*\n?',
        '', doc)
    # samotný skript
    doc = re.sub(
        r'[ \t]*<script src="https://cdn\.tailwindcss\.com"[^>]*></script>\s*\n?',
        '', doc)
    # config blok (aj zabalený v DOMContentLoaded)
    doc = re.sub(
        r'[ \t]*<script>\s*(?:window\.addEventListener\([^)]*\)\s*=>\s*\{\s*)?'
        r'tailwind\.config\s*=.*?</script>\s*\n?',
        '', doc, flags=re.S)

    # lokálne CSS hneď za site.css, aby poradie kaskády zostalo rovnaké
    if 'assets/tailwind.css' not in doc:
        site_css = re.search(r'[ \t]*<link rel="stylesheet" href="assets/site\.css[^"]*">\n?', doc)
        if site_css:
            indent = re.match(r'[ \t]*', site_css.group(0)).group(0)
            doc = (doc[:site_css.end()] + indent + TAILWIND_CSS_LINK + '\n'
                   + doc[site_css.end():])
        else:
            doc = doc.replace('</head>', '    ' + TAILWIND_CSS_LINK + '\n</head>', 1)
    return doc, True


def bump_asset_version(doc):
    doc = re.sub(r'assets/site\.css\?v=\d+', 'assets/site.css?v=%s' % ASSET_VERSION, doc)
    doc = re.sub(r'assets/site\.js\?v=\d+', 'assets/site.js?v=%s' % ASSET_VERSION, doc)
    doc = re.sub(r'assets/tailwind\.css\?v=\d+', 'assets/tailwind.css?v=%s' % ASSET_VERSION, doc)
    return doc


def build_css():
    """Zostaví minifikované Tailwind CSS z lokálnej inštalácie v ../_twbuild."""
    if not os.path.isdir(os.path.join(TW_BUILD, 'node_modules', 'tailwindcss')):
        print('! _twbuild/node_modules chýba — spusti: cd ../_twbuild && npm install')
        return False
    npx = shutil.which('npx')
    if not npx:
        print('! npx nie je v PATH — Tailwind CSS sa nepodarilo zostaviť')
        return False
    cmd = [npx, '--no-install', 'tailwindcss',
           '-c', 'tailwind.config.js', '-i', 'input.css',
           '-o', CSS_OUT, '--minify']
    print('$ ' + ' '.join(cmd))
    result = subprocess.run(cmd, cwd=TW_BUILD)
    if result.returncode != 0:
        print('! tailwindcss zlyhal (kód %d)' % result.returncode)
        return False
    print('  -> %s (%.1f kB)' % (CSS_OUT, os.path.getsize(CSS_OUT) / 1024.0))
    return True


def main():
    ok = True
    for name in PAGES:
        path = os.path.join(ROOT, name)
        if not os.path.isfile(path):
            print('SKIP %s (neexistuje)' % name)
            continue
        with open(path, encoding='utf-8', newline='') as fh:
            doc = fh.read()

        page_id = page_id_from(doc)
        data = load_page_dict(page_id) if page_id else {}
        doc, n_text = bake_texts(doc, data) if data else (doc, 0)
        doc, n_ph = bake_placeholders(doc, data) if data else (doc, 0)
        doc, had_cdn = replace_tailwind_cdn(doc)
        doc = bump_asset_version(doc)

        with open(path, 'w', encoding='utf-8', newline='') as fh:
            fh.write(doc)
        print('%-30s texty:%3d placeholdery:%2d cdn:%s' %
              (name, n_text, n_ph, 'áno' if had_cdn else 'nie'))

    if not build_css():
        ok = False
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
