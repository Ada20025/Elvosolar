#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Rozrezanie prekladov: preklad/<JAZYK>/spolocne.json + preklad/<JAZYK>/<stranka>.json"""
import io, json, os, re, sys, collections

ROOT = os.path.dirname(os.path.abspath(__file__))
os.chdir(ROOT)

LANGS = {'SK': 'sk', 'AN': 'en', 'DE': 'de'}  # kód v slovníku -> priečinok
FOLDER = {'SK': 'SK', 'AN': 'AN', 'DE': 'DE'}

# ---------- 1. Načítanie existujúcich index.json ----------
def flat(o, pre=''):
    out = {}
    for k, v in o.items():
        p = pre + k
        if isinstance(v, dict):
            out.update(flat(v, p + '.'))
        else:
            out[p] = v
    return out

def nest(flatd):
    out = {}
    for k, v in flatd.items():
        cur = out
        parts = k.split('.')
        for p in parts[:-1]:
            cur = cur.setdefault(p, {})
        cur[parts[-1]] = v
    return out

# Zdrojové „master" slovníky (pre generovanie). Per-stránkové súbory nižšie sú
# to, čo sa reálne načítava cez fetch — master sa po vygenerovaní nepoužíva.
base = {}
for L in LANGS:
    base[L] = json.load(io.open('preklad/_zdroj/%s.json' % L, encoding='utf-8'))

# ---------- 2. Doplňujúce kľúče (chýbali v index.json, boli len v HTML) ----------
EXTRA = {
    'sk': {
        'contact.form_error_email': 'Zadajte platnú e-mailovú adresu.',
        'contact.form_error_fields': 'Prosím, vyplňte všetky povinné polia.',
        'contact.form_success': 'Ďakujeme! Vaša správa bola úspešne odoslaná.',
        'pv_page.alert_error': 'Nastal problém pri odosielaní. Skúste to neskôr prosím.',
        'pv_page.alert_sending': 'Odosiela sa...',
        'pv_page.alert_sent': 'Ďakujeme. Váš dopyt bol úspešne odoslaný.',
        'pv_page.show_less': 'Zobraziť menej',
        'pv_page.show_more': 'Zobraziť viac',
        'solar_heating_page.alert_error': 'Nastal problém pri odosielaní. Skúste to neskôr prosím.',
        'solar_heating_page.alert_sending': 'Odosiela sa...',
        'solar_heating_page.alert_sent': 'Ďakujeme. Váš dopyt bol úspešne odoslaný.',
    },
    'en': {
        'contact.form_error_email': 'Please enter a valid email address.',
        'contact.form_error_fields': 'Please fill in all required fields.',
        'contact.form_success': 'Thank you! Your message has been sent successfully.',
        'pv_page.alert_error': 'An error occurred while sending. Please try again later.',
        'pv_page.alert_sending': 'Sending...',
        'pv_page.alert_sent': 'Thank you. Your inquiry has been successfully sent.',
        'pv_page.show_less': 'Show less',
        'pv_page.show_more': 'Show more',
        'solar_heating_page.alert_error': 'An error occurred while sending. Please try again later.',
        'solar_heating_page.alert_sending': 'Sending...',
        'solar_heating_page.alert_sent': 'Thank you. Your inquiry has been successfully sent.',
    },
    'de': {
        'contact.form_error_email': 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
        'contact.form_error_fields': 'Bitte füllen Sie alle Pflichtfelder aus.',
        'contact.form_success': 'Vielen Dank! Ihre Nachricht wurde erfolgreich gesendet.',
        'pv_page.alert_error': 'Beim Senden ist ein Problem aufgetreten. Bitte versuchen Sie es später erneut.',
        'pv_page.alert_sending': 'Wird gesendet...',
        'pv_page.alert_sent': 'Vielen Dank. Ihre Anfrage wurde erfolgreich gesendet.',
        'pv_page.show_less': 'Weniger anzeigen',
        'pv_page.show_more': 'Mehr anzeigen',
        'solar_heating_page.alert_error': 'Beim Senden ist ein Problem aufgetreten. Bitte versuchen Sie es später erneut.',
        'solar_heating_page.alert_sending': 'Wird gesendet...',
        'solar_heating_page.alert_sent': 'Vielen Dank. Ihre Anfrage wurde erfolgreich gesendet.',
    },
}

