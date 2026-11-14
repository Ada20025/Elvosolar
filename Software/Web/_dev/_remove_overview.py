#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Odstránenie rozcestníka „Prehľad služieb“.

 * z navigácie (desktop aj mobil) zmizne položka Prehľad služieb,
 * sluzby.html (rozcestník) sa zmaže a /sluzby sa presmeruje na /riesenia-na-kluc,
 * z prekladov sa odstránia nepoužité kľúče rozcestníka,
 * mapa webu, sitemap a build skript sa aktualizujú.

Spustenie: python _remove_overview.py
"""
import glob
import json
import os
import re
import sys

try:
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
except Exception:
    pass

ROOT = os.path.dirname(os.path.abspath(__file__))
OVERVIEW = os.path.join(ROOT, 'služby.html')

ITEM_RE = re.compile(r'[ \t]*<a href="sluzby" class="ec-dd-item">.*?</a>\n', re.S)


def strip_nav_item():
    for path in sorted(glob.glob(os.path.join(ROOT, '*.html'))):
        name = os.path.basename(path)
        if name.startswith('_'):
            continue
        with open(path, encoding='utf-8', newline='') as fh:
            doc = fh.read()
        new, n = ITEM_RE.subn('', doc)
        if n:
            with open(path, 'w', encoding='utf-8', newline='') as fh:
                fh.write(new)
            print('  menu: %-26s odstránené položky: %d' % (name, n))


def fix_breadcrumb():
    """Drobček už nemá existujúci prehľad — „Služby“ ostane ako text."""
    for name in ('servis.html', 'riešenia-na-kľúč.html'):
        path = os.path.join(ROOT, name)
        with open(path, encoding='utf-8', newline='') as fh:
            doc = fh.read()
        old = ('<a href="sluzby" class="hover:text-brand-green transition-colors">'
               '<span data-translate="nav.services">Služby</span></a>')
        if old in doc:
            doc = doc.replace(old, '<span data-translate="nav.services">Služby</span>', 1)
            with open(path, 'w', encoding='utf-8', newline='') as fh:
                fh.write(doc)
            print('  drobček: %s' % name)


def remove_overview_file():
    if os.path.isfile(OVERVIEW):
        os.remove(OVERVIEW)
        print('  zmazané: služby.html')


def clean_translations():
    for lang in ('SK', 'AN', 'DE'):
        nav_path = os.path.join(ROOT, 'preklad', lang, 'spolocne.json')
        with open(nav_path, encoding='utf-8') as fh:
            data = json.load(fh)
        nav = data.get('nav', {})
        for key in ('services_overview', 'services_overview_desc'):
            nav.pop(key, None)
        with open(nav_path, 'w', encoding='utf-8', newline='') as fh:
            json.dump(data, fh, ensure_ascii=False, indent=1)
            fh.write('\n')

        page_path = os.path.join(ROOT, 'preklad', lang, 'sluzby.json')
        with open(page_path, encoding='utf-8') as fh:
            data = json.load(fh)
        page = data.get('services_page', {})
        for key in ('overview_subtitle', 'overview_more'):
            page.pop(key, None)
        with open(page_path, 'w', encoding='utf-8', newline='') as fh:
            json.dump(data, fh, ensure_ascii=False, indent=1)
            fh.write('\n')
        print('  preklady: %s' % lang)


def update_map():
    path = os.path.join(ROOT, 'mapa-webu.html')
    with open(path, encoding='utf-8', newline='') as fh:
        doc = fh.read()
    doc = doc.replace('<b>13</b>', '<b>12</b>')
    card = re.search(r'[ \t]*<a class="card" href="sluzby".*?</a>\n', doc, re.S)
    if card:
        doc = doc[:card.start()] + doc[card.end():]
    with open(path, 'w', encoding='utf-8', newline='') as fh:
        fh.write(doc)
    print('  mapa-webu.html: bez Služby, 12 stránok')


def update_sitemap():
    path = os.path.join(ROOT, 'sitemap.xml')
    with open(path, encoding='utf-8', newline='') as fh:
        doc = fh.read()
    doc = doc.replace('  <url><loc>https://www.elvosolar.sk/sluzby</loc></url>\r\n', '')
    with open(path, 'w', encoding='utf-8', newline='') as fh:
        fh.write(doc)
    print('  sitemap.xml: bez /sluzby')


REDIRECTS = {
    'SK': "sluzby -> riesenia-na-kluc (301)",
}


def update_router():
    path = os.path.join(ROOT, 'index.php')
    with open(path, encoding='utf-8', newline='') as fh:
        doc = fh.read()
    if '$REDIRECTS' in doc:
        return
    doc = doc.replace("    'sluzby'              => 'služby.html',\n"
                      "    'služby'              => 'služby.html',\n", '', 1)
    block = (
        "// ---------------- Presmerovania starých adries ----------------\n"
        "$REDIRECTS = [\n"
        "    'sluzby'  => 'riesenia-na-kluc',\n"
        "    'služby'  => 'riesenia-na-kluc',\n"
        "];\n\n"
    )
    marker = "// ---------------- Trasy: URL cesta => súbor ----------------"
    doc = doc.replace(marker, block + marker, 1)

    redirect_logic = (
        "// ---------------- Presmerovanie ----------------\n"
        "$redirectKey = strtolower($requestPath);\n"
        "if (isset($REDIRECTS[$redirectKey])) {\n"
        "    $langPrefix = $lang !== null ? '/' . $lang : '';\n"
        "    header('Location: ' . $langPrefix . '/' . $REDIRECTS[$redirectKey], true, 301);\n"
        "    exit;\n"
        "}\n\n"
    )
    marker2 = "// ---------------- Vyriešenie trasy ----------------"
    doc = doc.replace(marker2, redirect_logic + marker2, 1)
    with open(path, 'w', encoding='utf-8', newline='') as fh:
        fh.write(doc)
    print('  index.php: 301 /sluzby -> /riesenia-na-kluc')


def update_build_list():
    path = os.path.join(ROOT, '_build_static.py')
    with open(path, encoding='utf-8', newline='') as fh:
        doc = fh.read()
    doc = doc.replace("    'index.html', 'o-nás.html', 'služby.html', 'fotovoltika.html',\n",
                      "    'index.html', 'o-nás.html', 'fotovoltika.html',\n")
    with open(path, 'w', encoding='utf-8', newline='') as fh:
        fh.write(doc)
    print('  _build_static.py: bez služby.html')


def main():
    strip_nav_item()
    fix_breadcrumb()
    remove_overview_file()
    clean_translations()
    update_map()
    update_sitemap()
    update_router()
    update_build_list()
    print('hotovo')


if __name__ == '__main__':
    main()
