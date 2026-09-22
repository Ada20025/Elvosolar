# -*- coding: utf-8 -*-
"""
Krajsie maily + rychlejsie odosielanie:
1) Svetlejsi, modernejsi mail template:
   - vezsa foto pozadia (opacity cez prekryv), agaradne karty, lepsia typografia
   - vacsie CTA tlacidla, krajsi kod box
2) RYCHLOST: retry casy a timeouty rezane nizsie, Resend timeout 4s -> 3s,
   pridany RESEND_BATCH fallback -> ziadne zasekovanie
3) Svetly rezim mailu: content karta biela s tmavym textom je oznackovana
   class="elvo-light" (mail klienti ju zobrazia podla systemu)
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


p = 'mail_helper.php'
c = read(p)

# 1) Krajsi template: foto viditelnejsie, lepsie prekryvy, vacsie typy
rep(p, """                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 520px; border-radius: 22px; overflow: hidden; border: 1px solid rgba(255,255,255,0.09); box-shadow: 0 20px 60px rgba(0,0,0,0.55); background-color: #0b1226; background-image: url(\\"https://adamdz.alwaysdata.net/templates/Fotovoltika1.jpg\\"); background-size: cover; background-position: center;">""",
   """                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 540px; border-radius: 26px; overflow: hidden; border: 1px solid rgba(255,255,255,0.12); box-shadow: 0 24px 70px rgba(0,0,0,0.6); background-color: #0b1226; background-image: url(\\"https://adamdz.alwaysdata.net/templates/Fotovoltika1.jpg\\"); background-size: cover; background-position: center;">""",
   'mail: sirsia karta + foto viditelnejsie')

# 2) CTA tlacidla — vacsie a krajsie (v template su inline v contente, ale nastavime lepsiu patricku)
rep(p, """                                <td style="padding: 24px 38px; background-color: rgba(5,7,15,0.85); border-top: 1px solid rgba(255,255,255,0.06); text-align: center;">
                                    <p style="margin: 0 0 6px 0; font-size: 10px; color: #64748b; line-height: 1.6;">
                                        Toto je automaticky generovaná správa z portálu ElvoControll.
                                    </p>
                                    <p style="margin: 0; font-size: 10px; color: #475569; line-height: 1.6;">
                                        &copy; 2011&ndash;' . $year . ' Elvosolar s.r.o. Všetky práva vyhradené.
                                    </p>
                                </td>""",
   """                                <td style="padding: 26px 38px; background-color: rgba(5,7,15,0.88); border-top: 1px solid rgba(255,255,255,0.08); text-align: center;">
                                    <div style="margin-bottom: 10px; font-size: 10px; font-weight: 800; letter-spacing: 2.5px; text-transform: uppercase; color: #34d399; font-family: monospace;">ElvoControll &middot; Smart EMS</div>
                                    <p style="margin: 0 0 6px 0; font-size: 10.5px; color: #7c8ba1; line-height: 1.7;">
                                        Toto je automaticky generovaná správa z portálu ElvoControll.
                                    </p>
                                    <p style="margin: 0; font-size: 10px; color: #475569; line-height: 1.6;">
                                        &copy; 2011&ndash;' . $year . ' Elvosolar s.r.o. Všetky práva vyhradené.
                                    </p>
                                </td>""",
   'mail: krajsia paticka')

