# Nasadenie na Railway (PHP + Apache) — Elvosolar

Web je pripravený na Railway: `Dockerfile` (PHP 8.3 + Apache), `railway.json`
(healthcheck `/healthz`) a konfigurácia iba cez premenné prostredia.
Nič tajné nie je v repozitári ani v image.

> **Dôležité:** Railway blokuje odchádzajúce SMTP na plánoch Free, Trial a Hobby.
> Preto send.php posiela e-maily cez **Resend HTTPS API** (port 443). SMTP ostáva
> ako záloha pre Pro plán alebo klasický hosting.

---

## 0. Jeden súbor so všetkým — `.env`

Všetky údaje a tajomstvá sú na jednom mieste v súbore **`.env`** v koreni projektu:
web, e-maily (Resend/SMTP), administrácia, Cloudflare aj Railway. Nič iné
netreba vypĺňať.

```
.env
 ├─ SITE_URL, ELVO_DATA_DIR
 ├─ RESEND_API_KEY, MAIL_FROM, MAIL_TO, MAIL_BACKUP, MAIL_REPLY_TO
 ├─ BREVO_API_KEY, SMTP_HOST/PORT/SECURE/USER/PASS
 ├─ ADMIN_USER, ADMIN_PASSWORD_HASH
 ├─ CLOUDFLARE_API_TOKEN, CLOUDFLARE_ZONE_ID, CLOUDFLARE_DOMAIN, CLOUDFLARE_PROXIED
 └─ RAILWAY_TOKEN, RAILWAY_PROJECT_ID, RAILWAY_SERVICE_ID, RAILWAY_ENVIRONMENT, RAILWAY_DOMAIN
```

Súbor čítajú priamo `send.php` a `admin.php` (premenné prostredia z Railway majú
prednosť). Z toho istého súboru sa nastaví aj Cloudflare a Railway:

```bash
python _dev/config.py check             # skontroluje, čo chýba
python _dev/config.py railway            # ukáže premenné (bez zmeny)
python _dev/config.py railway --apply    # nastaví ich na Railway
python _dev/config.py dns --apply        # CNAME www + @ v Cloudflare
python _dev/config.py all --apply        # oboje naraz
```

Bez `--apply` sa nič nezmení. Tajomstvá sa vo výpise maskujú. Súbor je
v `.gitignore`, v `.dockerignore` a `.htaccess` ho z webu blokuje.

---

## 1. Nasadenie (5 minút)

1. Nahrajte projekt do GitHub repozitára (bez `_mail-config.php` — je v `.gitignore`
   aj v `.dockerignore`, takže sa do image nedostane).
2. Railway → **New Project → Deploy from GitHub repo** → vyberte repozitár.
3. Ak je web v spoločnom repozitári (tu priečinok `Software/Web`), nastavte v službe
   **Settings → Source → Root Directory** na `Software/Web` — inak Railway nenájde `Dockerfile`.
   Najjednoduchšie je samostatný repozitár len pre tento web.
4. Railway automaticky použije `Dockerfile`. Netreba nastavovať build ani start príkaz.
5. Po nasadení otvorte vygenerovanú adresu `…up.railway.app` — web musí odpovedať.
   (Táto adresa má hlavičku `X-Robots-Tag: noindex`, aby nekonkurovala doméne.)

Healthcheck: `GET /healthz` → `200 ok`.

---

## 2. Premenné prostredia (Railway → Service → Variables)

| Premenná | Povinné | Príklad | Význam |
|---|---|---|---|
| `RESEND_API_KEY` | áno | `re_…` | HTTPS odosielanie e-mailov |
| `MAIL_FROM` | áno | `info@elvosolar.sk` | odosielateľ (doména overená v Resende) |
| `MAIL_FROM_NAME` | nie | `Elvosolar, a.s.` | meno odosielateľa |
| `MAIL_TO` | áno | `info@elvosolar.sk` | kam chodia dopyty |
| `MAIL_BACKUP` | nie | `adam.dzurko5@gmail.com` | záložná adresa pri zlyhaní |
| `MAIL_REPLY_TO` | nie | `info@elvosolar.sk` | Reply-To v e-mailoch |
| `SITE_URL` | nie | `https://www.elvosolar.sk` | adresa v šablónach e-mailov |
| `ELVO_DATA_DIR` | nie | `/data` | priečinok s dopytmi (image ho už nastavuje) |
| `ADMIN_USER` | nie | `admin` | prihlásenie do administrácie |
| `ADMIN_PASSWORD_HASH` | nie | `$2y$10$…` | hash hesla namiesto súboru v projekte |

