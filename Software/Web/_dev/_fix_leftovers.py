#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Oprava zvyškov po migrácii (riadky, ktoré regex neodchytil)."""
import io, os, re, sys

ROOT = os.path.dirname(os.path.abspath(__file__))
os.chdir(ROOT)

def patch(f, pairs):
    s = io.open(f, encoding='utf-8').read()
    for old, new, cnt in pairs:
        n = s.count(old)
        if n == 0:
            print('  !! %s: nenájdené %r' % (f, old[:70]))
            continue
        s = s.replace(old, new)
        print('  %s: %dx %r' % (f, n, old[:55]))
    io.open(f, 'w', encoding='utf-8', newline='').write(s)

# 1) o-nás: obrovský vložený slovník (regex zlyhal kvoli medzerám za =)
s = io.open('o-nás.html', encoding='utf-8').read()
i = s.find('// ----------------- INTEGROVANÁ ZÁLOHA PREKLADOV (FALLBACK) -----------------')
if i < 0:
    print('!! o-nás: fallback marker nenájdený')
else:
    j = s.find('<script>', i)
    # nájsť koniec const fullEmbeddedTranslations = { ... };
    k = s.find('const fullEmbeddedTranslations', j)
    d = 0
    start = s.find('{', k)
    for p in range(start, len(s)):
        if s[p] == '{':
            d += 1
        elif s[p] == '}':
            d -= 1
            if d == 0:
                break
    end = s.find(';', p) + 1
    # odstrániť od začiatku <script> po end (vrátane)
    head = s[:j]
    tail = s[end:]
    # vyčistiť prázdny script blok
    new = head + tail
    new = new.replace('    <script>\n\n\n\n', '    <script>\n')
    io.open('o-nás.html', 'w', encoding='utf-8', newline='').write(new)
    print('  o-nás.html: odstránený vložený slovník (%d B)' % (end - j))

# 2) zvyšné DOMContentLoaded loadLanguage + localFallbackTranslations
for f in ['kontakt.html', 'orc-jednotky.html', 'solárny-ohrev.html',
          'zelená-podnikom.html', 'služby.html', 'bateriové-úložiská.html',
          'absorpčné-chladenie.html']:
    patch(f, [
        ('\n        document.addEventListener("DOMContentLoaded", () => {\n'
         '            loadLanguage(currentLang);\n'
         '        });\n', '\n', 1),
        ('\n\n        const localFallbackTranslations = {}; // Ponechané pre konzistentnosť importu\n', '\n', 1),
        ('\n\n        const localFallbackTranslations = {};\n', '\n', 1),
    ])

# 3) fotovoltika: rozbitý zvyšok dropdown bloku (od <script> po DYNAMICKÁ DATABÁZA)
s = io.open('fotovoltika.html', encoding='utf-8').read()
a = s.find('    <!-- Skripty -->\n    <script>\n')
b = s.find('        // ----------------- DYNAMICKÁ DATABÁZA REFERENCIÍ')
if a >= 0 and b > a:
    s = s[:a + len('    <!-- Skripty -->\n    <script>\n')] + '\n' + s[b:]
    io.open('fotovoltika.html', 'w', encoding='utf-8', newline='').write(s)
    print('  fotovoltika.html: odstránený rozbitý dropdown zvyšok')
else:
    print('  !! fotovoltika: rozsah nenájdený %s %s' % (a, b))

# 4) referencie na fullEmbeddedTranslations v handleroch
for f in ['index.html', 'fotovoltika.html', 'solárny-ohrev.html']:
    patch(f, [
        ('currentTranslations || fullEmbeddedTranslations[currentLang] || fullEmbeddedTranslations[\'sk\']',
         '(currentTranslations && Object.keys(currentTranslations).length ? currentTranslations : JSON.parse(document.getElementById(\'elvo-sk-fallback\').textContent))', 0),
    ])

print('HOTOVO')