# ---------- 3. Nové kľúče (menu "O nás" + pätička + nová stránka SAMRS) ----------
NEW = {
    'sk': {
        'nav.about_desc': 'Spoznajte našu spoločnosť, skúsenosti a tím.',
        'nav.samrs': 'Projekty SAMRS',
        'nav.samrs_desc': 'Medzinárodné rozvojové projekty v energetike.',
        'footer.quick_links': 'Rýchle odkazy',
        'footer.contact_title': 'Kontakt',
        'footer.map': 'Mapa webu',
        'footer.address': 'Agátová 22, Bratislava',
        'samrs_page.title': 'Projekty SAMRS - Elvosolar',
        'samrs_page.hero_badge': 'Medzinárodná spolupráca',
        'samrs_page.hero_title': 'Projekty SAMRS',
        'samrs_page.hero_subtitle': 'Rozvojové projekty v Keni a Afganistane zamerané na energetickú efektívnosť, obnoviteľné zdroje a zvyšovanie kvality života miestnych komunít.',
        'green_enterprise_page.sources_title': 'Zdroje a legislatíva',
        'green_enterprise_page.source2': 'SIEA - Všeobecné podmienky k NP Zelená podnikom',
        'green_enterprise_page.source3': 'Výzva na predkladanie žiadostí o poskytnutie dotácie — Zelená podnikom',
    },
    'en': {
        'nav.about_desc': 'Learn about our company, experience and team.',
        'nav.samrs': 'SAMRS Projects',
        'nav.samrs_desc': 'International development projects in energy.',
        'footer.quick_links': 'Quick links',
        'footer.contact_title': 'Contact',
        'footer.map': 'Site map',
        'footer.address': 'Agátová 22, Bratislava',
        'samrs_page.title': 'SAMRS Projects - Elvosolar',
        'samrs_page.hero_badge': 'International cooperation',
        'samrs_page.hero_title': 'SAMRS Projects',
        'samrs_page.hero_subtitle': 'Development projects in Kenya and Afghanistan focused on energy efficiency, renewable sources and improving the quality of life of local communities.',
        'green_enterprise_page.sources_title': 'Sources and legislation',
        'green_enterprise_page.source2': 'SIEA - General terms and conditions for the Green for Enterprises programme',
        'green_enterprise_page.source3': 'Call for grant applications — Green for Enterprises',
    },
    'de': {
        'nav.about_desc': 'Lernen Sie unser Unternehmen, unsere Erfahrungen und unser Team kennen.',
        'nav.samrs': 'SAMRS-Projekte',
        'nav.samrs_desc': 'Internationale Entwicklungszusammenarbeit im Energiebereich.',
        'footer.quick_links': 'Schnelllinks',
        'footer.contact_title': 'Kontakt',
        'footer.map': 'Seitenübersicht',
        'footer.address': 'Agátová 22, Bratislava',
        'samrs_page.title': 'SAMRS-Projekte - Elvosolar',
        'samrs_page.hero_badge': 'Internationale Zusammenarbeit',
        'samrs_page.hero_title': 'SAMRS-Projekte',
        'samrs_page.hero_subtitle': 'Entwicklungsprojekte in Kenia und Afghanistan mit Schwerpunkt auf Energieeffizienz, erneuerbare Energien und die Verbesserung der Lebensqualität lokaler Gemeinschaften.',
        'green_enterprise_page.sources_title': 'Quellen und Rechtsgrundlagen',
        'green_enterprise_page.source2': 'SIEA - Allgemeine Bedingungen zum Programm Grün für Unternehmen',
        'green_enterprise_page.source3': 'Aufforderung zur Einreichung von Förderanträgen — Grün für Unternehmen',
    },
}

flat_base = {L: flat(base[L]) for L in LANGS}
for L, lc in LANGS.items():
    flat_base[L].update(EXTRA[lc])
    flat_base[L].update(NEW[lc])

# ---------- 4. Rozdelenie kľúčov podľa stránky ----------
SPOL = ['nav.', 'footer.', 'renewable.', 'contact.']
PAGE_TOPS = {
    'index':            ['title', 'hero', 'subsidies', 'services'],
    'o-nas':            ['about_page'],
    'kontakt':          ['contact_page'],
    'zelena-podnikom':  ['green_enterprise_page', 'samrs.sources_title', 'samrs.source2', 'samrs.source3'],
    'projekty-samrs':   ['samrs_page', 'samrs.'],
    'sluzby':           ['services_page'],
    'fotovoltika':      ['pv_page'],
    'solarny-ohrev':    ['solar_heating_page'],
    'orc-jednotky':     ['orc_page'],
    'absorbecne-chladenie': ['absorption_page'],
    'bateriove-uloziska':   ['battery_page'],
    '404':              [],
}

def owner(key):
    for prefix in SPOL:
        if key.startswith(prefix):
            return None  # spoločné
    for page, tops in PAGE_TOPS.items():
        for t in tops:
            if key == t or key.startswith(t if t.endswith('.') else t + '.'):
                return page
    return 'UNASSIGNED'

spol = {L: {} for L in LANGS}
pages = {p: {L: {} for L in LANGS} for p in PAGE_TOPS}
unassigned = []
for L in LANGS:
    for k, v in flat_base[L].items():
        o = owner(k)
        if o is None:
            spol[L][k] = v
        elif o == 'UNASSIGNED':
            unassigned.append((L, k))
        else:
            pages[o][L][k] = v

if unassigned:
    print('!! NEPRIRADENÉ KĽÚČE:')
    for L, k in unassigned:
        print('   %s %s' % (L, k))

# ---------- 5. Zápis ----------
os.makedirs('preklad', exist_ok=True)
for L in LANGS:
    folder = FOLDER[L]
    d = 'preklad/%s' % folder
    os.makedirs(d, exist_ok=True)
    with io.open('%s/spolocne.json' % d, 'w', encoding='utf-8', newline='\n') as f:
        json.dump(nest(spol[L]), f, ensure_ascii=False, indent=1)
        f.write('\n')
    for p in PAGE_TOPS:
        with io.open('%s/%s.json' % (d, p), 'w', encoding='utf-8', newline='\n') as f:
            json.dump(nest(pages[p][L]), f, ensure_ascii=False, indent=1)
            f.write('\n')

print('spolocne.json: %d kľúčov' % len(spol['SK']))
for p in PAGE_TOPS:
    print('  %-22s %d' % (p, len(pages[p]['SK'])))
print('HOTOVO')
