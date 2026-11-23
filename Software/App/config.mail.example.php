<?php
/**
 * config.mail.php — odosielanie e-mailov cez WebSupport.
 *
 * Tento súbor NIE JE v gite (obsahuje heslo schránky). Nahraj ho na hosting
 * vedľa config.php. Údaje nájdeš v paneli WebSupport:
 *   Emaily → Prihlasovacie údaje
 *
 * Ako to funguje:
 *   - ak sú smtp_user aj smtp_pass vyplnené → appka posiela cez SMTP
 *     (smtp.m1.websupport.sk, port 465, SSL),
 *   - ak nie → posiela natívnym mail() cez poštový server hostingu.
 *     Aj táto cesta funguje, netreba heslo schránky.
 *
 * Dôležité: mail_from MUSÍ byť existujúca schránka na tvojej doméne —
 * WebSupport ticho zahodí správu s vymyslenou adresou (napr. no-reply@).
 * Preto je predvolená hodnota info@elvosolar.sk (rovnaká ako v kontaktnom
 * formulári webu).
 */
return [
    'mail_from'       => 'info@elvosolar.sk',
    'smtp_host'       => 'smtp.m1.websupport.sk',
    'smtp_port'       => 465,
    'smtp_encryption' => 'ssl',                    // 'ssl' pre 465, 'tls' pre 587
    'smtp_user'       => '',                       // voliteľné: celá e-mailová adresa schránky
    'smtp_pass'       => '',                       // voliteľné: heslo schránky
];