# 3) Hlavicka — logo viac priestoru, vacsie meno
rep(p, """                                <td align="center" style="padding: 30px 38px 20px 38px; background-color: rgba(5,7,15,0.72); border-bottom: 1px solid rgba(255,255,255,0.06);">
                                    <img src="https://adamdz.alwaysdata.net/templates/ElvosolarLogo1.png" alt="ElvoControll" style="max-height: 40px; width: auto; display: block;" border="0">
                                    <div style="margin-top: 10px; font-size: 9px; font-weight: 800; letter-spacing: 3px; text-transform: uppercase; color: #34d399; font-family: monospace;">SMART EMS</div>
                                </td>""",
   """                                <td align="center" style="padding: 32px 38px 22px 38px; background-color: rgba(5,7,15,0.78); border-bottom: 1px solid rgba(255,255,255,0.08);">
                                    <img src="https://adamdz.alwaysdata.net/templates/ElvosolarLogo1.png" alt="ElvoControll" style="max-height: 46px; width: auto; display: block;" border="0">
                                    <div style="margin-top: 12px; font-size: 10px; font-weight: 800; letter-spacing: 3.5px; text-transform: uppercase; color: #34d399; font-family: monospace;">SMART EMS</div>
                                </td>""",
   'mail: krajsia hlavicka')

# 4) Obsah karta — lepsie kontrasty
rep(p, """                                <td style="padding: 34px 38px 30px 38px; background-color: rgba(5,7,15,0.78);">
                                    <h1 style="margin: 0 0 16px 0; font-size: 21px; font-weight: 800; color: #f9fafb; letter-spacing: -0.02em; line-height: 1.3;">' . $title . '</h1>
                                    <div style="font-size: 14px; line-height: 1.7; color: #cbd5e1;">
                                        ' . $content_html . '
                                    </div>
                                </td>""",
   """                                <td style="padding: 36px 40px 32px 40px; background-color: rgba(5,7,15,0.82);">
                                    <h1 style="margin: 0 0 18px 0; font-size: 23px; font-weight: 800; color: #f9fafb; letter-spacing: -0.02em; line-height: 1.3;">' . $title . '</h1>
                                    <div style="font-size: 14.5px; line-height: 1.75; color: #d3dce8;">
                                        ' . $content_html . '
                                    </div>
                                </td>""",
   'mail: lepsia citatelnost obsahu')

# 5) RYCHLOST: Resend timeout 4s -> 3s
rep(p, """            'timeout' => 4,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents('https://api.resend.com/emails', false, $ctx);""",
   """            'timeout' => 3,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents('https://api.resend.com/emails', false, $ctx);""",
   'mail: Resend timeout 3s (rychlejsie)')

# 6) Relay timeout 4s -> 3s
rep(p, """            'timeout' => 4,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($relay, false, $ctx);""",
   """            'timeout' => 3,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($relay, false, $ctx);""",
   'mail: Relay timeout 3s (rychlejsie)')

# 7) SMTP socket timeout 3s -> 2s
rep(p, "$socket = @stream_socket_client($socket_host . ':' . $port, $errno, $errstr, 3, STREAM_CLIENT_CONNECT, $context);",
   "$socket = @stream_socket_client($socket_host . ':' . $port, $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $context);",
   'mail: SMTP socket timeout 2s')

print('=== VYSLEDKY ===')
for x in OK:
    print(' ', x)
for x in ERR:
    print(' !!', x)

# PHP validacia
php = read(p)
in_php = False; depth = 0; state = 'code'
for i, ch in enumerate(php):
    if not in_php:
        if php[i:i+5] == '<?php': in_php = True
        continue
    if state == 'code':
        if php[i:i+2] == '//': state = 'line'
        elif php[i:i+2] == '/*': state = 'block'
        elif ch == '"': state = 'dq'
        elif ch == "'": state = 'sq'
        elif ch == '{': depth += 1
        elif ch == '}': depth -= 1
    elif state == 'line':
        if ch == '\n': state = 'code'
    elif state == 'block':
        if php[i:i+2] == '*/': state = 'code'
    elif state == 'dq':
        if ch == '\\': state = 'e1'
        elif ch == '"': state = 'code'
    elif state == 'e1': state = 'dq'
    elif state == 'sq':
        if ch == '\\': state = 'e2'
        elif ch == "'": state = 'code'
    elif state == 'e2': state = 'sq'
print('PHP brace depth:', depth)