Tieto premenné nemusíte vypĺňať ručne v dashboarde — sú v `.env` a nastaví ich
`python _dev/config.py railway --apply`.

Hash sa vytvorí lokálne:

```bash
php -r "echo password_hash('nove-dlhe-heslo', PASSWORD_DEFAULT), PHP_EOL;"
```

Pri zmene premennej Railway službu automaticky redeployne.

---

## 3. Trvalé úložisko pre dopyty (Volume)

Kontajner je efemérny — bez volume by sa `submissions.jsonl` zmazal pri každom
nasadení.

1. Service → **+ New → Volume**.
2. Mount path: **`/data`**.
3. Netreba nič ďalšie — `ELVO_DATA_DIR=/data` je už v Dockerfile.

Súbory na volume:

- `/data/submissions.jsonl` — všetky dopyty (zobrazuje `admin.php`)
- `/data/undelivered.log` — správy, ktoré nešlo odoslať e-mailom
- `/data/admin_config.php` — heslo zmenené v administrácii

Záloha: `railway ssh` → `cat /data/submissions.jsonl`, alebo dočasne pripojte
volume k službe a stiahnite súbor.

---

## 4. E-maily cez Resend

1. Vytvorte účet na [resend.com](https://resend.com) a pridajte doménu
   `elvosolar.sk` (Domains → Add Domain).
2. Do DNS domény vložte záznamy, ktoré Resend vypíše (DKIM + SPF). Bez overenia
   domény API vráti chybu 403 a send.php sa pokúsi o záložné kanály.
3. Po overení vytvorte API kľúč (API Keys → Create) a vložte ho do Railway ako
   `RESEND_API_KEY`.
4. Test: odošlite kontaktný formulár, skontrolujte doručenie do `MAIL_TO`
   a záznam v `admin.php`.

Overenie doručovania: [resend.com/emails](https://resend.com/emails) → stav
`Delivered`. Pri chybe 403 je problém v neoverenej doméne, pri 401 v API kľúči.

Prechodné nastavenie bez vlastnej domény: `MAIL_FROM=onboarding@resend.dev`
(doručí sa len na e-mail vlastníka účtu Resend).

---

## 5. Doména a SEO po nasadení

1. Railway → Settings → **Networking → Custom Domain** → `www.elvosolar.sk`.
2. Pridajte aj `elvosolar.sk`. Router sám robí **301 na `https://www.elvosolar.sk`**,
   takže vyhľadávače vidia jednu kanonickú verziu (zhoduje sa s `canonical`).
3. DNS: `CNAME www → …up.railway.app` (alebo A/ALIAS podľa Railway), pre apex
   doménu postupujte podľa pokynov Railway.
4. Po prepnutí DNS overte:
   - `https://www.elvosolar.sk/healthz` → `ok`
   - `https://elvosolar.sk/kontakt` → 301 na `https://www.elvosolar.sk/kontakt`
   - `https://www.elvosolar.sk/robots.txt` a `/sitemap.xml` → 200
   - v hlavičkách `www` domény **nesmie** byť `X-Robots-Tag: noindex`
5. Google Search Console → pridajte doménu → **Sitemaps → `sitemap.xml`** →
   požiadajte o preindexovanie (`URL Inspection`).
6. Rich results: <https://search.google.com/test/rich-results> na `/`,
   `/fotovoltika` a `/kontakt` (Organization, WebSite, Service, BreadcrumbList).

Starý WordPress na doméne: po prepnutí DNS skontrolujte, že všetky staré adresy
(`/sluzby`, `/wp-…`) už nevrátia 200. `robots.txt` a `sitemap.xml` sú súčasťou
projektu, netreba ich dopĺňať.

---

## 6. Užitočné príkazy

```bash
railway login
railway link            # vyberte projekt
railway up              # nasadenie z lokálneho priečinka
railway logs            # logy Apache/PHP (chyby e-mailov sa píšu sem)
railway variables       # zoznam premenných
railway ssh             # shell v kontajneri (ls /data)
```

Rýchla kontrola e-mailu v kontajneri:

```bash
railway ssh
php -r 'var_dump(getenv("RESEND_API_KEY") !== false);'
tail -n 20 /data/undelivered.log 2>/dev/null
```
