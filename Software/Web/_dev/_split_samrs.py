#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Rozdelenie zelená-podnikom.html:
   1) projekty-samrs.html = hero (samrs_page) + sekcia Projekty SAMRS
   2) zelená-podnikom.html bez sekcie Projekty SAMRS
   3) obnoví SK fallback oboch stránok + opraví <title> zelená JSONov
"""
import io, json, os, re

ROOT = os.path.dirname(os.path.abspath(__file__))
os.chdir(ROOT)

# ---------------------------------------------------------------- pomocné
def flat(o, pre=''):
    out = {}
    for k, v in o.items():
        p = pre + k
        if isinstance(v, dict):
            out.update(flat(v, p + '.'))
        else:
            out[p] = v
    return out

def nest(fl):
    out = {}
    for k, v in fl.items():
        cur = out
        parts = k.split('.')
        for p in parts[:-1]:
            cur = cur.setdefault(p, {})
        cur[parts[-1]] = v
    return out

SK_SPOL = json.load(io.open('preklad/SK/spolocne.json', encoding='utf-8'))

def sk_page(page):
    p = 'preklad/SK/%s.json' % page
    d = json.load(io.open(p, encoding='utf-8')) if os.path.exists(p) else {}
    return nest(flat(SK_SPOL).update(flat(d)) or flat(SK_SPOL).update(flat(d)) or _merged(d))

def _merged(d):
    m = flat(SK_SPOL)
    m.update(flat(d))
    return nest(m)

def set_fallback(html, page):
    blob = json.dumps(_merged(json.load(io.open('preklad/SK/%s.json' % page, encoding='utf-8'))),
                      ensure_ascii=False, separators=(',', ':'))
    tag = ('    <!-- SK záloha prekladov (ak sa JSON nenačíta) -->\n'
           '    <script type="application/json" id="elvo-sk-fallback">'
           + blob.replace('</', '<\\/') + '</script>\n')
    html = re.sub(r'[ \t]*<!-- SK záloha prekladov \(ak sa JSON nenačíta\) -->\r?\n'
                  r'[ \t]*<script type="application/json" id="elvo-sk-fallback">.*?</script>\r?\n',
                  '', html, flags=re.S)
    i = html.rfind('</body>')
    return html[:i] + tag + html[i:]

def cut(html, start_marker, end_marker):
    a = html.find(start_marker)
    b = html.find(end_marker)
    if a < 0 or b < 0 or b <= a:
        raise SystemExit('nenašiel som: %r -> %r' % (start_marker, end_marker))
    return html[:a] + html[b:]

# ---------------------------------------------------------------- titulky v JSONoch (skôr než fallback)
for lang, folder in (('SK', 'preklad/SK'), ('AN', 'preklad/AN'), ('DE', 'preklad/DE')):
    p = folder + '/zelena-podnikom.json'
    d = json.load(io.open(p, encoding='utf-8'))
    t = d.get('green_enterprise_page', {}).get('title')
    if t and 'SAMRS' in t:
        nt = t.replace(' & Projekty SAMRS', '').replace('& Projekty SAMRS', '')
        d['green_enterprise_page']['title'] = nt
        io.open(p, 'w', encoding='utf-8', newline='\n').write(
            json.dumps(d, ensure_ascii=False, indent=1))
        print('%s: title -> %s' % (lang, nt))

# ---------------------------------------------------------------- zelená-podnikom
zel = io.open('zelená-podnikom.html', encoding='utf-8').read()

# 1) odstrániť sekciu Projekty SAMRS (zo zelená)
zel2 = cut(zel, '    <!-- Projekty SAMRS -->', '    <!-- Zdroje a legislatíva -->')
# duplicitný komentár Pätička
zel2 = zel2.replace('    <!-- Pätička -->\n    <!-- Pätička -->', '    <!-- Pätička -->')
# <title> bez "& Projekty SAMRS"
zel2 = zel2.replace(
    '<title data-translate="green_enterprise_page.title">Zelená podnikom &amp; Projekty SAMRS - Elvosolar</title>',
    '<title data-translate="green_enterprise_page.title">Zelená podnikom - Elvosolar</title>')
zel2 = zel2.replace(
    '<title data-translate="green_enterprise_page.title">Zelená podnikom & Projekty SAMRS - Elvosolar</title>',
    '<title data-translate="green_enterprise_page.title">Zelená podnikom - Elvosolar</title>')
zel2 = set_fallback(zel2, 'zelena-podnikom')
io.open('zelená-podnikom.html', 'w', encoding='utf-8', newline='').write(zel2)
print('zelená-podnikom.html  %d -> %d B' % (len(zel), len(zel2)))

# ---------------------------------------------------------------- projekty-samrs.html
sam = zel  # pôvodný obsah (ešte so SAMRS sekciou)
# data-page
sam = sam.replace('data-page="zelena-podnikom"', 'data-page="projekty-samrs"', 1)
# title
sam = re.sub(r'<title data-translate="[^"]+">[^<]*</title>',
             '<title data-translate="samrs_page.title">Projekty SAMRS - Elvosolar</title>',
             sam, count=1)
# hero kľúče -> samrs_page
sam = sam.replace('data-translate="green_enterprise_page.hero_badge"',
                  'data-translate="samrs_page.hero_badge"', 1)
sam = sam.replace('data-translate="green_enterprise_page.hero_title"',
                  'data-translate="samrs_page.hero_title"', 1)
sam = sam.replace('data-translate="green_enterprise_page.hero_desc"',
                  'data-translate="samrs_page.hero_subtitle"', 1)
# odstrániť: Rýchle štatistiky + Detail Zelená podnikom (pred SAMRS sekciou)
sam = cut(sam, '    <!-- Rýchle štatistiky -->', '    <!-- Projekty SAMRS -->')
# odstrániť: Zdroje a legislatíva (patrí k Zelená podnikom)
sam = cut(sam, '    <!-- Zdroje a legislatíva -->', '    <!-- Pätička -->')
sam = sam.replace('    <!-- Pätička -->\n    <!-- Pätička -->', '    <!-- Pätička -->')
sam = set_fallback(sam, 'projekty-samrs')
io.open('projekty-samrs.html', 'w', encoding='utf-8', newline='').write(sam)
print('projekty-samrs.html   %d B' % len(sam))

print('HOTOVO')
