# -*- coding: utf-8 -*-
"""
Prehlad (dashboard) na telefone — rozlozenie ako chce user:
- 4 KPI karty: 2x2 na telefone (nie 4 v rade, nie prilis male)
- graf pod nimi na celu sirku
- OKTE widget hned pod grafom (mobile top uz existuje)
- stridace/zariadenia pod tym
- vsetko v 1 stĺpci, ziadne pretanie, poriadne velkosti
"""
import io

OK, ERR = [], []


def read(p):
    return io.open(p, 'r', encoding='utf-8').read()


def write(p, c):
    io.open(p, 'w', encoding='utf-8', newline='').write(c)


def rep(path, old, new, label, required=True):
    c = read(path)
    if new in c:
        OK.append('SKIP (already): ' + label)
        return
    for o in (old, old.replace('\n', '\r\n')):
        if o in c:
            c = c.replace(o, new.replace('\n', '\r\n') if '\r\n' in c else new, 1)
            write(path, c)
            OK.append('OK: ' + label)
            return
    if required:
        ERR.append('NOT FOUND: ' + label)
    else:
        OK.append('SKIP (not found): ' + label)


p = 'templates/dashboard.html'

# ============================================================
# 1) 768px breakpoint: 2x2 KPI, velke hodnoty, graf cela sirka
# ============================================================
rep(p, """        @media (max-width: 768px) {
            .okte-sidebar { display: none !important; }
            .okte-mobile-top { display: block !important; }
            .metrics-grid-4 { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .metric-card { padding: 14px; border-radius: 14px; }
            .metric-value-styled { font-size: 20px; }""",
   """        @media (max-width: 768px) {
            .okte-sidebar { display: none !important; }
            .okte-mobile-top { display: block !important; }
            /* KPI: 2x2 mriezka, vecie karty a citatelne hodnoty */
            .metrics-grid-4 { grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 16px; }
            .metric-card { padding: 16px 14px; border-radius: 16px; gap: 10px; }
            .metric-value-styled { font-size: 22px; font-weight: 900; letter-spacing: -0.02em; }
            .metric-label-styled { font-size: 9px; }
            .metric-indicator-bar { height: 5px; border-radius: 3px; }
            /* Graf cela sirka, primerana vyska */
            .dashboard-grid { grid-template-columns: 1fr !important; gap: 14px; }
            .main-wrapper { padding: 14px 12px !important; }""",
   'dashboard: 2x2 KPI + graf na sirku (768px)')

# ============================================================
# 2) 480px: este vacsie hodnoty, 2 karty v rade aj na malych
# ============================================================
rep(p, """        @media (max-width: 480px) {
            .metrics-grid-4 { grid-template-columns: 1fr 1fr; gap: 8px; }
            .metric-card { padding: 12px; }
            .metric-value-styled { font-size: 18px; }
            .metric-label-styled { font-size: 8px; }""",
   """        @media (max-width: 480px) {
            /* KPI zostavaju 2x2 aj na malych telefonoch — hodnoty velke a citatelne */
            .metrics-grid-4 { grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; }
            .metric-card { padding: 14px 12px; border-radius: 14px; gap: 8px; }
            .metric-value-styled { font-size: 20px; font-weight: 900; }
            .metric-label-styled { font-size: 8.5px; }
            .metric-indicator-bar { height: 4px; }""",
   'dashboard: 480px velkosti')

# ============================================================
# 3) Graf vyska na telefone — 240px a cela sirka
# ============================================================
rep(p, """        @media (max-width: 768px) {
            #elvoChart { height: 250px !important; }
            #sectionDashboard .glass-card div[style*="height:320px"] { height: 250px !important; }
        }""",
   """        @media (max-width: 768px) {
            #elvoChart { height: 260px !important; width: 100% !important; }
            #sectionDashboard .glass-card div[style*="height:320px"] { height: 260px !important; }
            .glass-card { overflow: hidden; }
        }""",
   'dashboard: graf vyska 260px na telefone')

# ============================================================
# 4) Order na mobile: KPI -> graf -> OKTE -> zariadenia
#    (okte-mobile-top uz je v HTML pred striedacmi — len确保 display block)
# ============================================================
rep(p, """                    <!-- Mobile OKTE (top) -->
                    <div class="okte-mobile-top" style="display:none;">""",
   """                    <!-- Mobile OKTE (pod grafom) -->
                    <div class="okte-mobile-top" style="display:none;">""",
   'dashboard: mobile OKTE komentar', required=False)

print('=== VYSLEDKY ===')
for x in OK:
    print(' ', x)
for x in ERR:
    print(' !!', x)
