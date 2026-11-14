// Lokalny dev server pre preview webu ElvoSolar (staticky + PHP stuby)
// Spustenie: node _dev_server.js   → http://localhost:8478
const http = require('http');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, 'Software', 'Web', process.env.ELVO_SUB || 'www.elvosolar.sk');
const PORT = parseInt(process.env.ELVO_PORT || '8478', 10);

const TYPES = {
    '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8',
    '.js': 'application/javascript; charset=utf-8', '.json': 'application/json; charset=utf-8',
    '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.svg': 'image/svg+xml',
    '.pdf': 'application/pdf', '.ico': 'image/x-icon', '.webp': 'image/webp',
    '.txt': 'text/plain; charset=utf-8', '.xml': 'application/xml; charset=utf-8',
};

function safePath(urlPath) {
    let p;
    try { p = decodeURIComponent(urlPath); } catch (e) { p = urlPath; }
    p = p.split('?')[0].split('#')[0];
    const abs = path.normalize(path.join(ROOT, p));
    if (!abs.startsWith(ROOT)) return null;
    return abs;
}

const server = http.createServer((req, res) => {
    let urlPath = req.url || '/';
    fs.appendFile(path.join(__dirname, '_dev_server.log'), 'RAW=' + JSON.stringify(req.url) + '\n', () => {});
    urlPath = urlPath.split('?')[0].split('#')[0];
    if (urlPath.includes('%')) {
        try { urlPath = decodeURIComponent(urlPath); }
        catch (e) {
            // neplatna UTF-8 sekvencia (CP1250 konzola) -> dekoduj bajt po bajte, slovenske znaky podla CP1250
            const C1250 = { 0x8A: 'Š', 0x8C: 'Ś', 0x8D: 'Ť', 0x8E: 'Ž', 0x8F: 'Ź', 0x9A: 'š', 0x9C: 'ś', 0x9D: 'ť', 0x9E: 'ž', 0x9F: 'ź', 0xA3: 'Ł', 0xA5: 'Ą', 0xAF: 'Ż', 0xB1: 'ą', 0xB3: 'ł', 0xBF: 'ż', 0xBC: 'Ľ', 0xBE: 'ľ', 0xC0: 'Ŕ', 0xC5: 'Ĺ', 0xC8: 'Č', 0xD0: 'Ď', 0xD2: 'Ň', 0xE0: 'ŕ', 0xE5: 'ĺ', 0xE8: 'č', 0xF0: 'ď', 0xF2: 'ň' };
            urlPath = urlPath.replace(/%([0-9A-Fa-f]{2})/g, (m, h) => {
                const b = parseInt(h, 16);
                return C1250[b] || String.fromCharCode(b);
            });
        }
    } else if (/[^\x00-\x7F]/.test(urlPath)) {
        // surove UTF-8 bajty v request line (citane ako latin1) -> skutocny text
        try { urlPath = Buffer.from(urlPath, 'latin1').toString('utf8'); } catch (e) { /* raw */ }
    }

    // PHP stuby (lokálne sa PHP nevykonáva)
    if (urlPath.endsWith('.php')) {
        const name = path.basename(urlPath);
        if (name === 'send.php') {
            res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
            res.end(JSON.stringify({ success: true, stub: true, message: 'DEV: e-mail by bol odoslaný (lokálny stub). Na produkcii odošle send.php reálny e-mail.' }));
        } else {
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            res.end('<!DOCTYPE html><meta charset="utf-8"><body style="font-family:sans-serif;background:#0b1220;color:#e5ecf5;padding:40px"><h1>' + name + '</h1><p>PHP sa na lokálnom preview nevykonáva. Na produkcii (InfinityFree) funguje normálne.</p></body>');
        }
        console.log(new Date().toISOString(), req.method, urlPath, '-> PHP STUB');
        return;
    }

    if (urlPath === '/' || urlPath === '') urlPath = '/index.html';
    const abs = safePath(urlPath);
    if (!abs) { res.writeHead(403); res.end('Forbidden'); return; }

    fs.stat(abs, (err, st) => {
        let file = abs;
        if (!err && st.isDirectory()) file = path.join(abs, 'index.html');
        fs.readFile(file, (err2, data) => {
            if (err2) {
                const notFound = path.join(ROOT, '404.html');
                fs.readFile(notFound, (e3, nf) => {
                    res.writeHead(404, { 'Content-Type': 'text/html; charset=utf-8' });
                    res.end(e3 ? '404' : nf);
                });
                console.log(new Date().toISOString(), req.method, urlPath, '-> 404');
                return;
            }
            const type = TYPES[path.extname(file).toLowerCase()] || 'application/octet-stream';
            res.writeHead(200, { 'Content-Type': type });
            res.end(data);
            console.log(new Date().toISOString(), req.method, urlPath, '-> 200');
        });
    });
});

server.on('error', (e) => { console.error('SERVER ERROR:', e.message); process.exit(1); });
server.listen(PORT, '127.0.0.1', () => console.log('ElvoSolar dev server bezi na http://localhost:' + PORT));
