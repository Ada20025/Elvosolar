#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Migrácia HTML stránok:
   1) data-page atribút na <html>
   2) dropdown „O nás“ (O nás + Projekty SAMRS) — desktop + mobil
   3) nová kompletná pätička (odkazy, telefón, e-mail, adresa, sociálne siete)
   4) odstránenie vložených slovníkov SK/EN/DE + duplicitného menu kódu
   5) vloženie SK zálohy (JSON) pre prípad, že sa preklad nenačíta
"""
import io, json, os, re, sys

ROOT = os.path.dirname(os.path.abspath(__file__))
os.chdir(ROOT)

# ---------------------------------------------------------------- preklady
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
    d = {}
    if os.path.exists(p):
        d = json.load(io.open(p, encoding='utf-8'))
    m = {}
    flat_src = flat(SK_SPOL); flat_src.update(flat(d))
    return nest(flat_src)

# ---------------------------------------------------------------- markup
DD_ITEM = (
    '                        <a href="{href}" class="ec-dd-item">\n'
    '                            <span class="ec-dd-tile" style="background:{bg}" aria-hidden="true">{ico}</span>\n'
    '                            <span class="ec-dd-body">\n'
    '                                <span class="ec-dd-title" data-translate="{tkey}">{tdef}</span>\n'
    '                                <span class="ec-dd-desc" data-translate="{dkey}">{ddef}</span>\n'
    '                            </span>\n'
    '                            <svg class="ec-dd-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>\n'
    '                        </a>'
)

ABOUT_ITEMS = '\n'.join([
    DD_ITEM.format(href='o-nas', bg='#fce7f3', ico='\U0001f3e2',
                   tkey='nav.about', tdef='O nás',
                   dkey='nav.about_desc', ddef='Spoznajte našu spoločnosť, skúsenosti a tím.'),
    DD_ITEM.format(href='projekty-samrs', bg='#d1fae5', ico='\U0001f30d',
                   tkey='nav.samrs', tdef='Projekty SAMRS',
                   dkey='nav.samrs_desc', ddef='Medzinárodné rozvojové projekty v energetike.'),
])

DESKTOP_ABOUT = (
    '                <div class="relative inline-block text-left" id="about-dropdown-container" data-dropdown-host>\n'
    '                    <button id="about-menu-btn" class="flex items-center gap-1 hover:text-brand-green font-semibold text-sm text-slate-600 transition-colors focus:outline-none py-2" aria-expanded="false" aria-haspopup="true" aria-label="Dropdown O nás">\n'
    '                        <span data-translate="nav.about"></span>\n'
    '                        <svg class="ec-chevron w-3.5 h-3.5 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">\n'
    '                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path>\n'
    '                        </svg>\n'
    '                    </button>\n'
    '                    <div id="about-dropdown" class="ec-menu hidden absolute left-0 mt-2 w-72 rounded-2xl border border-slate-100 bg-white p-2 shadow-xl shadow-slate-900/5 z-50">\n'
    + ABOUT_ITEMS + '\n'
    '                    </div>\n'
    '                </div>\n'
)

MOBILE_ABOUT = (
    '                <div class="flex flex-col">\n'
    '                    <button id="mobile-about-menu-btn" class="flex items-center justify-between hover:text-brand-green py-2 border-b border-slate-50 font-semibold text-slate-600 focus:outline-none w-full text-left" aria-expanded="false" aria-controls="mobile-about-submenu">\n'
    '                        <span data-translate="nav.about"></span>\n'
    '                        <svg class="ec-chevron w-4 h-4 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">\n'
    '                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path>\n'
    '                        </svg>\n'
    '                    </button>\n'
    '                    <div id="mobile-about-submenu" class="hidden flex-col space-y-1 p-2 border-b border-slate-50 bg-slate-50/50">\n'
    + ABOUT_ITEMS + '\n'
    '                    </div>\n'
    '                </div>\n'
)

NEW_FOOTER = '''    <!-- Pätička -->
    <footer class="bg-slate-950 text-slate-500 py-10 text-xs border-t border-slate-900 mt-auto">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid md:grid-cols-3 gap-8 mb-8">
                <div>
                    <img src="Png/ElvosolarLogoCele.png" alt="Elvosolar" class="h-8 mb-3 opacity-80">
                    <p class="text-slate-500 leading-relaxed" data-translate="footer.tagline"></p>
                </div>
                <div class="space-y-2">
                    <h4 class="text-slate-300 font-bold text-xs uppercase tracking-wider" data-translate="footer.quick_links"></h4>
                    <a href="o-nas" class="block text-slate-500 hover:text-brand-green transition-colors" data-translate="nav.about"></a>
                    <a href="sluzby" class="block text-slate-500 hover:text-brand-green transition-colors" data-translate="nav.services"></a>
                    <a href="kontakt" class="block text-slate-500 hover:text-brand-green transition-colors" data-translate="nav.contact"></a>
                    <a href="mapa-webu" class="block text-slate-500 hover:text-brand-green transition-colors" data-translate="footer.map"></a>
                </div>
                <div class="space-y-2">
                    <h4 class="text-slate-300 font-bold text-xs uppercase tracking-wider" data-translate="footer.contact_title"></h4>
                    <p><a href="tel:+421914332169" class="text-slate-500 hover:text-brand-green transition-colors">📞 +421 914 332 169</a></p>
                    <p><a href="mailto:info@elvosolar.sk" class="text-slate-500 hover:text-brand-green transition-colors">✉️ info@elvosolar.sk</a></p>
                    <p>🏢 <span data-translate="footer.address"></span></p>
                </div>
            </div>
            <div class="border-t border-slate-800 pt-6 flex flex-col items-center gap-4 md:flex-row md:justify-center md:gap-8 ec-footer-row">
                <p data-translate="footer.copyright"></p>
                <div class="flex gap-4">
                    <a href="https://www.facebook.com/elvosolar/" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><img src="Png/social/facebook.svg" alt="Facebook" width="24" height="24" class="w-6 h-6 object-contain" loading="lazy" decoding="async"></a>
                    <a href="https://sk.linkedin.com/company/elvosolar" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn"><img src="Png/social/linkedin.svg" alt="LinkedIn" width="24" height="24" class="w-6 h-6 object-contain" loading="lazy" decoding="async"></a>
                </div>
            </div>
        </div>
    </footer>'''

# ---------------------------------------------------------------- transformácie
def set_data_page(html, page):
    html = re.sub(r'<html\s+lang="sk"', '<html lang="sk" data-page="%s"' % page, html, count=1)
    return html

def fix_renewable_containers(html):
    """desktop dropdown container dostane data-dropdown-host, mobile tlačidlo aria-controls"""
    html = html.replace(
        '<div class="relative inline-block text-left" id="renewable-dropdown-container">',
        '<div class="relative inline-block text-left" id="renewable-dropdown-container" data-dropdown-host>')
    html = html.replace(
        '<div class="relative inline-block text-left" id="lang-selector-container">',
        '<div class="relative inline-block text-left" id="lang-selector-container" data-dropdown-host>')
    # mobile renewable accordion: aria-controls
    html = re.sub(
        r'(<button id="mobile-renewable-menu-btn"[^>]*?)>',
        r'\1 aria-controls="mobile-renewable-submenu">',
        html, count=1)
    return html

DESKTOP_LINK_RE = re.compile(
    r'[ \t]*<a href="o-nas"[^>]*data-translate="nav\.about"[^>]*>.*?</a>\n', re.S)
MOBILE_LINK_RE = re.compile(
    r'[ \t]*<a href="o-nas"[^>]*data-translate="nav\.about"[^>]*>.*?</a>\n', re.S)

def inject_about_dropdown(html):
    """Nahradí desktopový odkaz O nás dropdownom; mobilný odkaz akordeónom."""
    if 'id="about-dropdown"' in html:
        return html  # už vložené
    # desktop: prvý výskyt (v <nav class="hidden md:flex ...)
    m = DESKTOP_LINK_RE.search(html)
    if not m:
        raise SystemExit('desktop O nás link not found')
    html = html[:m.start()] + DESKTOP_ABOUT + html[m.end():]
    # mobilný: druhý výskyt (v #mobile-menu nav)
    m2 = MOBILE_LINK_RE.search(html)
    if not m2:
        raise SystemExit('mobile O nás link not found')
    html = html[:m2.start()] + MOBILE_ABOUT + html[m2.end():]
    return html

def replace_footer(html):
    if 'footer.contact_title' in html:
        return html  # nová pätička už je
    m = re.search(r'[ \t]*<footer\b.*?</footer>', html, re.S)
    if not m:
        raise SystemExit('footer not found')
    return html[:m.start()] + NEW_FOOTER + html[m.end():]

def strip_inline_engine(html):
    """Odstráni LOKALIZAČNÝ ENGINE + vložené slovníky z inline <script>."""
    if 'LOKALIZAČNÝ ENGINE' not in html and 'fullEmbeddedTranslations' not in html:
        return html
    # 1) fullEmbeddedTranslations / localFallbackTranslations
    html = re.sub(
        r'[ \t]*// -+ (?:INTEGROVANÁ ZÁLOHA PREKLADOV \(FALLBACK\)|INTEGRATED FALLBACK DICTIONARY) -+\r?\n'
        r'[ \t]*const fullEmbeddedTranslations\s*=\s*\{.*?\n[ \t]*\};[^\n]*\r?\n'
        r'(?:[ \t]*const localFallbackTranslations = \{.*?\};[^\n]*\r?\n)?',
        '', html, flags=re.S)
    # 2) LOKALIZAČNÝ ENGINE blok (od commentu po getNestedValue + DOMContentLoaded volanie)
    html = re.sub(
        r'[ \t]*// -+ LOKALIZAČNÝ ENGINE -+\r?\n'
        r'.*?function getNestedValue\(obj, path\) \{.*?\n[ \t]*\}\r?\n'
        r'(?:\r?\n[ \t]*document\.addEventListener\("DOMContentLoaded", \(\) => \{\r?\n[ \t]*loadLanguage\(currentLang\);\r?\n[ \t]*\}\);\r?\n)?',
        '', html, flags=re.S)
    # 3) štýl fotovoltiky/indexu: volanie loadLanguage pri štarte
    html = re.sub(r'[ \t]*// Načítanie vybraného jazyka pri štarte\r?\n[ \t]*loadLanguage\(currentLang\);\r?\n', '', html)
    html = re.sub(r'[ \t]*// Asynchrónna inicializácia lokalizácie a cookies na zabezpečenie TBT = 0 ms\r?\n'
                  r'[ \t]*setTimeout\(\(\) => \{\r?\n[ \t]*loadLanguage\(currentLang\);\r?\n[ \t]*\}, 50\);\r?\n', '', html)
    return html

def strip_menu_handlers(html):
    """Odstráni duplicitný kód hamburgeru/dropdownov (teraz je v assets/site.js)."""
    patterns = [
        # klasický blok (index a väčšina stránok)
        r'[ \t]*// Mobilné menu \(Hamburger\)\r?\n.*?document\.addEventListener\([\'"]click[\'"], \(\) => \{\r?\n[ \t]*closeDropdowns\(\);\r?\n[ \t]*\}\);\r?\n',
        r'[ \t]*const mobileMenuBtn = document\.getElementById\([\'"]mobile-menu-btn[\'"]\);.*?document\.addEventListener\([\'"]click[\'"], \(\) => \{\r?\n[ \t]*closeDropdowns\(\);\r?\n[ \t]*\}\);\r?\n',
        # štýl fotovoltiky (INICIALIZÁCIA DROPDOWNOV v DOMContentLoaded)
        r'[ \t]*// -+ INICIALIZÁCIA DROPDOWNOV \(DESKTOP & MOBILE\) -+\r?\n'
        r'[ \t]*document\.addEventListener\("DOMContentLoaded", \(\) => \{.*?\r?\n[ \t]*\}\);\r?\n',
    ]
    for p in patterns:
        html = re.sub(p, '', html, flags=re.S)
    # closeDropdowns funkcia ostala samostatne
    html = re.sub(r'[ \t]*function closeDropdowns\(\) \{.*?\n[ \t]*\}\r?\n', '', html, flags=re.S)
    return html

FALLBACK_MARK = '    <!-- SK záloha prekladov (ak sa JSON nenačíta) -->'

def inject_sk_fallback(html, page):
    d = sk_page(page)
    blob = json.dumps(d, ensure_ascii=False, indent=None, separators=(',', ':'))
    tag = (FALLBACK_MARK + '\n'
           '    <script type="application/json" id="elvo-sk-fallback">'
           + blob.replace('</', '<\\/') +
           '</script>\n')
    # vložiť tesne pred posledné </body>
    i = html.rfind('</body>')
    if i < 0:
        raise SystemExit('</body> not found')
    return html[:i] + tag + html[i:]

# ---------------------------------------------------------------- hlavný beh
PAGES = [
    'index.html', 'o-nás.html', 'kontakt.html', 'zelená-podnikom.html',
    'služby.html', 'fotovoltika.html', 'solárny-ohrev.html', 'orc-jednotky.html',
    'absorpčné-chladenie.html', 'bateriové-úložiská.html',
]
PAGE_ID = {
    'index.html': 'index', 'o-nás.html': 'o-nas', 'kontakt.html': 'kontakt',
    'zelená-podnikom.html': 'zelena-podnikom', 'služby.html': 'sluzby',
    'fotovoltika.html': 'fotovoltika', 'solárny-ohrev.html': 'solarny-ohrev',
    'orc-jednotky.html': 'orc-jednotky', 'absorpčné-chladenie.html': 'absorbecne-chladenie',
    'bateriové-úložiská.html': 'bateriove-uloziska',
}

for f in PAGES:
    page = PAGE_ID[f]
    html = io.open(f, encoding='utf-8').read()
    if 'data-page=' in html and 'elvo-sk-fallback' in html:
        print('%-28s preskočené (hotové)' % f)
        continue
    orig = html
    html = set_data_page(html, page)
    html = fix_renewable_containers(html)
    html = inject_about_dropdown(html)
    html = replace_footer(html)
    html = strip_menu_handlers(html)
    html = strip_inline_engine(html)
    html = inject_sk_fallback(html, page)
    io.open(f, 'w', encoding='utf-8', newline='').write(html)
    print('%-28s %d -> %d B' % (f, len(orig), len(html)))

print('HOTOVO')
